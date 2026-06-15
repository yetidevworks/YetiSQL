<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Executor;

use YetiDevWorks\YetiSQL\Engine\Blob;
use YetiDevWorks\YetiSQL\Engine\TableInfo;
use YetiDevWorks\YetiSQL\Exception\SqlException;
use YetiDevWorks\YetiSQL\Functions\Aggregates;
use YetiDevWorks\YetiSQL\Functions\Json;
use YetiDevWorks\YetiSQL\Functions\ScalarFunctions;
use YetiDevWorks\YetiSQL\Sql\Ast\Expr;
use YetiDevWorks\YetiSQL\Sql\Ast\SelectStatement;
use YetiDevWorks\YetiSQL\Types\Affinity;
use YetiDevWorks\YetiSQL\Types\Collation;
use YetiDevWorks\YetiSQL\Types\Value;

/**
 * Tree-walking expression evaluator. Implements SQLite's arithmetic, three-
 * valued logic, affinity-aware comparison, LIKE/GLOB, CAST and subquery
 * semantics. Aggregate function nodes resolve to values precomputed by the
 * executor and stashed in $aggregateValues (keyed by node object id).
 */
final class Evaluator
{
    /** @var array<int,null|int|float|string|Blob> aggregate results by spl_object_id(Expr) */
    public array $aggregateValues = [];

    /**
     * Precomputed window-function results: spl_object_id(Expr) => list of values
     * indexed by the row's position in the materialized result set. $windowRow
     * selects the current row during projection.
     *
     * @var array<int,list<null|int|float|string|Blob>>
     */
    public array $windowValues = [];
    public int $windowRow = 0;

    /**
     * SELECT-list aliases visible to HAVING/ORDER BY (lower-cased name => the
     * aliased expression). Consulted only when a real column does not resolve.
     *
     * @var array<string,Expr>
     */
    public array $aliasExprs = [];

    /**
     * Set by the single-table scan path when the chosen access plan already
     * guarantees the entire WHERE clause, so the executor can skip re-checking
     * it per row. (A covered COUNT then never reads a single column.)
     */
    public bool $whereCovered = false;

    /**
     * Compiled single-table fast paths for the current query, set by the
     * executor when the query is over one table. Null entries mean "not
     * compilable — use evaluate()". The closures take the evaluator as an
     * argument, so they are parameter-independent and cached across executions.
     *
     * @var \Closure(RowEnv,Evaluator):(null|int|float|string|Blob)|null
     */
    public ?\Closure $compiledWhere = null;
    /** @var list<\Closure(RowEnv,Evaluator):(null|int|float|string|Blob)|null>|null aligned to output columns */
    public ?array $compiledProject = null;
    /** @var list<\Closure(RowEnv,Evaluator):(null|int|float|string|Blob)>|null aligned to GROUP BY terms */
    public ?array $compiledGroupBy = null;
    /** @var array<int,list<\Closure(RowEnv,Evaluator):(null|int|float|string|Blob)>|null>|null aggregate-arg closures by node id */
    public ?array $compiledAggArgs = null;

    /**
     * Per-query memoization keyed by spl_object_id of the expression node. A
     * query's frame layout, and a column's declared affinity/collation, are
     * constant across every row scanned, so these are resolved once and reused.
     * The evaluator is created fresh per query, so the caches never outlive the
     * frame layout they describe.
     *
     * @var array<int,array{0:int,1:int}> resolved [frameIndex,pos] per COL node
     */
    private array $colSlot = [];
    /** @var array<int,array{0:?Affinity,1:?Affinity}> comparison affinities per node */
    private array $cmpAff = [];
    /** @var array<int,string> comparison collation per node */
    private array $cmpColl = [];

    /** @param array<string,null|int|float|string|Blob> $params bound parameters */
    /**
     * Enclosing-query scope for correlated subqueries: the outer row's RowEnv
     * and the Evaluator that owns it (so resolution can walk further out for
     * nested correlation). Null for a top-level (non-subquery) evaluator.
     */
    public ?RowEnv $outerEnv = null;
    public ?Evaluator $outerEval = null;

    public function __construct(
        private readonly Executor $executor,
        private array $params = [],
    ) {
    }

    /**
     * Resolve a column against the enclosing query scope(s) for a correlated
     * subquery. Tries the immediate outer row, then walks outward.
     *
     * @return array{0:bool,1:null|int|float|string|Blob} [found, value]
     */
    public function resolveOuter(?string $table, string $name): array
    {
        if ($this->outerEnv !== null && $this->outerEnv->hasColumn($table, $name)) {
            return [true, $this->outerEnv->resolveColumn($table, $name)];
        }
        if ($this->outerEval !== null) {
            return $this->outerEval->resolveOuter($table, $name);
        }
        return [false, null];
    }

    /** @param array<string,null|int|float|string|Blob> $params */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /** @return array<string,null|int|float|string|Blob> */
    public function params(): array
    {
        return $this->params;
    }

    // === expression compiler (single-table "VDBE") =======================
    //
    // Lowers an expression tree to a single composed PHP closure so that the
    // per-row hot loop runs as native PHP, with no per-node switch dispatch,
    // re-resolution of columns, or re-derivation of affinity/collation. The
    // closure takes the current single-frame RowEnv and returns the value.
    //
    // compile() returns null for any node it does not handle; callers then fall
    // back to evaluate(), so the tree-walker remains the source of truth and the
    // differential oracle guarantees the compiled path matches it.

