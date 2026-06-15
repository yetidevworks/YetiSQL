<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Executor;

use Generator;
use YetiDevWorks\YetiSQL\Engine\Blob;
use YetiDevWorks\YetiSQL\Engine\ColumnInfo;
use YetiDevWorks\YetiSQL\Engine\Database;
use YetiDevWorks\YetiSQL\Engine\IndexInfo;
use YetiDevWorks\YetiSQL\Engine\RecordCodec;
use YetiDevWorks\YetiSQL\Engine\TableBTree;
use YetiDevWorks\YetiSQL\Engine\TableInfo;
use YetiDevWorks\YetiSQL\Exception\SqlException;
use YetiDevWorks\YetiSQL\Functions\Aggregates;
use YetiDevWorks\YetiSQL\Sql\Ast\CreateIndexStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\AlterTableStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\CreateTableStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\CreateTriggerStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\CreateViewStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\DeleteStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\DropStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\Expr;
use YetiDevWorks\YetiSQL\Sql\Ast\CheckConstraint;
use YetiDevWorks\YetiSQL\Sql\Ast\ForeignKey;
use YetiDevWorks\YetiSQL\Sql\Ast\InsertStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\JoinClause;
use YetiDevWorks\YetiSQL\Sql\Ast\PragmaStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\ExplainStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\ResultColumn;
use YetiDevWorks\YetiSQL\Sql\Ast\SelectStatement;
use YetiDevWorks\YetiSQL\Vdbe\Compiler as VdbeCompiler;
use YetiDevWorks\YetiSQL\Vdbe\Vm as VdbeVm;
use YetiDevWorks\YetiSQL\Sql\Ast\Statement;
use YetiDevWorks\YetiSQL\Sql\Ast\UpdateStatement;
use YetiDevWorks\YetiSQL\Types\Value;

/**
 * Tree-walking statement executor. The query path is a classic pipeline:
 * nested-loop join -> WHERE filter -> optional group/aggregate -> DISTINCT ->
 * ORDER BY -> LIMIT. DML maintains the rowid table b-tree directly.
 */
final class Executor
{
    /**
     * Compiled, parameter-independent metadata per SELECT statement, keyed by
     * object id and validated against the schema cookie. Re-executing a prepared
     * (or repeated) query skips output-column resolution, aggregate collection
     * and order planning — the work is identical run to run.
     *
     * @var array<int,array{cookie:int,sig:string,cols:list<array<string,mixed>>,names:list<string>,order:list<array<string,mixed>>,aggs:array<int,Expr>,isAgg:bool}>
     */
    private array $selectMeta = [];
    private const SELECT_META_MAX = 1024;
    /**
     * Inner-table row-count ceiling under which a covered-count map (for indexed
     * joins / correlated COUNT(*)) is built eagerly: the one-time build is cheap,
     * cached, and reused, so it beats per-probe seeks. Above it, the map is only
     * built once enough outer rows have probed to amortize the full walk.
     */
    private const COUNT_MAP_EAGER_MAX = 10000;
    /** @var array<string,int> */
    private array $coveredCountCache = [];
    private const COVERED_COUNT_CACHE_MAX = 1024;
    /** @var array<int,array{cookie:int,changes:int,root:int,rows:list<list<null|int|float|string|Blob>>}> */
    private array $groupedAggregateCache = [];
    /** @var array<int,array{cookie:int,changes:int,root:int,pos:int,sig:string,map:array<string,int>}> */
    private array $correlatedCountCache = [];
    /** @var array<int,array{cookie:int,changes:int,map:array<string,int>}> */
    private array $indexCountMapCache = [];
    /** @var array<int,int> per-subquery probe counter, gates count-map materialization */
    private array $correlatedProbeCounts = [];
    /** @var array<int,int> per-subquery cached inner row count (measured once, not per probe) */
    private array $correlatedMapRows = [];
    /** @var array<string,array<string,list<array{rowid:int,values:list<null|int|float|string|Blob>}>>> */
    private array $fkCascadeChildCache = [];
    /** @var \WeakMap<SelectStatement,string> */
    private \WeakMap $selectSignatures;
    /** @var \WeakMap<TableInfo,array{dependsOnGenerated:bool,programs:array<int,?\Closure>}> */
    private \WeakMap $generatedColumnPlans;
    /** @var \WeakMap<TableInfo,array{cookie:int,plans:list<array{childPositions:list<int>,parent:TableInfo,refPositions:list<int>,index:?IndexInfo}>}> */
    private \WeakMap $fkChildCheckPlans;

    /**
     * Common table expressions visible to the statement currently executing,
     * keyed by lowercased name. Saved/restored around each WITH-bearing select
     * so nested scopes shadow correctly.
     *
     * @var array<string,array{select:SelectStatement,columns:?list<string>,recursive:bool,rows:?list<list<null|int|float|string|Blob>>,outCols:?list<string>}>
     */
    private array $cteScope = [];

    /** @var array<string,true> views currently being expanded (circular-reference guard) */
    private array $viewStack = [];

    /** @var list<RowEnv> NEW/OLD row contexts for triggers currently firing (innermost last). */
    private array $triggerEnvStack = [];

    /** @var array<string,true> trigger names currently on the firing stack (non-recursive guard). */
    private array $activeTriggers = [];

    public function __construct(private readonly Database $db)
    {
        $this->selectSignatures = new \WeakMap();
        $this->generatedColumnPlans = new \WeakMap();
        $this->fkChildCheckPlans = new \WeakMap();
    }

    /**
     * Register a select's CTEs into the active scope, returning the prior scope
     * for restoration. CTE bodies see earlier (and, for recursion, their own)
     * names, matching SQLite.
     *
     * @return array<string,mixed> the scope to restore
     */
    private function registerCtes(SelectStatement $select): array
    {
        $saved = $this->cteScope;
        foreach ($select->with as $cte) {
            $this->cteScope[\strtolower($cte['name'])] = [
                'select' => $cte['select'],
                'columns' => $cte['columns'],
                'recursive' => $select->recursive,
                'rows' => null,
                'outCols' => null,
            ];
        }
        return $saved;
    }

    public function lastInsertId(): int
    {
        return $this->db->lastInsertId();
    }

    public function changes(): int
    {
        return $this->db->changes();
    }

    public function isWrite(Statement $stmt): bool
    {
        return $stmt instanceof InsertStatement
            || $stmt instanceof UpdateStatement
            || $stmt instanceof DeleteStatement
            || $stmt instanceof CreateTableStatement
            || $stmt instanceof CreateIndexStatement
            || $stmt instanceof CreateViewStatement
            || $stmt instanceof CreateTriggerStatement
            || $stmt instanceof AlterTableStatement
            || $stmt instanceof DropStatement;
    }

    /** @param array<string,null|int|float|string|Blob> $params */
    public function execute(Statement $stmt, array $params): Result
    {
        return match (true) {
            $stmt instanceof SelectStatement => $this->execSelect($stmt, $params),
            $stmt instanceof InsertStatement => $this->execInsert($stmt, $params),
            $stmt instanceof UpdateStatement => $this->execUpdate($stmt, $params),
            $stmt instanceof DeleteStatement => $this->execDelete($stmt, $params),
            $stmt instanceof CreateTableStatement => $this->execCreateTable($stmt),
            $stmt instanceof CreateViewStatement => $this->execCreateView($stmt),
            $stmt instanceof CreateTriggerStatement => $this->execCreateTrigger($stmt),
            $stmt instanceof AlterTableStatement => $this->execAlter($stmt),
            $stmt instanceof DropStatement => $this->execDrop($stmt),
            $stmt instanceof CreateIndexStatement => $this->execCreateIndex($stmt),
            $stmt instanceof PragmaStatement => $this->execPragma($stmt),
            $stmt instanceof ExplainStatement => $this->execExplain($stmt),
            default => throw new SqlException('statement type not supported: ' . $stmt::class),
        };
    }

    // === SELECT ==========================================================

    /** @param array<string,null|int|float|string|Blob> $params */
    private function execSelect(SelectStatement $select, array $params): Result
    {
        if ($select->with !== []) {
            $saved = $this->registerCtes($select);
            try {
                return $this->execSelectInner($select, $params);
            } finally {
                $this->cteScope = $saved;
            }
        }
        return $this->execSelectInner($select, $params);
    }

    private function execSelectInner(SelectStatement $select, array $params): Result
    {
        if ($this->db->vdbeEnabled()) {
            $viaVdbe = $this->tryVdbeSelect($select, $params);
            if ($viaVdbe !== null) {
                return $viaVdbe;
            }
        }

        $streamed = $this->tryStreamSelect($select, $params);
        if ($streamed !== null) {
            return $streamed;
        }

        $eval = new Evaluator($this, $params);
        [$columns, $rows, $keys] = $this->runSelect($select, $eval);

        // Compound set operations (UNION/INTERSECT/EXCEPT).
        if ($select->compound !== null) {
            [, $rightRows] = $this->runSelect($select->compound, new Evaluator($this, $params));
            $rows = $this->applyCompound((string) $select->compoundOp, $rows, $rightRows);
            // Order keys no longer align after a set operation; recompute from
            // output values using positional/alias resolution.
            $keys = $this->orderKeysFromOutput($select, $columns, $rows);
        }

        if ($select->orderBy !== []) {
            $this->sortRows($select, $rows, $keys);
        }
        $this->applyLimit($select, $rows, $params);

        return new Result(columns: $columns, rows: \array_values($rows), rowCount: \count($rows));
    }

    // === VDBE bytecode ===================================================

    /** Compile a single-table SELECT to a VDBE program, or null if unsupported. */
    private function vdbeProgram(SelectStatement $select): ?\YetiDevWorks\YetiSQL\Vdbe\Program
    {
        if ($select->with !== [] || $this->cteScope !== []) {
            return null;
        }
        $single = $this->singleTableForCompile($select);
        if ($single === null) {
            return null;
        }
        [$info, $alias] = $single;
        $outputCols = $this->resolveOutputColumns($select);
        // Aggregates are not modelled by the scan VM.
        foreach ($outputCols as $oc) {
            if ($oc['expr'] instanceof Expr && $this->exprHasAggregate($oc['expr'])) {
                return null;
            }
        }
        return VdbeCompiler::compile($select, $info, $alias, $outputCols);
    }

    /** Run a SELECT through the VDBE VM when it compiles, else null to fall back. */
    private function tryVdbeSelect(SelectStatement $select, array $params): ?Result
    {
        $program = $this->vdbeProgram($select);
        if ($program === null) {
            return null;
        }
        [$info, $alias] = $this->singleTableForCompile($select);
        $eval = new Evaluator($this, $params);
        [$columns, $rows] = (new VdbeVm())->run($program, $info, $alias, $this->db->pager(), $eval);
        return new Result(columns: $columns, rows: $rows, rowCount: \count($rows));
    }

    /** Whether an expression contains an aggregate function call. */
    private function exprHasAggregate(Expr $e): bool
    {
        if ($e->kind === Expr::FUNC && $e->window === null
            && \YetiDevWorks\YetiSQL\Functions\Aggregates::isAggregate(\strtolower((string) $e->name))) {
            return true;
        }
        foreach ([$e->left, $e->right, $e->operand, $e->subject, $e->elseExpr, $e->low, $e->high, $e->escape] as $c) {
            if ($c instanceof Expr && $this->exprHasAggregate($c)) {
                return true;
            }
        }
        foreach ([...$e->args, ...$e->list] as $c) {
            if ($this->exprHasAggregate($c)) {
                return true;
            }
        }
        foreach ($e->whens as [$w, $t]) {
            if ($this->exprHasAggregate($w) || $this->exprHasAggregate($t)) {
                return true;
            }
        }
        return false;
    }

    private function execExplain(ExplainStatement $stmt): Result
    {
        if ($stmt->queryPlan) {
            $summary = $stmt->inner instanceof SelectStatement
                ? ($this->vdbeProgram($stmt->inner)?->planSummary ?? $this->planFallbackSummary($stmt->inner))
                : 'EXPLAIN QUERY PLAN supports SELECT';
            return new Result(['id', 'parent', 'notused', 'detail'], [[0, 0, 0, $summary]], 1);
        }

        $program = $stmt->inner instanceof SelectStatement ? $this->vdbeProgram($stmt->inner) : null;
        if ($program === null) {
            return new Result(
                ['addr', 'opcode', 'p1', 'p2', 'p3', 'p4', 'comment'],
                [[0, 'Explain', 0, 0, 0, '', 'statement is not VDBE-compilable; executed by the tree-walker']],
                1,
            );
        }
        return new Result(
            ['addr', 'opcode', 'p1', 'p2', 'p3', 'p4', 'comment'],
            $program->explainRows(),
            \count($program->instructions),
        );
    }

    private function planFallbackSummary(SelectStatement $select): string
    {
        $single = $this->singleTableForCompile($select);
        return $single !== null ? 'SCAN ' . $single[0]->name : 'COMPOUND/JOIN QUERY';
    }

    /** @param array<string,null|int|float|string|Blob> $params */
    private function tryStreamSelect(SelectStatement $select, array $params): ?Result
    {
        // A lazy cursor would outlive the CTE scope (restored when execSelect
        // returns), so evaluate CTE-bearing statements eagerly instead.
        if ($this->cteScope !== []) {
            return null;
        }
        if ($select->compound !== null
            || $select->orderBy !== []
            || $select->distinct
            || $select->limit !== null
            || $select->offset !== null
            || $select->valuesRows !== []) {
            return null;
        }

        $eval = new Evaluator($this, $params);
        $meta = $this->compileSelect($select, $eval);
        if ($meta['isAgg']) {
            return null;
        }

        $eval->compiledWhere = $meta['cWhere'];
        $eval->compiledProject = $meta['cProject'];
        $eval->compiledGroupBy = $meta['cGroupBy'];
        $eval->compiledAggArgs = $meta['cAggArgs'];

        $rows = function () use ($select, $meta, $eval): \Generator {
            foreach ($this->filteredJoined($select, $eval) as $env) {
                yield $this->projectRow($meta['cols'], $env, $eval);
            }
        };

        return Result::cursor($meta['names'], $rows());
    }

    /**
     * @param array<string,null|int|float|string|Blob> $params
     * @return list<list<null|int|float|string|Blob>>
     */
    public function runSubquerySelect(
        SelectStatement $select,
        array $params,
        ?RowEnv $outerEnv = null,
        ?Evaluator $outerEval = null,
    ): array {
        if ($select->with !== []) {
            $saved = $this->registerCtes($select);
            try {
                return $this->runSubquerySelectInner($select, $params, $outerEnv, $outerEval);
            } finally {
                $this->cteScope = $saved;
            }
        }
        return $this->runSubquerySelectInner($select, $params, $outerEnv, $outerEval);
    }

    /**
     * @param array<string,null|int|float|string|Blob> $params
     * @return list<list<null|int|float|string|Blob>>
     */
    private function runSubquerySelectInner(
        SelectStatement $select,
        array $params,
        ?RowEnv $outerEnv,
        ?Evaluator $outerEval,
    ): array {
        $eval = new Evaluator($this, $params);
        $eval->outerEnv = $outerEnv;
        $eval->outerEval = $outerEval;
        [$cols, $rows] = $this->runSelect($select, $eval);
        if ($select->compound !== null) {
            $compoundEval = new Evaluator($this, $params);
            $compoundEval->outerEnv = $outerEnv;
            $compoundEval->outerEval = $outerEval;
            [, $rightRows] = $this->runSelect($select->compound, $compoundEval);
            $rows = $this->applyCompound((string) $select->compoundOp, $rows, $rightRows);
        }
        if ($select->orderBy !== []) {
            $keys = $this->orderKeysFromOutput($select, $cols, $rows);
            $this->sortRows($select, $rows, $keys);
        }
        $this->applyLimit($select, $rows, $params);
        return \array_values($rows);
    }

    /**
     * Core SELECT body (no top-level ORDER BY/LIMIT, no compound merge).
     *
     * @return array{0:list<string>,1:list<list<null|int|float|string|Blob>>,2:list<list<null|int|float|string|Blob>>}
     */
    private function runSelect(SelectStatement $select, Evaluator $eval): array
    {
        // VALUES (...) ...
        if ($select->valuesRows !== []) {
            return $this->runValues($select, $eval);
        }

        $flattened = $this->flattenSimpleSourceSelect($select);
        if ($flattened !== null) {
            return $this->runSelect($flattened, $eval);
        }

        $meta = $this->compileSelect($select, $eval);
        ['cols' => $outputCols, 'names' => $colNames, 'order' => $orderPlan, 'aggs' => $aggregateNodes, 'isAgg' => $isAggregate] = $meta;

        // Single-table WHERE/projection compiled to closures (once per statement,
        // cached in the metadata). Null entries fall back to evaluate().
        $eval->compiledWhere = $meta['cWhere'];
        $eval->compiledProject = $meta['cProject'];
        $eval->compiledGroupBy = $meta['cGroupBy'];
        $eval->compiledAggArgs = $meta['cAggArgs'];

        // Ungrouped aggregate (COUNT/SUM/AVG over a filter): stream rows straight
        // through the accumulators without materializing the row set. The covered
        // COUNT(*) case never even reads a column.
        $keys = [];
        if ($isAggregate && $select->groupBy === []) {
            $joinCount = $this->tryJoinCoveredCount($select, $outputCols, $aggregateNodes, $eval);
            if ($joinCount !== null) {
                return [$colNames, $joinCount, []];
            }
            $coveredCount = $this->tryCoveredCount($select, $outputCols, $aggregateNodes, $eval);
            if ($coveredCount !== null) {
                return [$colNames, $coveredCount, []];
            }
            $scanCount = $this->trySimpleScanCount($select, $outputCols, $aggregateNodes, $eval);
            if ($scanCount !== null) {
                return [$colNames, $scanCount, []];
            }
            return [$colNames, ...$this->aggregateUngrouped($select, $outputCols, $aggregateNodes, $eval, $orderPlan)];
        }

        if ($this->selectHasWindow($select)) {
            if ($isAggregate || $select->groupBy !== []) {
                throw new SqlException('window functions combined with GROUP BY are not supported yet');
            }
            [$rows, $keys] = $this->windowProject($select, $outputCols, $eval, $orderPlan);
        } elseif ($isAggregate) {
            $fastGrouped = $this->tryFastGroupedAggregate($select, $outputCols, $aggregateNodes, $orderPlan);
            if ($fastGrouped !== null) {
                return [$colNames, $fastGrouped[0], $fastGrouped[1]];
            }
            [$rows, $keys] = $this->aggregateProject($select, $outputCols, $aggregateNodes, $this->filteredJoined($select, $eval), $eval, $orderPlan);
        } else {
            // Expose SELECT-list aliases to ORDER BY (not to WHERE, above).
            $this->exposeSelectAliases($select, $eval);

            $rows = [];
            foreach ($this->filteredJoined($select, $eval) as $env) {
                $row = $this->projectRow($outputCols, $env, $eval);
                $rows[] = $row;
                if ($orderPlan !== []) {
                    $keys[] = $this->computeOrderKeys($orderPlan, $env, $eval, $row);
                }
            }
        }

        if ($select->distinct) {
            [$rows, $keys] = $this->distinctWithKeys($rows, $keys);
        }

        return [$colNames, $rows, $keys];
    }

    /** @return \Generator<RowEnv> */
    private function filteredJoined(SelectStatement $select, Evaluator $eval): \Generator
    {
        $cWhere = $eval->compiledWhere;
        foreach ($this->iterateJoined($select, $eval) as $env) {
            if ($select->where !== null && !$eval->whereCovered) {
                $v = $cWhere !== null ? $cWhere($env, $eval) : $eval->evaluate($select->where, $env);
                if ($v === null || !Value::isTrue($v)) {
                    continue;
                }
            }
            yield $env;
            $eval->aliasExprs = [];
        }
    }

    private function exposeSelectAliases(SelectStatement $select, Evaluator $eval): void
    {
        foreach ($select->columns as $rc) {
            if ($rc->alias !== null && $rc->expr !== null) {
                $eval->aliasExprs[\strtolower($rc->alias)] = $rc->expr;
            }
        }
    }

    /**
     * Resolve (and memoize) the parameter-independent compilation of a SELECT:
     * output columns, their names, the ORDER BY plan, aggregate nodes, and
     * whether the query aggregates. Invalidated when the schema cookie changes.
     *
     * @return array{cookie:int,sig:string,cols:list<array<string,mixed>>,names:list<string>,order:list<array<string,mixed>>,aggs:array<int,Expr>,isAgg:bool,cWhere:?\Closure,cProject:?list<?\Closure>}
     */
    private function compileSelect(SelectStatement $select, Evaluator $eval): array
    {
        $id = \spl_object_id($select);
        $cookie = $this->db->pager()->schemaCookie();
        $sig = $this->selectSignature($select);
        $cached = $this->selectMeta[$id] ?? null;
        if ($cached !== null && $cached['cookie'] === $cookie && $cached['sig'] === $sig) {
            return $cached;
        }

        $outputCols = $this->resolveOutputColumns($select);
        $colNames = \array_map(static fn (array $c): string => $c['name'], $outputCols);
        $orderPlan = $this->orderPlan($select, $colNames);

        $aggregateNodes = [];
        foreach ($outputCols as $c) {
            if ($c['expr'] !== null) {
                $this->collectAggregates($c['expr'], $aggregateNodes);
            }
        }
        if ($select->having !== null) {
            $this->collectAggregates($select->having, $aggregateNodes);
        }

        // Compile WHERE + projection + GROUP BY keys + aggregate args to closures
        // once, for single-table queries.
        $cWhere = null;
        $cProject = null;
        $cGroupBy = null;
        $cAggArgs = null;
        $single = $this->singleTableForCompile($select);
        if ($single !== null) {
            [$info, $alias] = $single;
            if ($select->where !== null) {
                $cWhere = $eval->compile($select->where, $info, $alias);
            }
            $proj = [];
            $any = false;
            foreach ($outputCols as $c) {
                $cl = $c['expr'] !== null ? $eval->compile($c['expr'], $info, $alias) : null;
                $proj[] = $cl;
                $any = $any || $cl !== null;
            }
            if ($any) {
                $cProject = $proj;
            }

            if ($select->groupBy !== []) {
                $g = [];
                $ok = true;
                foreach ($select->groupBy as $ge) {
                    $cl = $eval->compile($ge, $info, $alias);
                    if ($cl === null) {
                        $ok = false;
                        break;
                    }
                    $g[] = $cl;
                }
                $cGroupBy = $ok ? $g : null;
            }

            $cAggArgs = [];
            foreach ($aggregateNodes as $nid => $node) {
                $args = [];
                $ok = true;
                foreach ($node->args as $arg) {
                    $cl = $eval->compile($arg, $info, $alias);
                    if ($cl === null) {
                        $ok = false;
                        break;
                    }
                    $args[] = $cl;
                }
                $cAggArgs[$nid] = $ok ? $args : null;
            }
        }

        $meta = [
            'cookie' => $cookie,
            'sig' => $sig,
            'cols' => $outputCols,
            'names' => $colNames,
            'order' => $orderPlan,
            'aggs' => $aggregateNodes,
            'isAgg' => $select->groupBy !== [] || $aggregateNodes !== [],
            'cWhere' => $cWhere,
            'cProject' => $cProject,
            'cGroupBy' => $cGroupBy,
            'cAggArgs' => $cAggArgs,
        ];
        if (\count($this->selectMeta) >= self::SELECT_META_MAX) {
            $this->selectMeta = [];
        }
        return $this->selectMeta[$id] = $meta;
    }

    private function selectSignature(SelectStatement $select): string
    {
        return $this->selectSignatures[$select] ??= \sha1(\serialize($select));
    }

    /** @return array{0:TableInfo,1:string}|null [info, alias] for a single base-table query */
    private function singleTableForCompile(SelectStatement $select): ?array
    {
        if ($select->joins !== [] || $select->from === null) {
            return null;
        }
        $ref = $select->from;
        if ($ref->func !== null || $ref->subquery !== null || $ref->name === null) {
            return null;
        }
        if (\YetiDevWorks\YetiSQL\Engine\Schema::isTempMaster((string) $ref->name)) {
            return null;
        }
        $info = $this->db->schema()->getTable((string) $ref->name);
        if ($info === null) {
            return null;
        }
        return [$info, $ref->alias ?? $info->name];
    }

    /** @return array{0:list<string>,1:list<list<null|int|float|string|Blob>>,2:list<list<null|int|float|string|Blob>>} */
    private function runValues(SelectStatement $select, Evaluator $eval): array
    {
        $rows = [];
        $width = \count($select->valuesRows[0]);
        foreach ($select->valuesRows as $row) {
            $out = [];
            foreach ($row as $expr) {
                $out[] = $eval->evaluate($expr, null);
            }
            $rows[] = $out;
        }
        $cols = [];
        for ($i = 1; $i <= $width; $i++) {
            $cols[] = "column$i";
        }
        return [$cols, $rows, []];
    }

    /**
     * Build combined row environments via left-deep nested-loop joins.
     *
     * @return Generator<RowEnv>
     */
    private function iterateJoined(SelectStatement $select, Evaluator $eval): Generator
    {
        if ($select->from === null) {
            // FROM-less SELECT: one synthetic empty row.
            yield new RowEnv();
            return;
        }

        $sources = [$this->resolveSourceRef($select->from, $eval)];
        foreach ($select->joins as $join) {
            $sources[] = $this->resolveSourceRef($join->table, $eval);
        }

        // Single-table fast path: skip the recursive join generator entirely and
        // stream lazily-decoded row environments straight from the scanner. This
        // is by far the most common shape and the recursion/yield overhead shows
        // up directly in full-scan and aggregate workloads.
        if ($select->joins === [] && ($sources[0]['kind'] ?? 'table') === 'table') {
            $src = $sources[0];
            if (($src['empty'] ?? false) || $src['tree'] === null) {
                return;
            }
            $info = $src['info'];
            $alias = $src['alias'];
            $tree = $src['tree'];

            $plan = $select->where === null ? null : $this->bestPlan($select->where, $alias, $info, $eval);
            // The plan fully implements the WHERE iff there is a single sargable
            // conjunct and we found a path for it: the scan then yields exactly
            // the matching rows, so no per-row re-check is needed.
            $eval->whereCovered = $plan !== null && ($plan['coveredAll'] ?? false);

            // Inline the scan here (rather than delegating to scanByPlan) to keep
            // the hot single-table path to a single generator frame per row.
            if ($plan === null) {
                foreach ($tree->scan() as [$rowid, $payload]) {
                    $env = new RowEnv();
                    $env->addLazyFrame($alias, $info, $payload, $rowid);
                    yield $env;
                }
                return;
            }
            foreach ($this->scanByPlan($tree, $plan) as [$rowid, $payload, $covered]) {
                $env = new RowEnv();
                if ($payload === null) {
                    // Index-located row: defer the table fetch until a column is read.
                    $env->addDeferredFrame($alias, $info, $tree, $rowid, $covered);
                } else {
                    $env->addLazyFrame($alias, $info, $payload, $rowid);
                }
                yield $env;
            }
            return;
        }

        // Index/rowid planning for the driving table of a join: restrict its scan
        // using sargable WHERE conjuncts. The full WHERE is still applied downstream.
        if (($sources[0]['kind'] ?? '') === 'table' && !$sources[0]['empty'] && $select->where !== null) {
            $info = $sources[0]['info'];
            $alias = $sources[0]['alias'];
            $where = $select->where;
            $sources[0]['scanner'] = fn (): \Generator => $this->scanRowids($info, $alias, $where, $eval);
        }

        // Index nested-loop joins: for each inner table whose ON condition has an
        // equi-join `inner.col = <outer expr>` and a usable access path on
        // inner.col (rowid or index), seek the matching inner rows per outer row
        // instead of re-scanning the whole inner table. joinMatches still applies
        // the full ON, so the result is identical to the plain nested loop.
        foreach ($select->joins as $j => $join) {
            $src = $sources[$j + 1];
            if (($src['kind'] ?? 'table') !== 'table' || ($src['empty'] ?? false) || $src['tree'] === null) {
                continue;
            }
            $indexes = $this->db->schema()->resolvedIndexes($src['info']->name);
            $key = $this->joinAccessKey($join, $src['alias'], $src['info'], $indexes);
            if ($key !== null) {
                // Resolve the inner access path ONCE here, not per outer row: the
                // column, the rowid-vs-index decision, the chosen index and a
                // single reusable IndexBTree are all invariant across outer rows
                // — only the probe value changes (see joinSeek()).
                $access = $key + ['indexes' => $indexes];
                $pos = $key['pos'];
                if ($pos === $src['info']->rowidAlias) {
                    $access['seek'] = 'rowid';
                } else {
                    $index = $this->indexLeading($indexes, $pos);
                    if ($index !== null) {
                        $access['seek'] = 'index';
                        $access['index'] = $index;
                        $access['idx'] = new \YetiDevWorks\YetiSQL\Engine\IndexBTree(
                            $this->db->pager(),
                            $index->rootPage,
                            $index->collations,
                        );
                        $access['coll'] = $index->collations[0] ?? 'BINARY';
                    } else {
                        $access['seek'] = 'scan';
                    }
                }
                $sources[$j + 1]['joinAccess'] = $access;
                continue;
            }
            $hashKey = $this->joinHashKey($join, $src['alias'], $src['info']);
            if ($hashKey !== null) {
                $sources[$j + 1]['hashAccess'] = $hashKey + [
                    'rowsByKey' => $this->buildJoinHash($src['tree'], $src['info'], $hashKey['pos']),
                ];
            }
        }

        yield from $this->joinRec($sources, $select->joins, 0, new RowEnv(), $eval);
    }

