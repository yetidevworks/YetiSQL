<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Executor;

use Generator;
use YetiDevWorks\YetiSQL\Engine\Blob;
use YetiDevWorks\YetiSQL\Engine\Database;
use YetiDevWorks\YetiSQL\Engine\RecordCodec;
use YetiDevWorks\YetiSQL\Engine\TableBTree;
use YetiDevWorks\YetiSQL\Engine\TableInfo;
use YetiDevWorks\YetiSQL\Exception\SqlException;
use YetiDevWorks\YetiSQL\Functions\Aggregates;
use YetiDevWorks\YetiSQL\Sql\Ast\CreateIndexStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\CreateTableStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\DeleteStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\DropStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\Expr;
use YetiDevWorks\YetiSQL\Sql\Ast\InsertStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\JoinClause;
use YetiDevWorks\YetiSQL\Sql\Ast\PragmaStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\SelectStatement;
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
     * @var array<int,array{cookie:int,cols:list<array<string,mixed>>,names:list<string>,order:list<array<string,mixed>>,aggs:array<int,Expr>,isAgg:bool}>
     */
    private array $selectMeta = [];
    private const SELECT_META_MAX = 1024;
    /** @var array<string,int> */
    private array $coveredCountCache = [];
    private const COVERED_COUNT_CACHE_MAX = 1024;

    public function __construct(private readonly Database $db)
    {
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
            $stmt instanceof DropStatement => $this->execDrop($stmt),
            $stmt instanceof CreateIndexStatement => $this->execCreateIndex($stmt),
            $stmt instanceof PragmaStatement => $this->execPragma($stmt),
            default => throw new SqlException('statement type not supported: ' . $stmt::class),
        };
    }

    // === SELECT ==========================================================

    /** @param array<string,null|int|float|string|Blob> $params */
    private function execSelect(SelectStatement $select, array $params): Result
    {
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

    /** @param array<string,null|int|float|string|Blob> $params */
    private function tryStreamSelect(SelectStatement $select, array $params): ?Result
    {
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
    public function runSubquerySelect(SelectStatement $select, array $params): array
    {
        $eval = new Evaluator($this, $params);
        [$cols, $rows] = $this->runSelect($select, $eval);
        if ($select->compound !== null) {
            [, $rightRows] = $this->runSelect($select->compound, new Evaluator($this, $params));
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
            $coveredCount = $this->tryCoveredCount($select, $outputCols, $aggregateNodes, $eval);
            if ($coveredCount !== null) {
                return [$colNames, $coveredCount, []];
            }
            return [$colNames, ...$this->aggregateUngrouped($select, $outputCols, $aggregateNodes, $eval, $orderPlan)];
        }

        if ($isAggregate) {
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
     * @return array{cookie:int,cols:list<array<string,mixed>>,names:list<string>,order:list<array<string,mixed>>,aggs:array<int,Expr>,isAgg:bool,cWhere:?\Closure,cProject:?list<?\Closure>}
     */
    private function compileSelect(SelectStatement $select, Evaluator $eval): array
    {
        $id = \spl_object_id($select);
        $cookie = $this->db->pager()->schemaCookie();
        $cached = $this->selectMeta[$id] ?? null;
        if ($cached !== null && $cached['cookie'] === $cookie) {
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
        if (isset($src['scanner'])) {
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
            // Uncorrelated derived table: materialise once.
            $res = $this->execSelect($ref->subquery, $eval->params());
            $alias = $ref->alias ?? '';
            return [
                'kind' => 'derived',
                'alias' => $alias,
                'info' => $this->derivedTableInfo($alias, $res->columns ?? []),
                'tree' => null,
                'empty' => false,
                'func' => null,
                'args' => [],
                'rows' => $res->rows,
            ];
        }
        return $this->resolveSource($ref);
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
        if ($ref->name !== null) {
            $info = $this->db->schema()->getTable($ref->name);
            if ($info !== null) {
                return [$ref->alias ?? $info->name, $info];
            }
        }
        return null;
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
    private function collectAggregates(Expr $e, array &$nodes): void
    {
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
                Aggregates::step($accs[$id], $args, $node->star);
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
        if ($plan['kind'] === 'index_eq') {
            /** @var \YetiDevWorks\YetiSQL\Engine\IndexInfo $index */
            $index = $plan['index'];
            $idx = new \YetiDevWorks\YetiSQL\Engine\IndexBTree($this->db->pager(), $index->rootPage, $index->collations);
            $coll = $index->collations[0] ?? 'BINARY';
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
                Aggregates::step($accsByGroup[$key][$id], $args, $node->star);
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

        if ($offset > 0 || $limit !== null) {
            $rows = \array_values(\array_slice($rows, \max(0, $offset), $limit === null ? null : \max(0, $limit)));
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

    private function decodeRow(TableInfo $info, int $rowid, string $payload): array
    {
        $values = RecordCodec::decode($payload);
        $n = $info->columnCount();
        if (\count($values) < $n) {
            $values = \array_pad($values, $n, null);
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
                foreach ($this->indexEntries($plan) as [$rid, $covered]) {
                    yield [$rid, null, $covered];
                }
                return;
        }

        foreach ($tree->scan() as [$rid, $payload]) {
            yield [$rid, $payload, []];
        }
    }

    /** @return \Generator<int,array{0:int,1:string}> */
    private function indexScan(TableBTree $tree, array $plan): \Generator
    {
        foreach ($this->indexRowids($plan) as $rid) {
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
     * @param list<\YetiDevWorks\YetiSQL\Engine\IndexInfo> $indexes
     * @return array<string,mixed>|null
     */
    private function predicatePlan(Expr $e, ?string $alias, TableInfo $info, array $indexes, Evaluator $eval): ?array
    {
        // Equality / range:  col OP const
        if ($e->kind === Expr::BIN && \in_array($e->op, ['=', '<', '<=', '>', '>='], true)) {
            [$col, $const, $flip] = $this->colConst($e->left, $e->right, $alias, $info);
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
            if ($index->leadingColumn() === $pos) {
                return $index;
            }
        }
        return null;
    }

    /** @return array{0:?int,1:?Expr,2:bool} [colPos, constExpr, flipped] */
    private function colConst(Expr $a, Expr $b, ?string $alias, TableInfo $info): array
    {
        $pa = $this->colPositionOf($a, $alias, $info);
        if ($pa !== null && $this->isConstExpr($b)) {
            return [$pa, $b, false];
        }
        $pb = $this->colPositionOf($b, $alias, $info);
        if ($pb !== null && $this->isConstExpr($a)) {
            return [$pb, $a, true];
        }
        return [null, null, false];
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
            $this->indexTree($index)->put($this->indexKey($index, $logicalValues, $rowid));
        }
    }

    /** @param list<null|int|float|string|Blob> $logicalValues */
    private function deleteIndexEntries(TableInfo $info, int $rowid, array $logicalValues): void
    {
        foreach ($this->db->schema()->resolvedIndexes($info->name) as $index) {
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

        $count = 0;
        $lastId = 0;
        foreach ($rowsData as $data) {
            $lastId = $this->insertOneRow($info, $tree, $targetCols, $data, $stmt, $eval);
            $count++;
        }
        return Result::affected($count, $lastId);
    }

    /**
     * @param list<string> $targetCols
     * @param list<null|int|float|string|Blob> $data
     */
    private function insertOneRow(TableInfo $info, TableBTree $tree, array $targetCols, array $data, InsertStatement $stmt, Evaluator $eval): int
    {
        // Map supplied values onto a full column vector.
        $byPos = \array_fill(0, $info->columnCount(), null);
        $provided = \array_fill(0, $info->columnCount(), false);

        if (!$stmt->defaultValues) {
            foreach ($targetCols as $k => $colName) {
                $pos = $info->columnPos($colName);
                if ($pos === null) {
                    throw new SqlException("table {$info->name} has no column named $colName");
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

        // Apply defaults / NOT NULL checks / affinity per column.
        $record = [];
        foreach ($info->columns as $pos => $col) {
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

        $payload = RecordCodec::encode(\array_values($record));

        if ($stmt->orReplace) {
            if ($tree->exists($rowid)) {
                $old = $this->decodeRow($info, $rowid, (string) $tree->get($rowid));
                $this->deleteIndexEntries($info, $rowid, $old);
                $tree->delete($rowid);
            }
            $tree->put($rowid, $payload);
        } elseif (!$tree->putIfAbsent($rowid, $payload)) {
            // Rowid already present: a single insert descent enforces uniqueness
            // (no separate exists() probe).
            if ($stmt->orIgnore) {
                return $this->db->lastInsertId();
            }
            throw SqlException::constraint("UNIQUE constraint failed: {$info->name} rowid $rowid");
        }

        $logical = $record;
        if ($info->hasRowidAlias()) {
            $logical[$info->rowidAlias] = $rowid;
        }
        $this->insertIndexEntries($info, $rowid, \array_values($logical));
        return $rowid;
    }

    // === UPDATE ==========================================================

    /** @param array<string,null|int|float|string|Blob> $params */
    private function execUpdate(UpdateStatement $stmt, array $params): Result
    {
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

        $count = 0;
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

            $record = $this->buildRecord($info, $newValues);
            if ($newRowid !== $rowid) {
                $tree->delete($rowid);
            }
            $tree->put($newRowid, RecordCodec::encode($record));
            // Maintain only indexes whose columns (or the rowid) actually changed.
            $this->maintainIndexesForUpdate($info, $rowid, $values, $newRowid, $newValues, $changed);
            $count++;
        }
        return Result::affected($count);
    }

    // === DELETE ==========================================================

    /** @param array<string,null|int|float|string|Blob> $params */
    private function execDelete(DeleteStatement $stmt, array $params): Result
    {
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
        foreach ($targets as [$rowid, $values]) {
            $this->deleteIndexEntries($info, $rowid, $values);
            $tree->delete($rowid);
        }
        return Result::affected(\count($targets));
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

    // === DDL =============================================================

    private function execCreateTable(CreateTableStatement $stmt): Result
    {
        $this->db->schema()->createTable($stmt);
        return Result::affected(0);
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
        } elseif ($stmt->kind === DropStatement::INDEX) {
            if ($schema->getIndex($stmt->name) === null) {
                if ($stmt->ifExists) {
                    return Result::affected(0);
                }
                throw new SqlException("no such index: {$stmt->name}");
            }
            $schema->dropIndex($stmt->name);
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
        $root = \YetiDevWorks\YetiSQL\Engine\IndexBTree::create($this->db->pager());
        $this->db->schema()->recordIndex($stmt, $root);

        // Populate the index from existing rows.
        $index = null;
        foreach ($this->db->schema()->resolvedIndexes($stmt->table) as $candidate) {
            if (\strcasecmp($candidate->name, $stmt->name) === 0) {
                $index = $candidate;
                break;
            }
        }
        if ($index !== null) {
            $idx = $this->indexTree($index);
            $tree = new TableBTree($this->db->pager(), $info->rootPage);
            $keys = [];
            foreach ($tree->scan() as [$rowid, $payload]) {
                $logical = $this->decodeRow($info, $rowid, $payload);
                $keys[] = $this->indexKey($index, $logical, $rowid);
            }
            $collations = $index->collations;
            \usort($keys, fn (array $a, array $b): int => $this->compareIndexKeys($a, $b, $collations));
            foreach ($keys as $key) {
                $idx->put($key);
            }
        }
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
        if (\in_array($name, ['foreign_keys', 'journal_mode', 'synchronous', 'cache_size', 'user_version', 'encoding'], true)) {
            // Accept settings; return a representative value for reads.
            $value = match ($name) {
                'journal_mode' => 'rollback',
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
            'table_info', 'table_xinfo' => $this->tableInfoResult($arg),
            'index_list' => $this->indexListResult($arg),
            'index_info', 'index_xinfo' => new Result(['seqno', 'cid', 'name'], [], 0),
            'foreign_key_list' => new Result(
                ['id', 'seq', 'table', 'from', 'to', 'on_update', 'on_delete', 'match'],
                [],
                0,
            ),
            default => new Result([], [], 0),
        };
    }

    private function tableInfoResult(string $tableName): Result
    {
        $cols = ['cid', 'name', 'type', 'notnull', 'dflt_value', 'pk'];
        $info = $this->db->schema()->getTable($tableName);
        if ($info === null) {
            return new Result($cols, [], 0);
        }
        $rows = [];
        $pkSeq = 0;
        foreach ($info->columns as $i => $col) {
            $pk = ($i === $info->rowidAlias || $col->primaryKey) ? ++$pkSeq : 0;
            $rows[] = [
                $i,
                $col->name,
                $col->declaredType ?? '',
                $col->notNull ? 1 : 0,
                $col->default !== null ? $this->exprLabel($col->default) : null,
                $pk,
            ];
        }
        return new Result($cols, $rows, \count($rows));
    }

    private function indexListResult(string $tableName): Result
    {
        $rows = [];
        $seq = 0;
        foreach ($this->db->schema()->indexesForTable($tableName) as $idx) {
            $rows[] = [$seq++, $idx['name'], $idx['ast']->unique ? 1 : 0, 'c', 0];
        }
        return new Result(['seq', 'name', 'unique', 'origin', 'partial'], $rows, \count($rows));
    }

    // === table-valued functions (pragma_*) ===============================

    /** Column names exposed by each supported table-valued pragma function. */
    private function tvfColumns(string $func): array
    {
        return match (\strtolower($func)) {
            'pragma_table_info', 'pragma_table_xinfo' => ['cid', 'name', 'type', 'notnull', 'dflt_value', 'pk'],
            'pragma_index_list' => ['seq', 'name', 'unique', 'origin', 'partial'],
            'pragma_index_info', 'pragma_index_xinfo' => ['seqno', 'cid', 'name'],
            'pragma_foreign_key_list' => ['id', 'seq', 'table', 'from', 'to', 'on_update', 'on_delete', 'match'],
            default => throw new SqlException("no such table-valued function: $func"),
        };
    }

    private function tvfTableInfo(string $func, string $alias): TableInfo
    {
        $numeric = ['cid', 'notnull', 'pk', 'seq', 'seqno', 'unique', 'partial', 'id'];
        $columns = [];
        foreach ($this->tvfColumns($func) as $name) {
            $aff = \in_array($name, $numeric, true)
                ? \YetiDevWorks\YetiSQL\Types\Affinity::INTEGER
                : \YetiDevWorks\YetiSQL\Types\Affinity::TEXT;
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
        $arg = isset($argVals[0]) ? (string) Value::toText($argVals[0]) : '';
        $kind = \strtolower((string) \preg_replace('/^pragma_/', '', \strtolower($func)));
        return $this->pragmaFunctionResult($kind, $arg)->rows;
    }

    private function requireTable(string $name): TableInfo
    {
        $info = $this->db->schema()->getTable($name);
        if ($info === null) {
            throw new SqlException("no such table: $name");
        }
        return $info;
    }
}