    /**
     * Compile an expression against a single table (frame 0) into a closure
     * `fn(RowEnv $env, Evaluator $ev): value`, or null for any node not handled.
     *
     * The closure takes the evaluator as a call argument (rather than capturing
     * it) so the compiled program is independent of bound parameters and can be
     * cached once per statement and reused across executions.
     *
     * @return \Closure(RowEnv,Evaluator):(null|int|float|string|Blob)|null
     */
    public function compile(Expr $e, TableInfo $info, string $alias): ?\Closure
    {
        switch ($e->kind) {
            case Expr::LIT:
                $v = $e->value;
                return static fn (RowEnv $env, Evaluator $ev): null|int|float|string|Blob => $v;

            case Expr::COL:
                $pos = $this->compileColPos($e, $info, $alias);
                if ($pos === null) {
                    return null;
                }
                if ($pos === -1) {
                    return static fn (RowEnv $env, Evaluator $ev): null|int|float|string|Blob => $env->frames[0]['rowid'];
                }
                return static fn (RowEnv $env, Evaluator $ev): null|int|float|string|Blob => $env->valueAt(0, $pos);

            case Expr::PARAM:
                $marker = (string) $e->name;
                return static fn (RowEnv $env, Evaluator $ev): null|int|float|string|Blob => $ev->resolveParam($marker);

            case Expr::UNARY:
                return $this->compileUnary($e, $info, $alias);

            case Expr::BIN:
                return $this->compileBinary($e, $info, $alias);

            case Expr::ISNULL:
                $oc = $this->compile($e->operand, $info, $alias);
                if ($oc === null) {
                    return null;
                }
                $wantNull = !$e->not;
                return static fn (RowEnv $env, Evaluator $ev): int => (($oc($env, $ev) === null) === $wantNull) ? 1 : 0;

            case Expr::CAST:
                $oc = $this->compile($e->operand, $info, $alias);
                if ($oc === null) {
                    return null;
                }
                $type = (string) $e->typeName;
                return static fn (RowEnv $env, Evaluator $ev): null|int|float|string|Blob => $ev->cast($oc($env, $ev), $type);

            case Expr::COLLATE:
                // Collation only affects comparison, handled where it is consumed.
                return $this->compile($e->operand, $info, $alias);

            case Expr::FUNC:
                return $this->compileFunc($e, $info, $alias);

            case Expr::BETWEEN:
                return $this->compileBetween($e, $info, $alias);

            case Expr::CASE_:
                return $this->compileCase($e, $info, $alias);

            default:
                return null; // IN/LIKE/SUBQUERY/EXISTS etc. -> fall back
        }
    }

    /** Resolve a column reference to a slot position, -1 for rowid, or null. */
    private function compileColPos(Expr $e, TableInfo $info, string $alias): ?int
    {
        if ($e->table !== null
            && \strcasecmp($e->table, $alias) !== 0
            && \strcasecmp($e->table, $info->name) !== 0) {
            return null;
        }
        $pos = $info->columnPos((string) $e->name);
        if ($pos !== null) {
            return $pos;
        }
        $lname = \strtolower((string) $e->name);
        if ($lname === 'rowid' || $lname === '_rowid_' || $lname === 'oid') {
            return -1;
        }
        return null;
    }

    private function compileUnary(Expr $e, TableInfo $info, string $alias): ?\Closure
    {
        $oc = $this->compile($e->operand, $info, $alias);
        if ($oc === null) {
            return null;
        }
        return match ($e->op) {
            'NOT' => static function (RowEnv $env, Evaluator $ev) use ($oc): null|int {
                $v = $oc($env, $ev);
                return $v === null ? null : (Value::isTrue($v) ? 0 : 1);
            },
            '-' => static function (RowEnv $env, Evaluator $ev) use ($oc): null|int|float {
                $v = $oc($env, $ev);
                if ($v === null) {
                    return null;
                }
                $n = Value::toNumber($v);
                return $n === 0 ? 0 : -$n;
            },
            '+' => static function (RowEnv $env, Evaluator $ev) use ($oc): null|int|float {
                $v = $oc($env, $ev);
                return $v === null ? null : Value::toNumber($v);
            },
            '~' => static function (RowEnv $env, Evaluator $ev) use ($oc): null|int {
                $v = $oc($env, $ev);
                return $v === null ? null : ~((int) Value::toNumber($v));
            },
            default => null,
        };
    }