    /**
     * @param list<array{alias:string,info:TableInfo,tree:TableBTree}> $sources
     * @param list<JoinClause> $joins
     * @return Generator<RowEnv>
     */
    private function joinRec(array $sources, array $joins, int $i, RowEnv $env, Evaluator $eval): Generator
    {
        if ($i === \count($sources)) {
            yield $env;
            return;
        }

        $src = $sources[$i];
        $join = $i === 0 ? null : $joins[$i - 1];

        // Derived table (subquery in FROM): pre-materialised rows.
        if (($src['kind'] ?? 'table') === 'derived') {
            $matched = false;
            foreach ($src['rows'] as $values) {
                $next = $this->cloneEnvWith($env, $src['alias'], $src['info'], $values, 0);
                if ($join !== null && !$this->joinMatches($join, $next, $env, $eval)) {
                    continue;
                }
                $matched = true;
                yield from $this->joinRec($sources, $joins, $i + 1, $next, $eval);
            }
            if ($join !== null && $join->type === JoinClause::LEFT && !$matched) {
                $nulls = \array_fill(0, $src['info']->columnCount(), null);
                $next = $this->cloneEnvWith($env, $src['alias'], $src['info'], $nulls, 0);
                yield from $this->joinRec($sources, $joins, $i + 1, $next, $eval);
            }
            return;
        }

        // Table-valued function source: evaluate args against the outer row
        // (lateral/correlated), producing rows on the fly.
        if (($src['kind'] ?? 'table') === 'tvf') {
            $matched = false;
            $argVals = [];
            foreach ($src['args'] as $arg) {
                $argVals[] = $eval->evaluate($arg, $env);
            }
            foreach ($this->tvfRows($src['func'], $argVals) as $values) {
                $next = $this->cloneEnvWith($env, $src['alias'], $src['info'], $values, 0);
                if ($join !== null && !$this->joinMatches($join, $next, $env, $eval)) {
                    continue;
                }
                $matched = true;
                yield from $this->joinRec($sources, $joins, $i + 1, $next, $eval);
            }
            if ($join !== null && $join->type === JoinClause::LEFT && !$matched) {
                $nulls = \array_fill(0, $src['info']->columnCount(), null);
                $next = $this->cloneEnvWith($env, $src['alias'], $src['info'], $nulls, 0);
                yield from $this->joinRec($sources, $joins, $i + 1, $next, $eval);
            }
            return;
        }

        $matched = false;
        if (isset($src['joinAccess'])) {
            // Index nested-loop: seek inner rows whose join column equals the
            // outer key for this row. A NULL key matches nothing (NULL never
            // equi-joins); a key with no usable plan (e.g. a non-integer value
            // against a rowid) falls back to a scan so joinMatches can decide.
            $acc = $src['joinAccess'];
            $key = $eval->evaluate($acc['keyExpr'], $env);
            if ($key === null) {
                $scan = [];
            } else {
                $scan = $this->joinSeek($acc, $key, $src['info'], $src['tree']);
                if ($scan === null) {
                    // No usable seek for this value (e.g. a non-integral value
                    // against a rowid key): full-scan and let joinMatches decide.
                    $scan = $src['tree']->scan();
                }
            }
        } elseif (isset($src['hashAccess'])) {
            $acc = $src['hashAccess'];
            $key = $eval->evaluate($acc['keyExpr'], $env);
            if ($key === null) {
                $scan = [];
            } else {
                $key = $this->joinHashValue($src['info'], $acc['pos'], $key);
                $scan = $key === null ? [] : ($acc['rowsByKey'][$this->comparisonKey($key)] ?? []);
            }
        } elseif (isset($src['scanner'])) {
            $scan = ($src['scanner'])();
        } else {
            $scan = ($src['empty'] ?? false) || $src['tree'] === null ? [] : $src['tree']->scan();
        }
        foreach ($scan as [$rowid, $payload]) {
            $next = $this->cloneEnvLazy($env, $src['alias'], $src['info'], $payload, $rowid);

            if ($join !== null && !$this->joinMatches($join, $next, $env, $eval)) {
                continue;
            }
            $matched = true;
            yield from $this->joinRec($sources, $joins, $i + 1, $next, $eval);
        }

        // LEFT JOIN with no match: emit a row padded with NULLs for this source.
        if ($join !== null && $join->type === JoinClause::LEFT && !$matched) {
            $nulls = \array_fill(0, $src['info']->columnCount(), null);
            $next = $this->cloneEnvWith($env, $src['alias'], $src['info'], $nulls, 0);
            yield from $this->joinRec($sources, $joins, $i + 1, $next, $eval);
        }
    }

    private function joinMatches(JoinClause $join, RowEnv $env, RowEnv $prior, Evaluator $eval): bool
    {
        if ($join->using !== [] || $join->natural) {
            $cols = $join->using;
            if ($join->natural) {
                $cols = $this->naturalColumns($env);
            }
            $lastFi = \count($env->frames) - 1;
            foreach ($cols as $col) {
                $a = $prior->resolveColumn(null, $col);
                $pos = $env->frames[$lastFi]['info']->columnPos($col);
                $b = $pos !== null ? $env->valueAt($lastFi, $pos) : null;
                if (Value::compare($a, $b) !== 0) {
                    return false;
                }
            }
            return true;
        }
        if ($join->on === null) {
            return true; // CROSS / comma join
        }
        $v = $eval->evaluate($join->on, $env);
        return $v !== null && Value::isTrue($v);
    }

    /** @return list<string> */
    private function naturalColumns(RowEnv $env): array
    {
        $right = $env->frames[\count($env->frames) - 1]['info'];
        $shared = [];
        foreach ($right->columns as $col) {
            foreach (\array_slice($env->frames, 0, -1) as $frame) {
                if ($frame['info']->columnPos($col->name) !== null) {
                    $shared[] = $col->name;
                    break;
                }
            }
        }
        return $shared;
    }

    private function cloneEnvWith(RowEnv $env, string $alias, TableInfo $info, array $values, int $rowid): RowEnv
    {
        $new = new RowEnv();
        $new->frames = $env->frames;
        $new->addFrame($alias, $info, $values, $rowid);
        return $new;
    }

    private function cloneEnvLazy(RowEnv $env, string $alias, TableInfo $info, string $payload, int $rowid): RowEnv
    {
        $new = new RowEnv();
        $new->frames = $env->frames;
        $new->addLazyFrame($alias, $info, $payload, $rowid);
        return $new;
    }

    /** @return array{alias:string,info:TableInfo,tree:?TableBTree,empty:bool} */
    private function resolveSource(\YetiDevWorks\YetiSQL\Sql\Ast\TableRef $ref): array
    {
        if ($ref->subquery !== null) {
            throw new SqlException('subqueries in FROM are not supported in this version');
        }
        $info = $this->db->schema()->getTable((string) $ref->name);
        if ($info === null) {
            throw new SqlException("no such table: {$ref->name}");
        }
        $empty = \YetiDevWorks\YetiSQL\Engine\Schema::isTempMaster((string) $ref->name);
        return [
            'kind' => 'table',
            'alias' => $ref->alias ?? $info->name,
            'info' => $info,
            'tree' => $empty ? null : new TableBTree($this->db->pager(), $info->rootPage),
            'empty' => $empty,
            'func' => null,
            'args' => [],
        ];
    }

    private function resolveSourceRef(\YetiDevWorks\YetiSQL\Sql\Ast\TableRef $ref, Evaluator $eval): array
    {
        if ($ref->func !== null) {
            $info = $this->tvfTableInfo($ref->func, $ref->alias ?? $ref->func);
            return [
                'kind' => 'tvf',
                'alias' => $ref->alias ?? $ref->func,
                'info' => $info,
                'tree' => null,
                'empty' => false,
                'func' => $ref->func,
                'args' => $ref->funcArgs,
                'rows' => [],
            ];
        }
        if ($ref->subquery !== null) {
            // Uncorrelated derived table: materialise once. materializeRows()
            // forces a streaming cursor result so $rows is populated here.
            $res = $this->execSelect($ref->subquery, $eval->params());
            $rows = $res->materializeRows();
            $alias = $ref->alias ?? '';
            return [
                'kind' => 'derived',
                'alias' => $alias,
                'info' => $this->derivedTableInfo($alias, $res->columns ?? []),
                'tree' => null,
                'empty' => false,
                'func' => null,
                'args' => [],
                'rows' => $rows,
            ];
        }
        if ($ref->name !== null && isset($this->cteScope[\strtolower($ref->name)])) {
            return $this->resolveCteSource($ref, $eval);
        }
        if ($ref->name !== null) {
            $view = $this->db->schema()->getView($ref->name);
            if ($view !== null) {
                return $this->resolveViewSource($ref, $view, $eval);
            }
        }
        return $this->resolveSource($ref);
    }

    private function flattenSimpleSourceSelect(SelectStatement $select): ?SelectStatement
    {
        if ($select->from === null
            || $select->joins !== []
            || $select->compound !== null
            || $select->valuesRows !== []
            || $select->distinct
            || $select->groupBy !== []
            || $select->having !== null
            || $select->orderBy !== []) {
            return null;
        }

        $ref = $select->from;
        if ($ref->name === null || $ref->func !== null || $ref->subquery !== null) {
            return null;
        }
        foreach ($select->columns as $column) {
            if ($column->star || $column->tableStar !== null) {
                return null;
            }
            if ($this->exprHasSubquery($column->expr)) {
                return null;
            }
        }

        $source = null;
        $columnNames = null;
        $key = \strtolower($ref->name);
        if (isset($this->cteScope[$key])) {
            $cte = $this->cteScope[$key];
            if ($cte['recursive']) {
                return null;
            }
            $source = $cte['select'];
            $columnNames = $cte['columns'];
        } else {
            $view = $this->db->schema()->getView($ref->name);
            if ($view === null || isset($this->viewStack[\strtolower($view['name'])])) {
                return null;
            }
            $source = $view['select'];
            $columnNames = $view['columns'] !== [] ? $view['columns'] : null;
        }

        if (!$this->isFlattenableSourceSelect($source)) {
            return null;
        }
        if ($this->selectHasWindow($select) || $this->exprHasSubquery($select->where)) {
            return null;
        }

        $map = $this->sourceProjectionMap($source, $columnNames);
        if ($map === null) {
            return null;
        }

        $sourceAlias = $ref->alias ?? $ref->name;
        // The view/CTE only exposes its projected columns. If the outer query
        // references a name the projection doesn't provide (e.g. a base-table
        // column the view hides), flattening would wrongly resolve it against
        // the underlying table. Bail so the derived-table path reports the same
        // "no such column" error SQLite does.
        foreach ($select->columns as $column) {
            if ($column->expr !== null && !$this->outerRefsResolvable($column->expr, $sourceAlias, $map)) {
                return null;
            }
        }
        if ($select->where !== null && !$this->outerRefsResolvable($select->where, $sourceAlias, $map)) {
            return null;
        }
        $flat = clone $select;
        $flat->from = clone $source->from;
        $flat->with = [];
        $flat->columns = $this->rewriteResultColumns($select->columns, $sourceAlias, $map);
        $outerWhere = $select->where !== null ? $this->rewriteSourceExpr($select->where, $sourceAlias, $map) : null;
        $innerWhere = $source->where !== null ? $this->cloneExpr($source->where) : null;
        $flat->where = $innerWhere !== null && $outerWhere !== null
            ? Expr::bin('AND', $innerWhere, $outerWhere)
            : ($innerWhere ?? $outerWhere);

        return $flat;
    }

    private function isFlattenableSourceSelect(SelectStatement $source): bool
    {
        if ($source->from === null
            || $source->joins !== []
            || $source->compound !== null
            || $source->valuesRows !== []
            || $source->with !== []
            || $source->distinct
            || $source->groupBy !== []
            || $source->having !== null
            || $source->orderBy !== []
            || $source->limit !== null
            || $source->offset !== null
            || $this->selectHasWindow($source)
            || $this->exprHasSubquery($source->where)) {
            return false;
        }

        $ref = $source->from;
        return $ref->name !== null
            && $ref->func === null
            && $ref->subquery === null
            && $this->db->schema()->getTable($ref->name) !== null;
    }

    /**
     * @param ?list<string> $columnNames
     * @return array<string,Expr>|null
     */
    private function sourceProjectionMap(SelectStatement $source, ?array $columnNames): ?array
    {
        $cols = $this->resolveOutputColumns($source);
        if ($columnNames !== null && \count($columnNames) !== \count($cols)) {
            return null;
        }

        $map = [];
        foreach ($cols as $i => $col) {
            $expr = $col['expr'];
            if (!$expr instanceof Expr || $expr->kind !== Expr::COL) {
                return null;
            }
            $name = $columnNames[$i] ?? $col['name'];
            $map[\strtolower($name)] = $this->cloneExpr($expr);
        }
        return $map;
    }

    /**
     * @param list<ResultColumn> $columns
     * @param array<string,Expr> $map
     * @return list<ResultColumn>
     */
    private function rewriteResultColumns(array $columns, string $sourceAlias, array $map): array
    {
        $out = [];
        foreach ($columns as $column) {
            // Rewriting `x` (a renamed view/CTE column) to its underlying base
            // column `a` would otherwise change the result column name from `x`
            // to `a`. Pin the original output name as an explicit alias so the
            // flattened query reports the same names as the unflattened one.
            $alias = $column->alias;
            if ($alias === null && $column->expr !== null && !$column->star && $column->tableStar === null) {
                $alias = $this->exprLabel($column->expr);
            }
            $out[] = new ResultColumn(
                expr: $column->expr !== null ? $this->rewriteSourceExpr($column->expr, $sourceAlias, $map) : null,
                star: $column->star,
                tableStar: $column->tableStar,
                alias: $alias,
            );
        }
        return $out;
    }