    private function compileBinary(Expr $e, TableInfo $info, string $alias): ?\Closure
    {
        $op = (string) $e->op;
        $lc = $this->compile($e->left, $info, $alias);
        $rc = $this->compile($e->right, $info, $alias);
        if ($lc === null || $rc === null) {
            return null;
        }

        if ($op === 'AND') {
            return static function (RowEnv $env, Evaluator $ev) use ($lc, $rc): null|int {
                $l = $lc($env, $ev);
                if ($l !== null && !Value::isTrue($l)) {
                    return 0;
                }
                $r = $rc($env, $ev);
                if ($r !== null && !Value::isTrue($r)) {
                    return 0;
                }
                return ($l === null || $r === null) ? null : 1;
            };
        }
        if ($op === 'OR') {
            return static function (RowEnv $env, Evaluator $ev) use ($lc, $rc): null|int {
                $l = $lc($env, $ev);
                if ($l !== null && Value::isTrue($l)) {
                    return 1;
                }
                $r = $rc($env, $ev);
                if ($r !== null && Value::isTrue($r)) {
                    return 1;
                }
                return ($l === null || $r === null) ? null : 0;
            };
        }
        if (\in_array($op, ['=', '<>', '<', '<=', '>', '>=', 'IS', 'IS NOT'], true)) {
            return $this->compileComparison($e, $op, $lc, $rc, $info, $alias);
        }

        if ($op === '->' || $op === '->>') {
            return static fn (RowEnv $env, Evaluator $ev): null|int|float|string
                => $ev->jsonArrow($op, $lc($env, $ev), $rc($env, $ev));
        }
        if ($op === '||') {
            return static function (RowEnv $env, Evaluator $ev) use ($lc, $rc): ?string {
                $l = $lc($env, $ev);
                $r = $rc($env, $ev);
                return ($l === null || $r === null) ? null : (string) Value::toText($l) . (string) Value::toText($r);
            };
        }

        // Arithmetic / bitwise: NULL-propagating.
        return match ($op) {
            '+' => static function (RowEnv $env, Evaluator $ev) use ($lc, $rc): null|int|float {
                $l = $lc($env, $ev);
                $r = $rc($env, $ev);
                return ($l === null || $r === null) ? null : Value::toNumber($l) + Value::toNumber($r);
            },
            '-' => static function (RowEnv $env, Evaluator $ev) use ($lc, $rc): null|int|float {
                $l = $lc($env, $ev);
                $r = $rc($env, $ev);
                return ($l === null || $r === null) ? null : Value::toNumber($l) - Value::toNumber($r);
            },
            '*' => static function (RowEnv $env, Evaluator $ev) use ($lc, $rc): null|int|float {
                $l = $lc($env, $ev);
                $r = $rc($env, $ev);
                return ($l === null || $r === null) ? null : Value::toNumber($l) * Value::toNumber($r);
            },
            '/' => static function (RowEnv $env, Evaluator $ev) use ($lc, $rc): null|int|float {
                $l = $lc($env, $ev);
                $r = $rc($env, $ev);
                return ($l === null || $r === null) ? null : $ev->divide($l, $r);
            },
            '%' => static function (RowEnv $env, Evaluator $ev) use ($lc, $rc): null|int|float {
                $l = $lc($env, $ev);
                $r = $rc($env, $ev);
                return ($l === null || $r === null) ? null : $ev->modulo($l, $r);
            },
            '&' => static function (RowEnv $env, Evaluator $ev) use ($lc, $rc): ?int {
                $l = $lc($env, $ev);
                $r = $rc($env, $ev);
                return ($l === null || $r === null) ? null : (int) Value::toNumber($l) & (int) Value::toNumber($r);
            },
            '|' => static function (RowEnv $env, Evaluator $ev) use ($lc, $rc): ?int {
                $l = $lc($env, $ev);
                $r = $rc($env, $ev);
                return ($l === null || $r === null) ? null : (int) Value::toNumber($l) | (int) Value::toNumber($r);
            },
            '<<' => static function (RowEnv $env, Evaluator $ev) use ($lc, $rc): ?int {
                $l = $lc($env, $ev);
                $r = $rc($env, $ev);
                return ($l === null || $r === null) ? null : (int) Value::toNumber($l) << (int) Value::toNumber($r);
            },
            '>>' => static function (RowEnv $env, Evaluator $ev) use ($lc, $rc): ?int {
                $l = $lc($env, $ev);
                $r = $rc($env, $ev);
                return ($l === null || $r === null) ? null : (int) Value::toNumber($l) >> (int) Value::toNumber($r);
            },
            default => null,
        };
    }

    /**
     * Compile a comparison, pre-resolving affinity and collation at compile time
     * (constant for the whole scan) so the per-row closure does only the coercion
     * and the comparison.
     *
     * @param \Closure(RowEnv,Evaluator):(null|int|float|string|Blob) $lc
     * @param \Closure(RowEnv,Evaluator):(null|int|float|string|Blob) $rc
     */
    private function compileComparison(Expr $e, string $op, \Closure $lc, \Closure $rc, TableInfo $info, string $alias): \Closure
    {
        $la = $this->compileAffinity($e->left, $info, $alias);
        $ra = $this->compileAffinity($e->right, $info, $alias);
        $numeric = static fn (?Affinity $a): bool =>
            $a === Affinity::INTEGER || $a === Affinity::REAL || $a === Affinity::NUMERIC;

        $coerceR = false;
        $coerceL = false;
        $coerceRText = false;
        $coerceLText = false;
        if ($numeric($la) && !$numeric($ra)) {
            $coerceR = true;
        } elseif ($numeric($ra) && !$numeric($la)) {
            $coerceL = true;
        } elseif ($la === Affinity::TEXT && $ra === null) {
            $coerceRText = true;
        } elseif ($ra === Affinity::TEXT && $la === null) {
            $coerceLText = true;
        }

        $coll = $this->compileCollation($e, $info, $alias);

        return static function (RowEnv $env, Evaluator $ev) use ($lc, $rc, $op, $coll, $coerceL, $coerceR, $coerceLText, $coerceRText): ?int {
            $l = $lc($env, $ev);
            $r = $rc($env, $ev);
            if ($l === null || $r === null) {
                // `IS`/`IS NOT` are NULL-safe and never yield NULL; the other
                // comparisons propagate NULL.
                if ($op === 'IS' || $op === 'IS NOT') {
                    return (($l === null && $r === null) === ($op === 'IS')) ? 1 : 0;
                }
                return null;
            }
            if ($coerceR) {
                $r = Affinity::NUMERIC->apply($r);
            } elseif ($coerceL) {
                $l = Affinity::NUMERIC->apply($l);
            } elseif ($coerceRText) {
                $r = Affinity::TEXT->apply($r);
            } elseif ($coerceLText) {
                $l = Affinity::TEXT->apply($l);
            }
            $cmp = Value::compare($l, $r, $coll);
            return match ($op) {
                '=', 'IS' => $cmp === 0 ? 1 : 0,
                '<>', 'IS NOT' => $cmp !== 0 ? 1 : 0,
                '<' => $cmp < 0 ? 1 : 0,
                '<=' => $cmp <= 0 ? 1 : 0,
                '>' => $cmp > 0 ? 1 : 0,
                '>=' => $cmp >= 0 ? 1 : 0,
                default => 0,
            };
        };
    }

    private function compileAffinity(?Expr $e, TableInfo $info, string $alias): ?Affinity
    {
        if ($e === null) {
            return null;
        }
        return match ($e->kind) {
            Expr::COL => (function () use ($e, $info, $alias): ?Affinity {
                $pos = $this->compileColPos($e, $info, $alias);
                return ($pos === null || $pos < 0) ? null : $info->columns[$pos]->affinity;
            })(),
            Expr::CAST => Affinity::fromDeclaredType($e->typeName),
            Expr::COLLATE => $this->compileAffinity($e->operand, $info, $alias),
            default => null,
        };
    }

    private function compileCollation(Expr $e, TableInfo $info, string $alias): string
    {
        $c = $this->explicitCollation($e->left) ?? $this->explicitCollation($e->right);
        if ($c !== null) {
            return $c;
        }
        foreach ([$e->left, $e->right] as $side) {
            if ($side !== null && $side->kind === Expr::COL) {
                $pos = $this->compileColPos($side, $info, $alias);
                if ($pos !== null && $pos >= 0) {
                    return $info->columns[$pos]->collation ?? Collation::BINARY;
                }
            }
        }
        return Collation::BINARY;
    }

    private function compileBetween(Expr $e, TableInfo $info, string $alias): ?\Closure
    {
        $oc = $this->compile($e->operand, $info, $alias);
        $lo = $this->compile($e->low, $info, $alias);
        $hi = $this->compile($e->high, $info, $alias);
        if ($oc === null || $lo === null || $hi === null) {
            return null;
        }
        $not = $e->not;
        return static function (RowEnv $env, Evaluator $ev) use ($oc, $lo, $hi, $not): null|int {
            $v = $oc($env, $ev);
            $l = $lo($env, $ev);
            $h = $hi($env, $ev);
            if ($v === null || $l === null || $h === null) {
                return null;
            }
            $in = Value::compare($v, $l) >= 0 && Value::compare($v, $h) <= 0;
            return ($in !== $not) ? 1 : 0;
        };
    }

    private function compileCase(Expr $e, TableInfo $info, string $alias): ?\Closure
    {
        $subjectC = $e->subject !== null ? $this->compile($e->subject, $info, $alias) : null;
        if ($e->subject !== null && $subjectC === null) {
            return null;
        }
        $branches = [];
        foreach ($e->whens as [$when, $then]) {
            $wc = $this->compile($when, $info, $alias);
            $tc = $this->compile($then, $info, $alias);
            if ($wc === null || $tc === null) {
                return null;
            }
            $branches[] = [$wc, $tc];
        }
        $elseC = $e->elseExpr !== null ? $this->compile($e->elseExpr, $info, $alias) : null;
        if ($e->elseExpr !== null && $elseC === null) {
            return null;
        }

        if ($subjectC !== null) {
            return static function (RowEnv $env, Evaluator $ev) use ($subjectC, $branches, $elseC): null|int|float|string|Blob {
                $subject = $subjectC($env, $ev);
                foreach ($branches as [$wc, $tc]) {
                    $w = $wc($env, $ev);
                    if ($subject !== null && $w !== null && Value::compare($subject, $w) === 0) {
                        return $tc($env, $ev);
                    }
                }
                return $elseC !== null ? $elseC($env, $ev) : null;
            };
        }
        return static function (RowEnv $env, Evaluator $ev) use ($branches, $elseC): null|int|float|string|Blob {
            foreach ($branches as [$wc, $tc]) {
                $cond = $wc($env, $ev);
                if ($cond !== null && Value::isTrue($cond)) {
                    return $tc($env, $ev);
                }
            }
            return $elseC !== null ? $elseC($env, $ev) : null;
        };
    }

    private function compileFunc(Expr $e, TableInfo $info, string $alias): ?\Closure
    {
        $name = \strtolower((string) $e->name);

        if (Aggregates::isAggregate($name)) {
            $id = \spl_object_id($e);
            return static fn (RowEnv $env, Evaluator $ev): null|int|float|string|Blob => $ev->aggregateValues[$id] ?? null;
        }

        // Only the plain scalar functions are compiled; date/like/glob and the
        // executor-bound helpers fall back to evaluate().
        if (!ScalarFunctions::exists($name)) {
            return null;
        }
        $argCs = [];
        foreach ($e->args as $a) {
            $c = $this->compile($a, $info, $alias);
            if ($c === null) {
                return null;
            }
            $argCs[] = $c;
        }
        return static function (RowEnv $env, Evaluator $ev) use ($name, $argCs): null|int|float|string|Blob {
            $args = [];
            foreach ($argCs as $c) {
                $args[] = $c($env, $ev);
            }
            return ScalarFunctions::call($name, $args);
        };
    }