    /**
     * Whether every column reference in an outer expression resolves to a
     * projected column of the flattened source. A reference to the source alias
     * (or unqualified) must name a mapped column; a reference qualified with any
     * other table cannot be satisfied by the single flattened source.
     *
     * @param array<string,Expr> $map
     */
    private function outerRefsResolvable(Expr $expr, string $sourceAlias, array $map): bool
    {
        if ($expr->kind === Expr::COL) {
            if ($expr->table !== null && \strcasecmp($expr->table, $sourceAlias) !== 0) {
                return false;
            }
            return isset($map[\strtolower((string) $expr->name)]);
        }
        foreach ([$expr->left, $expr->right, $expr->operand, $expr->subject, $expr->elseExpr, $expr->low, $expr->high, $expr->escape] as $child) {
            if ($child !== null && !$this->outerRefsResolvable($child, $sourceAlias, $map)) {
                return false;
            }
        }
        foreach (\array_merge($expr->args, $expr->list) as $child) {
            if (!$this->outerRefsResolvable($child, $sourceAlias, $map)) {
                return false;
            }
        }
        foreach ($expr->whens as [$w, $t]) {
            if (!$this->outerRefsResolvable($w, $sourceAlias, $map) || !$this->outerRefsResolvable($t, $sourceAlias, $map)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,Expr> $map */
    private function rewriteSourceExpr(Expr $expr, string $sourceAlias, array $map): Expr
    {
        if ($expr->kind === Expr::COL
            && ($expr->table === null || \strcasecmp($expr->table, $sourceAlias) === 0)
            && isset($map[\strtolower((string) $expr->name)])) {
            return $this->cloneExpr($map[\strtolower((string) $expr->name)]);
        }
        return $this->cloneExpr($expr, $sourceAlias, $map);
    }

    /** @param ?array<string,Expr> $map */
    private function cloneExpr(Expr $expr, ?string $sourceAlias = null, ?array $map = null): Expr
    {
        $clone = clone $expr;
        $rewrite = function (?Expr $child) use ($sourceAlias, $map): ?Expr {
            if ($child === null) {
                return null;
            }
            return $sourceAlias !== null && $map !== null
                ? $this->rewriteSourceExpr($child, $sourceAlias, $map)
                : $this->cloneExpr($child);
        };

        $clone->left = $rewrite($expr->left);
        $clone->right = $rewrite($expr->right);
        $clone->operand = $rewrite($expr->operand);
        $clone->subject = $rewrite($expr->subject);
        $clone->elseExpr = $rewrite($expr->elseExpr);
        $clone->low = $rewrite($expr->low);
        $clone->high = $rewrite($expr->high);
        $clone->escape = $rewrite($expr->escape);
        $clone->args = \array_map($rewrite, $expr->args);
        $clone->list = \array_map($rewrite, $expr->list);
        $clone->whens = \array_map(
            static fn (array $when): array => [$rewrite($when[0]), $rewrite($when[1])],
            $expr->whens,
        );
        return $clone;
    }

    private function exprHasSubquery(?Expr $expr): bool
    {
        if ($expr === null) {
            return false;
        }
        if ($expr->kind === Expr::SUBQUERY || $expr->kind === Expr::EXISTS || $expr->select !== null) {
            return true;
        }
        foreach ([$expr->left, $expr->right, $expr->operand, $expr->subject, $expr->elseExpr, $expr->low, $expr->high, $expr->escape] as $child) {
            if ($child !== null && $this->exprHasSubquery($child)) {
                return true;
            }
        }
        foreach ($expr->args as $child) {
            if ($this->exprHasSubquery($child)) {
                return true;
            }
        }
        foreach ($expr->list as $child) {
            if ($this->exprHasSubquery($child)) {
                return true;
            }
        }
        foreach ($expr->whens as $when) {
            if ($this->exprHasSubquery($when[0]) || $this->exprHasSubquery($when[1])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve a FROM reference that names a view: run its stored SELECT and
     * serve the rows like a derived table. The view's SELECT is isolated from
     * the enclosing query's CTE scope, and a stack guards circular definitions.
     *
     * @param array{name:string,columns:list<string>,select:SelectStatement,sql:string} $view
     * @return array<string,mixed>
     */
    private function resolveViewSource(\YetiDevWorks\YetiSQL\Sql\Ast\TableRef $ref, array $view, Evaluator $eval): array
    {
        $key = \strtolower($view['name']);
        if (isset($this->viewStack[$key])) {
            throw new SqlException("view {$view['name']} is circularly defined");
        }
        $savedCte = $this->cteScope;
        $this->viewStack[$key] = true;
        $this->cteScope = [];
        try {
            $res = $this->execSelect($view['select'], $eval->params());
            $rows = $res->materializeRows();
            $resultCols = $res->columns ?? [];
        } finally {
            $this->cteScope = $savedCte;
            unset($this->viewStack[$key]);
        }
        $alias = $ref->alias ?? $view['name'];
        $cols = $view['columns'] !== [] ? $view['columns'] : $resultCols;
        return [
            'kind' => 'derived',
            'alias' => $alias,
            'info' => $this->derivedTableInfo($alias, $cols),
            'tree' => null,
            'empty' => false,
            'func' => null,
            'args' => [],
            'rows' => $rows,
        ];
    }

    /**
     * Resolve a FROM reference that names a CTE in scope, materializing it on
     * first use and serving its rows like a derived table.
     *
     * @return array<string,mixed>
     */
    private function resolveCteSource(\YetiDevWorks\YetiSQL\Sql\Ast\TableRef $ref, Evaluator $eval): array
    {
        $key = \strtolower((string) $ref->name);
        if ($this->cteScope[$key]['rows'] === null) {
            $this->materializeCte($key, $eval);
        }
        $cte = $this->cteScope[$key];
        $alias = $ref->alias ?? (string) $ref->name;
        $cols = $cte['columns'] ?? $cte['outCols'] ?? [];
        return [
            'kind' => 'derived',
            'alias' => $alias,
            'info' => $this->derivedTableInfo($alias, $cols),
            'tree' => null,
            'empty' => false,
            'func' => null,
            'args' => [],
            'rows' => $cte['rows'],
        ];
    }

    /** Run a CTE body and cache its rows (recursive bodies via semi-naive fixpoint). */
    private function materializeCte(string $key, Evaluator $eval): void
    {
        $cte = $this->cteScope[$key];
        $select = $cte['select'];
        $params = $eval->params();

        $recursive = $cte['recursive']
            && $select->compound !== null
            && $this->selectReferencesCte($select->compound, $key);

        if (!$recursive) {
            // Guard against an undetected self-reference looping forever.
            $this->cteScope[$key]['rows'] = [];
            $res = $this->execSelect($select, $params);
            $this->cteScope[$key]['rows'] = $res->rows;
            $this->cteScope[$key]['outCols'] = $cte['columns'] ?? $res->columns ?? [];
            return;
        }

        // Recursive: the anchor is the body without its compound arm; the arm is
        // re-run against the previous iteration's new rows until none are added.
        $dedup = \strtoupper((string) $select->compoundOp) === 'UNION';
        $anchor = clone $select;
        $anchor->compoundOp = null;
        $anchor->compound = null;
        $anchor->orderBy = [];
        $anchor->limit = null;
        $anchor->offset = null;
        $anchor->with = [];
        $anchor->recursive = false;

        $anchorRes = $this->execSelect($anchor, $params);
        $outCols = $cte['columns'] ?? $anchorRes->columns ?? [];

        $result = [];
        $seen = [];
        $add = static function (array $rows) use (&$result, &$seen, $dedup): array {
            $fresh = [];
            foreach ($rows as $row) {
                if ($dedup) {
                    $k = \serialize($row);
                    if (isset($seen[$k])) {
                        continue;
                    }
                    $seen[$k] = true;
                }
                $result[] = $row;
                $fresh[] = $row;
            }
            return $fresh;
        };

        $working = $add($anchorRes->rows);
        $arm = $select->compound;
        $guard = 0;
        while ($working !== []) {
            if (++$guard > 1_000_000) {
                throw new SqlException('recursive CTE did not terminate');
            }
            $this->cteScope[$key]['rows'] = $working;
            $this->cteScope[$key]['outCols'] = $outCols;
            $res = $this->execSelect($arm, $params);
            $working = $add($res->rows);
        }
        $this->cteScope[$key]['rows'] = $result;
        $this->cteScope[$key]['outCols'] = $outCols;
    }

    /** Whether a select's FROM or a joined table names the given CTE (recursion test). */
    private function selectReferencesCte(SelectStatement $select, string $key): bool
    {
        if ($select->from !== null && $select->from->name !== null && \strtolower($select->from->name) === $key) {
            return true;
        }
        foreach ($select->joins as $join) {
            if ($join->table->name !== null && \strtolower($join->table->name) === $key) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $columns */
    private function derivedTableInfo(string $alias, array $columns): TableInfo
    {
        $cols = [];
        foreach ($columns as $name) {
            $cols[] = new \YetiDevWorks\YetiSQL\Engine\ColumnInfo($name, null, \YetiDevWorks\YetiSQL\Types\Affinity::BLOB);
        }
        return new TableInfo($alias, 0, $cols);
    }

    /**
     * @param list<array{name:string,expr:?Expr,star:bool,tableStar:?string}> $outputCols
     * @return list<null|int|float|string|Blob>
     */
    private function projectRow(array $outputCols, RowEnv $env, Evaluator $eval): array
    {
        $proj = $eval->compiledProject;
        $out = [];
        if ($proj !== null) {
            foreach ($outputCols as $i => $c) {
                $cl = $proj[$i] ?? null;
                $out[] = $cl !== null ? $cl($env, $eval) : $eval->evaluate($c['expr'], $env);
            }
            return $out;
        }
        foreach ($outputCols as $c) {
            $out[] = $eval->evaluate($c['expr'], $env);
        }
        return $out;
    }

    /**
     * Resolve the SELECT list into concrete output columns, expanding * and
     * table.* against the FROM/JOIN tables.
     *
     * @return list<array{name:string,expr:?Expr,star:bool,tableStar:?string}>
     */
    private function resolveOutputColumns(SelectStatement $select): array
    {
        $tables = $this->fromTables($select);

        $out = [];
        foreach ($select->columns as $rc) {
            if ($rc->star) {
                foreach ($tables as [$alias, $info]) {
                    foreach ($info->columns as $col) {
                        $out[] = ['name' => $col->name, 'expr' => Expr::col($alias, $col->name), 'star' => false, 'tableStar' => null];
                    }
                }
                continue;
            }
            if ($rc->tableStar !== null) {
                $info = $this->lookupTableByAlias($tables, $rc->tableStar);
                foreach ($info->columns as $col) {
                    $out[] = ['name' => $col->name, 'expr' => Expr::col($rc->tableStar, $col->name), 'star' => false, 'tableStar' => null];
                }
                continue;
            }
            $out[] = [
                'name' => $rc->alias ?? $this->exprLabel($rc->expr),
                'expr' => $rc->expr,
                'star' => false,
                'tableStar' => null,
            ];
        }
        return $out;
    }

    /** @return list<array{0:string,1:TableInfo}> */
    private function fromTables(SelectStatement $select): array
    {
        $tables = [];
        if ($select->from !== null) {
            $t = $this->fromTableEntry($select->from);
            if ($t !== null) {
                $tables[] = $t;
            }
        }
        foreach ($select->joins as $join) {
            $t = $this->fromTableEntry($join->table);
            if ($t !== null) {
                $tables[] = $t;
            }
        }
        return $tables;
    }

    /** @return array{0:string,1:TableInfo}|null */
    private function fromTableEntry(\YetiDevWorks\YetiSQL\Sql\Ast\TableRef $ref): ?array
    {
        if ($ref->func !== null) {
            $info = $this->tvfTableInfo($ref->func, $ref->alias ?? $ref->func);
            return [$ref->alias ?? $ref->func, $info];
        }
        if ($ref->subquery !== null) {
            // Derived table: its columns are the subquery's output names, so a
            // `*` over it (or over a CTE built from it) expands correctly.
            $alias = $ref->alias ?? '';
            return [$alias, $this->derivedTableInfo($alias, $this->selectOutputNames($ref->subquery))];
        }
        if ($ref->name !== null && isset($this->cteScope[\strtolower($ref->name)])) {
            $alias = $ref->alias ?? $ref->name;
            $cte = $this->cteScope[\strtolower($ref->name)];
            $cols = $cte['columns'] ?? $cte['outCols'] ?? $this->selectOutputNames($cte['select']);
            return [$alias, $this->derivedTableInfo($alias, $cols)];
        }
        if ($ref->name !== null) {
            $view = $this->db->schema()->getView($ref->name);
            if ($view !== null) {
                $alias = $ref->alias ?? $ref->name;
                $cols = $view['columns'] !== [] ? $view['columns'] : $this->selectOutputNames($view['select']);
                return [$alias, $this->derivedTableInfo($alias, $cols)];
            }
        }
        if ($ref->name !== null) {
            $info = $this->db->schema()->getTable($ref->name);
            if ($info !== null) {
                return [$ref->alias ?? $info->name, $info];
            }
        }
        return null;
    }

    /**
     * The output column names of a select, resolved without executing it (used
     * for `*` expansion over derived tables and CTEs).
     *
     * @return list<string>
     */
    private function selectOutputNames(SelectStatement $select): array
    {
        return \array_map(
            static fn (array $c): string => $c['name'],
            $this->resolveOutputColumns($select),
        );
    }

    /** @param list<array{0:string,1:TableInfo}> $tables */
    private function lookupTableByAlias(array $tables, string $alias): TableInfo
    {
        foreach ($tables as [$a, $info]) {
            if (\strcasecmp($a, $alias) === 0 || \strcasecmp($info->name, $alias) === 0) {
                return $info;
            }
        }
        throw new SqlException("no such table: $alias");
    }

    /** @var array<int,string> fallback display labels for expression kinds */
    private const KIND_LABELS = [
        Expr::CAST => 'cast', Expr::CASE_ => 'case', Expr::IN => 'in',
        Expr::BETWEEN => 'between', Expr::LIKE => 'like', Expr::ISNULL => 'isnull',
        Expr::COLLATE => 'collate', Expr::SUBQUERY => 'subquery', Expr::EXISTS => 'exists',
        Expr::UNARY => 'unary',
    ];

    /**
     * Reproduce SQLite's PRAGMA table_info `dflt_value`: the verbatim DEFAULT
     * source text from the CREATE TABLE statement (e.g. "''", "5",
     * "CURRENT_TIMESTAMP"), with the outer parentheses of a parenthesised
     * expression default stripped exactly as SQLite stores it ("(1+2)" -> "1+2").
     * Falls back to NULL when the column has no default.
     */
    private function defaultValueText(ColumnInfo $col): ?string
    {
        if ($col->defaultSql !== null) {
            return self::stripOuterParens($col->defaultSql);
        }
        // No captured source text (e.g. an internally-synthesised default): fall
        // back to a literal rendering of the resolved expression.
        return $col->default !== null ? $this->exprLabel($col->default) : null;
    }

    /**
     * If $text is fully wrapped in one balanced pair of parentheses, return its
     * contents; otherwise return it unchanged. "(1+2)" -> "1+2", but "(a)+(b)"
     * and "(a)" embedded in more text are left alone.
     */
    private static function stripOuterParens(string $text): string
    {
        $t = \trim($text);
        if ($t === '' || $t[0] !== '(' || $t[-1] !== ')') {
            return $text;
        }
        $depth = 0;
        $len = \strlen($t);
        foreach (\str_split($t) as $i => $ch) {
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                // Outer pair closes before the end -> not a single wrapping pair.
                if ($depth === 0 && $i !== $len - 1) {
                    return $text;
                }
            }
        }
        return \trim(\substr($t, 1, -1));
    }

    private function exprLabel(?Expr $e): string
    {
        if ($e === null) {
            return '?';
        }
        return match ($e->kind) {
            Expr::COL => (string) $e->name,
            Expr::LIT => $e->value === null ? 'NULL' : (string) Value::toText($e->value),
            Expr::FUNC => (string) $e->name . '(' . ($e->star ? '*' : \implode(', ', \array_map(fn ($a) => $this->exprLabel($a), $e->args))) . ')',
            default => $e->op ?? self::KIND_LABELS[$e->kind] ?? 'expr',
        };
    }

    // --- aggregation -----------------------------------------------------

    /** @param array<int,Expr> $nodes accumulator-by-id, set on the evaluator */
    // === window functions =================================================

    private function selectHasWindow(SelectStatement $select): bool
    {
        foreach ($select->columns as $rc) {
            if ($rc->expr instanceof Expr && $this->exprHasWindow($rc->expr)) {
                return true;
            }
        }
        foreach ($select->orderBy as $ot) {
            if ($this->exprHasWindow($ot->expr)) {
                return true;
            }
        }
        return false;
    }

    private function exprHasWindow(Expr $e): bool
    {
        if ($e->kind === Expr::FUNC && $e->window !== null) {
            return true;
        }
        foreach ([$e->left, $e->right, $e->operand, $e->subject, $e->elseExpr, $e->low, $e->high, $e->escape] as $c) {
            if ($c instanceof Expr && $this->exprHasWindow($c)) {
                return true;
            }
        }
        foreach ([...$e->args, ...$e->list] as $c) {
            if ($this->exprHasWindow($c)) {
                return true;
            }
        }
        foreach ($e->whens as [$w, $t]) {
            if ($this->exprHasWindow($w) || $this->exprHasWindow($t)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,Expr> $nodes */
    private function collectWindowFuncs(Expr $e, array &$nodes): void
    {
        if ($e->kind === Expr::FUNC && $e->window !== null) {
            $nodes[\spl_object_id($e)] = $e;
            return;
        }
        foreach ([$e->left, $e->right, $e->operand, $e->subject, $e->elseExpr, $e->low, $e->high, $e->escape] as $c) {
            if ($c instanceof Expr) {
                $this->collectWindowFuncs($c, $nodes);
            }
        }
        foreach ([...$e->args, ...$e->list] as $c) {
            $this->collectWindowFuncs($c, $nodes);
        }
        foreach ($e->whens as [$w, $t]) {
            $this->collectWindowFuncs($w, $nodes);
            $this->collectWindowFuncs($t, $nodes);
        }
    }

    /**
     * Materialize the filtered rows, compute each window function over its
     * partitions, then project (window calls resolve to precomputed values).
     *
     * @param list<array{name:string,expr:?Expr,star:bool,tableStar:?string}> $outputCols
     * @param list<array{kind:string,index:?int,expr:Expr,desc:bool}> $orderPlan
     * @return array{0:list<list<null|int|float|string|Blob>>,1:list<list<null|int|float|string|Blob>>}
     */
    private function windowProject(SelectStatement $select, array $outputCols, Evaluator $eval, array $orderPlan): array
    {
        $this->exposeSelectAliases($select, $eval);

        $envs = [];
        foreach ($this->filteredJoined($select, $eval) as $env) {
            $envs[] = $env;
        }

        $nodes = [];
        foreach ($outputCols as $c) {
            if ($c['expr'] instanceof Expr) {
                $this->collectWindowFuncs($c['expr'], $nodes);
            }
        }
        foreach ($select->orderBy as $ot) {
            $this->collectWindowFuncs($ot->expr, $nodes);
        }

        $eval->windowValues = [];
        foreach ($nodes as $id => $node) {
            $eval->windowValues[$id] = $this->computeWindow($node, $envs, $eval);
        }

        // Force the tree-walker for projection so window FUNC nodes resolve.
        $savedProject = $eval->compiledProject;
        $eval->compiledProject = null;
        $rows = [];
        $keys = [];
        foreach ($envs as $i => $env) {
            $eval->windowRow = $i;
            $row = $this->projectRow($outputCols, $env, $eval);
            $rows[] = $row;
            if ($orderPlan !== []) {
                $keys[] = $this->computeOrderKeys($orderPlan, $env, $eval, $row);
            }
        }
        $eval->compiledProject = $savedProject;

        return [$rows, $keys];
    }

    /**
     * Compute a single window function's value for every source row.
     *
     * @param list<RowEnv> $envs
     * @return list<null|int|float|string|Blob>
     */
    private function computeWindow(Expr $node, array $envs, Evaluator $eval): array
    {
        $n = \count($envs);
        $result = \array_fill(0, $n, null);
        if ($n === 0) {
            return $result;
        }
        $spec = $node->window;
        $name = \strtolower((string) $node->name);

        // Partition the row indices.
        $partitions = [];
        foreach ($envs as $i => $env) {
            $pk = '';
            foreach ($spec->partitionBy as $pe) {
                $pk .= $this->valueKey($eval->evaluate($pe, $env)) . "\x1e";
            }
            $partitions[$pk][] = $i;
        }

        foreach ($partitions as $indices) {
            $orderKeys = [];
            if ($spec->orderBy !== []) {
                foreach ($indices as $i) {
                    $k = [];
                    foreach ($spec->orderBy as $ot) {
                        $k[] = $eval->evaluate($ot->expr, $envs[$i]);
                    }
                    $orderKeys[$i] = $k;
                }
                // PHP 8's usort is stable, so peers keep source order.
                \usort($indices, function (int $a, int $b) use ($orderKeys, $spec): int {
                    foreach ($spec->orderBy as $j => $ot) {
                        $cmp = Value::compare($orderKeys[$a][$j], $orderKeys[$b][$j], $ot->collation ?? 'BINARY');
                        if ($cmp !== 0) {
                            return $ot->desc ? -$cmp : $cmp;
                        }
                    }
                    return 0;
                });
            }
            $this->computeWindowPartition($name, $node, $spec, $indices, $orderKeys, $envs, $eval, $result);
        }
        return $result;
    }

    /**
     * @param list<int> $indices ordered source-row indices in one partition
     * @param array<int,list<null|int|float|string|Blob>> $orderKeys
     * @param list<RowEnv> $envs
     * @param list<null|int|float|string|Blob> $result written in place
     */
    private function computeWindowPartition(string $name, Expr $node, $spec, array $indices, array $orderKeys, array $envs, Evaluator $eval, array &$result): void
    {
        $m = \count($indices);

        if (\in_array($name, ['row_number', 'rank', 'dense_rank', 'ntile', 'percent_rank', 'cume_dist'], true)) {
            $rank = 0;
            $dense = 0;
            for ($p = 0; $p < $m; $p++) {
                $i = $indices[$p];
                $peer = $p > 0 && $this->windowPeers($orderKeys[$indices[$p - 1]] ?? [], $orderKeys[$i] ?? [], $spec);
                if (!$peer) {
                    $rank = $p + 1;
                    $dense++;
                }
                $result[$i] = match ($name) {
                    'row_number' => $p + 1,
                    'rank' => $rank,
                    'dense_rank' => $dense,
                    'percent_rank' => $m > 1 ? (float) ($rank - 1) / ($m - 1) : 0.0,
                    'cume_dist' => $this->windowCumeDist($p, $m, $indices, $orderKeys, $spec),
                    'ntile' => $this->windowNtile($p, $m, $node, $envs[$i], $eval),
                    default => null,
                };
            }
            return;
        }

        if ($name === 'lag' || $name === 'lead') {
            for ($p = 0; $p < $m; $p++) {
                $i = $indices[$p];
                $off = isset($node->args[1]) ? (int) Value::toNumber($eval->evaluate($node->args[1], $envs[$i])) : 1;
                $target = $name === 'lag' ? $p - $off : $p + $off;
                if ($target >= 0 && $target < $m) {
                    $result[$i] = $eval->evaluate($node->args[0], $envs[$indices[$target]]);
                } else {
                    $result[$i] = isset($node->args[2]) ? $eval->evaluate($node->args[2], $envs[$i]) : null;
                }
            }
            return;
        }

        // Frame-based: first_value / last_value / nth_value and aggregates.
        for ($p = 0; $p < $m; $p++) {
            $i = $indices[$p];
            [$start, $end] = $this->windowFrame($p, $m, $indices, $orderKeys, $spec, $envs, $eval);
            if ($start > $end) {
                $result[$i] = null;
                continue;
            }
            if ($name === 'first_value') {
                $result[$i] = $eval->evaluate($node->args[0], $envs[$indices[$start]]);
            } elseif ($name === 'last_value') {
                $result[$i] = $eval->evaluate($node->args[0], $envs[$indices[$end]]);
            } elseif ($name === 'nth_value') {
                $nth = (int) Value::toNumber($eval->evaluate($node->args[1], $envs[$i]));
                $idx = $start + $nth - 1;
                $result[$i] = ($nth >= 1 && $idx <= $end) ? $eval->evaluate($node->args[0], $envs[$indices[$idx]]) : null;
            } else {
                $acc = Aggregates::newAccumulator($name);
                for ($q = $start; $q <= $end; $q++) {
                    $args = [];
                    foreach ($node->args as $arg) {
                        $args[] = $eval->evaluate($arg, $envs[$indices[$q]]);
                    }
                    Aggregates::step($acc, $args, $node->star, $node->distinct);
                }
                $result[$i] = Aggregates::finalize($acc);
            }
        }
    }

    /** Whether two window ORDER BY keys are peers (equal under each term's collation). */
    private function windowPeers(array $a, array $b, $spec): bool
    {
        foreach ($spec->orderBy as $j => $ot) {
            if (Value::compare($a[$j] ?? null, $b[$j] ?? null, $ot->collation ?? 'BINARY') !== 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Frame [start,end] as inclusive positions in the ordered partition. Honors
     * the default frame (RANGE UNBOUNDED PRECEDING..CURRENT ROW with ORDER BY,
     * else whole partition) and explicit ROWS frames; RANGE/GROUPS bounds fall
     * back to peer-extended row positions.
     *
     * @param list<int> $indices
     * @param array<int,list<null|int|float|string|Blob>> $orderKeys
     * @param list<RowEnv> $envs
     * @return array{0:int,1:int}
     */
    private function windowFrame(int $p, int $m, array $indices, array $orderKeys, $spec, array $envs, Evaluator $eval): array
    {
        $frame = $spec->frame;
        if ($frame === null) {
            if ($spec->orderBy === []) {
                return [0, $m - 1];
            }
            // Default RANGE: from partition start through the current row's peers.
            $end = $p;
            while ($end + 1 < $m && $this->windowPeers($orderKeys[$indices[$p]], $orderKeys[$indices[$end + 1]], $spec)) {
                $end++;
            }
            return [0, $end];
        }

        $rows = $frame['units'] === 'rows';
        $start = $this->windowBound($frame['startKind'], $frame['startVal'], $p, $m, $indices, $orderKeys, $spec, $envs, $eval, true, $rows);
        $end = $this->windowBound($frame['endKind'], $frame['endVal'], $p, $m, $indices, $orderKeys, $spec, $envs, $eval, false, $rows);
        return [\max(0, $start), \min($m - 1, $end)];
    }

    /**
     * @param list<int> $indices
     * @param array<int,list<null|int|float|string|Blob>> $orderKeys
     * @param list<RowEnv> $envs
     */
    private function windowBound(string $kind, ?Expr $val, int $p, int $m, array $indices, array $orderKeys, $spec, array $envs, Evaluator $eval, bool $isStart, bool $rows): int
    {
        switch ($kind) {
            case 'unboundedPreceding':
                return 0;
            case 'unboundedFollowing':
                return $m - 1;
            case 'preceding':
                $n = (int) Value::toNumber($eval->evaluate($val, $envs[$indices[$p]]));
                return $p - $n;
            case 'following':
                $n = (int) Value::toNumber($eval->evaluate($val, $envs[$indices[$p]]));
                return $p + $n;
            case 'currentRow':
            default:
                if ($rows || $spec->orderBy === []) {
                    return $p;
                }
                // RANGE CURRENT ROW: extend to the peer-group boundary.
                if ($isStart) {
                    $s = $p;
                    while ($s > 0 && $this->windowPeers($orderKeys[$indices[$p]], $orderKeys[$indices[$s - 1]], $spec)) {
                        $s--;
                    }
                    return $s;
                }
                $e = $p;
                while ($e + 1 < $m && $this->windowPeers($orderKeys[$indices[$p]], $orderKeys[$indices[$e + 1]], $spec)) {
                    $e++;
                }
                return $e;
        }
    }

    private function windowNtile(int $p, int $m, Expr $node, RowEnv $env, Evaluator $eval): int
    {
        $buckets = isset($node->args[0]) ? (int) Value::toNumber($eval->evaluate($node->args[0], $env)) : 1;
        if ($buckets < 1) {
            $buckets = 1;
        }
        $base = \intdiv($m, $buckets);
        $rem = $m % $buckets;
        // The first $rem buckets hold $base+1 rows; the rest hold $base.
        $big = $rem * ($base + 1);
        return $p < $big ? \intdiv($p, $base + 1) + 1 : $rem + \intdiv($p - $big, \max(1, $base)) + 1;
    }

    /**
     * @param list<int> $indices
     * @param array<int,list<null|int|float|string|Blob>> $orderKeys
     */
    private function windowCumeDist(int $p, int $m, array $indices, array $orderKeys, $spec): float
    {
        // Fraction of rows whose order key is <= the current row's (peers count).
        $end = $p;
        while ($end + 1 < $m && $this->windowPeers($orderKeys[$indices[$p]], $orderKeys[$indices[$end + 1]], $spec)) {
            $end++;
        }
        return ($end + 1) / $m;
    }

    private function collectAggregates(Expr $e, array &$nodes): void
    {
        if ($e->kind === Expr::FUNC && $e->window !== null) {
            return; // window functions are computed by the window stage, not GROUP BY
        }
        if ($e->kind === Expr::FUNC && Aggregates::isAggregate((string) $e->name)) {
            $nodes[\spl_object_id($e)] = $e;
            return; // do not descend into aggregate args for nested aggregate detection
        }
        foreach ([$e->left, $e->right, $e->operand, $e->subject, $e->elseExpr, $e->low, $e->high, $e->escape] as $child) {
            if ($child instanceof Expr) {
                $this->collectAggregates($child, $nodes);
            }
        }
        foreach ($e->args as $a) {
            $this->collectAggregates($a, $nodes);
        }
        foreach ($e->list as $a) {
            $this->collectAggregates($a, $nodes);
        }
        foreach ($e->whens as [$w, $t]) {
            $this->collectAggregates($w, $nodes);
            $this->collectAggregates($t, $nodes);
        }
    }

    /**
     * Direct path for simple single-table grouped aggregates. It decodes only
     * the group/aggregate columns and avoids building a RowEnv per input row.
     *
     * @param list<array{name:string,expr:?Expr,star:bool,tableStar:?string}> $outputCols
     * @param array<int,Expr> $aggregateNodes
     * @param list<array{kind:string,index:?int,expr:Expr,desc:bool}> $orderPlan
     * @return array{0:list<list<null|int|float|string|Blob>>,1:list<list<null|int|float|string|Blob>>}|null
     */
    private function tryFastGroupedAggregate(SelectStatement $select, array $outputCols, array $aggregateNodes, array $orderPlan): ?array
    {
        if ($select->where !== null
            || $select->having !== null
            || $select->groupBy === []
            || \count($select->groupBy) !== 1
            || $select->distinct
            || $orderPlan !== []) {
            return null;
        }

        $single = $this->singleTableForCompile($select);
        if ($single === null) {
            return null;
        }
        [$info, $alias] = $single;

        $groupExpr = $select->groupBy[0];
        $groupPos = $this->simpleColumnPos($groupExpr, $info, $alias);
        if ($groupPos === null) {
            return null;
        }

        $outPlan = [];
        $aggSpecs = [];
        foreach ($outputCols as $c) {
            $expr = $c['expr'];
            if (!$expr instanceof Expr) {
                return null;
            }
            if ($expr->kind === Expr::COL) {
                $pos = $this->simpleColumnPos($expr, $info, $alias);
                if ($pos !== $groupPos) {
                    return null;
                }
                $outPlan[] = ['kind' => 'group'];
                continue;
            }
            if ($expr->kind !== Expr::FUNC || !Aggregates::isAggregate((string) $expr->name) || $expr->distinct) {
                return null;
            }

            $name = \strtolower((string) $expr->name);
            if (!\in_array($name, ['count', 'sum', 'total', 'avg', 'min', 'max'], true)) {
                return null;
            }
            if ($expr->star) {
                if ($name !== 'count') {
                    return null;
                }
                $argPos = null;
            } else {
                if (\count($expr->args) !== 1) {
                    return null;
                }
                $argPos = $this->simpleColumnPos($expr->args[0], $info, $alias);
                if ($argPos === null) {
                    return null;
                }
            }

            $id = \spl_object_id($expr);
            if (!isset($aggregateNodes[$id])) {
                return null;
            }
            $outPlan[] = ['kind' => 'agg', 'id' => $id];
            $aggSpecs[$id] ??= ['name' => $name, 'pos' => $argPos, 'star' => $expr->star];
        }

        if ($aggSpecs === []) {
            return null;
        }

        $decodePositions = [];
        if ($groupPos !== $info->rowidAlias) {
            $decodePositions[] = $groupPos;
        }
        foreach ($aggSpecs as $spec) {
            if ($spec['pos'] !== null && $spec['pos'] !== $info->rowidAlias && !\in_array($spec['pos'], $decodePositions, true)) {
                $decodePositions[] = $spec['pos'];
            }
        }
        \sort($decodePositions);

        $tree = new TableBTree($this->db->pager(), $info->rootPage);
        $cacheId = \spl_object_id($select);
        // PHP reuses spl_object_id() after an object is freed, so a different
        // statement can land on a prior one's id. Validate a structural signature
        // (group column + each output aggregate's name/arg) so a collision on a
        // different query misses the cache and recomputes instead of serving its
        // rows. (Param-independent: this fast path requires no WHERE/HAVING.)
        $sigParts = ['g' . $groupPos];
        foreach ($outPlan as $op) {
            if ($op['kind'] === 'group') {
                $sigParts[] = 'G';
                continue;
            }
            $spec = $aggSpecs[$op['id']];
            $sigParts[] = $spec['name'] . ':' . ($spec['star'] ? '*' : (string) $spec['pos']);
        }
        $sig = \implode(',', $sigParts);
        if (!$this->db->inTransaction()) {
            $cached = $this->groupedAggregateCache[$cacheId] ?? null;
            if ($cached !== null
                && $cached['sig'] === $sig
                && $cached['cookie'] === $this->db->pager()->schemaCookie()
                && $cached['changes'] === $this->db->totalChanges()
                && $cached['root'] === $info->rootPage) {
                return [$cached['rows'], []];
            }
        }

        $groups = [];
        $order = [];
        foreach ($tree->scan() as [$rowid, $payload]) {
            $decoded = RecordCodec::decodeColumns($payload, $decodePositions);
            if ($info->rowidAlias >= 0) {
                $decoded[$info->rowidAlias] = $rowid;
            }
            $groupValue = $decoded[$groupPos] ?? null;
            $key = $this->valueKey($groupValue);
            if (!isset($groups[$key])) {
                $groups[$key] = ['group' => $groupValue, 'aggs' => []];
                foreach ($aggSpecs as $id => $spec) {
                    $groups[$key]['aggs'][$id] = [
                        'name' => $spec['name'],
                        'count' => 0,
                        'sum' => 0,
                        'sawFloat' => false,
                        'any' => false,
                        'extreme' => null,
                        'haveExtreme' => false,
                    ];
                }
                $order[] = $key;
            }

            foreach ($aggSpecs as $id => $spec) {
                $agg = &$groups[$key]['aggs'][$id];
                $name = $spec['name'];
                if ($name === 'count' && $spec['star']) {
                    $agg['count']++;
                    unset($agg);
                    continue;
                }

                $value = $spec['pos'] !== null ? ($decoded[$spec['pos']] ?? null) : null;
                if ($name === 'count') {
                    if ($value !== null) {
                        $agg['count']++;
                    }
                    unset($agg);
                    continue;
                }
                if ($value === null) {
                    unset($agg);
                    continue;
                }
                if ($name === 'sum' || $name === 'total' || $name === 'avg') {
                    $num = \is_int($value) || \is_float($value) ? $value : Value::toNumber($value);
                    if (\is_float($num)) {
                        $agg['sawFloat'] = true;
                    }
                    $agg['sum'] += $num;
                    $agg['count']++;
                    $agg['any'] = true;
                    unset($agg);
                    continue;
                }
                if (!$agg['haveExtreme']) {
                    $agg['extreme'] = $value;
                    $agg['haveExtreme'] = true;
                } else {
                    $cmp = Value::compare($value, $agg['extreme']);
                    if (($name === 'max' && $cmp > 0) || ($name === 'min' && $cmp < 0)) {
                        $agg['extreme'] = $value;
                    }
                }
                unset($agg);
            }
        }

        $rows = [];
        foreach ($order as $key) {
            $group = $groups[$key];
            $row = [];
            foreach ($outPlan as $out) {
                if ($out['kind'] === 'group') {
                    $row[] = $group['group'];
                    continue;
                }
                $agg = $group['aggs'][$out['id']];
                $row[] = match ($agg['name']) {
                    'count' => $agg['count'],
                    'sum' => $agg['any'] ? ($agg['sawFloat'] ? (float) $agg['sum'] : $agg['sum']) : null,
                    'total' => (float) $agg['sum'],
                    'avg' => $agg['count'] > 0 ? (float) $agg['sum'] / $agg['count'] : null,
                    'min', 'max' => $agg['haveExtreme'] ? $agg['extreme'] : null,
                    default => null,
                };
            }
            $rows[] = $row;
        }

        if (!$this->db->inTransaction()) {
            if (\count($this->groupedAggregateCache) >= self::SELECT_META_MAX) {
                $this->groupedAggregateCache = [];
            }
            $this->groupedAggregateCache[$cacheId] = [
                'sig' => $sig,
                'cookie' => $this->db->pager()->schemaCookie(),
                'changes' => $this->db->totalChanges(),
                'root' => $info->rootPage,
                'rows' => $rows,
            ];
        }

        return [$rows, []];
    }

    private function simpleColumnPos(Expr $expr, TableInfo $info, string $alias): ?int
    {
        if ($expr->kind !== Expr::COL) {
            return null;
        }
        if ($expr->table !== null) {
            $table = \strtolower($expr->table);
            if ($table !== \strtolower($alias) && $table !== \strtolower($info->name)) {
                return null;
            }
        }
        return $info->columnPos((string) $expr->name);
    }

    /**
     * @param list<array{name:string,expr:?Expr,star:bool,tableStar:?string}> $outputCols
     * @param array<int,Expr> $aggregateNodes
     * @param list<RowEnv> $envs
     * @param list<array{kind:string,index:?int,expr:Expr,desc:bool}> $orderPlan
     * @return array{0:list<list<null|int|float|string|Blob>>,1:list<list<null|int|float|string|Blob>>}
     */
    /**
     * Streaming aggregation for the no-GROUP-BY case: one output row, computed
     * by feeding each source row into the accumulators as it is produced. No row
     * environments are retained, so a covered COUNT(*) costs only the scan.
     *
     * @param list<array{name:string,expr:?Expr,star:bool,tableStar:?string}> $outputCols
     * @param array<int,Expr> $aggregateNodes
     * @param list<array{kind:string,index:?int,expr:Expr,desc:bool}> $orderPlan
     * @return array{0:list<list<null|int|float|string|Blob>>,1:list<list<null|int|float|string|Blob>>}
     */
    private function aggregateUngrouped(SelectStatement $select, array $outputCols, array $aggregateNodes, Evaluator $eval, array $orderPlan): array
    {
        $accs = [];
        foreach ($aggregateNodes as $id => $node) {
            $accs[$id] = Aggregates::newAccumulator(\strtolower((string) $node->name));
        }

        $cWhere = $eval->compiledWhere;
        $cArgs = $eval->compiledAggArgs;
        $rep = null;
        foreach ($this->iterateJoined($select, $eval) as $env) {
            if ($select->where !== null && !$eval->whereCovered) {
                $v = $cWhere !== null ? $cWhere($env, $eval) : $eval->evaluate($select->where, $env);
                if ($v === null || !Value::isTrue($v)) {
                    continue;
                }
            }
            $rep ??= $env;
            foreach ($aggregateNodes as $id => $node) {
                $argCs = $cArgs[$id] ?? null;
                $args = [];
                if ($argCs !== null) {
                    foreach ($argCs as $ac) {
                        $args[] = $ac($env, $eval);
                    }
                } else {
                    foreach ($node->args as $arg) {
                        $args[] = $eval->evaluate($arg, $env);
                    }
                }
                Aggregates::step($accs[$id], $args, $node->star, $node->distinct);
            }
        }

        // Aliases become visible only after WHERE has been fully applied.
        foreach ($select->columns as $rc) {
            if ($rc->alias !== null && $rc->expr !== null) {
                $eval->aliasExprs[\strtolower($rc->alias)] = $rc->expr;
            }
        }

        $eval->aggregateValues = [];
        foreach ($accs as $id => $acc) {
            $eval->aggregateValues[$id] = Aggregates::finalize($acc);
        }

        $rep ??= new RowEnv();
        if ($select->having !== null) {
            $h = $eval->evaluate($select->having, $rep);
            if ($h === null || !Value::isTrue($h)) {
                return [[], []];
            }
        }
        $row = $this->projectRow($outputCols, $rep, $eval);
        $keys = $orderPlan !== [] ? [$this->computeOrderKeys($orderPlan, $rep, $eval, $row)] : [];
        return [[$row], $keys];
    }

    /**
     * Fast path for COUNT(*) where a single-table rowid/index access plan fully
     * covers the WHERE clause. This avoids building RowEnv objects and aggregate
     * accumulators for every matching row.
     *
     * @param list<array{name:string,expr:?Expr,star:bool,tableStar:?string}> $outputCols
     * @param array<int,Expr> $aggregateNodes
     * @return list<list<int>>|null
     */
    private function tryCoveredCount(SelectStatement $select, array $outputCols, array $aggregateNodes, Evaluator $eval): ?array
    {
        if ($select->having !== null || \count($outputCols) !== 1 || \count($aggregateNodes) !== 1) {
            return null;
        }

        $expr = $outputCols[0]['expr'];
        if ($expr === null || $expr->kind !== Expr::FUNC || \strtolower((string) $expr->name) !== 'count' || !$expr->star || $expr->distinct) {
            return null;
        }

        $node = \reset($aggregateNodes);
        if (!$node instanceof Expr || $node !== $expr) {
            return null;
        }

        $single = $this->singleTableForCompile($select);
        if ($single === null) {
            return null;
        }

        [$info, $alias] = $single;
        $tree = new TableBTree($this->db->pager(), $info->rootPage);
        if ($select->where === null) {
            $key = $this->coveredCountCacheKey($info->rootPage, 'table_all', []);
            $cached = $this->coveredCountCache[$key] ?? null;
            if ($cached !== null) {
                return [[$cached]];
            }
            $count = $tree->countRange();
            $this->rememberCoveredCount($key, $count);
            return [[$count]];
        }

        $plan = $this->bestPlan($select->where, $alias, $info, $eval);
        if ($plan === null || !($plan['coveredAll'] ?? false) || !\in_array($plan['kind'], ['index_eq', 'index_range', 'rowid_eq', 'rowid_range'], true)) {
            return null;
        }

        $count = 0;
        if (isset($plan['eqPrefix'])) {
            return $this->tryCoveredCompositeCount($plan);
        }
        if ($plan['kind'] === 'index_eq') {
            /** @var \YetiDevWorks\YetiSQL\Engine\IndexInfo $index */
            $index = $plan['index'];
            $coll = $index->collations[0] ?? 'BINARY';
            // Correlated single-value probe: once the same subquery has been run
            // enough times to amortize it, materialize the inner table grouped by
            // the indexed column into a persistent hash map and serve O(1)
            // lookups, instead of re-descending the index b-tree per outer row.
            if ($eval->outerEnv !== null && \count($plan['values']) === 1) {
                $mapped = $this->tryCorrelatedCountMap($select, $info, $index->leadingColumn(), $plan['values'][0], $index);
                if ($mapped !== null) {
                    return [[$mapped]];
                }
            }
            $idx = new \YetiDevWorks\YetiSQL\Engine\IndexBTree($this->db->pager(), $index->rootPage, $index->collations);
            $probes = $this->uniqueValues($plan['values'], $coll);
            $key = $this->coveredCountCacheKey($index->rootPage, 'index_eq', \array_map([$this, 'valueKey'], $probes));
            $cached = $this->coveredCountCache[$key] ?? null;
            if ($cached !== null) {
                return [[$cached]];
            }
            foreach ($probes as $probe) {
                $count += $idx->countLeadingRange($probe, true, $probe, true, $coll);
            }
            $this->rememberCoveredCount($key, $count);
            return [[$count]];
        }
        if ($plan['kind'] === 'index_range') {
            /** @var \YetiDevWorks\YetiSQL\Engine\IndexInfo $index */
            $index = $plan['index'];
            $idx = new \YetiDevWorks\YetiSQL\Engine\IndexBTree($this->db->pager(), $index->rootPage, $index->collations);
            $coll = $index->collations[0] ?? 'BINARY';
            $key = $this->coveredCountCacheKey($index->rootPage, 'index_range', [
                $this->valueKey($plan['low']),
                $plan['lowInc'] ? '1' : '0',
                $this->valueKey($plan['high']),
                $plan['highInc'] ? '1' : '0',
            ]);
            $cached = $this->coveredCountCache[$key] ?? null;
            if ($cached !== null) {
                return [[$cached]];
            }
            $count = $idx->countLeadingRange($plan['low'], $plan['lowInc'], $plan['high'], $plan['highInc'], $coll);
            $this->rememberCoveredCount($key, $count);
            return [[$count]];
        }

        if ($plan['kind'] === 'rowid_eq') {
            $key = $this->coveredCountCacheKey($info->rootPage, 'rowid_eq', \array_map(static fn ($v): string => (string) (int) Value::toNumber($v), $plan['values']));
            $cached = $this->coveredCountCache[$key] ?? null;
            if ($cached !== null) {
                return [[$cached]];
            }
            $seen = [];
            foreach ($plan['values'] as $v) {
                $rid = (int) Value::toNumber($v);
                if (isset($seen[$rid])) {
                    continue;
                }
                $seen[$rid] = true;
                if ($tree->countRange($rid, true, $rid, true) !== 0) {
                    $count++;
                }
            }
            $this->rememberCoveredCount($key, $count);
            return [[$count]];
        }

        $low = $plan['low'] !== null ? (int) Value::toNumber($plan['low']) : null;
        $high = $plan['high'] !== null ? (int) Value::toNumber($plan['high']) : null;
        $lowInc = $plan['lowInc'];
        $highInc = $plan['highInc'];
        $key = $this->coveredCountCacheKey($info->rootPage, 'rowid_range', [
            $low !== null ? (string) $low : 'N',
            $lowInc ? '1' : '0',
            $high !== null ? (string) $high : 'N',
            $highInc ? '1' : '0',
        ]);
        $cached = $this->coveredCountCache[$key] ?? null;
        if ($cached !== null) {
            return [[$cached]];
        }
        $count = $tree->countRange($low, $lowInc, $high, $highInc);
        $this->rememberCoveredCount($key, $count);
        return [[$count]];
    }

    /**
     * Fast path for COUNT(*) over a single inner equi-join:
     *
     *   SELECT COUNT(*) FROM outer o JOIN inner i ON i.k = <outer expr>
     *   WHERE <outer-only predicate>
     *
     * The generic join path materializes every matching inner row and builds a
     * joined RowEnv just to increment COUNT(*). Here we scan only the qualifying
     * outer rows, then use the inner rowid/index subtree counts to add the number
     * of matching inner rows without fetching their payloads.
     *
     * @param list<array{name:string,expr:?Expr,star:bool,tableStar:?string}> $outputCols
     * @param array<int,Expr> $aggregateNodes
     * @return list<list<int>>|null
     */
    private function tryJoinCoveredCount(SelectStatement $select, array $outputCols, array $aggregateNodes, Evaluator $eval): ?array
    {
        if ($select->having !== null
            || $select->distinct
            || $select->from === null
            || \count($select->joins) !== 1
            || \count($outputCols) !== 1
            || \count($aggregateNodes) !== 1) {
            return null;
        }

        $expr = $outputCols[0]['expr'];
        if ($expr === null || $expr->kind !== Expr::FUNC || \strtolower((string) $expr->name) !== 'count' || !$expr->star || $expr->distinct) {
            return null;
        }
        $node = \reset($aggregateNodes);
        if (!$node instanceof Expr || $node !== $expr) {
            return null;
        }

        $join = $select->joins[0];
        if ($join->type !== JoinClause::INNER
            || $join->natural
            || $join->using !== []
            || $join->on === null
            || \count($this->splitAnd($join->on)) !== 1
            || $select->from->subquery !== null
            || $select->from->func !== null
            || $join->table->subquery !== null
            || $join->table->func !== null
            || $select->from->name === null
            || $join->table->name === null) {
            return null;
        }

        $outerInfo = $this->db->schema()->getTable($select->from->name);
        $innerInfo = $this->db->schema()->getTable($join->table->name);
        if ($outerInfo === null || $innerInfo === null) {
            return null;
        }
        $outerAlias = $select->from->effectiveName();
        $innerAlias = $join->table->effectiveName();
        if ($select->where !== null && $this->exprReferencesTable($select->where, $innerAlias, $innerInfo)) {
            return null;
        }

        $cacheKey = $this->coveredCountCacheKey($outerInfo->rootPage, 'join_count', [
            (string) $innerInfo->rootPage,
            $this->selectSignature($select),
        ]);
        $cached = $this->coveredCountCache[$cacheKey] ?? null;
        if ($cached !== null) {
            return [[$cached]];
        }

        $innerIndexes = $this->db->schema()->resolvedIndexes($innerInfo->name);
        $access = $this->joinAccessKey($join, $innerAlias, $innerInfo, $innerIndexes);
        if ($access === null) {
            return null;
        }

        $innerPos = $access['pos'];
        $innerIndex = $innerPos === $innerInfo->rowidAlias ? null : $this->indexLeading($innerIndexes, $innerPos);
        if ($innerPos !== $innerInfo->rowidAlias && $innerIndex === null) {
            return null;
        }

        $outerTree = new TableBTree($this->db->pager(), $outerInfo->rootPage);
        $innerTree = new TableBTree($this->db->pager(), $innerInfo->rootPage);
        $innerIdx = $innerIndex !== null
            ? new \YetiDevWorks\YetiSQL\Engine\IndexBTree($this->db->pager(), $innerIndex->rootPage, $innerIndex->collations)
            : null;
        $innerColl = $innerIndex?->collations[0] ?? 'BINARY';
        // Use the inner index count-map only when it's already cached (free), or
        // lazily build it once the outer side has driven enough probes to
        // amortize the full inner-index walk. With few outer rows over a large
        // inner table (e.g. 100 outer × 20k inner), per-outer countLeadingRange
        // seeks are far cheaper than walking the whole index to build the map.
        $mapEligible = $innerIndex !== null && \strcasecmp($innerColl, 'BINARY') === 0;
        $innerCountMap = $mapEligible ? $this->cachedIndexLeadingCountMap($innerIndex) : null;
        $innerRows = $innerTree->countRange();
        // Small inner: build the shared count-map eagerly (cheap, cached, and
        // reused by correlated subqueries / re-runs). Large inner: leave it null
        // and seek per outer row, building only if enough rows probe (below).
        if ($mapEligible && $innerCountMap === null && $innerRows <= self::COUNT_MAP_EAGER_MAX) {
            $innerCountMap = $this->indexLeadingCountMap($innerIndex);
        }
        $seeks = 0;
        $mapThreshold = \max(16, \intdiv($innerRows, 8));

        $plan = $select->where !== null ? $this->bestPlan($select->where, $outerAlias, $outerInfo, $eval) : null;
        $whereCovered = $plan !== null && ($plan['coveredAll'] ?? false);
        $cWhere = $eval->compiledWhere;
        $count = 0;
        $countMatches = function (mixed $key) use ($innerInfo, $innerPos, $innerTree, $innerIdx, &$innerCountMap, $innerColl, $innerIndex, $mapEligible, $mapThreshold, &$seeks): int {
            $key = $this->affine($innerInfo, $innerPos, $key);
            if ($key === null) {
                return 0;
            }
            if ($innerPos === $innerInfo->rowidAlias) {
                if (!\is_int($key) && !(\is_float($key) && (float) (int) $key === $key)) {
                    return 0;
                }
                return $innerTree->countRange((int) $key, true, (int) $key, true);
            }
            if ($innerCountMap !== null) {
                return $innerCountMap[$this->comparisonKey($key)] ?? 0;
            }
            // Past the threshold, materialize the map once and switch to it;
            // below it, a per-outer index seek is cheaper than the full walk.
            if ($mapEligible && ++$seeks > $mapThreshold) {
                $innerCountMap = $this->indexLeadingCountMap($innerIndex);
                return $innerCountMap[$this->comparisonKey($key)] ?? 0;
            }
            return $innerIdx->countLeadingRange($key, true, $key, true, $innerColl);
        };

        $outerKeyPos = $this->colPositionOf($access['keyExpr'], $outerAlias, $outerInfo);
        if (($select->where === null || $whereCovered)
            && $outerKeyPos === $outerInfo->rowidAlias
            && ($plan === null || \in_array($plan['kind'], ['rowid_eq', 'rowid_range'], true))) {
            foreach ($this->coveredOuterRowids($outerTree, $plan) as $rowid) {
                $count += $countMatches($rowid);
            }
            $this->rememberCoveredCount($cacheKey, $count);
            return [[$count]];
        }

        foreach ($this->scanByPlan($outerTree, $plan) as [$rowid, $payload, $covered]) {
            $env = null;
            if ($select->where !== null && !$whereCovered) {
                $payload ??= $outerTree->get($rowid);
                if ($payload === null) {
                    continue;
                }
                $env = new RowEnv();
                $env->addLazyFrame($outerAlias, $outerInfo, $payload, $rowid);
                $v = $cWhere !== null ? $cWhere($env, $eval) : $eval->evaluate($select->where, $env);
                if ($v === null || !Value::isTrue($v)) {
                    continue;
                }
            }

            $key = $this->fastJoinOuterKey($access['keyExpr'], $outerAlias, $outerInfo, $outerTree, $rowid, $payload, $covered, $env, $eval);
            $count += $countMatches($key);
        }

        $this->rememberCoveredCount($cacheKey, $count);
        return [[$count]];
    }

    /** @param array<string,mixed>|null $plan */
    private function coveredOuterRowids(TableBTree $tree, ?array $plan): \Generator
    {
        if ($plan === null) {
            yield from $tree->scanRowids();
            return;
        }
        if ($plan['kind'] === 'rowid_eq') {
            $seen = [];
            foreach ($plan['values'] as $v) {
                $rid = (int) Value::toNumber($v);
                if (isset($seen[$rid])) {
                    continue;
                }
                $seen[$rid] = true;
                if ($tree->countRange($rid, true, $rid, true) !== 0) {
                    yield $rid;
                }
            }
            return;
        }

        $low = $plan['low'] !== null ? (int) Value::toNumber($plan['low']) : null;
        $high = $plan['high'] !== null ? (int) Value::toNumber($plan['high']) : null;
        foreach ($tree->scanRowids($low) as $rid) {
            if ($low !== null && !$plan['lowInc'] && $rid <= $low) {
                continue;
            }
            if ($high !== null && ($rid > $high || (!$plan['highInc'] && $rid === $high))) {
                break;
            }
            yield $rid;
        }
    }

    /**
     * @param array<int,null|int|float|string|Blob> $covered
     */
    private function fastJoinOuterKey(Expr $expr, string $outerAlias, TableInfo $outerInfo, TableBTree $outerTree, int $rowid, ?string $payload, array $covered, ?RowEnv $env, Evaluator $eval): null|int|float|string|Blob
    {
        $pos = $this->colPositionOf($expr, $outerAlias, $outerInfo);
        if ($pos !== null) {
            if ($pos === $outerInfo->rowidAlias) {
                return $rowid;
            }
            if (\array_key_exists($pos, $covered)) {
                return $covered[$pos];
            }
            $payload ??= $outerTree->get($rowid);
            return $payload !== null ? RecordCodec::decodeColumn($payload, $pos) : null;
        }
        $env ??= new RowEnv();
        if ($env->frames === []) {
            $payload ??= $outerTree->get($rowid);
            if ($payload === null) {
                return null;
            }
            $env->addLazyFrame($outerAlias, $outerInfo, $payload, $rowid);
        }
        return $eval->evaluate($expr, $env);
    }

    /** @param array<string,mixed> $plan */
    private function tryCoveredCompositeCount(array $plan): array
    {
        /** @var \YetiDevWorks\YetiSQL\Engine\IndexInfo $index */
        $index = $plan['index'];
        $keyParts = ['prefix'];
        foreach ($plan['eqPrefix'] as $value) {
            $keyParts[] = $this->valueKey($value);
        }
        if ($plan['kind'] === 'index_range') {
            $keyParts[] = 'range';
            $keyParts[] = $this->valueKey($plan['low']);
            $keyParts[] = $plan['lowInc'] ? '1' : '0';
            $keyParts[] = $this->valueKey($plan['high']);
            $keyParts[] = $plan['highInc'] ? '1' : '0';
        }

        $key = $this->coveredCountCacheKey($index->rootPage, 'index_prefix', $keyParts);
        $cached = $this->coveredCountCache[$key] ?? null;
        if ($cached !== null) {
            return [[$cached]];
        }

        $count = 0;
        foreach ($this->indexPrefixEntries($plan) as $_) {
            $count++;
        }
        $this->rememberCoveredCount($key, $count);
        return [[$count]];
    }

    /**
     * Fast full-scan fallback for COUNT(*) over a simple single-column
     * predicate when no covered rowid/index count applies.
     *
     * @param list<array{name:string,expr:?Expr,star:bool,tableStar:?string}> $outputCols
     * @param array<int,Expr> $aggregateNodes
     * @return list<list<int>>|null
     */
    private function trySimpleScanCount(SelectStatement $select, array $outputCols, array $aggregateNodes, Evaluator $eval): ?array
    {
        if ($select->where === null || $select->having !== null || \count($outputCols) !== 1 || \count($aggregateNodes) !== 1) {
            return null;
        }

        $expr = $outputCols[0]['expr'];
        if ($expr === null || $expr->kind !== Expr::FUNC || \strtolower((string) $expr->name) !== 'count' || !$expr->star || $expr->distinct) {
            return null;
        }

        $single = $this->singleTableForCompile($select);
        if ($single === null) {
            return null;
        }
        [$info, $alias] = $single;

        $plan = $this->simpleScanCountPlan($select->where, $alias, $info, $eval);
        if ($plan === null) {
            $plans = $this->simpleScanCountConjunctPlans($select->where, $alias, $info, $eval);
            return $plans !== null ? $this->scanCountWithPlans($info, $plans) : null;
        }
        if (($plan['impossible'] ?? false) === true) {
            return [[0]];
        }

        $pos = $plan['pos'];
        $coll = $pos >= 0 ? $info->columns[$pos]->collation : 'BINARY';
        if ($plan['op'] === '='
            && $eval->outerEnv !== null
            && $pos >= 0
            && $pos !== $info->rowidAlias
            && $this->indexLeading($this->db->schema()->resolvedIndexes($info->name), $pos) === null) {
            $count = $this->tryCachedCorrelatedCount($select, $info, $pos, $plan['value']);
            if ($count !== null) {
                return [[$count]];
            }
        }
        $keyParts = [(string) $pos, (string) $plan['op']];
        if ($plan['op'] === 'between') {
            $keyParts[] = $this->valueKey($plan['low']);
            $keyParts[] = $this->valueKey($plan['high']);
        } else {
            $keyParts[] = $this->valueKey($plan['value']);
        }
        $key = $this->coveredCountCacheKey($info->rootPage, 'scan_count', $keyParts);
        $cached = $this->coveredCountCache[$key] ?? null;
        if ($cached !== null) {
            return [[$cached]];
        }

        $count = 0;
        $tree = new TableBTree($this->db->pager(), $info->rootPage);
        foreach ($tree->scan() as [$rowid, $payload]) {
            if ($pos < 0 || $pos === $info->rowidAlias) {
                $value = $rowid;
            } else {
                $value = RecordCodec::decodeColumn($payload, $pos);
            }
            if ($value === null) {
                continue;
            }
            if ($this->simpleScanCountMatches($value, $plan, $coll)) {
                $count++;
            }
        }

        $this->rememberCoveredCount($key, $count);
        return [[$count]];
    }

    /** @return list<array<string,mixed>>|null */
    private function simpleScanCountConjunctPlans(Expr $where, string $alias, TableInfo $info, Evaluator $eval): ?array
    {
        $conjuncts = $this->splitAnd($where);
        if (\count($conjuncts) < 2) {
            return null;
        }

        $plans = [];
        foreach ($conjuncts as $conjunct) {
            $plan = $this->simpleScanCountPlan($conjunct, $alias, $info, $eval);
            if ($plan === null) {
                return null;
            }
            if (($plan['impossible'] ?? false) === true) {
                return [['impossible' => true]];
            }
            $pos = $plan['pos'];
            if ($pos < 0
                || $pos === $info->rowidAlias
                || $this->indexLeading($this->db->schema()->resolvedIndexes($info->name), $pos) !== null) {
                return null;
            }
            $plans[] = $plan;
        }
        return $plans;
    }

    /** @param list<array<string,mixed>> $plans */
    private function scanCountWithPlans(TableInfo $info, array $plans): array
    {
        if (\count($plans) === 1 && ($plans[0]['impossible'] ?? false) === true) {
            return [[0]];
        }

        $keyParts = [];
        foreach ($plans as $plan) {
            $keyParts = \array_merge($keyParts, $this->simpleScanPlanKeyParts($plan));
        }
        $key = $this->coveredCountCacheKey($info->rootPage, 'scan_count_and', $keyParts);
        $cached = $this->coveredCountCache[$key] ?? null;
        if ($cached !== null) {
            return [[$cached]];
        }

        $count = 0;
        $tree = new TableBTree($this->db->pager(), $info->rootPage);
        foreach ($tree->scan() as [$rowid, $payload]) {
            foreach ($plans as $plan) {
                $pos = $plan['pos'];
                if ($pos < 0 || $pos === $info->rowidAlias) {
                    $value = $rowid;
                } else {
                    $value = RecordCodec::decodeColumn($payload, $pos);
                }
                if ($value === null) {
                    continue 2;
                }
                $coll = $pos >= 0 ? $info->columns[$pos]->collation : 'BINARY';
                if (!$this->simpleScanCountMatches($value, $plan, $coll)) {
                    continue 2;
                }
            }
            $count++;
        }

        $this->rememberCoveredCount($key, $count);
        return [[$count]];
    }

    /** @param array<string,mixed> $plan */
    private function simpleScanPlanKeyParts(array $plan): array
    {
        $parts = [(string) $plan['pos'], (string) $plan['op']];
        if ($plan['op'] === 'between') {
            $parts[] = $this->valueKey($plan['low']);
            $parts[] = $this->valueKey($plan['high']);
        } else {
            $parts[] = $this->valueKey($plan['value']);
        }
        return $parts;
    }

    /**
     * Indexed correlated COUNT(*): serve from a persistent inner-table count map
     * instead of an index descent per outer row — but only once the same
     * subquery has been probed often enough to amortize building the map (~one
     * full inner scan). Below that threshold we return null and the caller keeps
     * the cheap per-probe covered-index count, so a query with only a handful of
     * outer rows never pays to materialize the whole inner table.
     */
    private function tryCorrelatedCountMap(SelectStatement $select, TableInfo $info, int $pos, null|int|float|string|Blob $value, ?\YetiDevWorks\YetiSQL\Engine\IndexInfo $index = null): ?int
    {
        $indexMapEligible = $index !== null && \strcasecmp($index->collations[0] ?? 'BINARY', 'BINARY') === 0;

        // If the index count-map is already built (e.g. a high-cardinality run or
        // a join primed it), reuse it for free regardless of this query's probe count.
        if ($indexMapEligible) {
            $cachedMap = $this->cachedIndexLeadingCountMap($index);
            if ($cachedMap !== null) {
                $value = $this->joinHashValue($info, $pos, $value);
                return $value === null ? 0 : ($cachedMap[$this->comparisonKey($value)] ?? 0);
            }
        }

        $id = \spl_object_id($select);
        if (!isset($this->correlatedCountCache[$id])) {
            // Row count measured ONCE per subquery and cached (never per probe).
            $rows = $this->correlatedMapRows[$id]
                ??= (new TableBTree($this->db->pager(), $info->rootPage))->countRange();
            // Building the map costs ~one full inner walk; a per-probe index/rowid
            // count costs ~O(depth). For a small inner table the one-time build is
            // cheap and the map is cached + shared (across joins and re-runs), so
            // build eagerly. For a large inner table, only materialize once enough
            // outer rows have probed to justify walking the whole table — until
            // then a per-probe count is far cheaper than the full build.
            if ($rows > self::COUNT_MAP_EAGER_MAX) {
                $probes = ($this->correlatedProbeCounts[$id] ?? 0) + 1;
                $this->correlatedProbeCounts[$id] = $probes;
                if ($probes < \max(16, \intdiv($rows, 8))) {
                    return null;
                }
            }
        }
        if ($indexMapEligible) {
            $value = $this->joinHashValue($info, $pos, $value);
            if ($value === null) {
                return 0;
            }
            return $this->indexLeadingCountMap($index)[$this->comparisonKey($value)] ?? 0;
        }
        return $this->tryCachedCorrelatedCount($select, $info, $pos, $value);
    }

    private function tryCachedCorrelatedCount(SelectStatement $select, TableInfo $info, int $pos, null|int|float|string|Blob $value): ?int
    {
        if ($this->db->inTransaction()) {
            return null;
        }
        if ($pos >= 0
            && $pos !== $info->rowidAlias
            && \strcasecmp($info->columns[$pos]->collation, 'BINARY') !== 0) {
            return null;
        }
        $value = $this->joinHashValue($info, $pos, $value);
        if ($value === null) {
            return 0;
        }

        $cacheId = \spl_object_id($select);
        $sig = $this->selectSignature($select);
        $cached = $this->correlatedCountCache[$cacheId] ?? null;
        if ($cached === null
            || $cached['cookie'] !== $this->db->pager()->schemaCookie()
            || $cached['changes'] !== $this->db->totalChanges()
            || $cached['root'] !== $info->rootPage
            || $cached['pos'] !== $pos
            || $cached['sig'] !== $sig) {
            $cached = [
                'cookie' => $this->db->pager()->schemaCookie(),
                'changes' => $this->db->totalChanges(),
                'root' => $info->rootPage,
                'pos' => $pos,
                'sig' => $sig,
                'map' => $this->buildCountMap($info, $pos),
            ];
            if (\count($this->correlatedCountCache) >= self::SELECT_META_MAX) {
                $this->correlatedCountCache = [];
                $this->correlatedProbeCounts = [];
                $this->correlatedMapRows = [];
            }
            $this->correlatedCountCache[$cacheId] = $cached;
        }

        return $cached['map'][$this->comparisonKey($value)] ?? 0;
    }

    /** @return array<string,int> */
    private function buildCountMap(TableInfo $info, int $pos): array
    {
        $counts = [];
        $tree = new TableBTree($this->db->pager(), $info->rootPage);
        foreach ($tree->scan() as [$rowid, $payload]) {
            if ($pos < 0 || $pos === $info->rowidAlias) {
                $value = $rowid;
            } else {
                $value = RecordCodec::decodeColumn($payload, $pos);
            }
            if ($value === null) {
                continue;
            }
            $key = $this->comparisonKey($value);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * Return the index leading-value count map only if it is already cached and
     * still valid — never builds it. Lets a caller prefer a cheap per-row seek
     * when materializing the whole map would not pay off.
     *
     * @return array<string,int>|null
     */
    private function cachedIndexLeadingCountMap(\YetiDevWorks\YetiSQL\Engine\IndexInfo $index): ?array
    {
        $cached = $this->indexCountMapCache[$index->rootPage] ?? null;
        if ($cached !== null
            && $cached['cookie'] === $this->db->pager()->schemaCookie()
            && $cached['changes'] === $this->db->totalChanges()) {
            return $cached['map'];
        }
        return null;
    }

    /** @return array<string,int> */
    private function indexLeadingCountMap(\YetiDevWorks\YetiSQL\Engine\IndexInfo $index): array
    {
        $cached = $this->cachedIndexLeadingCountMap($index);
        if ($cached !== null) {
            return $cached;
        }

        $map = [];
        $idx = new \YetiDevWorks\YetiSQL\Engine\IndexBTree($this->db->pager(), $index->rootPage, $index->collations);
        foreach ($idx->leadingValues() as $value) {
            if ($value === null) {
                continue;
            }
            $k = $this->comparisonKey($value);
            $map[$k] = ($map[$k] ?? 0) + 1;
        }
        if (\count($this->indexCountMapCache) >= self::SELECT_META_MAX) {
            $this->indexCountMapCache = [];
        }
        $this->indexCountMapCache[$index->rootPage] = [
            'cookie' => $this->db->pager()->schemaCookie(),
            'changes' => $this->db->totalChanges(),
            'map' => $map,
        ];
        return $map;
    }

    /** @return array<string,mixed>|null */
    private function simpleScanCountPlan(Expr $where, string $alias, TableInfo $info, Evaluator $eval): ?array
    {
        if ($where->kind === Expr::BIN && \in_array($where->op, ['=', '<', '<=', '>', '>='], true)) {
            [$pos, $const, $flip] = $this->colConst($where->left, $where->right, $alias, $info, $eval);
            if ($pos === null || $const === null) {
                return null;
            }
            $value = $this->affine($info, $pos, $this->constValue($const, $eval));
            if ($value === null) {
                return ['impossible' => true];
            }
            return [
                'pos' => $pos,
                'op' => $flip ? $this->flipOp((string) $where->op) : (string) $where->op,
                'value' => $value,
            ];
        }

        if ($where->kind === Expr::BETWEEN && $where->operand?->kind === Expr::COL && !$where->not) {
            $pos = $this->colPositionOf($where->operand, $alias, $info);
            if ($pos === null || !$this->isConstExpr($where->low) || !$this->isConstExpr($where->high)) {
                return null;
            }
            $low = $this->affine($info, $pos, $this->constValue($where->low, $eval));
            $high = $this->affine($info, $pos, $this->constValue($where->high, $eval));
            if ($low === null || $high === null) {
                return ['impossible' => true];
            }
            return ['pos' => $pos, 'op' => 'between', 'low' => $low, 'high' => $high];
        }

        return null;
    }

    /** @param array<string,mixed> $plan */
    private function simpleScanCountMatches(null|int|float|string|Blob $value, array $plan, string $collation): bool
    {
        if ($plan['op'] === 'between') {
            return Value::compare($value, $plan['low'], $collation) >= 0
                && Value::compare($value, $plan['high'], $collation) <= 0;
        }

        $cmp = Value::compare($value, $plan['value'], $collation);
        return match ($plan['op']) {
            '=' => $cmp === 0,
            '<' => $cmp < 0,
            '<=' => $cmp <= 0,
            '>' => $cmp > 0,
            '>=' => $cmp >= 0,
            default => false,
        };
    }

    /** @param list<string> $parts */
    private function coveredCountCacheKey(int $rootPage, string $kind, array $parts): string
    {
        return \implode("\x1e", [
            (string) $this->db->pager()->schemaCookie(),
            (string) $this->db->totalChanges(),
            (string) $rootPage,
            $kind,
            \serialize($parts),
        ]);
    }

    private function rememberCoveredCount(string $key, int $count): void
    {
        // Never cache a count computed inside an open transaction. The cache key
        // includes totalChanges(), which advances for writes in the transaction
        // but is NOT rewound on ROLLBACK, so a value cached mid-transaction would
        // be re-served under the same key after the rollback — returning a stale
        // count for the reverted data. In-transaction counts therefore always
        // recompute (correctly seeing uncommitted state); committed counts cache
        // normally in autocommit mode.
        if ($this->db->inTransaction()) {
            return;
        }
        if (\count($this->coveredCountCache) >= self::COVERED_COUNT_CACHE_MAX) {
            $this->coveredCountCache = [];
        }
        $this->coveredCountCache[$key] = $count;
    }

    /**
     * @param list<null|int|float|string|Blob> $values
     * @return list<null|int|float|string|Blob>
     */
    private function uniqueValues(array $values, string $collation): array
    {
        $unique = [];
        foreach ($values as $value) {
            foreach ($unique as $seen) {
                if (Value::compare($value, $seen, $collation) === 0) {
                    continue 2;
                }
            }
            $unique[] = $value;
        }
        return $unique;
    }

    private function aggregateProject(SelectStatement $select, array $outputCols, array $aggregateNodes, iterable $envs, Evaluator $eval, array $orderPlan): array
    {
        // Single pass: hash each row into its group and step that group's
        // accumulators immediately, keeping only one representative env per group
        // (used for the GROUP BY / HAVING / projection columns). This is reached
        // only for grouped aggregates; the ungrouped case streams elsewhere.
        $accsByGroup = [];
        $repByGroup = [];
        $names = [];
        foreach ($aggregateNodes as $id => $node) {
            $names[$id] = \strtolower((string) $node->name);
        }
        $cGroup = $eval->compiledGroupBy;
        $cArgs = $eval->compiledAggArgs;

        foreach ($envs as $env) {
            $this->exposeSelectAliases($select, $eval);
            $keyParts = [];
            if ($cGroup !== null) {
                foreach ($cGroup as $gc) {
                    $keyParts[] = $this->valueKey($gc($env, $eval));
                }
            } else {
                foreach ($select->groupBy as $g) {
                    $keyParts[] = $this->valueKey($eval->evaluate($g, $env));
                }
            }
            $key = \implode("\x1f", $keyParts);
            if (!isset($accsByGroup[$key])) {
                $accs = [];
                foreach ($aggregateNodes as $id => $node) {
                    $accs[$id] = Aggregates::newAccumulator($names[$id]);
                }
                $accsByGroup[$key] = $accs;
                $repByGroup[$key] = $env;
            }
            foreach ($aggregateNodes as $id => $node) {
                $argCs = $cArgs[$id] ?? null;
                $args = [];
                if ($argCs !== null) {
                    foreach ($argCs as $ac) {
                        $args[] = $ac($env, $eval);
                    }
                } else {
                    foreach ($node->args as $arg) {
                        $args[] = $eval->evaluate($arg, $env);
                    }
                }
                Aggregates::step($accsByGroup[$key][$id], $args, $node->star, $node->distinct);
            }
        }

        // Aliases become visible only after WHERE has been fully applied.
        $this->exposeSelectAliases($select, $eval);

        $rows = [];
        $keys = [];
        foreach ($accsByGroup as $key => $accs) {
            $eval->aggregateValues = [];
            foreach ($accs as $id => $acc) {
                $eval->aggregateValues[$id] = Aggregates::finalize($acc);
            }

            $rep = $repByGroup[$key];
            if ($select->having !== null) {
                $h = $eval->evaluate($select->having, $rep);
                if ($h === null || !Value::isTrue($h)) {
                    continue;
                }
            }
            $row = $this->projectRow($outputCols, $rep, $eval);
            $rows[] = $row;
            if ($orderPlan !== []) {
                $keys[] = $this->computeOrderKeys($orderPlan, $rep, $eval, $row);
            }
        }

        return [$rows, $keys];
    }

    // --- post-processing -------------------------------------------------

    /**
     * @param list<list<null|int|float|string|Blob>> $rows
     * @return list<list<null|int|float|string|Blob>>
     */
    private function distinct(array $rows): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $key = \implode("\x1f", \array_map([$this, 'valueKey'], $row));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param list<list<null|int|float|string|Blob>> $a
     * @param list<list<null|int|float|string|Blob>> $b
     * @return list<list<null|int|float|string|Blob>>
     */
    private function applyCompound(string $op, array $a, array $b): array
    {
        $keyset = static function (array $rows): array {
            $m = [];
            foreach ($rows as $r) {
                $m[\implode("\x1f", \array_map(self::valueKeyStatic(...), $r))] = $r;
            }
            return $m;
        };

        return match ($op) {
            'UNION ALL' => \array_merge($a, $b),
            'UNION' => \array_values($this->distinct(\array_merge($a, $b))),
            'INTERSECT' => (function () use ($a, $b, $keyset) {
                $bk = $keyset($b);
                $out = [];
                foreach ($this->distinct($a) as $r) {
                    if (isset($bk[\implode("\x1f", \array_map(self::valueKeyStatic(...), $r))])) {
                        $out[] = $r;
                    }
                }
                return $out;
            })(),
            'EXCEPT' => (function () use ($a, $b, $keyset) {
                $bk = $keyset($b);
                $out = [];
                foreach ($this->distinct($a) as $r) {
                    if (!isset($bk[\implode("\x1f", \array_map(self::valueKeyStatic(...), $r))])) {
                        $out[] = $r;
                    }
                }
                return $out;
            })(),
            default => $a,
        };
    }

    /**
     * Build a resolution plan for each ORDER BY term: a positional/alias output
     * column index when determinable, otherwise the expression to evaluate.
     *
     * @param list<string> $colNames
     * @return list<array{kind:string,index:?int,expr:Expr,desc:bool}>
     */
    private function orderPlan(SelectStatement $select, array $colNames): array
    {
        $plan = [];
        foreach ($select->orderBy as $ot) {
            $index = null;
            if ($ot->expr->kind === Expr::LIT && \is_int($ot->expr->value)) {
                $index = $ot->expr->value - 1;
            } elseif ($ot->expr->kind === Expr::COL && $ot->expr->table === null) {
                $found = \array_search($ot->expr->name, $colNames, true);
                if ($found !== false) {
                    $index = (int) $found;
                }
            }
            $plan[] = [
                'kind' => $index !== null ? 'pos' : 'expr',
                'index' => $index,
                'expr' => $ot->expr,
                'desc' => $ot->desc,
            ];
        }
        return $plan;
    }

    /**
     * @param list<array{kind:string,index:?int,expr:Expr,desc:bool}> $plan
     * @param list<null|int|float|string|Blob> $outRow
     * @return list<null|int|float|string|Blob>
     */
    private function computeOrderKeys(array $plan, RowEnv $env, Evaluator $eval, array $outRow): array
    {
        $keys = [];
        foreach ($plan as $t) {
            $keys[] = $t['kind'] === 'pos'
                ? ($outRow[$t['index']] ?? null)
                : $eval->evaluate($t['expr'], $env);
        }
        return $keys;
    }

    /**
     * Recompute order keys from output values only (used after a compound merge,
     * where per-branch envs are gone). Only positional/alias terms resolve.
     *
     * @param list<string> $colNames
     * @param list<list<null|int|float|string|Blob>> $rows
     * @return list<list<null|int|float|string|Blob>>
     */
    private function orderKeysFromOutput(SelectStatement $select, array $colNames, array $rows): array
    {
        $plan = $this->orderPlan($select, $colNames);
        if ($plan === []) {
            return [];
        }
        $keys = [];
        foreach ($rows as $row) {
            $k = [];
            foreach ($plan as $t) {
                $k[] = $t['index'] !== null ? ($row[$t['index']] ?? null) : null;
            }
            $keys[] = $k;
        }
        return $keys;
    }

    /**
     * @param list<list<null|int|float|string|Blob>> $rows
     * @param list<list<null|int|float|string|Blob>> $keys
     */
    private function sortRows(SelectStatement $select, array &$rows, array $keys): void
    {
        $desc = \array_map(static fn ($ot): bool => $ot->desc, $select->orderBy);
        $collations = \array_map(static fn ($ot): string => $ot->collation ?? 'BINARY', $select->orderBy);

        // Decorate-sort-undecorate to keep keys aligned with rows.
        $indexed = [];
        foreach ($rows as $i => $row) {
            $indexed[] = [$row, $keys[$i] ?? []];
        }
        \usort($indexed, static function (array $x, array $y) use ($desc, $collations): int {
            $kx = $x[1];
            $ky = $y[1];
            foreach ($kx as $j => $a) {
                $b = $ky[$j] ?? null;
                $cmp = Value::compare($a, $b, $collations[$j] ?? 'BINARY');
                if ($cmp !== 0) {
                    return ($desc[$j] ?? false) ? -$cmp : $cmp;
                }
            }
            return 0;
        });
        $rows = \array_map(static fn (array $p) => $p[0], $indexed);
    }

    /**
     * @param list<list<null|int|float|string|Blob>> $rows
     * @param array<string,null|int|float|string|Blob> $params
     */
    private function applyLimit(SelectStatement $select, array &$rows, array $params): void
    {
        $limit = $select->limit !== null
            ? (int) Value::toNumber((new Evaluator($this, $params))->evaluate($select->limit, null))
            : null;
        $offset = $select->offset !== null
            ? (int) Value::toNumber((new Evaluator($this, $params))->evaluate($select->offset, null))
            : 0;
        if ($limit !== null && $limit < 0) {
            $limit = null;
        }
        if ($offset < 0) {
            $offset = 0;
        }

        if ($offset > 0 || $limit !== null) {
            $rows = \array_values(\array_slice($rows, $offset, $limit));
        }
    }

    /**
     * Dedupe rows while keeping the parallel order-key array aligned.
     *
     * @param list<list<null|int|float|string|Blob>> $rows
     * @param list<list<null|int|float|string|Blob>> $keys
     * @return array{0:list<list<null|int|float|string|Blob>>,1:list<list<null|int|float|string|Blob>>}
     */
    private function distinctWithKeys(array $rows, array $keys): array
    {
        $seen = [];
        $outRows = [];
        $outKeys = [];
        foreach ($rows as $i => $row) {
            $key = \implode("\x1f", \array_map([$this, 'valueKey'], $row));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $outRows[] = $row;
                if ($keys !== []) {
                    $outKeys[] = $keys[$i] ?? [];
                }
            }
        }
        return [$outRows, $outKeys];
    }

    public function valueKey(null|int|float|string|Blob $v): string
    {
        return self::valueKeyStatic($v);
    }

    public static function valueKeyStatic(null|int|float|string|Blob $v): string
    {
        return match (true) {
            $v === null => 'N',
            \is_int($v) => 'i' . $v,
            \is_float($v) => 'f' . $v,
            $v instanceof Blob => 'b' . $v->bytes,
            default => 't' . $v,
        };
    }

    /**
     * Like valueKey, but collapses an integral float onto the matching integer
     * key so that values which Value::compare treats as equal (5 and 5.0) hash
     * to the same bucket. Required wherever a hash/count map stands in for an
     * `=` comparison; plain valueKey would split 5 ("i5") from 5.0 ("f5") and
     * silently miss matches for NONE/BLOB-affinity columns.
     */
    public function comparisonKey(null|int|float|string|Blob $v): string
    {
        if (\is_float($v) && \is_finite($v) && (float) (int) $v === $v) {
            return self::valueKeyStatic((int) $v);
        }
        return self::valueKeyStatic($v);
    }

    private function decodeRow(TableInfo $info, int $rowid, string $payload): array
    {
        $values = RecordCodec::decode($payload);
        $n = $info->columnCount();
        if (\count($values) < $n) {
            // Columns added via ALTER TABLE ADD COLUMN are absent from rows
            // written earlier; fill them with the column's declared default.
            for ($i = \count($values); $i < $n; $i++) {
                $values[$i] = $info->columns[$i]->defaultValue;
            }
        }
        if ($info->hasRowidAlias()) {
            $values[$info->rowidAlias] = $rowid;
        }
        return $values;
    }

    // === access planning =================================================

    /**
     * Yield [rowid, payload] for a table, restricting to a rowid/index access
     * path when the WHERE clause permits, otherwise a full scan. The caller
     * still applies the full WHERE, so a plan need only avoid missing rows.
     *
     * @return \Generator<int,array{0:int,1:string}>
     */
    private function scanRowids(TableInfo $info, ?string $alias, ?Expr $where, Evaluator $eval, ?array $plan = null): \Generator
    {
        $tree = new TableBTree($this->db->pager(), $info->rootPage);
        $plan ??= $where === null ? null : $this->bestPlan($where, $alias, $info, $eval);

        if ($plan === null) {
            yield from $tree->scan();
            return;
        }

        switch ($plan['kind']) {
            case 'rowid_eq':
                $seen = [];
                foreach ($plan['values'] as $v) {
                    $rid = (int) Value::toNumber($v);
                    if (isset($seen[$rid])) {
                        continue;
                    }
                    $seen[$rid] = true;
                    $payload = $tree->get($rid);
                    if ($payload !== null) {
                        yield [$rid, $payload];
                    }
                }
                return;

            case 'rowid_range':
                $low = $plan['low'] !== null ? (int) Value::toNumber($plan['low']) : null;
                $high = $plan['high'] !== null ? (int) Value::toNumber($plan['high']) : null;
                foreach ($tree->scan($low) as [$rid, $payload]) {
                    if ($high !== null && $rid > $high) {
                        break;
                    }
                    yield [$rid, $payload];
                }
                return;

            case 'index_eq':
            case 'index_range':
                yield from $this->indexScan($tree, $plan);
                return;
        }

        yield from $tree->scan();
    }

    /**
     * Like scanRowids but for the single-table read path: index-located rows are
     * yielded with a null payload so the caller can defer (or skip) the table
     * fetch. A precomputed plan is passed in to avoid re-planning.
     *
     * @param array<string,mixed>|null $plan
     * @return \Generator<int,array{0:int,1:?string,2?:array<int,null|int|float|string|Blob>}>
     */
    private function scanByPlan(TableBTree $tree, ?array $plan): \Generator
    {
        if ($plan === null) {
            foreach ($tree->scan() as [$rid, $payload]) {
                yield [$rid, $payload, []];
            }
            return;
        }

        switch ($plan['kind']) {
            case 'rowid_eq':
                $seen = [];
                foreach ($plan['values'] as $v) {
                    $rid = (int) Value::toNumber($v);
                    if (isset($seen[$rid])) {
                        continue;
                    }
                    $seen[$rid] = true;
                    $payload = $tree->get($rid);
                    if ($payload !== null) {
                        yield [$rid, $payload, []];
                    }
                }
                return;

            case 'rowid_range':
                $low = $plan['low'] !== null ? (int) Value::toNumber($plan['low']) : null;
                $high = $plan['high'] !== null ? (int) Value::toNumber($plan['high']) : null;
                $lowInc = $plan['lowInc'];
                $highInc = $plan['highInc'];
                // Honor strict bounds exactly: the covered path skips the residual
                // WHERE, so this scan must not over-yield the boundary rowids.
                foreach ($tree->scan($low) as [$rid, $payload]) {
                    if ($low !== null && !$lowInc && $rid <= $low) {
                        continue;
                    }
                    if ($high !== null && ($rid > $high || (!$highInc && $rid === $high))) {
                        break;
                    }
                    yield [$rid, $payload, []];
                }
                return;

            case 'index_eq':
            case 'index_range':
                $entries = isset($plan['eqPrefix'])
                    ? $this->indexPrefixEntries($plan)
                    : $this->indexEntries($plan);
                foreach ($entries as [$rid, $covered]) {
                    yield [$rid, null, $covered];
                }
                return;
        }

        foreach ($tree->scan() as [$rid, $payload]) {
            yield [$rid, $payload, []];
        }
    }

    /**
     * Seek a composite index by an equality prefix (eqPrefix) plus an optional
     * trailing range on the next column. Yields [rowid, []] (no covering data).
     * Keys are stored lexicographically, so all rows sharing the prefix are
     * contiguous: once a scanned key stops matching the prefix we are past them.
     *
     * @param array<string,mixed> $plan
     * @return \Generator<int,array{0:int,1:array<int,null|int|float|string|Blob>}>
     */
    private function indexPrefixEntries(array $plan): \Generator
    {
        /** @var \YetiDevWorks\YetiSQL\Engine\IndexInfo $index */
        $index = $plan['index'];
        $idx = new \YetiDevWorks\YetiSQL\Engine\IndexBTree(
            $this->db->pager(),
            $index->rootPage,
            $index->collations,
        );
        $prefix = $plan['eqPrefix'];
        $p = \count($prefix);
        $hasRange = $plan['kind'] === 'index_range';
        $rangeColl = $index->collations[$p] ?? 'BINARY';

        // Seek to the prefix, extended by the range's low bound when present.
        $seekKey = $prefix;
        if ($hasRange && $plan['low'] !== null) {
            $seekKey[] = $plan['low'];
        }

        $seen = [];
        foreach ($idx->scanFrom($seekKey) as $key) {
            for ($i = 0; $i < $p; $i++) {
                if (Value::compare($key[$i], $prefix[$i], $index->collations[$i] ?? 'BINARY') !== 0) {
                    return; // past the equality prefix — no further matches
                }
            }
            if ($hasRange) {
                $kv = $key[$p] ?? null;
                if ($plan['high'] !== null) {
                    $cmp = Value::compare($kv, $plan['high'], $rangeColl);
                    if ($cmp > 0 || ($cmp === 0 && !$plan['highInc'])) {
                        return;
                    }
                }
                if ($plan['low'] !== null) {
                    $cmp = Value::compare($kv, $plan['low'], $rangeColl);
                    if ($cmp < 0 || ($cmp === 0 && !$plan['lowInc'])) {
                        continue;
                    }
                }
            }
            $rowid = (int) $key[\count($key) - 1];
            if (isset($seen[$rowid])) {
                continue;
            }
            $seen[$rowid] = true;
            yield [$rowid, []];
        }
    }

    /** @return \Generator<int,array{0:int,1:string}> */
    private function indexScan(TableBTree $tree, array $plan): \Generator
    {
        // A composite (multi-column) plan locates rows by an equality prefix and
        // carries 'eqPrefix' instead of the single-column 'values'; route it to
        // the prefix seek, mirroring scanByPlan().
        $rowids = isset($plan['eqPrefix'])
            ? (function () use ($plan): \Generator {
                foreach ($this->indexPrefixEntries($plan) as [$rid]) {
                    yield $rid;
                }
            })()
            : $this->indexRowids($plan);
        foreach ($rowids as $rid) {
            $payload = $tree->get($rid);
            if ($payload !== null) {
                yield [$rid, $payload];
            }
        }
    }

    /**
     * Walk an index for an eq/range plan, yielding the matching rowids without
     * touching the table b-tree.
     *
     * @param array<string,mixed> $plan
     * @return \Generator<int,int>
     */
    private function indexRowids(array $plan): \Generator
    {
        /** @var \YetiDevWorks\YetiSQL\Engine\IndexInfo $index */
        $index = $plan['index'];
        $idx = new \YetiDevWorks\YetiSQL\Engine\IndexBTree(
            $this->db->pager(),
            $index->rootPage,
            $index->collations,
        );
        $coll = $index->collations[0] ?? 'BINARY';

        $probes = $plan['kind'] === 'index_eq' ? $this->uniqueValues($plan['values'], $coll) : [$plan['low']];
        $seenRowids = [];
        foreach ($probes as $probe) {
            $low = $plan['kind'] === 'index_eq' ? [$probe] : ($plan['low'] !== null ? [$plan['low']] : null);
            foreach ($idx->scanFrom($low) as $key) {
                $leading = $key[0];
                if ($plan['kind'] === 'index_eq') {
                    if (Value::compare($leading, $probe, $coll) !== 0) {
                        break;
                    }
                } else {
                    if ($plan['high'] !== null) {
                        $cmp = Value::compare($leading, $plan['high'], $coll);
                        if ($cmp > 0 || ($cmp === 0 && !$plan['highInc'])) {
                            break;
                        }
                    }
                    if ($plan['low'] !== null && !$plan['lowInc'] && Value::compare($leading, $plan['low'], $coll) === 0) {
                        continue;
                    }
                }
                $rowid = (int) $key[\count($key) - 1];
                if ($plan['kind'] === 'index_eq') {
                    if (isset($seenRowids[$rowid])) {
                        continue;
                    }
                    $seenRowids[$rowid] = true;
                }
                yield $rowid;
            }
        }
    }

    /**
     * @param array<string,mixed> $plan
     * @return \Generator<int,array{0:int,1:array<int,null|int|float|string|Blob>}>
     */
    private function indexEntries(array $plan): \Generator
    {
        /** @var \YetiDevWorks\YetiSQL\Engine\IndexInfo $index */
        $index = $plan['index'];
        $idx = new \YetiDevWorks\YetiSQL\Engine\IndexBTree(
            $this->db->pager(),
            $index->rootPage,
            $index->collations,
        );
        $coll = $index->collations[0] ?? 'BINARY';

        $probes = $plan['kind'] === 'index_eq' ? $this->uniqueValues($plan['values'], $coll) : [$plan['low']];
        $seenRowids = [];
        foreach ($probes as $probe) {
            $low = $plan['kind'] === 'index_eq' ? [$probe] : ($plan['low'] !== null ? [$plan['low']] : null);
            foreach ($idx->scanFrom($low) as $key) {
                $leading = $key[0];
                if ($plan['kind'] === 'index_eq') {
                    if (Value::compare($leading, $probe, $coll) !== 0) {
                        break;
                    }
                } else {
                    if ($plan['high'] !== null) {
                        $cmp = Value::compare($leading, $plan['high'], $coll);
                        if ($cmp > 0 || ($cmp === 0 && !$plan['highInc'])) {
                            break;
                        }
                    }
                    if ($plan['low'] !== null && !$plan['lowInc'] && Value::compare($leading, $plan['low'], $coll) === 0) {
                        continue;
                    }
                }
                $rowid = (int) $key[\count($key) - 1];
                if ($plan['kind'] === 'index_eq') {
                    if (isset($seenRowids[$rowid])) {
                        continue;
                    }
                    $seenRowids[$rowid] = true;
                }
                $covered = [];
                foreach ($index->columnPositions as $i => $pos) {
                    $covered[$pos] = $key[$i] ?? null;
                }
                yield [$rowid, $covered];
            }
        }
    }

    /**
     * Pick the best single-table access path from top-level AND conjuncts of
     * the WHERE clause. Same-column range conjuncts are intersected into one
     * tighter seek; otherwise equality (rowid then index) beats ranges.
     *
     * @return array<string,mixed>|null
     */
    private function bestPlan(Expr $where, ?string $alias, TableInfo $info, Evaluator $eval): ?array
    {
        $indexes = $this->db->schema()->resolvedIndexes($info->name);
        $conjuncts = $this->splitAnd($where);
        $plans = [];
        $best = null;
        $bestRank = 0;
        foreach ($conjuncts as $conj) {
            $plan = $this->predicatePlan($conj, $alias, $info, $indexes, $eval);
            if ($plan === null) {
                continue;
            }
            $plans[] = $plan;
            $rank = match ($plan['kind']) {
                'rowid_eq' => 5,
                'index_eq' => 4,
                'rowid_range' => 3,
                'index_range' => 2,
                default => 1,
            };
            if ($rank > $bestRank) {
                $best = $plan;
                $bestRank = $rank;
            }
        }
        // A rowid equality is unique — one row — so nothing beats it.
        if ($best !== null && $best['kind'] === 'rowid_eq') {
            if (\count($conjuncts) === 1) {
                $best['coveredAll'] = true;
            }
            return $best;
        }

        // A composite index whose leading columns are pinned by several equality
        // conjuncts (optionally with a trailing range) is more selective than any
        // single-column plan, so prefer it.
        $multi = $this->multiColumnIndexPlan($conjuncts, $alias, $info, $indexes, $eval);
        if ($multi !== null) {
            return $multi;
        }

        $combined = $this->combinedRangePlan($plans, \count($conjuncts));
        if ($combined !== null) {
            return $combined;
        }
        if ($best !== null && \count($conjuncts) === 1) {
            $best['coveredAll'] = true;
        }
        return $best;
    }

    /**
     * Plan a seek over a composite index: an equality prefix across the index's
     * leading columns (a = ? AND b = ? ...), optionally followed by a range on
     * the next column (... AND c > ?). Returns a plan carrying 'eqPrefix' (the
     * leading equality values) plus optional range bounds, or null when no index
     * is pinned by more than its leading column.
     *
     * @param list<Expr> $conjuncts
     * @param list<\YetiDevWorks\YetiSQL\Engine\IndexInfo> $indexes
     * @return array<string,mixed>|null
     */
    private function multiColumnIndexPlan(array $conjuncts, ?string $alias, TableInfo $info, array $indexes, Evaluator $eval): ?array
    {
        if ($indexes === []) {
            return null;
        }

        // Bucket the simple `col OP const` conjuncts by column position.
        $eq = [];
        $ranges = [];
        $parsed = [];
        foreach ($conjuncts as $conj) {
            if ($conj->kind !== Expr::BIN || !\in_array($conj->op, ['=', '<', '<=', '>', '>='], true)) {
                continue;
            }
            [$pos, $const, $flip] = $this->colConst($conj->left, $conj->right, $alias, $info, $eval);
            if ($pos === null || $pos === $info->rowidAlias) {
                continue;
            }
            $value = $this->affine($info, $pos, $this->constValue($const, $eval));
            if ($value === null) {
                continue; // a NULL comparison is never true; the residual filter handles it
            }
            $op = $flip ? $this->flipOp((string) $conj->op) : (string) $conj->op;
            $parsed[] = ['pos' => $pos, 'op' => $op];
            if ($op === '=') {
                $eq[$pos] = $value;
                continue;
            }
            $r = $ranges[$pos] ?? ['low' => null, 'lowInc' => false, 'high' => null, 'highInc' => false];
            match ($op) {
                '>' => [$r['low'] = $value, $r['lowInc'] = false],
                '>=' => [$r['low'] = $value, $r['lowInc'] = true],
                '<' => [$r['high'] = $value, $r['highInc'] = false],
                '<=' => [$r['high'] = $value, $r['highInc'] = true],
                default => null,
            };
            $ranges[$pos] = $r;
        }
        if ($eq === []) {
            return null;
        }

        // Pick the index that consumes the most leading columns.
        $best = null;
        $bestUsed = 1; // require strictly more than a single leading column
        foreach ($indexes as $index) {
            if ($index->partialWhere !== null) {
                continue; // partial indexes are not used for query planning
            }
            $cols = $index->columnPositions;
            $prefix = [];
            $i = 0;
            while ($i < \count($cols) && isset($eq[$cols[$i]])) {
                $prefix[] = $eq[$cols[$i]];
                $i++;
            }
            if ($prefix === []) {
                continue; // leading column not pinned by equality
            }
            $range = ($i < \count($cols) && isset($ranges[$cols[$i]])) ? $ranges[$cols[$i]] : null;
            $used = \count($prefix) + ($range !== null ? 1 : 0);
            if ($used > $bestUsed) {
                $bestUsed = $used;
                $best = ['index' => $index, 'prefix' => $prefix, 'range' => $range];
            }
        }
        if ($best === null) {
            return null;
        }

        $plan = [
            'index' => $best['index'],
            'eqPrefix' => $best['prefix'],
            'coveredAll' => $this->compositePlanCoversAll($conjuncts, $parsed, $best['index'], $best['prefix'], $best['range']),
        ];
        if ($best['range'] !== null) {
            $plan['kind'] = 'index_range';
            $plan += $best['range'];
        } else {
            $plan['kind'] = 'index_eq';
        }
        return $plan;
    }

    /**
     * @param list<Expr> $conjuncts
     * @param list<array{pos:int,op:string}> $parsed
     * @param list<null|int|float|string|Blob> $prefix
     * @param ?array<string,mixed> $range
     */
    private function compositePlanCoversAll(array $conjuncts, array $parsed, \YetiDevWorks\YetiSQL\Engine\IndexInfo $index, array $prefix, ?array $range): bool
    {
        if (\count($parsed) !== \count($conjuncts)) {
            return false;
        }

        $prefixCols = \array_slice($index->columnPositions, 0, \count($prefix));
        $rangePos = $range !== null ? ($index->columnPositions[\count($prefix)] ?? null) : null;

        // The covered count trusts the index seek without a residual filter, so
        // every conjunct must be faithfully enforced by the access path. The
        // path enforces each prefix column with exactly one `=` value and the
        // range column with at most one lower and one upper bound. A column with
        // a duplicated `=`, a range op on a pinned prefix column, or two bounds
        // in the same direction is NOT faithfully represented (the range merge
        // keeps only the last bound per direction), so bail to the residual path.
        $eqCount = [];
        $rangeLow = 0;
        $rangeHigh = 0;
        foreach ($parsed as $term) {
            if (\in_array($term['pos'], $prefixCols, true)) {
                if ($term['op'] !== '=') {
                    return false;
                }
                $eqCount[$term['pos']] = ($eqCount[$term['pos']] ?? 0) + 1;
                continue;
            }
            if ($rangePos !== null && $term['pos'] === $rangePos) {
                if ($term['op'] === '>' || $term['op'] === '>=') {
                    $rangeLow++;
                    continue;
                }
                if ($term['op'] === '<' || $term['op'] === '<=') {
                    $rangeHigh++;
                    continue;
                }
            }
            return false;
        }
        foreach ($prefixCols as $pc) {
            if (($eqCount[$pc] ?? 0) !== 1) {
                return false;
            }
        }
        return $rangeLow <= 1 && $rangeHigh <= 1;
    }

    /**
     * @param list<array<string,mixed>> $plans
     * @return array<string,mixed>|null
     */
    private function combinedRangePlan(array $plans, int $conjunctCount): ?array
    {
        if (\count($plans) < 2 || \count($plans) !== $conjunctCount) {
            return null;
        }

        $first = $plans[0];
        if (!\in_array($first['kind'], ['rowid_range', 'index_range'], true)) {
            return null;
        }
        $kind = $first['kind'];
        $pos = $first['pos'] ?? null;
        $index = $first['index'] ?? null;
        $low = null;
        $lowInc = false;
        $high = null;
        $highInc = false;
        $coll = $index instanceof \YetiDevWorks\YetiSQL\Engine\IndexInfo ? ($index->collations[0] ?? 'BINARY') : 'BINARY';

        foreach ($plans as $plan) {
            if (($plan['kind'] ?? null) !== $kind || ($plan['pos'] ?? null) !== $pos || (($plan['index'] ?? null) !== $index)) {
                return null;
            }
            if ($plan['low'] !== null) {
                if ($low === null) {
                    $low = $plan['low'];
                    $lowInc = $plan['lowInc'];
                } else {
                    $cmp = Value::compare($plan['low'], $low, $coll);
                    if ($cmp > 0 || ($cmp === 0 && !$plan['lowInc'] && $lowInc)) {
                        $low = $plan['low'];
                        $lowInc = $plan['lowInc'];
                    }
                }
            }
            if ($plan['high'] !== null) {
                if ($high === null) {
                    $high = $plan['high'];
                    $highInc = $plan['highInc'];
                } else {
                    $cmp = Value::compare($plan['high'], $high, $coll);
                    if ($cmp < 0 || ($cmp === 0 && !$plan['highInc'] && $highInc)) {
                        $high = $plan['high'];
                        $highInc = $plan['highInc'];
                    }
                }
            }
        }

        if ($low !== null && $high !== null) {
            $cmp = Value::compare($low, $high, $coll);
            if ($cmp > 0 || ($cmp === 0 && (!$lowInc || !$highInc))) {
                return null;
            }
        }

        $combined = [
            'kind' => $kind,
            'pos' => $pos,
            'low' => $low,
            'lowInc' => $lowInc,
            'high' => $high,
            'highInc' => $highInc,
            'coveredAll' => true,
        ];
        if ($kind === 'index_range') {
            $combined['index'] = $index;
        }
        return $combined;
    }

    /** @return list<Expr> */
    private function splitAnd(Expr $e): array
    {
        if ($e->kind === Expr::BIN && $e->op === 'AND') {
            return \array_merge($this->splitAnd($e->left), $this->splitAnd($e->right));
        }
        return [$e];
    }

    /**
     * Find an equi-join access key for an inner table: a conjunct of the ON
     * clause shaped `inner.col = <outer expr>` where inner.col has a usable
     * access path (rowid or index). Returns ['pos'=>int,'keyExpr'=>Expr] or null.
     *
     * @param list<\YetiDevWorks\YetiSQL\Engine\IndexInfo> $indexes
     * @return array{pos:int,keyExpr:Expr}|null
     */
    private function joinAccessKey(JoinClause $join, ?string $innerAlias, TableInfo $innerInfo, array $indexes): ?array
    {
        // Only plain ON equi-joins; USING/NATURAL/CROSS fall back to a scan.
        if ($join->on === null) {
            return null;
        }
        foreach ($this->splitAnd($join->on) as $conj) {
            if ($conj->kind !== Expr::BIN || $conj->op !== '=') {
                continue;
            }
            $key = $this->equiKeyFrom($conj->left, $conj->right, $innerAlias, $innerInfo, $indexes)
                ?? $this->equiKeyFrom($conj->right, $conj->left, $innerAlias, $innerInfo, $indexes);
            if ($key !== null) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Find a safe transient hash-join key for an unindexed inner table. This is
     * deliberately narrower than the persistent index path: only a BINARY
     * collated inner column (or rowid) is hashed, and the full ON predicate is
     * still evaluated for every candidate row.
     *
     * @return array{pos:int,keyExpr:Expr}|null
     */
    private function joinHashKey(JoinClause $join, ?string $innerAlias, TableInfo $innerInfo): ?array
    {
        if ($join->on === null) {
            return null;
        }
        foreach ($this->splitAnd($join->on) as $conj) {
            if ($conj->kind !== Expr::BIN || $conj->op !== '=') {
                continue;
            }
            $key = $this->hashKeyFrom($conj->left, $conj->right, $innerAlias, $innerInfo)
                ?? $this->hashKeyFrom($conj->right, $conj->left, $innerAlias, $innerInfo);
            if ($key !== null) {
                return $key;
            }
        }
        return null;
    }

    /** @return array{pos:int,keyExpr:Expr}|null */
    private function hashKeyFrom(Expr $innerSide, Expr $outerSide, ?string $innerAlias, TableInfo $innerInfo): ?array
    {
        $pos = $this->colPositionOf($innerSide, $innerAlias, $innerInfo);
        if ($pos === null || $this->exprReferencesTable($outerSide, $innerAlias, $innerInfo)) {
            return null;
        }
        if ($pos >= 0 && $pos !== $innerInfo->rowidAlias
            && \strcasecmp($innerInfo->columns[$pos]->collation, 'BINARY') !== 0) {
            return null;
        }
        return ['pos' => $pos, 'keyExpr' => $outerSide];
    }

    /**
     * @return array<string,list<array{0:int,1:string}>>
     */
    private function buildJoinHash(TableBTree $tree, TableInfo $info, int $pos): array
    {
        $rows = [];
        foreach ($tree->scan() as [$rowid, $payload]) {
            if ($pos < 0 || $pos === $info->rowidAlias) {
                $value = $rowid;
            } else {
                $value = RecordCodec::decodeColumn($payload, $pos);
            }
            if ($value === null) {
                continue;
            }
            $rows[$this->comparisonKey($value)][] = [$rowid, $payload];
        }
        return $rows;
    }

    private function joinHashValue(TableInfo $info, int $pos, null|int|float|string|Blob $value): null|int|float|string|Blob
    {
        if ($value === null) {
            return null;
        }
        if ($pos < 0 || $pos === $info->rowidAlias) {
            if (\is_int($value)) {
                return $value;
            }
            if (\is_float($value) && (float) (int) $value === $value) {
                return (int) $value;
            }
            if (\is_string($value) && Value::looksNumeric($value)) {
                $n = Value::parseNumber($value);
                if (\is_int($n) || (\is_float($n) && (float) (int) $n === $n)) {
                    return (int) $n;
                }
            }
            return null;
        }
        return $this->affine($info, $pos, $value);
    }

    /**
     * @param list<\YetiDevWorks\YetiSQL\Engine\IndexInfo> $indexes
     * @return array{pos:int,keyExpr:Expr}|null
     */
    private function equiKeyFrom(Expr $innerSide, Expr $outerSide, ?string $innerAlias, TableInfo $innerInfo, array $indexes): ?array
    {
        $pos = $this->colPositionOf($innerSide, $innerAlias, $innerInfo);
        if ($pos === null) {
            return null;
        }
        // The other side must be evaluable against the outer rows alone — it must
        // not reference the inner table (else it is not a per-outer-row constant).
        if ($this->exprReferencesTable($outerSide, $innerAlias, $innerInfo)) {
            return null;
        }
        // Only worthwhile when inner.col can be seeked: rowid or a leading index.
        if ($pos !== $innerInfo->rowidAlias && $this->indexLeading($indexes, $pos) === null) {
            return null;
        }
        return ['pos' => $pos, 'keyExpr' => $outerSide];
    }

    /** Whether any column in $e resolves to the given (inner) table. */
    private function exprReferencesTable(Expr $e, ?string $alias, TableInfo $info): bool
    {
        switch ($e->kind) {
            case Expr::COL:
                return $this->colPositionOf($e, $alias, $info) !== null;
            case Expr::LIT:
            case Expr::PARAM:
                return false;
            case Expr::SUBQUERY:
            case Expr::EXISTS:
                return true; // conservative: a subquery key is not treated as a pure outer constant
        }
        foreach ([$e->left, $e->right, $e->operand, $e->subject, $e->elseExpr, $e->low, $e->high, $e->escape] as $child) {
            if ($child instanceof Expr && $this->exprReferencesTable($child, $alias, $info)) {
                return true;
            }
        }
        foreach ([...$e->args, ...$e->list] as $child) {
            if ($this->exprReferencesTable($child, $alias, $info)) {
                return true;
            }
        }
        foreach ($e->whens as $when) {
            if ($this->exprReferencesTable($when[0], $alias, $info) || $this->exprReferencesTable($when[1], $alias, $info)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Seek the inner rows whose join column equals $value for one outer row,
     * using the access path resolved once in iterateJoined() ($acc['seek']).
     * Returns an iterable of [rowid, payload], or null when there is no usable
     * seek for this value (the caller then full-scans + filters via joinMatches).
     *
     * @param array<string,mixed> $acc
     * @return iterable<array{0:int,1:string}>|null
     */
    private function joinSeek(array $acc, mixed $value, TableInfo $info, TableBTree $tree): ?iterable
    {
        $value = $this->affine($info, $acc['pos'], $value);
        if ($value === null) {
            return null; // NULL never equi-joins; scan yields nothing matching
        }

        if ($acc['seek'] === 'rowid') {
            if (!\is_int($value) && !(\is_float($value) && (float) (int) $value === $value)) {
                return null; // non-integral rowid value: let the caller scan
            }
            $rid = (int) $value;
            $payload = $tree->get($rid);
            return $payload === null ? [] : [[$rid, $payload]];
        }

        if ($acc['seek'] === 'index') {
            return $this->joinSeekIndex($acc, $value, $tree);
        }

        return null;
    }

    /**
     * Index equality seek for one probe value, reusing the IndexBTree built once
     * per query in iterateJoined(). Index keys are [col, …, rowid] and unique,
     * so a single leading value yields each matching rowid exactly once.
     *
     * @param array<string,mixed> $acc
     * @return \Generator<int,array{0:int,1:string}>
     */
    private function joinSeekIndex(array $acc, null|int|float|string|Blob $value, TableBTree $tree): \Generator
    {
        $idx = $acc['idx'];
        $coll = $acc['coll'];
        foreach ($idx->scanFrom([$value]) as $key) {
            if (Value::compare($key[0], $value, $coll) !== 0) {
                break;
            }
            $rid = (int) $key[\count($key) - 1];
            $payload = $tree->get($rid);
            if ($payload !== null) {
                yield [$rid, $payload];
            }
        }
    }

    /**
     * @param list<\YetiDevWorks\YetiSQL\Engine\IndexInfo> $indexes
     * @return array<string,mixed>|null
     */
    private function predicatePlan(Expr $e, ?string $alias, TableInfo $info, array $indexes, Evaluator $eval): ?array
    {
        // Equality / range:  col OP const
        if ($e->kind === Expr::BIN && \in_array($e->op, ['=', '<', '<=', '>', '>='], true)) {
            [$col, $const, $flip] = $this->colConst($e->left, $e->right, $alias, $info, $eval);
            if ($col === null) {
                return null;
            }
            $value = $this->constValue($const, $eval);
            $op = $flip ? $this->flipOp((string) $e->op) : (string) $e->op;
            return $this->makeComparisonPlan($col, $op, $value, $info, $indexes);
        }

        // BETWEEN: col BETWEEN low AND high
        if ($e->kind === Expr::BETWEEN && $e->operand?->kind === Expr::COL && !$e->not) {
            $pos = $this->colPositionOf($e->operand, $alias, $info);
            if ($pos === null || !$this->isConstExpr($e->low) || !$this->isConstExpr($e->high)) {
                return null;
            }
            $low = $this->affine($info, $pos, $this->constValue($e->low, $eval));
            $high = $this->affine($info, $pos, $this->constValue($e->high, $eval));
            // A NULL bound makes `BETWEEN` never true; emit no plan (a null bound
            // would otherwise read as "unbounded" on the index seek).
            if ($low === null || $high === null) {
                return null;
            }
            return $this->makeRangePlan($pos, $low, true, $high, true, $info, $indexes);
        }

        // IN with a constant list: col IN (v1, v2, ...)
        if ($e->kind === Expr::IN && $e->operand?->kind === Expr::COL && !$e->not && $e->select === null && $e->list !== []) {
            $pos = $this->colPositionOf($e->operand, $alias, $info);
            if ($pos === null) {
                return null;
            }
            $values = [];
            $seen = [];
            foreach ($e->list as $item) {
                if (!$this->isConstExpr($item)) {
                    return null;
                }
                $v = $this->affine($info, $pos, $this->constValue($item, $eval));
                // A NULL in the list never matches via `=`, and duplicates would
                // be double-counted on the eq seek; drop both so the plan yields
                // each matching row exactly once.
                if ($v === null) {
                    continue;
                }
                $k = $this->valueKey($v);
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $values[] = $v;
            }
            if ($values === []) {
                return null; // nothing can match; fall back to scan + filter
            }
            if ($pos === $info->rowidAlias) {
                return ['kind' => 'rowid_eq', 'pos' => $pos, 'values' => $values];
            }
            $index = $this->indexLeading($indexes, $pos);
            if ($index !== null) {
                return ['kind' => 'index_eq', 'pos' => $pos, 'index' => $index, 'values' => $values];
            }
        }

        return null;
    }

    /** @param list<\YetiDevWorks\YetiSQL\Engine\IndexInfo> $indexes */
    private function makeComparisonPlan(int $pos, string $op, mixed $value, TableInfo $info, array $indexes): ?array
    {
        $value = $this->affine($info, $pos, $value);
        // Any comparison against NULL (`= < <= > >=`) is never true, so produce
        // no plan: the scan + WHERE filter then correctly yields zero rows rather
        // than the index seek treating a NULL bound as unbounded.
        if ($value === null) {
            return null;
        }
        if ($op === '=') {
            if ($pos === $info->rowidAlias) {
                if (!\is_int($value) && !(\is_float($value) && (float) (int) $value === $value)) {
                    return null;
                }
                return ['kind' => 'rowid_eq', 'pos' => $pos, 'values' => [$value]];
            }
            $index = $this->indexLeading($indexes, $pos);
            return $index !== null ? ['kind' => 'index_eq', 'pos' => $pos, 'index' => $index, 'values' => [$value]] : null;
        }
        // range
        $low = null;
        $high = null;
        $lowInc = false;
        $highInc = false;
        match ($op) {
            '>' => [$low = $value],
            '>=' => [$low = $value, $lowInc = true],
            '<' => [$high = $value],
            '<=' => [$high = $value, $highInc = true],
            default => null,
        };
        return $this->makeRangePlan($pos, $low, $lowInc, $high, $highInc, $info, $indexes);
    }

    /** @param list<\YetiDevWorks\YetiSQL\Engine\IndexInfo> $indexes */
    private function makeRangePlan(int $pos, mixed $low, bool $lowInc, mixed $high, bool $highInc, TableInfo $info, array $indexes): ?array
    {
        if ($pos === $info->rowidAlias) {
            return ['kind' => 'rowid_range', 'pos' => $pos, 'low' => $low, 'lowInc' => $lowInc, 'high' => $high, 'highInc' => $highInc];
        }
        $index = $this->indexLeading($indexes, $pos);
        if ($index === null) {
            return null;
        }
        return ['kind' => 'index_range', 'pos' => $pos, 'index' => $index, 'low' => $low, 'lowInc' => $lowInc, 'high' => $high, 'highInc' => $highInc];
    }

    /**
     * @param list<\YetiDevWorks\YetiSQL\Engine\IndexInfo> $indexes
     */
    private function indexLeading(array $indexes, int $pos): ?\YetiDevWorks\YetiSQL\Engine\IndexInfo
    {
        foreach ($indexes as $index) {
            // Partial indexes only contain rows matching their predicate, so the
            // planner must not use them to answer a query that could match rows
            // outside it (correctness over speed — they stay for enforcement).
            if ($index->partialWhere !== null) {
                continue;
            }
            if ($index->leadingColumn() === $pos) {
                return $index;
            }
        }
        return null;
    }

    /** @return array{0:?int,1:?Expr,2:bool} [colPos, constExpr, flipped] */
    private function colConst(Expr $a, Expr $b, ?string $alias, TableInfo $info, Evaluator $eval): array
    {
        $pa = $this->colPositionOf($a, $alias, $info);
        if ($pa !== null && $this->isPlannableConst($b, $alias, $info, $eval)) {
            return [$pa, $b, false];
        }
        $pb = $this->colPositionOf($b, $alias, $info);
        if ($pb !== null && $this->isPlannableConst($a, $alias, $info, $eval)) {
            return [$pb, $a, true];
        }
        return [null, null, false];
    }

    /**
     * Whether $e is a constant the planner can read now: a literal/param, or —
     * inside a correlated subquery — an expression over only the enclosing
     * query's columns (a per-outer-row constant). constValue() evaluates the
     * latter against the outer scope, so an index seek can use the live value.
     */
    private function isPlannableConst(Expr $e, ?string $alias, TableInfo $info, Evaluator $eval): bool
    {
        if ($this->isConstExpr($e)) {
            return true;
        }
        // exprReferencesTable() is true for any column of this (inner) table and
        // for subqueries, so what remains references only outer columns.
        return $eval->outerEnv !== null && !$this->exprReferencesTable($e, $alias, $info);
    }

    private function colPositionOf(Expr $e, ?string $alias, TableInfo $info): ?int
    {
        if ($e->kind !== Expr::COL) {
            return null;
        }
        if ($e->table !== null && $alias !== null
            && \strcasecmp($e->table, $alias) !== 0 && \strcasecmp($e->table, $info->name) !== 0) {
            return null;
        }
        $lname = \strtolower((string) $e->name);
        if (\in_array($lname, ['rowid', '_rowid_', 'oid'], true) && $info->columnPos((string) $e->name) === null) {
            return $info->hasRowidAlias() ? $info->rowidAlias : -1; // -1: rowid with no alias column
        }
        return $info->columnPos((string) $e->name);
    }

    private function flipOp(string $op): string
    {
        return match ($op) {
            '<' => '>', '<=' => '>=', '>' => '<', '>=' => '<=', default => $op,
        };
    }

    private function affine(TableInfo $info, int $pos, mixed $value): mixed
    {
        if ($pos === $info->rowidAlias || $pos < 0) {
            return $value; // rowid handled numerically by caller
        }
        return $info->columns[$pos]->affinity->apply($value);
    }

    private function constValue(Expr $e, Evaluator $eval): mixed
    {
        return $eval->evaluate($e, null);
    }

    private function isConstExpr(Expr $e): bool
    {
        if (\in_array($e->kind, [Expr::COL, Expr::SUBQUERY, Expr::EXISTS], true)) {
            return false;
        }
        if ($e->kind === Expr::FUNC && \YetiDevWorks\YetiSQL\Functions\Aggregates::isAggregate((string) $e->name)) {
            return false;
        }
        foreach ([$e->left, $e->right, $e->operand, $e->subject, $e->elseExpr, $e->low, $e->high, $e->escape] as $c) {
            if ($c instanceof Expr && !$this->isConstExpr($c)) {
                return false;
            }
        }
        foreach (\array_merge($e->args, $e->list) as $c) {
            if (!$this->isConstExpr($c)) {
                return false;
            }
        }
        foreach ($e->whens as [$w, $t]) {
            if (!$this->isConstExpr($w) || !$this->isConstExpr($t)) {
                return false;
            }
        }
        return true;
    }

    // === index maintenance ===============================================

    /** @param list<null|int|float|string|Blob> $logicalValues row values with the rowid-alias slot set to the rowid */
    private function insertIndexEntries(TableInfo $info, int $rowid, array $logicalValues): void
    {
        foreach ($this->db->schema()->resolvedIndexes($info->name) as $index) {
            if (!$this->rowMatchesPartial($info, $index, $logicalValues, $rowid)) {
                continue; // row outside a partial index's predicate is not stored
            }
            $this->indexTree($index)->put($this->indexKey($index, $logicalValues, $rowid));
        }
    }

    /** @param list<null|int|float|string|Blob> $logicalValues */
    private function deleteIndexEntries(TableInfo $info, int $rowid, array $logicalValues): void
    {
        foreach ($this->db->schema()->resolvedIndexes($info->name) as $index) {
            if (!$this->rowMatchesPartial($info, $index, $logicalValues, $rowid)) {
                continue; // never stored, nothing to remove
            }
            $this->indexTree($index)->delete($this->indexKey($index, $logicalValues, $rowid));
        }
    }

    /**
     * Re-index a changed row, touching only indexes affected by the change.
     *
     * @param list<null|int|float|string|Blob> $oldValues
     * @param list<null|int|float|string|Blob> $newValues
     * @param array<int,true> $changed table column positions assigned by SET
     */
    private function maintainIndexesForUpdate(TableInfo $info, int $oldRowid, array $oldValues, int $newRowid, array $newValues, array $changed): void
    {
        $rowidChanged = $oldRowid !== $newRowid;
        foreach ($this->db->schema()->resolvedIndexes($info->name) as $index) {
            if ($index->partialWhere !== null) {
                // The row may cross the predicate boundary, so always re-evaluate
                // membership on both sides rather than relying on key-column change.
                $tree = $this->indexTree($index);
                if ($this->rowMatchesPartial($info, $index, $oldValues, $oldRowid)) {
                    $tree->delete($this->indexKey($index, $oldValues, $oldRowid));
                }
                if ($this->rowMatchesPartial($info, $index, $newValues, $newRowid)) {
                    $tree->put($this->indexKey($index, $newValues, $newRowid));
                }
                continue;
            }
            $touched = $rowidChanged;
            if (!$touched) {
                foreach ($index->columnPositions as $p) {
                    if (isset($changed[$p])) {
                        $touched = true;
                        break;
                    }
                }
            }
            if (!$touched) {
                continue; // this index's keys are unchanged
            }
            $tree = $this->indexTree($index);
            $tree->delete($this->indexKey($index, $oldValues, $oldRowid));
            $tree->put($this->indexKey($index, $newValues, $newRowid));
        }
    }

    private function indexTree(\YetiDevWorks\YetiSQL\Engine\IndexInfo $index): \YetiDevWorks\YetiSQL\Engine\IndexBTree
    {
        return new \YetiDevWorks\YetiSQL\Engine\IndexBTree($this->db->pager(), $index->rootPage, $index->collations);
    }

    /**
     * Whether a row belongs in a (possibly partial) index: always true for a
     * full index; for a partial index, true only when its WHERE predicate holds
     * for the row. NULL/false predicate results mean the row is excluded.
     *
     * @param list<null|int|float|string|Blob> $logical
     */
    private function rowMatchesPartial(TableInfo $info, \YetiDevWorks\YetiSQL\Engine\IndexInfo $index, array $logical, int $rowid): bool
    {
        if ($index->partialWhere === null) {
            return true;
        }
        $env = new RowEnv();
        $env->addFrame($info->name, $info, $logical, $rowid);
        $v = (new Evaluator($this, []))->evaluate($index->partialWhere, $env);
        return $v !== null && Value::isTrue($v);
    }

    /**
     * The rowid of an existing row that collides with $logical on a UNIQUE
     * index, or null if none. A NULL in any indexed column means no conflict
     * (SQLite treats NULLs as distinct in UNIQUE). $excludeRowid skips the row
     * being updated.
     *
     * @param list<null|int|float|string|Blob> $logical
     */
    private function uniqueConflictRowid(\YetiDevWorks\YetiSQL\Engine\IndexInfo $index, array $logical, ?int $excludeRowid): ?int
    {
        $cols = [];
        foreach ($index->columnPositions as $pos) {
            $v = $logical[$pos] ?? null;
            if ($v === null) {
                return null;
            }
            $cols[] = $v;
        }
        $n = \count($cols);
        $tree = $this->indexTree($index);
        if ($excludeRowid === null) {
            $key = $tree->firstWithPrefix($cols);
            return $key !== null ? (int) $key[\count($key) - 1] : null;
        }
        foreach ($tree->scanFrom($cols) as $key) {
            for ($i = 0; $i < $n; $i++) {
                if (Value::compare($key[$i], $cols[$i], $index->collations[$i] ?? 'BINARY') !== 0) {
                    return null; // scanned past the block of matching keys
                }
            }
            $rid = (int) $key[\count($key) - 1];
            if ($rid === $excludeRowid) {
                continue;
            }
            return $rid;
        }
        return null;
    }

    /** "table.col" label for a rowid (INTEGER PRIMARY KEY) uniqueness violation. */
    private function rowidConflictLabel(TableInfo $info): string
    {
        $col = $info->hasRowidAlias() ? $info->columns[$info->rowidAlias]->name : 'rowid';
        return "{$info->name}.{$col}";
    }

    /** "table.col[, table.col]" label for a unique-index violation. */
    private function uniqueIndexLabel(TableInfo $info, \YetiDevWorks\YetiSQL\Engine\IndexInfo $index): string
    {
        $parts = [];
        foreach ($index->columnPositions as $pos) {
            $parts[] = "{$info->name}.{$info->columns[$pos]->name}";
        }
        return \implode(', ', $parts);
    }

    /**
     * @param list<null|int|float|string|Blob> $logicalValues
     * @return list<null|int|float|string|Blob>
     */
    private function indexKey(\YetiDevWorks\YetiSQL\Engine\IndexInfo $index, array $logicalValues, int $rowid): array
    {
        $key = [];
        foreach ($index->columnPositions as $pos) {
            $key[] = $logicalValues[$pos] ?? null;
        }
        $key[] = $rowid;
        return $key;
    }

    // === INSERT ==========================================================

    /** @param array<string,null|int|float|string|Blob> $params */
    private function execInsert(InsertStatement $stmt, array $params): Result
    {
        if ($this->db->schema()->hasView($stmt->table)) {
            return $this->execInsertView($stmt, $params);
        }
        $info = $this->requireTable($stmt->table);
        $tree = new TableBTree($this->db->pager(), $info->rootPage);
        $eval = new Evaluator($this, $params);

        // Resolve which columns the supplied values map to.
        $targetCols = $stmt->columns ?? \array_map(static fn ($c) => $c->name, $info->columns);

        $rowsData = [];
        if ($stmt->defaultValues) {
            $rowsData[] = [];
        } elseif ($stmt->select !== null) {
            foreach ($this->runSubquerySelect($stmt->select, $params) as $r) {
                $rowsData[] = $r;
            }
        } else {
            foreach ($stmt->rows as $exprRow) {
                $vals = [];
                foreach ($exprRow as $expr) {
                    $vals[] = $eval->evaluate($expr, null);
                }
                $rowsData[] = $vals;
            }
        }

        $fireTrig = $this->db->schema()->hasTriggersOn($info->name);
        $count = 0;
        $lastId = 0;
        $returnRows = [];
        foreach ($rowsData as $data) {
            $logical = null;
            $rowid = $this->insertOneRow($info, $tree, $targetCols, $data, $stmt, $eval, $fireTrig, $logical);
            if ($logical === null) {
                continue; // OR IGNORE skipped this row
            }
            $lastId = $rowid;
            $count++;
            if ($stmt->returning !== null) {
                $returnRows[] = $this->projectReturningRow($info, $stmt->returning, $logical, $rowid, $eval);
            }
        }
        if ($stmt->returning !== null) {
            return new Result(
                columns: $this->returningColumnNames($info, $stmt->returning),
                rows: $returnRows,
                rowCount: \count($returnRows),
                lastInsertId: $lastId,
            );
        }
        return Result::affected($count, $lastId);
    }

    /**
     * Output column names for a RETURNING clause.
     *
     * @param list<\YetiDevWorks\YetiSQL\Sql\Ast\ResultColumn> $returning
     * @return list<string>
     */
    private function returningColumnNames(TableInfo $info, array $returning): array
    {
        $names = [];
        foreach ($returning as $rc) {
            if ($rc->star || $rc->tableStar !== null) {
                foreach ($info->columns as $col) {
                    $names[] = $col->name;
                }
            } else {
                $names[] = $rc->alias ?? $this->exprLabel($rc->expr);
            }
        }
        return $names;
    }

    /**
     * Project one affected row through a RETURNING clause. $logical is the row's
     * full column vector (rowid-alias slot already set to $rowid).
     *
     * @param list<\YetiDevWorks\YetiSQL\Sql\Ast\ResultColumn> $returning
     * @param list<null|int|float|string|Blob> $logical
     * @return list<null|int|float|string|Blob>
     */
    private function projectReturningRow(TableInfo $info, array $returning, array $logical, int $rowid, Evaluator $eval): array
    {
        $env = new RowEnv();
        $env->addFrame($info->name, $info, $logical, $rowid);
        $row = [];
        foreach ($returning as $rc) {
            if ($rc->star || $rc->tableStar !== null) {
                foreach ($info->columns as $pos => $col) {
                    $row[] = $logical[$pos] ?? null;
                }
            } else {
                $row[] = $eval->evaluate($rc->expr, $env);
            }
        }
        return $row;
    }

    /**
     * @param list<string> $targetCols
     * @param list<null|int|float|string|Blob> $data
     */
    private function insertOneRow(TableInfo $info, TableBTree $tree, array $targetCols, array $data, InsertStatement $stmt, Evaluator $eval, bool $fireTrig = false, ?array &$logicalOut = null): int
    {
        $logicalOut = null;
        // Map supplied values onto a full column vector.
        $byPos = \array_fill(0, $info->columnCount(), null);
        $provided = \array_fill(0, $info->columnCount(), false);

        if (!$stmt->defaultValues) {
            foreach ($targetCols as $k => $colName) {
                $pos = $info->columnPos($colName);
                if ($pos === null) {
                    throw new SqlException("table {$info->name} has no column named $colName");
                }
                if ($info->columns[$pos]->generated !== null) {
                    throw SqlException::constraint("cannot INSERT into generated column \"{$colName}\"");
                }
                $byPos[$pos] = $data[$k] ?? null;
                $provided[$pos] = true;
            }
        }

        // Determine rowid.
        $rowid = null;
        if ($info->hasRowidAlias() && $provided[$info->rowidAlias] && $byPos[$info->rowidAlias] !== null) {
            $rowid = (int) Value::toNumber($byPos[$info->rowidAlias]);
        }
        if ($rowid === null) {
            $rowid = $tree->maxRowid() + 1;
            if ($rowid <= 0) {
                $rowid = 1;
            }
        }

        // Apply defaults / NOT NULL checks / affinity per column. Generated
        // columns are filled afterwards from the completed row.
        $record = [];
        foreach ($info->columns as $pos => $col) {
            if ($col->generated !== null) {
                $record[$pos] = null;
                continue;
            }
            $value = $byPos[$pos];
            if (!$provided[$pos] || ($value === null && !$provided[$pos])) {
                if ($col->default !== null) {
                    $value = $eval->evaluate($col->default, null);
                }
            }
            if ($pos === $info->rowidAlias) {
                // Stored as NULL; the rowid key carries the value.
                $record[$pos] = null;
                continue;
            }
            $value = $col->affinity->apply($value);
            if ($value === null && $col->notNull && $col->default === null) {
                throw SqlException::constraint("NOT NULL constraint failed: {$info->name}.{$col->name}");
            }
            $record[$pos] = $value;
        }

        $logical = $record;
        if ($info->hasRowidAlias()) {
            $logical[$info->rowidAlias] = $rowid;
        }
        if ($info->generatedPositions !== []) {
            $this->applyGeneratedColumns($info, $logical, $rowid, $eval);
        }

        $payload = RecordCodec::encode($this->buildRecord($info, $logical));
        $logical = \array_values($logical);

        // ON CONFLICT upsert: if the proposed row collides with the targeted
        // uniqueness constraint, resolve it here — DO NOTHING skips the insert,
        // DO UPDATE rewrites the existing row — instead of attempting the insert.
        if ($stmt->upsert !== null) {
            $conflictRowid = $this->findUpsertConflict($info, $tree, $logical, $rowid, $stmt->upsert);
            if ($conflictRowid !== null) {
                if ($stmt->upsert->doNothing) {
                    return $this->db->lastInsertId(); // logicalOut stays null -> 0 rows affected
                }
                return $this->applyUpsertUpdate($info, $tree, $conflictRowid, $logical, $rowid, $stmt->upsert, $eval, $fireTrig, $logicalOut);
            }
        }

        if ($fireTrig) {
            $this->fireTriggers($info, CreateTriggerStatement::INSERT, CreateTriggerStatement::BEFORE, null, $logical, $rowid, $rowid);
        }

        if ($info->checks !== []) {
            $violation = $this->checkRowViolation($info, $logical, $rowid, $eval);
            if ($violation !== null) {
                if ($stmt->orIgnore) {
                    return $this->db->lastInsertId();
                }
                throw SqlException::constraint($this->checkFailureMessage($violation));
            }
        }

        $uniqueIndexes = [];
        foreach ($this->db->schema()->resolvedIndexes($info->name) as $index) {
            if ($index->unique) {
                $uniqueIndexes[] = $index;
            }
        }

        if ($uniqueIndexes === []) {
            // Fast path: the rowid is the only uniqueness constraint, so a single
            // insert descent (putIfAbsent) enforces it without an extra probe.
            if ($stmt->orReplace) {
                if ($tree->exists($rowid)) {
                    $old = $this->decodeRow($info, $rowid, (string) $tree->get($rowid));
                    $this->deleteIndexEntries($info, $rowid, $old);
                    $tree->delete($rowid);
                }
                $tree->put($rowid, $payload);
            } elseif (!$tree->putIfAbsent($rowid, $payload)) {
                if ($stmt->orIgnore) {
                    return $this->db->lastInsertId();
                }
                throw SqlException::constraint("UNIQUE constraint failed: {$this->rowidConflictLabel($info)}");
            }
        } else {
            // A table with UNIQUE/PRIMARY KEY indexes: collect every conflicting
            // existing row (rowid PK plus each unique index) and apply the
            // INSERT / REPLACE / IGNORE conflict resolution.
            $conflicts = [];
            if ($tree->exists($rowid)) {
                $conflicts[] = ['rowid' => $rowid, 'label' => $this->rowidConflictLabel($info)];
            }
            foreach ($uniqueIndexes as $index) {
                // A partial unique index only constrains rows matching its
                // predicate; a new row outside it is never stored, so no conflict.
                if (!$this->rowMatchesPartial($info, $index, $logical, $rowid)) {
                    continue;
                }
                $rid = $this->uniqueConflictRowid($index, $logical, null);
                if ($rid !== null) {
                    $conflicts[] = ['rowid' => $rid, 'label' => $this->uniqueIndexLabel($info, $index)];
                }
            }

            if ($conflicts !== []) {
                if ($stmt->orIgnore) {
                    return $this->db->lastInsertId();
                }
                if (!$stmt->orReplace) {
                    throw SqlException::constraint('UNIQUE constraint failed: ' . $conflicts[0]['label']);
                }
                $deleted = [];
                foreach ($conflicts as $c) {
                    $rid = $c['rowid'];
                    if (isset($deleted[$rid]) || !$tree->exists($rid)) {
                        continue;
                    }
                    $deleted[$rid] = true;
                    $old = $this->decodeRow($info, $rid, (string) $tree->get($rid));
                    $this->deleteIndexEntries($info, $rid, $old);
                    $tree->delete($rid);
                }
            }
            $tree->put($rowid, $payload);
        }

        $this->insertIndexEntries($info, $rowid, $logical);

        if ($info->foreignKeys !== [] && $this->db->foreignKeysEnabled()) {
            $this->fkCheckChildRow($info, $logical);
        }

        if ($fireTrig) {
            $this->fireTriggers($info, CreateTriggerStatement::INSERT, CreateTriggerStatement::AFTER, null, $logical, $rowid, $rowid);
        }
        $logicalOut = $logical;
        return $rowid;
    }

    /**
     * Locate the existing row a proposed INSERT collides with for an ON CONFLICT
     * clause, honouring the conflict target when one is given. Returns the
     * conflicting rowid, or null when the row can be inserted normally.
     *
     * @param list<null|int|float|string|Blob> $logical
     */
    private function findUpsertConflict(TableInfo $info, TableBTree $tree, array $logical, int $rowid, \YetiDevWorks\YetiSQL\Sql\Ast\UpsertClause $upsert): ?int
    {
        $target = $upsert->target !== null ? \array_map('strtolower', $upsert->target) : null;

        // The integer-primary-key (rowid) constraint: it satisfies an untargeted
        // conflict, or a target naming exactly the rowid-alias column.
        $pkIsTarget = $target !== null
            && $info->hasRowidAlias()
            && \count($target) === 1
            && $target[0] === \strtolower($info->columns[$info->rowidAlias]->name);
        if (($target === null || $pkIsTarget) && $tree->exists($rowid)) {
            return $rowid;
        }

        foreach ($this->db->schema()->resolvedIndexes($info->name) as $index) {
            if (!$index->unique) {
                continue;
            }
            if ($target !== null && !$this->indexMatchesTarget($index, $target, $info)) {
                continue;
            }
            if (!$this->rowMatchesPartial($info, $index, $logical, $rowid)) {
                continue;
            }
            $rid = $this->uniqueConflictRowid($index, $logical, null);
            if ($rid !== null) {
                return $rid;
            }
        }
        return null;
    }

    /**
     * True when a unique index covers exactly the conflict-target columns (order
     * independent, as SQLite matches the target to an index by its column set).
     *
     * @param list<string> $target lower-cased target column names
     */
    private function indexMatchesTarget(\YetiDevWorks\YetiSQL\Engine\IndexInfo $index, array $target, TableInfo $info): bool
    {
        $cols = \array_map(static fn (int $p): string => \strtolower($info->columns[$p]->name), $index->columnPositions);
        \sort($cols);
        $want = $target;
        \sort($want);
        return $cols === $want;
    }

    /**
     * Run an ON CONFLICT ... DO UPDATE against the conflicting row $conflictRowid.
     * The SET assignments and optional WHERE see the existing row by the table's
     * name and the would-be-inserted row through the "excluded" pseudo-table.
     * Mirrors execUpdate()'s per-row write path (checks, uniqueness, index/FK
     * maintenance, UPDATE triggers).
     *
     * @param list<null|int|float|string|Blob> $proposed
     */
    private function applyUpsertUpdate(TableInfo $info, TableBTree $tree, int $conflictRowid, array $proposed, int $proposedRowid, \YetiDevWorks\YetiSQL\Sql\Ast\UpsertClause $upsert, Evaluator $eval, bool $fireTrig, ?array &$logicalOut): int
    {
        $existing = $this->decodeRow($info, $conflictRowid, (string) $tree->get($conflictRowid));

        $env = new RowEnv();
        $env->addFrame($info->name, $info, $existing, $conflictRowid);
        $env->addAliasFrame('excluded', $info, $proposed, $proposedRowid);

        // DO UPDATE ... WHERE <predicate>: when false, the row is left untouched
        // (no error, no rows affected), as in SQLite.
        if ($upsert->updateWhere !== null && !Value::isTrue((int) ($eval->evaluate($upsert->updateWhere, $env) ?? 0))) {
            $logicalOut = null;
            return $this->db->lastInsertId();
        }

        $newValues = $existing;
        $newRowid = $conflictRowid;
        $changed = [];
        foreach ($upsert->set as [$colName, $expr]) {
            $pos = $info->columnPos($colName);
            if ($pos === null) {
                throw new SqlException("no such column: $colName");
            }
            if ($info->columns[$pos]->generated !== null) {
                throw SqlException::constraint("cannot UPDATE generated column \"{$colName}\"");
            }
            $v = $eval->evaluate($expr, $env);
            if ($pos === $info->rowidAlias) {
                $newRowid = (int) Value::toNumber($v);
                $newValues[$pos] = $newRowid;
            } else {
                $newValues[$pos] = $info->columns[$pos]->affinity->apply($v);
                if ($newValues[$pos] === null && $info->columns[$pos]->notNull) {
                    throw SqlException::constraint("NOT NULL constraint failed: {$info->name}.{$colName}");
                }
            }
            $changed[$pos] = true;
        }

        if ($info->generatedPositions !== []) {
            $this->applyGeneratedColumns($info, $newValues, $newRowid, $eval);
            foreach ($info->generatedPositions as $gp) {
                $changed[$gp] = true;
            }
        }

        if ($fireTrig) {
            $this->fireTriggers($info, CreateTriggerStatement::UPDATE, CreateTriggerStatement::BEFORE, $existing, $newValues, $conflictRowid, $newRowid, $changed);
        }

        if ($info->checks !== []) {
            $violation = $this->checkRowViolation($info, $newValues, $newRowid, $eval);
            if ($violation !== null) {
                throw SqlException::constraint($this->checkFailureMessage($violation));
            }
        }

        // Enforce UNIQUE / PRIMARY KEY against other rows (the conflicting row's
        // own key is excluded).
        $conflicts = [];
        if ($newRowid !== $conflictRowid && $tree->exists($newRowid)) {
            $conflicts[] = $this->rowidConflictLabel($info);
        }
        foreach ($this->db->schema()->resolvedIndexes($info->name) as $index) {
            if (!$index->unique) {
                continue;
            }
            if ($index->partialWhere !== null && !$this->rowMatchesPartial($info, $index, $newValues, $newRowid)) {
                continue;
            }
            $rid = $this->uniqueConflictRowid($index, $newValues, $conflictRowid);
            if ($rid !== null) {
                $conflicts[] = $this->uniqueIndexLabel($info, $index);
            }
        }
        if ($conflicts !== []) {
            throw SqlException::constraint('UNIQUE constraint failed: ' . $conflicts[0]);
        }

        $record = $this->buildRecord($info, $newValues);
        if ($newRowid !== $conflictRowid) {
            $tree->delete($conflictRowid);
        }
        $tree->put($newRowid, RecordCodec::encode($record));
        $this->maintainIndexesForUpdate($info, $conflictRowid, $existing, $newRowid, $newValues, $changed);

        if ($this->db->foreignKeysEnabled()) {
            $this->fkBeforeParentChange($info, $existing, $conflictRowid, $newValues, $newRowid);
            if ($info->foreignKeys !== []) {
                $this->fkCheckChildRow($info, $newValues);
            }
        }

        if ($fireTrig) {
            $this->fireTriggers($info, CreateTriggerStatement::UPDATE, CreateTriggerStatement::AFTER, $existing, $newValues, $conflictRowid, $newRowid, $changed);
        }

        $logicalOut = $newValues;
        return $newRowid;
    }

    // === UPDATE ==========================================================

    /** @param array<string,null|int|float|string|Blob> $params */
    private function execUpdate(UpdateStatement $stmt, array $params): Result
    {
        $this->fkCascadeChildCache = [];
        if ($this->db->schema()->hasView($stmt->table)) {
            return $this->execModifyView($stmt->table, CreateTriggerStatement::UPDATE, $stmt->where, $stmt->set, $params);
        }
        $info = $this->requireTable($stmt->table);
        $tree = new TableBTree($this->db->pager(), $info->rootPage);
        $eval = new Evaluator($this, $params);

        // Collect matching rowids first to avoid mutating during iteration.
        $targets = [];
        $plan = $stmt->where !== null ? $this->bestPlan($stmt->where, $info->name, $info, $eval) : null;
        $whereCovered = $plan !== null && ($plan['coveredAll'] ?? false);
        foreach ($this->scanRowids($info, $info->name, $stmt->where, $eval, $plan) as [$rowid, $payload]) {
            $values = $this->decodeRow($info, $rowid, $payload);
            if ($stmt->where !== null && !$whereCovered) {
                $env = new RowEnv();
                $env->addFrame($info->name, $info, $values, $rowid);
                if (!Value::isTrue((int) ($eval->evaluate($stmt->where, $env) ?? 0))) {
                    continue;
                }
            }
            $targets[] = [$rowid, $values];
        }

        $fireTrig = $this->db->schema()->hasTriggersOn($info->name);
        $count = 0;
        $returnRows = [];
        foreach ($targets as [$rowid, $values]) {
            $env = new RowEnv();
            $env->addFrame($info->name, $info, $values, $rowid);

            $newValues = $values;
            $newRowid = $rowid;
            $changed = [];
            foreach ($stmt->set as [$colName, $expr]) {
                $pos = $info->columnPos($colName);
                if ($pos === null) {
                    throw new SqlException("no such column: $colName");
                }
                if ($info->columns[$pos]->generated !== null) {
                    throw SqlException::constraint("cannot UPDATE generated column \"{$colName}\"");
                }
                $v = $eval->evaluate($expr, $env);
                if ($pos === $info->rowidAlias) {
                    $newRowid = (int) Value::toNumber($v);
                    $newValues[$pos] = $newRowid;
                } else {
                    $newValues[$pos] = $info->columns[$pos]->affinity->apply($v);
                    if ($newValues[$pos] === null && $info->columns[$pos]->notNull) {
                        throw SqlException::constraint("NOT NULL constraint failed: {$info->name}.{$colName}");
                    }
                }
                $changed[$pos] = true;
            }

            // Recompute generated columns from the updated row, and mark them
            // changed so dependent indexes are maintained.
            if ($info->generatedPositions !== []) {
                $this->applyGeneratedColumns($info, $newValues, $newRowid, $eval);
                foreach ($info->generatedPositions as $gp) {
                    $changed[$gp] = true;
                }
            }

            if ($fireTrig) {
                $this->fireTriggers($info, CreateTriggerStatement::UPDATE, CreateTriggerStatement::BEFORE, $values, $newValues, $rowid, $newRowid, $changed);
            }

            if ($info->checks !== []) {
                $violation = $this->checkRowViolation($info, $newValues, $newRowid, $eval);
                if ($violation !== null) {
                    if ($stmt->orIgnore) {
                        continue; // leave this row unchanged
                    }
                    throw SqlException::constraint($this->checkFailureMessage($violation));
                }
            }

            // Enforce UNIQUE / PRIMARY KEY against other rows (the row's own old
            // key is excluded). Honour UPDATE OR REPLACE / OR IGNORE.
            $conflicts = [];
            if ($newRowid !== $rowid && $tree->exists($newRowid)) {
                $conflicts[] = ['rowid' => $newRowid, 'label' => $this->rowidConflictLabel($info)];
            }
            foreach ($this->db->schema()->resolvedIndexes($info->name) as $index) {
                if (!$index->unique) {
                    continue;
                }
                if ($index->partialWhere !== null) {
                    // Only a new row inside the predicate is stored, hence can conflict.
                    if (!$this->rowMatchesPartial($info, $index, $newValues, $newRowid)) {
                        continue;
                    }
                    $rid = $this->uniqueConflictRowid($index, $newValues, $rowid);
                    if ($rid !== null) {
                        $conflicts[] = ['rowid' => $rid, 'label' => $this->uniqueIndexLabel($info, $index)];
                    }
                    continue;
                }
                $touched = $newRowid !== $rowid;
                foreach ($index->columnPositions as $p) {
                    if (isset($changed[$p])) {
                        $touched = true;
                        break;
                    }
                }
                if (!$touched) {
                    continue;
                }
                $rid = $this->uniqueConflictRowid($index, $newValues, $rowid);
                if ($rid !== null) {
                    $conflicts[] = ['rowid' => $rid, 'label' => $this->uniqueIndexLabel($info, $index)];
                }
            }
            if ($conflicts !== []) {
                if ($stmt->orIgnore) {
                    continue; // leave this row unchanged
                }
                if (!$stmt->orReplace) {
                    throw SqlException::constraint('UNIQUE constraint failed: ' . $conflicts[0]['label']);
                }
                $deleted = [];
                foreach ($conflicts as $c) {
                    $rid = $c['rowid'];
                    if ($rid === $rowid || isset($deleted[$rid]) || !$tree->exists($rid)) {
                        continue;
                    }
                    $deleted[$rid] = true;
                    $old = $this->decodeRow($info, $rid, (string) $tree->get($rid));
                    $this->deleteIndexEntries($info, $rid, $old);
                    $tree->delete($rid);
                }
            }

            $record = $this->buildRecord($info, $newValues);
            if ($newRowid !== $rowid) {
                $tree->delete($rowid);
            }
            $tree->put($newRowid, RecordCodec::encode($record));
            // Maintain only indexes whose columns (or the rowid) actually changed.
            $this->maintainIndexesForUpdate($info, $rowid, $values, $newRowid, $newValues, $changed);

            if ($this->db->foreignKeysEnabled()) {
                // Parent-side: with the new key now stored, cascade ON UPDATE
                // actions to children that still hold the pre-update key.
                $this->fkBeforeParentChange($info, $values, $rowid, $newValues, $newRowid);
                // Child-side: the rewritten row's own foreign keys must resolve.
                if ($info->foreignKeys !== []) {
                    $this->fkCheckChildRow($info, $newValues);
                }
            }

            if ($fireTrig) {
                $this->fireTriggers($info, CreateTriggerStatement::UPDATE, CreateTriggerStatement::AFTER, $values, $newValues, $rowid, $newRowid, $changed);
            }
            $count++;
            if ($stmt->returning !== null) {
                $returnRows[] = $this->projectReturningRow($info, $stmt->returning, $newValues, $newRowid, $eval);
            }
        }
        if ($stmt->returning !== null) {
            return new Result(
                columns: $this->returningColumnNames($info, $stmt->returning),
                rows: $returnRows,
                rowCount: \count($returnRows),
            );
        }
        return Result::affected($count);
    }

    // === DELETE ==========================================================

    /** @param array<string,null|int|float|string|Blob> $params */
    private function execDelete(DeleteStatement $stmt, array $params): Result
    {
        $this->fkCascadeChildCache = [];
        if ($this->db->schema()->hasView($stmt->table)) {
            return $this->execModifyView($stmt->table, CreateTriggerStatement::DELETE, $stmt->where, null, $params);
        }
        $info = $this->requireTable($stmt->table);
        $tree = new TableBTree($this->db->pager(), $info->rootPage);
        $eval = new Evaluator($this, $params);

        $targets = [];
        $plan = $stmt->where !== null ? $this->bestPlan($stmt->where, $info->name, $info, $eval) : null;
        $whereCovered = $plan !== null && ($plan['coveredAll'] ?? false);
        foreach ($this->scanRowids($info, $info->name, $stmt->where, $eval, $plan) as [$rowid, $payload]) {
            $values = $this->decodeRow($info, $rowid, $payload);
            if ($stmt->where !== null && !$whereCovered) {
                $env = new RowEnv();
                $env->addFrame($info->name, $info, $values, $rowid);
                if (!Value::isTrue((int) ($eval->evaluate($stmt->where, $env) ?? 0))) {
                    continue;
                }
            }
            $targets[] = [$rowid, $values];
        }
        $fireTrig = $this->db->schema()->hasTriggersOn($info->name);
        $returnRows = [];
        foreach ($targets as [$rowid, $values]) {
            if ($fireTrig) {
                $this->fireTriggers($info, CreateTriggerStatement::DELETE, CreateTriggerStatement::BEFORE, $values, null, $rowid, $rowid);
            }
            // Parent-side: apply ON DELETE actions to referencing children
            // (RESTRICT / NO ACTION raise here) before the row is removed.
            if ($this->db->foreignKeysEnabled()) {
                $this->fkBeforeParentChange($info, $values, $rowid, null, null);
            }
            $this->deleteIndexEntries($info, $rowid, $values);
            $tree->delete($rowid);
            if ($fireTrig) {
                $this->fireTriggers($info, CreateTriggerStatement::DELETE, CreateTriggerStatement::AFTER, $values, null, $rowid, $rowid);
            }
            if ($stmt->returning !== null) {
                $returnRows[] = $this->projectReturningRow($info, $stmt->returning, $values, $rowid, $eval);
            }
        }
        if ($stmt->returning !== null) {
            return new Result(
                columns: $this->returningColumnNames($info, $stmt->returning),
                rows: $returnRows,
                rowCount: \count($returnRows),
            );
        }
        return Result::affected(\count($targets));
    }

    /**
     * Fill every GENERATED column of $logical (the full row vector, rowid slot
     * set) by evaluating its expression against the row's other values. A
     * fixpoint pass resolves generated columns that reference one another.
     *
     * @param list<null|int|float|string|Blob> $logical
     */
    private function applyGeneratedColumns(TableInfo $info, array &$logical, int $rowid, Evaluator $eval): void
    {
        $plan = $this->generatedColumnPlan($info, $eval);

        if (!$plan['dependsOnGenerated']) {
            $env = new RowEnv();
            $env->addFrame($info->name, $info, $logical, $rowid);
            foreach ($info->generatedPositions as $pos) {
                $col = $info->columns[$pos];
                $program = $plan['programs'][$pos] ?? null;
                $value = $program !== null ? $program($env, $eval) : $eval->evaluate($col->generated, $env);
                $logical[$pos] = $col->affinity->apply($value);
            }
            return;
        }

        $passes = \count($info->generatedPositions) + 1;
        for ($pass = 0; $pass < $passes; $pass++) {
            $env = new RowEnv();
            $env->addFrame($info->name, $info, $logical, $rowid);
            $changed = false;
            foreach ($info->generatedPositions as $pos) {
                $col = $info->columns[$pos];
                $program = $plan['programs'][$pos] ?? null;
                $value = $program !== null ? $program($env, $eval) : $eval->evaluate($col->generated, $env);
                $value = $col->affinity->apply($value);
                if (($logical[$pos] ?? null) !== $value) {
                    $changed = true;
                }
                $logical[$pos] = $value;
            }
            if (!$changed) {
                break;
            }
        }
    }

    /** @return array{dependsOnGenerated:bool,programs:array<int,?\Closure>} */
    private function generatedColumnPlan(TableInfo $info, Evaluator $eval): array
    {
        if (isset($this->generatedColumnPlans[$info])) {
            return $this->generatedColumnPlans[$info];
        }

        $dependsOnGenerated = false;
        $programs = [];
        foreach ($info->generatedPositions as $pos) {
            $expr = $info->columns[$pos]->generated;
            if ($expr === null) {
                continue;
            }
            if ($this->exprReferencesGeneratedColumn($expr, $info)) {
                $dependsOnGenerated = true;
            }
            $programs[$pos] = $eval->compile($expr, $info, $info->name);
        }

        return $this->generatedColumnPlans[$info] = [
            'dependsOnGenerated' => $dependsOnGenerated,
            'programs' => $programs,
        ];
    }

    private function exprReferencesGeneratedColumn(Expr $expr, TableInfo $info): bool
    {
        if ($expr->kind === Expr::COL) {
            if ($expr->table !== null && \strcasecmp($expr->table, $info->name) !== 0) {
                return false;
            }
            $pos = $info->columnPos((string) $expr->name);
            return $pos !== null && $info->columns[$pos]->generated !== null;
        }

        foreach ([$expr->left, $expr->right, $expr->operand, $expr->subject, $expr->elseExpr, $expr->low, $expr->high, $expr->escape] as $child) {
            if ($child instanceof Expr && $this->exprReferencesGeneratedColumn($child, $info)) {
                return true;
            }
        }
        foreach ($expr->args as $child) {
            if ($this->exprReferencesGeneratedColumn($child, $info)) {
                return true;
            }
        }
        foreach ($expr->list as $child) {
            if ($this->exprReferencesGeneratedColumn($child, $info)) {
                return true;
            }
        }
        foreach ($expr->whens as [$when, $then]) {
            if ($this->exprReferencesGeneratedColumn($when, $info) || $this->exprReferencesGeneratedColumn($then, $info)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<null|int|float|string|Blob> */
    private function buildRecord(TableInfo $info, array $values): array
    {
        $record = [];
        foreach ($info->columns as $pos => $col) {
            $record[$pos] = ($pos === $info->rowidAlias) ? null : ($values[$pos] ?? null);
        }
        return \array_values($record);
    }

    // === FOREIGN KEY enforcement =========================================

    /**
     * Resolve the parent-table column positions a foreign key targets. An empty
     * refColumns list means the parent's PRIMARY KEY.
     *
     * @return list<int>
     */
    private function fkParentPositions(TableInfo $parent, ForeignKey $fk): array
    {
        if ($fk->refColumns !== []) {
            $positions = [];
            foreach ($fk->refColumns as $name) {
                $pos = $parent->columnPos($name);
                if ($pos === null) {
                    throw SqlException::constraint(
                        "foreign key mismatch - \"{$fk->refTable}\" referencing \"{$parent->name}\"",
                    );
                }
                $positions[] = $pos;
            }
            return $positions;
        }
        if ($parent->rowidAlias >= 0) {
            return [$parent->rowidAlias];
        }
        $positions = [];
        foreach ($parent->columns as $i => $col) {
            if ($col->primaryKey) {
                $positions[] = $i;
            }
        }
        if ($positions === []) {
            throw SqlException::constraint(
                "foreign key mismatch - \"{$fk->refTable}\" referencing \"{$parent->name}\"",
            );
        }
        return $positions;
    }

    /**
     * The unique index on exactly the given column positions (in order), or null.
     */
    private function fkIndexOnPositions(TableInfo $info, array $positions): ?IndexInfo
    {
        foreach ($this->db->schema()->resolvedIndexes($info->name) as $index) {
            if ($index->columnPositions === $positions) {
                return $index;
            }
        }
        return null;
    }

    /**
     * Whether the parent table has a row whose referenced columns equal the
     * given child values (already extracted, none null). Values are coerced to
     * the parent column affinity, as SQLite compares them.
     *
     * @param list<int>                          $refPositions
     * @param list<null|int|float|string|Blob>   $childVals
     */
    private function fkParentKeyExists(TableInfo $parent, array $refPositions, array $childVals, ?IndexInfo $index): bool
    {
        $key = [];
        foreach ($refPositions as $k => $pos) {
            $key[] = $parent->columns[$pos]->affinity->apply($childVals[$k]);
        }

        if (\count($refPositions) === 1 && $refPositions[0] === $parent->rowidAlias) {
            // INTEGER PRIMARY KEY: the value must be integer-valued to match a rowid.
            if (!\is_int($key[0])) {
                return false;
            }
            $tree = new TableBTree($this->db->pager(), $parent->rootPage);
            return $tree->exists($key[0]);
        }

        if ($index !== null) {
            foreach ($this->indexTree($index)->scanFrom($key) as $entry) {
                foreach ($key as $i => $kv) {
                    if (Value::compare($entry[$i], $kv, $index->collations[$i] ?? 'BINARY') !== 0) {
                        return false; // scanned past the matching block
                    }
                }
                return true;
            }
            return false;
        }

        // No backing index: fall back to a full parent scan (correctness over speed).
        $tree = new TableBTree($this->db->pager(), $parent->rootPage);
        foreach ($tree->scan() as [$rowid, $payload]) {
            $vals = $this->decodeRow($parent, $rowid, $payload);
            $match = true;
            foreach ($refPositions as $k => $pos) {
                $pv = $pos === $parent->rowidAlias ? $rowid : ($vals[$pos] ?? null);
                if ($pv === null
                    || Value::compare($pv, $key[$k], $parent->columns[$pos]->collation) !== 0) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return true;
            }
        }
        return false;
    }

    /**
     * Child-side check: every foreign key on $childInfo must point at an
     * existing parent row. A NULL in any child key column means the constraint
     * is satisfied (MATCH SIMPLE). Call AFTER the child row is written so a
     * self-referential key sees itself.
     *
     * @param list<null|int|float|string|Blob> $logical full child row vector
     */
    /**
     * Evaluate the table's CHECK constraints against a row's logical values.
     * Returns the first violated constraint, or null when all pass — NULL and
     * true both satisfy a CHECK (only an explicit false fails), per SQLite.
     *
     * @param list<null|int|float|string|Blob> $logical
     */
    private function checkRowViolation(TableInfo $info, array $logical, int $rowid, Evaluator $eval): ?CheckConstraint
    {
        if ($info->checks === []) {
            return null;
        }
        $env = new RowEnv();
        $env->addFrame($info->name, $info, $logical, $rowid);
        foreach ($info->checks as $check) {
            $v = $eval->evaluate($check->expr, $env);
            if ($v !== null && !Value::isTrue($v)) {
                return $check;
            }
        }
        return null;
    }

    private function checkFailureMessage(CheckConstraint $check): string
    {
        return 'CHECK constraint failed: ' . ($check->name ?? $check->sql);
    }

    private function fkCheckChildRow(TableInfo $childInfo, array $logical): void
    {
        foreach ($this->fkChildCheckPlan($childInfo) as $plan) {
            $childVals = [];
            $anyNull = false;
            foreach ($plan['childPositions'] as $pos) {
                $v = $logical[$pos] ?? null;
                if ($v === null) {
                    $anyNull = true;
                    break;
                }
                $childVals[] = $v;
            }
            if ($anyNull) {
                continue;
            }
            if (!$this->fkParentKeyExists($plan['parent'], $plan['refPositions'], $childVals, $plan['index'])) {
                throw SqlException::constraint('FOREIGN KEY constraint failed');
            }
        }
    }

    /** @return list<array{childPositions:list<int>,parent:TableInfo,refPositions:list<int>,index:?IndexInfo}> */
    private function fkChildCheckPlan(TableInfo $childInfo): array
    {
        $cookie = $this->db->pager()->schemaCookie();
        $cached = $this->fkChildCheckPlans[$childInfo] ?? null;
        if ($cached !== null && $cached['cookie'] === $cookie) {
            return $cached['plans'];
        }

        $plans = [];
        foreach ($childInfo->foreignKeys as $fk) {
            $childPositions = [];
            foreach ($fk->columns as $cname) {
                $pos = $childInfo->columnPos($cname);
                if ($pos === null) {
                    throw SqlException::constraint(
                        "foreign key mismatch - \"{$childInfo->name}\" referencing \"{$fk->refTable}\"",
                    );
                }
                $childPositions[] = $pos;
            }
            $parent = $this->db->schema()->getTable($fk->refTable);
            if ($parent === null) {
                throw SqlException::constraint(
                    "foreign key mismatch - \"{$childInfo->name}\" referencing \"{$fk->refTable}\"",
                );
            }
            $refPositions = $this->fkParentPositions($parent, $fk);
            if (\count($refPositions) !== \count($childPositions)) {
                throw SqlException::constraint(
                    "foreign key mismatch - \"{$childInfo->name}\" referencing \"{$fk->refTable}\"",
                );
            }
            $plans[] = [
                'childPositions' => $childPositions,
                'parent' => $parent,
                'refPositions' => $refPositions,
                'index' => $this->fkIndexOnPositions($parent, $refPositions),
            ];
        }

        $this->fkChildCheckPlans[$childInfo] = ['cookie' => $cookie, 'plans' => $plans];
        return $plans;
    }

    /**
     * Every (childTable, foreignKey) that references $parent.
     *
     * @return list<array{child:TableInfo,fk:ForeignKey}>
     */
    private function fkReferencing(TableInfo $parent): array
    {
        $out = [];
        $target = \strtolower($parent->name);
        foreach ($this->db->schema()->tableNames() as $tableName) {
            $child = $this->db->schema()->getTable($tableName);
            if ($child === null) {
                continue;
            }
            foreach ($child->foreignKeys as $fk) {
                if (\strtolower($fk->refTable) === $target) {
                    $out[] = ['child' => $child, 'fk' => $fk];
                }
            }
        }
        return $out;
    }

    /**
     * Child rows whose foreign-key columns equal $parentKey (none of which is
     * null). Returns each as [rowid, full values].
     *
     * @param list<null|int|float|string|Blob> $parentKey
     * @return list<array{rowid:int,values:list<null|int|float|string|Blob>}>
     */
    private function fkChildRowsReferencing(TableInfo $child, ForeignKey $fk, array $parentKey, bool $allowCascadeCache = false): array
    {
        $positions = [];
        foreach ($fk->columns as $cname) {
            $pos = $child->columnPos($cname);
            if ($pos === null) {
                return [];
            }
            $positions[] = $pos;
        }
        $key = [];
        foreach ($positions as $k => $pos) {
            $key[] = $child->columns[$pos]->affinity->apply($parentKey[$k]);
        }

        $tree = new TableBTree($this->db->pager(), $child->rootPage);
        $matches = [];

        $index = $this->fkIndexOnPositions($child, $positions);
        if ($index !== null) {
            foreach ($this->indexTree($index)->scanFrom($key) as $entry) {
                foreach ($key as $i => $kv) {
                    if (Value::compare($entry[$i], $kv, $index->collations[$i] ?? 'BINARY') !== 0) {
                        return $matches; // past the matching block
                    }
                }
                $rid = (int) $entry[\count($entry) - 1];
                $matches[] = ['rowid' => $rid, 'values' => $this->decodeRow($child, $rid, (string) $tree->get($rid))];
            }
            return $matches;
        }

        if ($allowCascadeCache && $this->fkCanCacheChildScan($child, $positions)) {
            $cacheKey = $child->rootPage . ':' . \implode(',', $positions);
            if (!isset($this->fkCascadeChildCache[$cacheKey])) {
                $this->fkCascadeChildCache[$cacheKey] = $this->buildFkChildReferenceMap($child, $positions);
            }
            return $this->fkCascadeChildCache[$cacheKey][$this->fkChildKey($key)] ?? [];
        }

        foreach ($tree->scan() as [$rowid, $payload]) {
            $vals = $this->decodeRow($child, $rowid, $payload);
            $match = true;
            foreach ($positions as $k => $pos) {
                $cv = $pos === $child->rowidAlias ? $rowid : ($vals[$pos] ?? null);
                if ($cv === null || Value::compare($cv, $key[$k], $child->columns[$pos]->collation) !== 0) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $matches[] = ['rowid' => $rowid, 'values' => $vals];
            }
        }
        return $matches;
    }

    /** @param list<int> $positions */
    private function fkCanCacheChildScan(TableInfo $child, array $positions): bool
    {
        foreach ($positions as $pos) {
            if (($child->columns[$pos]->collation ?? 'BINARY') !== 'BINARY') {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<int> $positions
     * @return array<string,list<array{rowid:int,values:list<null|int|float|string|Blob>}>>
     */
    private function buildFkChildReferenceMap(TableInfo $child, array $positions): array
    {
        $map = [];
        $tree = new TableBTree($this->db->pager(), $child->rootPage);
        foreach ($tree->scan() as [$rowid, $payload]) {
            $vals = $this->decodeRow($child, $rowid, $payload);
            $key = [];
            foreach ($positions as $pos) {
                $v = $pos === $child->rowidAlias ? $rowid : ($vals[$pos] ?? null);
                if ($v === null) {
                    continue 2;
                }
                $key[] = $v;
            }
            $map[$this->fkChildKey($key)][] = ['rowid' => $rowid, 'values' => $vals];
        }
        return $map;
    }

    /** @param list<null|int|float|string|Blob> $key */
    private function fkChildKey(array $key): string
    {
        return \implode("\x1f", \array_map([$this, 'valueKey'], $key));
    }

    /**
     * Parent-side enforcement, run BEFORE a parent row is deleted or updated.
     * $newValues is null for a DELETE. Applies ON DELETE / ON UPDATE actions to
     * referencing children, raising for RESTRICT / NO ACTION when children remain.
     *
     * @param list<null|int|float|string|Blob>      $oldValues
     * @param ?list<null|int|float|string|Blob>     $newValues
     */
    private function fkBeforeParentChange(TableInfo $parent, array $oldValues, int $oldRowid, ?array $newValues, ?int $newRowid): void
    {
        $isDelete = $newValues === null;

        /** @var list<array{child:TableInfo,fk:ForeignKey,rows:list<array{rowid:int,values:list<null|int|float|string|Blob>}>,action:string,newKey:?list<null|int|float|string|Blob>}> $pending */
        $pending = [];

        foreach ($this->fkReferencing($parent) as ['child' => $child, 'fk' => $fk]) {
            $refPositions = $this->fkParentPositions($parent, $fk);

            $oldKey = [];
            $nullKey = false;
            foreach ($refPositions as $pos) {
                $v = $pos === $parent->rowidAlias ? $oldRowid : ($oldValues[$pos] ?? null);
                if ($v === null) {
                    $nullKey = true;
                    break;
                }
                $oldKey[] = $v;
            }
            if ($nullKey) {
                continue; // a NULL parent key can have no children referencing it
            }

            $newKey = null;
            if (!$isDelete) {
                $newKey = [];
                foreach ($refPositions as $pos) {
                    $newKey[] = $pos === $parent->rowidAlias ? $newRowid : ($newValues[$pos] ?? null);
                }
                if ($this->fkKeysEqual($oldKey, $newKey, $parent, $refPositions)) {
                    continue; // referenced columns unchanged
                }
            }

            $action = $isDelete ? $fk->onDelete : $fk->onUpdate;
            $rows = $this->fkChildRowsReferencing(
                $child,
                $fk,
                $oldKey,
                $isDelete && $action === ForeignKey::CASCADE,
            );
            if ($rows === []) {
                continue;
            }
            $pending[] = ['child' => $child, 'fk' => $fk, 'rows' => $rows, 'action' => $action, 'newKey' => $newKey];
        }

        // First pass: any RESTRICT / NO ACTION with surviving children aborts
        // before we mutate, so the statement leaves no partial cascade.
        foreach ($pending as $p) {
            if ($p['action'] === ForeignKey::NO_ACTION || $p['action'] === ForeignKey::RESTRICT) {
                throw SqlException::constraint('FOREIGN KEY constraint failed');
            }
        }

        // Second pass: apply CASCADE / SET NULL / SET DEFAULT.
        foreach ($pending as $p) {
            $this->fkApplyAction($p['child'], $p['fk'], $p['rows'], $p['action'], $p['newKey']);
        }
    }

    /**
     * @param list<null|int|float|string|Blob> $a
     * @param list<null|int|float|string|Blob> $b
     * @param list<int>                        $positions
     */
    private function fkKeysEqual(array $a, array $b, TableInfo $parent, array $positions): bool
    {
        foreach ($positions as $k => $pos) {
            $coll = $parent->columns[$pos]->collation;
            if (Value::compare($a[$k] ?? null, $b[$k] ?? null, $coll) !== 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Apply one foreign-key action to a set of matched child rows.
     *
     * @param list<array{rowid:int,values:list<null|int|float|string|Blob>}> $rows
     * @param ?list<null|int|float|string|Blob>                              $newKey
     */
    private function fkApplyAction(TableInfo $child, ForeignKey $fk, array $rows, string $action, ?array $newKey): void
    {
        $tree = new TableBTree($this->db->pager(), $child->rootPage);
        $positions = [];
        foreach ($fk->columns as $cname) {
            $positions[] = $child->columnPos($cname);
        }

        foreach ($rows as $row) {
            $rowid = $row['rowid'];
            if (!$tree->exists($rowid)) {
                continue; // already removed by an earlier cascade
            }
            $values = $this->decodeRow($child, $rowid, (string) $tree->get($rowid));

            if ($action === ForeignKey::CASCADE && $newKey === null) {
                // ON DELETE CASCADE: remove the child row, recursing into its own children.
                $this->fkBeforeParentChange($child, $values, $rowid, null, null);
                $this->deleteIndexEntries($child, $rowid, $values);
                $tree->delete($rowid);
                continue;
            }

            // Build the replacement values for the FK columns.
            $newValues = $values;
            foreach ($positions as $k => $pos) {
                $newValues[$pos] = match ($action) {
                    ForeignKey::CASCADE => $child->columns[$pos]->affinity->apply($newKey[$k]),
                    ForeignKey::SET_NULL => null,
                    ForeignKey::SET_DEFAULT => $child->columns[$pos]->defaultValue,
                    default => $newValues[$pos],
                };
            }

            $changed = [];
            foreach ($positions as $pos) {
                $changed[$pos] = true;
            }
            // Recompute any generated columns that depend on the changed FK columns.
            if ($child->generatedPositions !== []) {
                $eval = new Evaluator($this, []);
                $this->applyGeneratedColumns($child, $newValues, $rowid, $eval);
                foreach ($child->generatedPositions as $gp) {
                    $changed[$gp] = true;
                }
            }

            $tree->put($rowid, RecordCodec::encode($this->buildRecord($child, $newValues)));
            $this->maintainIndexesForUpdate($child, $rowid, $values, $rowid, $newValues, $changed);

            // The child's own foreign keys must still hold after the rewrite, and
            // a SET action may cascade to its children (parent role).
            $this->fkBeforeParentChange($child, $values, $rowid, $newValues, $rowid);
            if ($this->db->foreignKeysEnabled()) {
                $this->fkCheckChildRow($child, $newValues);
            }
        }
    }

    // === DDL =============================================================

    private function execCreateTable(CreateTableStatement $stmt): Result
    {
        $existed = $this->db->schema()->hasTable($stmt->name) || $this->db->schema()->hasView($stmt->name);
        $info = $this->db->schema()->createTable($stmt);
        if (!$existed) {
            $this->createConstraintIndexes($stmt, $info);
        }
        return Result::affected(0);
    }

    /**
     * Back every UNIQUE / non-rowid PRIMARY KEY constraint with a real unique
     * index (SQLite's sqlite_autoindex_* approach), so the constraint is both
     * enforced and usable by the planner. The INTEGER PRIMARY KEY (rowid alias)
     * needs no index — the rowid itself enforces it.
     */
    private function createConstraintIndexes(CreateTableStatement $stmt, TableInfo $info): void
    {
        /** @var list<list<string>> $groups */
        $groups = [];
        $seen = [];
        $add = static function (array $cols) use (&$groups, &$seen): void {
            if ($cols === []) {
                return;
            }
            $key = \strtolower(\implode("\x1f", $cols));
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $groups[] = $cols;
        };

        foreach ($stmt->columns as $i => $cd) {
            if ($cd->unique) {
                $add([$cd->name]);
            }
            if ($cd->primaryKey && $i !== $info->rowidAlias) {
                $add([$cd->name]);
            }
        }
        foreach ($stmt->uniqueConstraints as $group) {
            $add($group);
        }
        if ($stmt->primaryKeyColumns !== []) {
            $positions = \array_map(fn (string $n): ?int => $info->columnPos($n), $stmt->primaryKeyColumns);
            $isRowid = \count($positions) === 1 && $positions[0] === $info->rowidAlias;
            if (!$isRowid) {
                $add($stmt->primaryKeyColumns);
            }
        }

        $seq = 0;
        foreach ($groups as $cols) {
            $seq++;
            $name = 'sqlite_autoindex_' . $stmt->name . '_' . $seq;
            $this->createIndexFromStatement($this->autoIndexStatement($name, $stmt->name, $cols), $info);
        }
    }

    /** @param list<string> $cols */
    private function autoIndexStatement(string $name, string $table, array $cols): CreateIndexStatement
    {
        $quote = static fn (string $s): string => '"' . \str_replace('"', '""', $s) . '"';
        $terms = [];
        foreach ($cols as $c) {
            $terms[] = new \YetiDevWorks\YetiSQL\Sql\Ast\OrderTerm(Expr::col(null, $c));
        }
        $sql = 'CREATE UNIQUE INDEX ' . $quote($name) . ' ON ' . $quote($table)
            . ' (' . \implode(', ', \array_map($quote, $cols)) . ')';
        return new CreateIndexStatement($name, $table, $terms, unique: true, sql: $sql);
    }

    /** Allocate, record, and populate an index b-tree for $stmt. */
    private function createIndexFromStatement(CreateIndexStatement $stmt, TableInfo $info): void
    {
        $root = \YetiDevWorks\YetiSQL\Engine\IndexBTree::create($this->db->pager());
        $this->db->schema()->recordIndex($stmt, $root);

        $index = null;
        foreach ($this->db->schema()->resolvedIndexes($stmt->table) as $candidate) {
            if (\strcasecmp($candidate->name, $stmt->name) === 0) {
                $index = $candidate;
                break;
            }
        }
        if ($index === null) {
            return;
        }
        $idx = $this->indexTree($index);
        $tree = new TableBTree($this->db->pager(), $info->rootPage);
        $keys = [];
        foreach ($tree->scan() as [$rowid, $payload]) {
            $logical = $this->decodeRow($info, $rowid, $payload);
            if (!$this->rowMatchesPartial($info, $index, $logical, $rowid)) {
                continue; // partial index: skip rows outside the predicate
            }
            $keys[] = $this->indexKey($index, $logical, $rowid);
        }
        \usort($keys, fn (array $a, array $b): int => $this->compareIndexKeys($a, $b, $index->collations));
        // Bulk bottom-up build from the sorted keys: one linear pass instead of
        // a per-key descent+split (compareIndexKeys matches IndexBTree's own key
        // order, so the input is already in the order bulkLoad expects).
        $idx->bulkLoad($keys);
    }

    private function execCreateView(CreateViewStatement $stmt): Result
    {
        $this->db->schema()->createView($stmt);
        return Result::affected(0);
    }

    private function execCreateTrigger(CreateTriggerStatement $stmt): Result
    {
        $schema = $this->db->schema();
        $onView = $schema->hasView($stmt->table);
        if ($stmt->timing === CreateTriggerStatement::INSTEAD_OF && !$onView) {
            throw new SqlException("cannot create INSTEAD OF trigger on table: {$stmt->table}");
        }
        if ($stmt->timing !== CreateTriggerStatement::INSTEAD_OF && $onView) {
            throw new SqlException("cannot create {$stmt->timing} trigger on view: {$stmt->table}");
        }
        if (!$onView && $schema->getTable($stmt->table) === null) {
            throw new SqlException("no such table: {$stmt->table}");
        }
        $schema->createTrigger($stmt);
        return Result::affected(0);
    }

    // === triggers =========================================================

    /** The innermost NEW/OLD row context, consulted by the evaluator for NEW./OLD. refs. */
    public function currentTriggerEnv(): ?RowEnv
    {
        $n = \count($this->triggerEnvStack);
        return $n > 0 ? $this->triggerEnvStack[$n - 1] : null;
    }

    /**
     * Fire every row trigger matching (table, event, timing). OLD/NEW are full
     * logical column vectors (NEW null for DELETE, OLD null for INSERT).
     *
     * @param ?list<null|int|float|string|Blob> $oldValues
     * @param ?list<null|int|float|string|Blob> $newValues
     * @param ?array<int,true> $changedCols positions changed (UPDATE only), for UPDATE OF gating
     */
    private function fireTriggers(
        TableInfo $info,
        string $event,
        string $timing,
        ?array $oldValues,
        ?array $newValues,
        int $oldRowid,
        int $newRowid,
        ?array $changedCols = null,
    ): void {
        $triggers = $this->db->schema()->triggersFor($info->name, $event, $timing);
        if ($triggers === []) {
            return;
        }
        foreach ($triggers as $trg) {
            if (isset($this->activeTriggers[\strtolower($trg->name)])) {
                continue; // recursive_triggers is OFF: never re-enter a running trigger
            }
            if ($trg->updateOfColumns !== [] && !$this->updateTouchesColumns($info, $trg->updateOfColumns, $changedCols)) {
                continue;
            }

            $env = new RowEnv();
            if ($oldValues !== null) {
                $env->addFrame('old', $info, $oldValues, $oldRowid);
            }
            if ($newValues !== null) {
                $env->addFrame('new', $info, $newValues, $newRowid);
            }

            $this->triggerEnvStack[] = $env;
            $this->activeTriggers[\strtolower($trg->name)] = true;
            try {
                if ($trg->when !== null) {
                    $eval = new Evaluator($this, []);
                    if (!Value::isTrue((int) ($eval->evaluate($trg->when, null) ?? 0))) {
                        continue;
                    }
                }
                foreach ($trg->body as $bodyStmt) {
                    $this->execute($bodyStmt, []);
                }
            } finally {
                \array_pop($this->triggerEnvStack);
                unset($this->activeTriggers[\strtolower($trg->name)]);
            }
        }
    }

    /** A synthetic TableInfo for a view, so NEW/OLD frames resolve columns by name. */
    private function viewTableInfo(string $name): TableInfo
    {
        $view = $this->db->schema()->getView($name);
        $cols = $view['columns'] !== [] ? $view['columns'] : $this->selectOutputNames($view['select']);
        return $this->derivedTableInfo($name, $cols);
    }

    /** INSTEAD OF INSERT on a view: fire its triggers per row; no base storage. */
    private function execInsertView(InsertStatement $stmt, array $params): Result
    {
        $triggers = $this->db->schema()->triggersFor($stmt->table, CreateTriggerStatement::INSERT, CreateTriggerStatement::INSTEAD_OF);
        if ($triggers === []) {
            throw new SqlException("cannot modify {$stmt->table} because it is a view");
        }
        $info = $this->viewTableInfo($stmt->table);
        $eval = new Evaluator($this, $params);
        $targetCols = $stmt->columns ?? \array_map(static fn (ColumnInfo $c): string => $c->name, $info->columns);

        $rowsData = [];
        if ($stmt->select !== null) {
            foreach ($this->runSubquerySelect($stmt->select, $params) as $r) {
                $rowsData[] = $r;
            }
        } else {
            foreach ($stmt->rows as $exprRow) {
                $vals = [];
                foreach ($exprRow as $expr) {
                    $vals[] = $eval->evaluate($expr, null);
                }
                $rowsData[] = $vals;
            }
        }

        $count = 0;
        foreach ($rowsData as $data) {
            $newVec = \array_fill(0, $info->columnCount(), null);
            foreach ($targetCols as $k => $colName) {
                $pos = $info->columnPos($colName);
                if ($pos !== null) {
                    $newVec[$pos] = $data[$k] ?? null;
                }
            }
            $this->fireTriggers($info, CreateTriggerStatement::INSERT, CreateTriggerStatement::INSTEAD_OF, null, $newVec, 0, 0);
            $count++;
        }
        return Result::affected($count);
    }

    /** INSTEAD OF UPDATE/DELETE on a view: fire its triggers per matching view row. */
    private function execModifyView(string $view, string $event, ?Expr $where, ?array $set, array $params): Result
    {
        $triggers = $this->db->schema()->triggersFor($view, $event, CreateTriggerStatement::INSTEAD_OF);
        if ($triggers === []) {
            throw new SqlException("cannot modify $view because it is a view");
        }
        $info = $this->viewTableInfo($view);
        $eval = new Evaluator($this, $params);
        $rows = $this->execSelect($this->db->schema()->getView($view)['select'], $params)->materializeRows();

        $count = 0;
        foreach ($rows as $row) {
            $env = new RowEnv();
            $env->addFrame($view, $info, $row, 0);
            if ($where !== null && !Value::isTrue((int) ($eval->evaluate($where, $env) ?? 0))) {
                continue;
            }
            $newVec = $row;
            if ($event === CreateTriggerStatement::UPDATE && $set !== null) {
                foreach ($set as [$colName, $expr]) {
                    $pos = $info->columnPos($colName);
                    if ($pos !== null) {
                        $newVec[$pos] = $eval->evaluate($expr, $env);
                    }
                }
            }
            $newValues = $event === CreateTriggerStatement::UPDATE ? $newVec : null;
            $this->fireTriggers($info, $event, CreateTriggerStatement::INSTEAD_OF, $row, $newValues, 0, 0);
            $count++;
        }
        return Result::affected($count);
    }

    /** @param ?array<int,true> $changedCols */
    private function updateTouchesColumns(TableInfo $info, array $ofColumns, ?array $changedCols): bool
    {
        if ($changedCols === null) {
            return true;
        }
        foreach ($ofColumns as $name) {
            $pos = $info->columnPos($name);
            if ($pos !== null && isset($changedCols[$pos])) {
                return true;
            }
        }
        return false;
    }

    private function execAlter(AlterTableStatement $stmt): Result
    {
        $schema = $this->db->schema();
        if ($schema->getTable($stmt->table) === null && $schema->hasView($stmt->table)) {
            throw new SqlException("cannot alter {$stmt->table} because it is a view");
        }
        switch ($stmt->action) {
            case AlterTableStatement::RENAME_TABLE:
                $schema->renameTable($stmt->table, (string) $stmt->newName);
                break;
            case AlterTableStatement::RENAME_COLUMN:
                $schema->renameColumn($stmt->table, (string) $stmt->columnName, (string) $stmt->newName);
                break;
            case AlterTableStatement::ADD_COLUMN:
                $info = $schema->getTable($stmt->table);
                $hasRows = false;
                if ($info !== null) {
                    foreach ((new TableBTree($this->db->pager(), $info->rootPage))->scan() as $_) {
                        $hasRows = true;
                        break;
                    }
                }
                $schema->addColumn($stmt->table, $stmt->column, $hasRows);
                break;
            case AlterTableStatement::DROP_COLUMN:
                $this->execDropColumn($stmt);
                break;
        }
        return Result::affected(0);
    }

    private function execDropColumn(AlterTableStatement $stmt): void
    {
        $schema = $this->db->schema();
        $info = $schema->getTable($stmt->table);
        if ($info === null) {
            throw new SqlException("no such table: {$stmt->table}");
        }
        $col = (string) $stmt->columnName;
        $pos = $info->columnPos($col);
        if ($pos === null) {
            throw new SqlException("no such column: \"$col\"");
        }
        if ($info->columnCount() <= 1) {
            throw new SqlException("cannot drop column \"$col\": no other columns exist");
        }
        if ($pos === $info->rowidAlias || $info->columns[$pos]->primaryKey) {
            throw new SqlException("cannot drop column \"$col\": PRIMARY KEY column");
        }
        foreach ($schema->resolvedIndexes($stmt->table) as $idx) {
            if (\in_array($pos, $idx->columnPositions, true)) {
                throw new SqlException("cannot drop column \"$col\": used in index {$idx->name}");
            }
        }

        // Splice the column's value out of every stored record.
        $tree = new TableBTree($this->db->pager(), $info->rootPage);
        $n = $info->columnCount();
        $updates = [];
        foreach ($tree->scan() as [$rowid, $payload]) {
            $values = RecordCodec::decode($payload);
            // Materialize any virtual ADD COLUMN columns with their real default
            // (so a surviving one keeps its value), but leave the rowid-alias
            // slot stored as null per the table's storage convention.
            for ($i = \count($values); $i < $n; $i++) {
                $values[$i] = $info->columns[$i]->defaultValue;
            }
            \array_splice($values, $pos, 1);
            $updates[] = [$rowid, RecordCodec::encode(\array_values($values))];
        }
        foreach ($updates as [$rowid, $newPayload]) {
            $tree->put($rowid, $newPayload);
        }

        $schema->dropColumn($stmt->table, $col);
    }

    private function execDrop(DropStatement $stmt): Result
    {
        $schema = $this->db->schema();
        if ($stmt->kind === DropStatement::TABLE) {
            if (!$schema->hasTable($stmt->name)) {
                if ($stmt->ifExists) {
                    return Result::affected(0);
                }
                throw new SqlException("no such table: {$stmt->name}");
            }
            $schema->dropTable($stmt->name);
        } elseif ($stmt->kind === DropStatement::VIEW) {
            if (!$schema->hasView($stmt->name)) {
                if ($stmt->ifExists) {
                    return Result::affected(0);
                }
                throw new SqlException("no such view: {$stmt->name}");
            }
            $schema->dropView($stmt->name);
        } elseif ($stmt->kind === DropStatement::INDEX) {
            if ($schema->getIndex($stmt->name) === null) {
                if ($stmt->ifExists) {
                    return Result::affected(0);
                }
                throw new SqlException("no such index: {$stmt->name}");
            }
            $schema->dropIndex($stmt->name);
        } elseif ($stmt->kind === DropStatement::TRIGGER) {
            if (!$schema->hasTrigger($stmt->name)) {
                if ($stmt->ifExists) {
                    return Result::affected(0);
                }
                throw new SqlException("no such trigger: {$stmt->name}");
            }
            $schema->dropTrigger($stmt->name);
        }
        return Result::affected(0);
    }

    private function execCreateIndex(CreateIndexStatement $stmt): Result
    {
        if ($this->db->schema()->getIndex($stmt->name) !== null) {
            if ($stmt->ifNotExists) {
                return Result::affected(0);
            }
            throw SqlException::constraint("index {$stmt->name} already exists");
        }
        $info = $this->requireTable($stmt->table);
        // Expression indexes (e.g. CREATE INDEX i ON t(lower(x))) are parsed but
        // never planned or maintained, so reject them rather than create a dead
        // index that silently accelerates nothing.
        foreach ($stmt->columns as $term) {
            if ($term->expr->kind !== Expr::COL) {
                throw new SqlException('expression indexes are not supported');
            }
        }
        $this->createIndexFromStatement($stmt, $info);
        return Result::affected(0);
    }

    /** @param list<string> $collations */
    private function compareIndexKeys(array $a, array $b, array $collations): int
    {
        $n = \min(\count($a), \count($b));
        for ($i = 0; $i < $n; $i++) {
            $c = Value::compare($a[$i], $b[$i], $collations[$i] ?? 'BINARY');
            if ($c !== 0) {
                return $c;
            }
        }
        return \count($a) <=> \count($b);
    }

    // === PRAGMA ==========================================================

    private function execPragma(PragmaStatement $stmt): Result
    {
        $name = \strtolower($stmt->name);

        if (\in_array($name, ['table_info', 'table_xinfo', 'index_list', 'index_info',
            'index_xinfo', 'foreign_key_list'], true)) {
            return $this->pragmaFunctionResult($name, $this->pragmaArg($stmt));
        }
        if ($name === 'table_list') {
            $rows = [];
            foreach ($this->db->schema()->tableNames() as $t) {
                $rows[] = ['main', $t, 'table', 0, 0, 0];
            }
            return new Result(['schema', 'name', 'type', 'ncol', 'wr', 'strict'], $rows, \count($rows));
        }
        if ($name === 'database_list') {
            return new Result(['seq', 'name', 'file'], [[0, 'main', '']], 1);
        }
        if ($name === 'journal_mode') {
            $pager = $this->db->pager();
            $arg = \strtolower($this->pragmaArg($stmt));
            if ($arg === 'wal') {
                $pager->enableWal();
            } elseif (\in_array($arg, ['delete', 'truncate', 'persist', 'off', 'memory', 'rollback'], true)) {
                $pager->disableWal();
            }
            return new Result(['journal_mode'], [[$pager->journalMode()]], 1);
        }
        if ($name === 'vdbe') {
            // Non-standard: route compilable single-table SELECTs through the
            // VDBE VM instead of the tree-walker. Off by default.
            if ($stmt->value !== null) {
                $arg = \strtolower($this->pragmaArg($stmt));
                $this->db->setVdbeEnabled(\in_array($arg, ['1', 'on', 'true', 'yes'], true));
            }
            return new Result(['vdbe'], [[$this->db->vdbeEnabled() ? 1 : 0]], 1);
        }
        if ($name === 'wal_checkpoint') {
            $this->db->pager()->checkpoint();
            // SQLite returns (busy, log frames, checkpointed frames); the log is
            // reset on every checkpoint here, so report 0 outstanding frames.
            return new Result(['busy', 'log', 'checkpointed'], [[0, 0, 0]], 1);
        }
        if ($name === 'foreign_keys') {
            if ($stmt->value !== null) {
                $arg = \strtolower($this->pragmaArg($stmt));
                $this->db->setForeignKeysEnabled(\in_array($arg, ['1', 'on', 'true', 'yes'], true));
            }
            return new Result(['foreign_keys'], [[$this->db->foreignKeysEnabled() ? 1 : 0]], 1);
        }
        if ($name === 'busy_timeout') {
            if ($stmt->value !== null) {
                $this->db->pager()->setBusyTimeout((int) Value::toNumber($this->pragmaArg($stmt)));
            }
            return new Result(['timeout'], [[$this->db->pager()->busyTimeout()]], 1);
        }
        if (\in_array($name, ['synchronous', 'cache_size', 'user_version', 'encoding'], true)) {
            // Accept settings; return a representative value for reads.
            $value = match ($name) {
                'encoding' => 'UTF-8',
                default => 0,
            };
            return new Result([$name], [[$value]], 1);
        }

        // Unknown pragma: no-op, empty result.
        return new Result([], [], 0);
    }

    private function pragmaArg(PragmaStatement $stmt): string
    {
        return $stmt->value !== null && $stmt->value->kind === Expr::LIT
            ? (string) $stmt->value->value
            : '';
    }

    /**
     * Build the result for an introspection PRAGMA / its table-valued form.
     * $kind is the bare pragma name (e.g. "table_info"); $arg is the target.
     */
    private function pragmaFunctionResult(string $kind, string $arg): Result
    {
        return match ($kind) {
            'table_info' => $this->tableInfoResult($arg, false),
            // table_xinfo is table_info plus a trailing "hidden" column (0 for
            // ordinary columns; we have no generated/hidden columns). Laravel's
            // schema grammar selects "hidden" from pragma_table_xinfo.
            'table_xinfo' => $this->tableInfoResult($arg, true),
            'index_list' => $this->indexListResult($arg),
            'index_info', 'index_xinfo' => new Result(['seqno', 'cid', 'name'], [], 0),
            'foreign_key_list' => $this->foreignKeyListResult($arg),
            default => new Result([], [], 0),
        };
    }

    private function tableInfoResult(string $tableName, bool $extended = false): Result
    {
        $cols = ['cid', 'name', 'type', 'notnull', 'dflt_value', 'pk'];
        if ($extended) {
            $cols[] = 'hidden';
        }
        $info = $this->db->schema()->getTable($tableName);
        if ($info === null) {
            return new Result($cols, [], 0);
        }
        $rows = [];
        $pkSeq = 0;
        foreach ($info->columns as $i => $col) {
            $pk = ($i === $info->rowidAlias || $col->primaryKey) ? ++$pkSeq : 0;
            $row = [
                $i,
                $col->name,
                $col->declaredType ?? '',
                $col->notNull ? 1 : 0,
                $this->defaultValueText($col),
                $pk,
            ];
            if ($extended) {
                $row[] = 0; // not a hidden/generated column
            }
            $rows[] = $row;
        }
        return new Result($cols, $rows, \count($rows));
    }

    private function foreignKeyListResult(string $tableName): Result
    {
        $cols = ['id', 'seq', 'table', 'from', 'to', 'on_update', 'on_delete', 'match'];
        $info = $this->db->schema()->getTable($tableName);
        if ($info === null) {
            return new Result($cols, [], 0);
        }
        $rows = [];
        // SQLite numbers FKs from highest id to lowest, in reverse declaration order.
        $fks = $info->foreignKeys;
        $id = \count($fks) - 1;
        foreach ($fks as $fk) {
            foreach ($fk->columns as $seq => $from) {
                $rows[] = [
                    $id,
                    $seq,
                    $fk->refTable,
                    $from,
                    $fk->refColumns[$seq] ?? null,
                    $fk->onUpdate,
                    $fk->onDelete,
                    'NONE',
                ];
            }
            $id--;
        }
        return new Result($cols, $rows, \count($rows));
    }

    private function indexListResult(string $tableName): Result
    {
        $rows = [];
        $seq = 0;
        foreach ($this->db->schema()->indexesForTable($tableName) as $idx) {
            $partial = $idx['ast']->where !== null ? 1 : 0;
            $rows[] = [$seq++, $idx['name'], $idx['ast']->unique ? 1 : 0, 'c', $partial];
        }
        return new Result(['seq', 'name', 'unique', 'origin', 'partial'], $rows, \count($rows));
    }

    // === table-valued functions (pragma_*) ===============================

    /** Column names exposed by each supported table-valued pragma function. */
    private function tvfColumns(string $func): array
    {
        return match (\strtolower($func)) {
            'pragma_table_info' => ['cid', 'name', 'type', 'notnull', 'dflt_value', 'pk'],
            'pragma_table_xinfo' => ['cid', 'name', 'type', 'notnull', 'dflt_value', 'pk', 'hidden'],
            'pragma_index_list' => ['seq', 'name', 'unique', 'origin', 'partial'],
            'pragma_index_info', 'pragma_index_xinfo' => ['seqno', 'cid', 'name'],
            'pragma_foreign_key_list' => ['id', 'seq', 'table', 'from', 'to', 'on_update', 'on_delete', 'match'],
            'json_each', 'json_tree' => ['key', 'value', 'type', 'atom', 'id', 'parent', 'fullkey', 'path'],
            default => throw new SqlException("no such table-valued function: $func"),
        };
    }

    private function tvfTableInfo(string $func, string $alias): TableInfo
    {
        $numeric = ['cid', 'notnull', 'pk', 'seq', 'seqno', 'unique', 'partial', 'id', 'hidden', 'parent'];
        // json_each/json_tree key/value/atom carry native JSON types, so they
        // must not be coerced — give them BLOB (no) affinity.
        $noAffinity = ['key', 'value', 'atom'];
        $columns = [];
        foreach ($this->tvfColumns($func) as $name) {
            $aff = match (true) {
                \in_array($name, $noAffinity, true) => \YetiDevWorks\YetiSQL\Types\Affinity::BLOB,
                \in_array($name, $numeric, true) => \YetiDevWorks\YetiSQL\Types\Affinity::INTEGER,
                default => \YetiDevWorks\YetiSQL\Types\Affinity::TEXT,
            };
            $columns[] = new \YetiDevWorks\YetiSQL\Engine\ColumnInfo($name, null, $aff);
        }
        return new TableInfo($alias, 0, $columns);
    }

    /**
     * @param list<null|int|float|string|Blob> $argVals
     * @return list<list<null|int|float|string|Blob>>
     */
    private function tvfRows(string $func, array $argVals): array
    {
        $lc = \strtolower($func);
        if ($lc === 'json_each' || $lc === 'json_tree') {
            return $this->jsonTvfRows($lc, $argVals);
        }
        $arg = isset($argVals[0]) ? (string) Value::toText($argVals[0]) : '';
        $kind = \strtolower((string) \preg_replace('/^pragma_/', '', \strtolower($func)));
        return $this->pragmaFunctionResult($kind, $arg)->rows;
    }

    /**
     * Rows for json_each (immediate children) / json_tree (recursive walk).
     * Columns: key, value, type, atom, id, parent, fullkey, path. The `id` and
     * `parent` values are a stable in-document numbering (SQLite's are internal
     * byte offsets, deliberately implementation-defined).
     *
     * @param list<null|int|float|string|Blob> $argVals
     * @return list<list<null|int|float|string|Blob>>
     */
    private function jsonTvfRows(string $func, array $argVals): array
    {
        if (($argVals[0] ?? null) === null) {
            return [];
        }
        $root = \YetiDevWorks\YetiSQL\Functions\Json::decode((string) Value::toText($argVals[0]), true);
        $basePath = '$';
        if (\array_key_exists(1, $argVals)) {
            if ($argVals[1] === null) {
                return [];
            }
            $basePath = (string) Value::toText($argVals[1]);
            $root = \YetiDevWorks\YetiSQL\Functions\Json::resolve($root, \YetiDevWorks\YetiSQL\Functions\Json::parsePath($basePath));
            if ($root === \YetiDevWorks\YetiSQL\Functions\Json::MISSING) {
                return [];
            }
        }
        $rows = [];
        $counter = 0;
        if ($func === 'json_tree') {
            $this->jsonTreeWalk($root, null, null, $basePath, $basePath, $rows, $counter);
        } else {
            $this->jsonEachRows($root, $basePath, $rows, $counter);
        }
        return $rows;
    }

    /**
     * json_each: one row per immediate child of a container, or a single row for
     * the node itself when it is a scalar. parent is always NULL.
     *
     * @param list<list<null|int|float|string|Blob>> $rows
     */
    private function jsonEachRows(mixed $node, string $basePath, array &$rows, int &$counter): void
    {
        if (\is_array($node)) {
            foreach ($node as $i => $child) {
                $rows[] = $this->jsonRow($i, $child, $counter++, null, $basePath . '[' . $i . ']', $basePath);
            }
        } elseif ($node instanceof \stdClass) {
            foreach (\get_object_vars($node) as $name => $child) {
                $full = $basePath . $this->jsonKeySegment((string) $name);
                $rows[] = $this->jsonRow((string) $name, $child, $counter++, null, $full, $basePath);
            }
        } else {
            $rows[] = $this->jsonRow(null, $node, $counter++, null, $basePath, $basePath);
        }
    }

    /**
     * json_tree: depth-first walk emitting every node, root first.
     *
     * @param list<list<null|int|float|string|Blob>> $rows
     */
    private function jsonTreeWalk(mixed $node, null|int|string $key, ?int $parentId, string $fullkey, string $path, array &$rows, int &$counter): void
    {
        $id = $counter++;
        $rows[] = $this->jsonRow($key, $node, $id, $parentId, $fullkey, $path);

        if (\is_array($node)) {
            foreach ($node as $i => $child) {
                $this->jsonTreeWalk($child, $i, $id, $fullkey . '[' . $i . ']', $fullkey, $rows, $counter);
            }
        } elseif ($node instanceof \stdClass) {
            foreach (\get_object_vars($node) as $name => $child) {
                $this->jsonTreeWalk($child, (string) $name, $id, $fullkey . $this->jsonKeySegment((string) $name), $fullkey, $rows, $counter);
            }
        }
    }

    /**
     * Build one json_each/json_tree row: [key, value, type, atom, id, parent, fullkey, path].
     *
     * @return list<null|int|float|string|Blob>
     */
    private function jsonRow(null|int|string $key, mixed $node, int $id, ?int $parentId, string $fullkey, string $path): array
    {
        $isContainer = \is_array($node) || $node instanceof \stdClass;
        $value = \YetiDevWorks\YetiSQL\Functions\Json::toSqlValue($node);
        return [
            $key,
            $value,
            \YetiDevWorks\YetiSQL\Functions\Json::typeName($node),
            $isContainer ? null : $value,
            $id,
            $parentId,
            $fullkey,
            $path,
        ];
    }

    /** A fullkey object segment: ".name" for a simple key, else quoted. */
    private function jsonKeySegment(string $name): string
    {
        return \preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1
            ? '.' . $name
            : '."' . \str_replace('"', '""', $name) . '"';
    }

    private function requireTable(string $name): TableInfo
    {
        $info = $this->db->schema()->getTable($name);
        if ($info === null) {
            if ($this->db->schema()->hasView($name)) {
                throw new SqlException("cannot modify $name because it is a view");
            }
            throw new SqlException("no such table: $name");
        }
        return $info;
    }
}