    public function evaluate(Expr $e, ?RowEnv $env = null): null|int|float|string|Blob
    {
        switch ($e->kind) {
            case Expr::LIT:
                return $e->value;

            case Expr::COL:
                // NEW./OLD. references inside a trigger body or WHEN clause.
                if ($e->table !== null) {
                    $tl = \strtolower($e->table);
                    if ($tl === 'new' || $tl === 'old') {
                        $tenv = $this->executor->currentTriggerEnv();
                        if ($tenv !== null && $tenv->hasColumn($e->table, (string) $e->name)) {
                            return $tenv->resolveColumn($e->table, (string) $e->name);
                        }
                    }
                }
                if ($env !== null) {
                    // Fast path: a memoized concrete slot, resolved once per query.
                    $id = \spl_object_id($e);
                    $slot = $this->colSlot[$id] ?? null;
                    if ($slot !== null) {
                        return $env->valueAt($slot[0], $slot[1]);
                    }
                    $slot = $env->resolveSlot($e->table, (string) $e->name);
                    if ($slot !== null) {
                        $this->colSlot[$id] = $slot;
                        return $env->valueAt($slot[0], $slot[1]);
                    }
                    if ($env->hasColumn($e->table, (string) $e->name)) {
                        return $env->resolveColumn($e->table, (string) $e->name);
                    }
                }
                // Fall back to a SELECT-list alias (legal in HAVING/ORDER BY).
                if ($e->table === null && isset($this->aliasExprs[\strtolower((string) $e->name)])) {
                    return $this->evaluate($this->aliasExprs[\strtolower((string) $e->name)], $env);
                }
                // Correlated subquery: resolve against the enclosing query scope.
                if ($this->outerEnv !== null) {
                    [$found, $value] = $this->resolveOuter($e->table, (string) $e->name);
                    if ($found) {
                        return $value;
                    }
                }
                if ($env === null) {
                    throw new SqlException("no such column: {$e->name}");
                }
                return $env->resolveColumn($e->table, (string) $e->name);

            case Expr::PARAM:
                return $this->resolveParam((string) $e->name);

            case Expr::UNARY:
                return $this->unary($e, $env);

            case Expr::BIN:
                return $this->binary($e, $env);

            case Expr::FUNC:
                return $this->func($e, $env);

            case Expr::CAST:
                return $this->cast($this->evaluate($e->operand, $env), (string) $e->typeName);

            case Expr::CASE_:
                return $this->caseExpr($e, $env);

            case Expr::COLLATE:
                return $this->evaluate($e->operand, $env);

            case Expr::ISNULL:
                $v = $this->evaluate($e->operand, $env);
                return ($v === null) === !$e->not ? 1 : 0;

            case Expr::BETWEEN:
                return $this->between($e, $env);

            case Expr::IN:
                return $this->inExpr($e, $env);

            case Expr::LIKE:
                return $this->likeExpr($e, $env);

            case Expr::SUBQUERY:
                return $this->scalarSubquery($e->select, $env);

            case Expr::EXISTS:
                $rows = $this->executor->runSubquerySelect($e->select, $this->params, $env, $this);
                return $rows === [] ? 0 : 1;

            default:
                throw new SqlException("cannot evaluate expression kind: {$e->kind}");
        }
    }

    public function resolveParam(string $marker): null|int|float|string|Blob
    {
        // Positional params arrive pre-numbered as "?N" from the parser, so
        // resolution is order-independent (binding key is the 1-based ordinal).
        if ($marker[0] === '?') {
            return $this->params[\substr($marker, 1)] ?? null;
        }
        // Named: accept the binding with or without the leading sigil.
        return $this->params[$marker]
            ?? $this->params[\substr($marker, 1)]
            ?? null;
    }

    private function unary(Expr $e, ?RowEnv $env): null|int|float|string|Blob
    {
        if ($e->op === 'NOT') {
            $v = $this->evaluate($e->operand, $env);
            if ($v === null) {
                return null;
            }
            return Value::isTrue($v) ? 0 : 1;
        }
        $v = $this->evaluate($e->operand, $env);
        if ($v === null) {
            return null;
        }
        return match ($e->op) {
            '-' => self::negate(Value::toNumber($v)),
            '+' => Value::toNumber($v),
            '~' => ~((int) Value::toNumber($v)),
            default => throw new SqlException("bad unary op {$e->op}"),
        };
    }

    private static function negate(int|float $n): int|float
    {
        return $n === 0 ? 0 : -$n;
    }

    private function binary(Expr $e, ?RowEnv $env): null|int|float|string|Blob
    {
        $op = (string) $e->op;

        // Logical operators implement three-valued logic and short-circuit.
        if ($op === 'AND') {
            $l = $this->evaluate($e->left, $env);
            if ($l !== null && !Value::isTrue($l)) {
                return 0;
            }
            $r = $this->evaluate($e->right, $env);
            if ($r !== null && !Value::isTrue($r)) {
                return 0;
            }
            return ($l === null || $r === null) ? null : 1;
        }
        if ($op === 'OR') {
            $l = $this->evaluate($e->left, $env);
            if ($l !== null && Value::isTrue($l)) {
                return 1;
            }
            $r = $this->evaluate($e->right, $env);
            if ($r !== null && Value::isTrue($r)) {
                return 1;
            }
            return ($l === null || $r === null) ? null : 0;
        }

        if ($op === 'IS' || $op === 'IS NOT') {
            $l = $this->evaluate($e->left, $env);
            $r = $this->evaluate($e->right, $env);
            return ($this->isEqual($l, $r, $e, $env) === ($op === 'IS')) ? 1 : 0;
        }

        $l = $this->evaluate($e->left, $env);
        $r = $this->evaluate($e->right, $env);

        // Comparisons and arithmetic propagate NULL.
        if (\in_array($op, ['=', '<>', '<', '<=', '>', '>='], true)) {
            if ($l === null || $r === null) {
                return null;
            }
            return $this->compareOp($op, $l, $r, $e, $env) ? 1 : 0;
        }

        if ($op === '->' || $op === '->>') {
            return $this->jsonArrow($op, $l, $r);
        }
        if ($op === '||') {
            if ($l === null || $r === null) {
                return null;
            }
            return (string) Value::toText($l) . (string) Value::toText($r);
        }

        if ($l === null || $r === null) {
            return null;
        }

        return match ($op) {
            '+' => $this->arith($l, $r, static fn ($a, $b) => $a + $b),
            '-' => $this->arith($l, $r, static fn ($a, $b) => $a - $b),
            '*' => $this->arith($l, $r, static fn ($a, $b) => $a * $b),
            '/' => $this->divide($l, $r),
            '%' => $this->modulo($l, $r),
            '&' => (int) Value::toNumber($l) & (int) Value::toNumber($r),
            '|' => (int) Value::toNumber($l) | (int) Value::toNumber($r),
            '<<' => (int) Value::toNumber($l) << (int) Value::toNumber($r),
            '>>' => (int) Value::toNumber($l) >> (int) Value::toNumber($r),
            default => throw new SqlException("bad operator $op"),
        };
    }

    private function arith(mixed $l, mixed $r, callable $fn): int|float
    {
        return $fn(Value::toNumber($l), Value::toNumber($r));
    }

    private function divide(mixed $l, mixed $r): null|int|float
    {
        $a = Value::toNumber($l);
        $b = Value::toNumber($r);
        if ($b == 0) {
            return null; // SQLite: division by zero yields NULL
        }
        if (\is_int($a) && \is_int($b)) {
            return \intdiv($a, $b); // integer division truncates toward zero
        }
        return $a / $b;
    }

    private function modulo(mixed $l, mixed $r): null|int|float
    {
        $a = (int) Value::toNumber($l);
        $b = (int) Value::toNumber($r);
        if ($b === 0) {
            return null;
        }
        return $a % $b;
    }

    /**
     * Combine two already-evaluated operands under a binary operator. The VDBE
     * VM uses this so its bytecode shares the interpreter's exact NULL,
     * comparison-affinity, and collation semantics. (The tree-walker keeps its
     * own short-circuiting AND/OR in {@see binary()}; combining both evaluated
     * operands here yields the identical three-valued result.)
     */
    public function combineBinary(string $op, mixed $l, mixed $r, Expr $e, ?RowEnv $env): null|int|float|string|Blob
    {
        if ($op === 'AND') {
            if ($l !== null && !Value::isTrue($l)) {
                return 0;
            }
            if ($r !== null && !Value::isTrue($r)) {
                return 0;
            }
            return ($l === null || $r === null) ? null : 1;
        }
        if ($op === 'OR') {
            if ($l !== null && Value::isTrue($l)) {
                return 1;
            }
            if ($r !== null && Value::isTrue($r)) {
                return 1;
            }
            return ($l === null || $r === null) ? null : 0;
        }
        if ($op === 'IS' || $op === 'IS NOT') {
            return ($this->isEqual($l, $r, $e, $env) === ($op === 'IS')) ? 1 : 0;
        }
        if (\in_array($op, ['=', '<>', '<', '<=', '>', '>='], true)) {
            if ($l === null || $r === null) {
                return null;
            }
            return $this->compareOp($op, $l, $r, $e, $env) ? 1 : 0;
        }
        if ($op === '->' || $op === '->>') {
            return $this->jsonArrow($op, $l, $r);
        }
        if ($op === '||') {
            if ($l === null || $r === null) {
                return null;
            }
            return (string) Value::toText($l) . (string) Value::toText($r);
        }
        if ($l === null || $r === null) {
            return null;
        }
        return match ($op) {
            '+' => $this->arith($l, $r, static fn ($a, $b) => $a + $b),
            '-' => $this->arith($l, $r, static fn ($a, $b) => $a - $b),
            '*' => $this->arith($l, $r, static fn ($a, $b) => $a * $b),
            '/' => $this->divide($l, $r),
            '%' => $this->modulo($l, $r),
            '&' => (int) Value::toNumber($l) & (int) Value::toNumber($r),
            '|' => (int) Value::toNumber($l) | (int) Value::toNumber($r),
            '<<' => (int) Value::toNumber($l) << (int) Value::toNumber($r),
            '>>' => (int) Value::toNumber($l) >> (int) Value::toNumber($r),
            default => throw new SqlException("bad operator $op"),
        };
    }

    /** Apply a unary operator to an already-evaluated operand (for the VDBE VM). */
    public function combineUnary(string $op, mixed $v): null|int|float|string|Blob
    {
        if ($op === 'NOT') {
            return $v === null ? null : (Value::isTrue($v) ? 0 : 1);
        }
        if ($v === null) {
            return null;
        }
        return match ($op) {
            '-' => self::negate(Value::toNumber($v)),
            '+' => Value::toNumber($v),
            '~' => ~((int) Value::toNumber($v)),
            default => throw new SqlException("bad unary op $op"),
        };
    }

    /**
     * The `->` and `->>` JSON operators. `->` returns the located value as JSON
     * text; `->>` returns it as a SQL value. NULL on either side, or a path that
     * matches nothing, yields NULL; malformed JSON raises (matching SQLite).
     */
    public function jsonArrow(string $op, mixed $l, mixed $r): null|int|float|string
    {
        if ($l === null || $r === null) {
            return null;
        }
        $found = Json::resolve(
            Json::decode((string) Value::toText($l), true),
            Json::arrowSegments($r),
        );
        if ($found === Json::MISSING) {
            return null;
        }
        return $op === '->' ? Json::encode($found) : Json::toSqlValue($found);
    }

    /**
     * SQLite `IS` equality: identical to `=` (same comparison affinity and
     * collation), but NULL-safe — two NULLs are equal and a NULL never equals a
     * non-NULL. Never yields NULL.
     */
    private function isEqual(mixed $l, mixed $r, Expr $e, ?RowEnv $env): bool
    {
        if ($l === null || $r === null) {
            return $l === null && $r === null;
        }
        return $this->compareOp('=', $l, $r, $e, $env);
    }

    private function compareOp(string $op, mixed $l, mixed $r, Expr $e, ?RowEnv $env): bool
    {
        [$l, $r] = $this->applyComparisonAffinity($l, $r, $e, $env);
        $collation = $this->comparisonCollation($e, $env);
        $cmp = Value::compare($l, $r, $collation);
        return match ($op) {
            '=' => $cmp === 0,
            '<>' => $cmp !== 0,
            '<' => $cmp < 0,
            '<=' => $cmp <= 0,
            '>' => $cmp > 0,
            '>=' => $cmp >= 0,
            default => false,
        };
    }

    /**
     * SQLite comparison affinity: numeric affinity on one side coerces the
     * other; TEXT affinity coerces a no-affinity operand to text.
     *
     * @return array{0:null|int|float|string|Blob,1:null|int|float|string|Blob}
     */
    private function applyComparisonAffinity(mixed $l, mixed $r, Expr $e, ?RowEnv $env): array
    {
        $id = \spl_object_id($e);
        if (isset($this->cmpAff[$id])) {
            [$la, $ra] = $this->cmpAff[$id];
        } else {
            $la = $this->exprAffinity($e->left, $env);
            $ra = $this->exprAffinity($e->right, $env);
            if ($env !== null) {
                $this->cmpAff[$id] = [$la, $ra];
            }
        }
        $numeric = static fn (?Affinity $a): bool =>
            $a === Affinity::INTEGER || $a === Affinity::REAL || $a === Affinity::NUMERIC;

        if ($numeric($la) && !$numeric($ra)) {
            $r = Affinity::NUMERIC->apply($r);
        } elseif ($numeric($ra) && !$numeric($la)) {
            $l = Affinity::NUMERIC->apply($l);
        } elseif ($la === Affinity::TEXT && $ra === null) {
            $r = Affinity::TEXT->apply($r);
        } elseif ($ra === Affinity::TEXT && $la === null) {
            $l = Affinity::TEXT->apply($l);
        }
        return [$l, $r];
    }

    private function exprAffinity(?Expr $e, ?RowEnv $env): ?Affinity
    {
        if ($e === null || $env === null) {
            return null;
        }
        return match ($e->kind) {
            Expr::COL => $env->columnAffinity($e->table, (string) $e->name),
            Expr::CAST => Affinity::fromDeclaredType($e->typeName),
            Expr::COLLATE => $this->exprAffinity($e->operand, $env),
            default => null,
        };
    }

    private function comparisonCollation(Expr $e, ?RowEnv $env): string
    {
        $id = \spl_object_id($e);
        if (isset($this->cmpColl[$id])) {
            return $this->cmpColl[$id];
        }
        // Explicit COLLATE on either side wins; else the left column's collation.
        $c = $this->explicitCollation($e->left) ?? $this->explicitCollation($e->right);
        if ($c === null) {
            if ($env !== null && $e->left?->kind === Expr::COL) {
                $c = $env->columnCollation($e->left->table, (string) $e->left->name) ?? Collation::BINARY;
            } elseif ($env !== null && $e->right?->kind === Expr::COL) {
                $c = $env->columnCollation($e->right->table, (string) $e->right->name) ?? Collation::BINARY;
            } else {
                $c = Collation::BINARY;
            }
        }
        if ($env !== null) {
            $this->cmpColl[$id] = $c;
        }
        return $c;
    }

    private function explicitCollation(?Expr $e): ?string
    {
        return $e !== null && $e->kind === Expr::COLLATE ? $e->collation : null;
    }

    private function func(Expr $e, ?RowEnv $env): null|int|float|string|Blob
    {
        $name = \strtolower((string) $e->name);

        if ($e->window !== null) {
            return $this->windowValues[\spl_object_id($e)][$this->windowRow] ?? null;
        }

        if (Aggregates::isAggregate($name)) {
            return $this->aggregateValues[\spl_object_id($e)] ?? null;
        }

        return match ($name) {
            'current_date' => \gmdate('Y-m-d'),
            'current_time' => \gmdate('H:i:s'),
            'current_timestamp' => \gmdate('Y-m-d H:i:s'),
            'last_insert_rowid' => $this->executor->lastInsertId(),
            'changes' => $this->executor->changes(),
            'glob' => $this->globMatch(
                (string) Value::toText($this->evaluate($e->args[1], $env)),
                $this->evaluate($e->args[0], $env),
            ) ? 1 : 0,
            'like' => $this->likeMatch(
                (string) Value::toText($this->evaluate($e->args[0], $env)),
                $this->evaluate($e->args[1], $env),
                isset($e->args[2]) ? (string) Value::toText($this->evaluate($e->args[2], $env)) : null,
            ) ? 1 : 0,
            default => ScalarFunctions::call($name, $this->evalArgs($e->args, $env)),
        };
    }

    /** @return list<null|int|float|string|Blob> */
    private function evalArgs(array $args, ?RowEnv $env): array
    {
        $out = [];
        foreach ($args as $a) {
            $out[] = $this->evaluate($a, $env);
        }
        return $out;
    }

    public function cast(null|int|float|string|Blob $v, string $type): null|int|float|string|Blob
    {
        $aff = Affinity::fromDeclaredType($type);
        if ($v === null) {
            return null;
        }
        return match ($aff) {
            Affinity::INTEGER => (int) Value::toNumber($v),
            Affinity::REAL => (float) Value::toNumber($v),
            Affinity::NUMERIC => self::castNumeric($v),
            Affinity::TEXT => $v instanceof Blob ? $v->bytes : (string) Value::toText($v),
            Affinity::BLOB => $v instanceof Blob ? $v : new Blob((string) Value::toText($v)),
        };
    }

    private static function castNumeric(null|int|float|string|Blob $v): int|float
    {
        $n = Value::toNumber($v);
        if (\is_float($n) && \is_finite($n) && (float) (int) $n === $n) {
            return (int) $n;
        }
        return $n;
    }

    private function caseExpr(Expr $e, ?RowEnv $env): null|int|float|string|Blob
    {
        $subject = $e->subject !== null ? $this->evaluate($e->subject, $env) : null;
        foreach ($e->whens as [$when, $then]) {
            if ($e->subject !== null) {
                $w = $this->evaluate($when, $env);
                if ($subject !== null && $w !== null && Value::compare($subject, $w) === 0) {
                    return $this->evaluate($then, $env);
                }
            } else {
                $cond = $this->evaluate($when, $env);
                if ($cond !== null && Value::isTrue($cond)) {
                    return $this->evaluate($then, $env);
                }
            }
        }
        return $e->elseExpr !== null ? $this->evaluate($e->elseExpr, $env) : null;
    }

    private function between(Expr $e, ?RowEnv $env): null|int|float|string|Blob
    {
        $v = $this->evaluate($e->operand, $env);
        $lo = $this->evaluate($e->low, $env);
        $hi = $this->evaluate($e->high, $env);
        if ($v === null || $lo === null || $hi === null) {
            return null;
        }
        $in = Value::compare($v, $lo) >= 0 && Value::compare($v, $hi) <= 0;
        return ($in !== $e->not) ? 1 : 0;
    }

    private function inExpr(Expr $e, ?RowEnv $env): null|int|float|string|Blob
    {
        $v = $this->evaluate($e->operand, $env);

        $candidates = [];
        if ($e->select !== null) {
            foreach ($this->executor->runSubquerySelect($e->select, $this->params, $env, $this) as $row) {
                $candidates[] = $row[0] ?? null;
            }
        } else {
            foreach ($e->list as $item) {
                $candidates[] = $this->evaluate($item, $env);
            }
        }

        if ($v === null) {
            return null;
        }
        $sawNull = false;
        foreach ($candidates as $c) {
            if ($c === null) {
                $sawNull = true;
                continue;
            }
            if (Value::compare($v, $c) === 0) {
                return $e->not ? 0 : 1;
            }
        }
        // No match: NULL if any candidate was NULL, else definite.
        if ($sawNull) {
            return null;
        }
        return $e->not ? 1 : 0;
    }

    private function likeExpr(Expr $e, ?RowEnv $env): null|int|float|string|Blob
    {
        $subject = $this->evaluate($e->left, $env);
        $pattern = $this->evaluate($e->right, $env);
        if ($subject === null || $pattern === null) {
            return null;
        }
        $escape = $e->escape !== null ? (string) Value::toText($this->evaluate($e->escape, $env)) : null;

        $matched = match ($e->op) {
            'GLOB' => $this->globMatch((string) Value::toText($pattern), $subject),
            'REGEXP' => @\preg_match('/' . \str_replace('/', '\/', (string) Value::toText($pattern)) . '/u', (string) Value::toText($subject)) === 1,
            // MATCH has no meaning without an FTS table (FTS5 is unimplemented).
            // SQLite raises here rather than silently treating it as LIKE.
            'MATCH' => throw new SqlException('unable to use function MATCH in the requested context'),
            default => $this->likeMatch((string) Value::toText($subject), $pattern, $escape),
        };
        return ($matched !== $e->not) ? 1 : 0;
    }

    private function likeMatch(string $subject, null|int|float|string|Blob $pattern, ?string $escape): bool
    {
        $regex = self::likeToRegex((string) Value::toText($pattern), $escape);
        // SQLite LIKE is case-insensitive for ASCII by default.
        return \preg_match($regex . 'iu', $subject) === 1;
    }

    private static function likeToRegex(string $pattern, ?string $escape): string
    {
        $re = '/^';
        $len = \strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];
            if ($escape !== null && $escape !== '' && $ch === $escape[0] && $i + 1 < $len) {
                $re .= \preg_quote($pattern[++$i], '/');
                continue;
            }
            $re .= match ($ch) {
                '%' => '.*',
                '_' => '.',
                default => \preg_quote($ch, '/'),
            };
        }
        return $re . '$/';
    }

    private function globMatch(string $pattern, null|int|float|string|Blob $subject): bool
    {
        $re = '/^';
        $len = \strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];
            $re .= match ($ch) {
                '*' => '.*',
                '?' => '.',
                '[' => self::globClass($pattern, $i),
                default => \preg_quote($ch, '/'),
            };
        }
        return \preg_match($re . '$/su', (string) Value::toText($subject)) === 1;
    }

    private static function globClass(string $pattern, int &$i): string
    {
        $len = \strlen($pattern);
        $class = '[';
        $i++;
        if ($i < $len && $pattern[$i] === '^') {
            $class .= '^';
            $i++;
        }
        while ($i < $len && $pattern[$i] !== ']') {
            $class .= \preg_quote($pattern[$i], '/');
            $i++;
        }
        return $class . ']';
    }

    private function scalarSubquery(SelectStatement $select, ?RowEnv $outerEnv): null|int|float|string|Blob
    {
        $rows = $this->executor->runSubquerySelect($select, $this->params, $outerEnv, $this);
        if ($rows === []) {
            return null;
        }
        return $rows[0][0] ?? null;
    }
}
