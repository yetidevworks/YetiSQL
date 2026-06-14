<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Vdbe;

use YetiDevWorks\YetiSQL\Engine\TableInfo;
use YetiDevWorks\YetiSQL\Sql\Ast\Expr;
use YetiDevWorks\YetiSQL\Sql\Ast\SelectStatement;
use YetiDevWorks\YetiSQL\Types\Affinity;
use YetiDevWorks\YetiSQL\Types\Collation;

/**
 * Lowers a single-table SELECT into a {@see Program}. The supported subset is a
 * sequential table scan with a WHERE filter, scalar projection, and an optional
 * literal LIMIT/OFFSET. Anything outside that — joins, GROUP BY, aggregates,
 * DISTINCT, ORDER BY, HAVING, window functions, or a non-compilable expression
 * (subqueries, IN, LIKE, …) — makes {@see compile()} return null so the caller
 * falls back to the tree-walking executor.
 */
final class Compiler
{
    /** @var list<array{0:int,1:int,2:int,3:int,4:mixed}> */
    private array $code = [];
    private int $nextReg = 0;

    private function __construct(
        private readonly TableInfo $info,
        private readonly string $alias,
    ) {
    }

    /**
     * @param list<array{name:string,expr:?Expr,star:bool,tableStar:?string}> $outputCols
     */
    public static function compile(SelectStatement $select, TableInfo $info, string $alias, array $outputCols): ?Program
    {
        // Bail on anything the scan VM does not model.
        if ($select->groupBy !== [] || $select->having !== null || $select->distinct
            || $select->orderBy !== [] || $select->compound !== null || $select->with !== []) {
            return null;
        }

        $limit = self::literalInt($select->limit);
        $offset = self::literalInt($select->offset);
        if ($select->limit !== null && $limit === null) {
            return null; // non-literal LIMIT
        }
        if ($select->offset !== null && $offset === null) {
            return null;
        }
        if ($limit !== null && $limit < 0) {
            $limit = null;
        }
        if ($offset !== null && $offset < 0) {
            $offset = 0;
        }

        $c = new self($info, $alias);

        // Reserve registers for the projected output, contiguous for ResultRow.
        $resultCols = [];
        $projExprs = [];
        foreach ($outputCols as $oc) {
            if ($oc['star'] || $oc['tableStar'] !== null) {
                foreach ($info->columns as $col) {
                    $resultCols[] = $col->name;
                    $projExprs[] = Expr::col($alias, $col->name);
                }
                continue;
            }
            if (!$oc['expr'] instanceof Expr) {
                return null;
            }
            $resultCols[] = $oc['name'];
            $projExprs[] = $oc['expr'];
        }
        if ($projExprs === []) {
            return null;
        }

        // --- emit the program -------------------------------------------
        // 0: OpenRead   1: Rewind ->end   2..: body   Next ->body   end: Halt
        $c->emit(Opcode::OPEN_READ, $info->rootPage, 0, 0, null);
        $rewindAddr = $c->emit(Opcode::REWIND, 0, 0, 0, null); // p2 patched to end

        $bodyStart = \count($c->code);

        if ($select->where !== null) {
            $wReg = $c->compileExpr($select->where);
            if ($wReg === null) {
                return null;
            }
            $c->emit(Opcode::IF_FALSE, $wReg, 0, 0, null); // p2 patched to next
        }

        // Reserve a contiguous register block for ResultRow up front, so the
        // temporaries an expression allocates can't land on a later slot.
        $resultBase = $c->nextReg;
        $c->nextReg += \count($projExprs);
        foreach ($projExprs as $i => $pe) {
            $reg = $c->compileExprInto($pe, $resultBase + $i);
            if ($reg === null) {
                return null;
            }
        }
        $c->emit(Opcode::RESULT_ROW, $resultBase, \count($projExprs), 0, null);

        $nextAddr = $c->emit(Opcode::NEXT, 0, $bodyStart, 0, null);
        $endAddr = \count($c->code);
        $c->emit(Opcode::HALT, 0, 0, 0, null);

        // Patch jump targets now that addresses are known.
        $c->code[$rewindAddr][2] = $endAddr;
        $c->code[$nextAddr][2] = $bodyStart;
        // IF_FALSE (if present) jumps to the NEXT instruction.
        foreach ($c->code as &$ins) {
            if ($ins[0] === Opcode::IF_FALSE && $ins[2] === 0) {
                $ins[2] = $nextAddr;
            }
        }
        unset($ins);

        return new Program(
            $c->code,
            $c->nextReg,
            $info->rootPage,
            $info->name,
            $resultCols,
            $limit,
            $offset,
            'SCAN ' . $info->name,
        );
    }

    private function emit(int $op, int $p1, int $p2, int $p3, mixed $aux): int
    {
        $this->code[] = [$op, $p1, $p2, $p3, $aux];
        return \count($this->code) - 1;
    }

    /** Compile an expression, returning the register holding its value, or null. */
    private function compileExpr(Expr $e): ?int
    {
        $dest = $this->nextReg++;
        return $this->compileExprInto($e, $dest);
    }

    private function compileExprInto(Expr $e, int $dest): ?int
    {
        switch ($e->kind) {
            case Expr::LIT:
                $this->emit(Opcode::LOAD, 0, $dest, 0, $e->value);
                return $dest;

            case Expr::PARAM:
                $this->emit(Opcode::PARAM, 0, $dest, 0, (string) $e->name);
                return $dest;

            case Expr::COLLATE:
                // Collation is consumed by comparison; the value is unchanged.
                return $this->compileExprInto($e->operand, $dest);

            case Expr::COL:
                $pos = $this->columnPos($e);
                if ($pos === null) {
                    return null;
                }
                if ($pos === -1) {
                    $this->emit(Opcode::ROWID, 0, $dest, 0, null);
                } else {
                    $this->emit(Opcode::COLUMN, $pos, $dest, 0, null);
                }
                return $dest;

            case Expr::UNARY:
                $src = $this->compileExpr($e->operand);
                if ($src === null) {
                    return null;
                }
                $this->emit(Opcode::UNOP, $src, $dest, 0, (string) $e->op);
                return $dest;

            case Expr::ISNULL:
                $src = $this->compileExpr($e->operand);
                if ($src === null) {
                    return null;
                }
                $this->emit(Opcode::ISNULL, $src, $dest, $e->not ? 1 : 0, null);
                return $dest;

            case Expr::CAST:
                $src = $this->compileExpr($e->operand);
                if ($src === null) {
                    return null;
                }
                $this->emit(Opcode::CAST, $src, $dest, 0, (string) $e->typeName);
                return $dest;

            case Expr::BIN:
                $a = $this->compileExpr($e->left);
                if ($a === null) {
                    return null;
                }
                $b = $this->compileExpr($e->right);
                if ($b === null) {
                    return null;
                }
                $this->emit(Opcode::BINOP, $a, $b, $dest, [
                    (string) $e->op,
                    $e,
                    $this->exprAffinity($e->left),
                    $this->exprAffinity($e->right),
                    $this->comparisonCollation($e),
                ]);
                return $dest;

            default:
                return null; // FUNC/IN/LIKE/BETWEEN/CASE/SUBQUERY/EXISTS -> fall back
        }
    }

    /** Column position, -1 for rowid, or null if it is not this table's column. */
    private function columnPos(Expr $e): ?int
    {
        if ($e->table !== null
            && \strcasecmp($e->table, $this->alias) !== 0
            && \strcasecmp($e->table, $this->info->name) !== 0) {
            return null;
        }
        $name = \strtolower((string) $e->name);
        if (\in_array($name, ['rowid', '_rowid_', 'oid'], true) && $this->info->columnPos((string) $e->name) === null) {
            return -1;
        }
        $pos = $this->info->columnPos((string) $e->name);
        if ($pos === null) {
            return null;
        }
        if ($pos === $this->info->rowidAlias) {
            return -1; // INTEGER PRIMARY KEY reads from the rowid, not the record
        }
        return $pos;
    }

    private function exprAffinity(?Expr $e): ?Affinity
    {
        if ($e === null) {
            return null;
        }
        return match ($e->kind) {
            Expr::COL => $this->columnAffinity($e),
            Expr::CAST => Affinity::fromDeclaredType($e->typeName),
            Expr::COLLATE => $this->exprAffinity($e->operand),
            default => null,
        };
    }

    private function columnAffinity(Expr $e): ?Affinity
    {
        $pos = $this->columnPos($e);
        if ($pos === null) {
            return null;
        }
        if ($pos < 0) {
            return Affinity::INTEGER;
        }
        return $this->info->columns[$pos]->affinity;
    }

    private function comparisonCollation(Expr $e): string
    {
        return $this->explicitCollation($e->left)
            ?? $this->explicitCollation($e->right)
            ?? $this->columnCollation($e->left)
            ?? $this->columnCollation($e->right)
            ?? Collation::BINARY;
    }

    private function explicitCollation(?Expr $e): ?string
    {
        return $e !== null && $e->kind === Expr::COLLATE ? $e->collation : null;
    }

    private function columnCollation(?Expr $e): ?string
    {
        if ($e === null || $e->kind !== Expr::COL) {
            return null;
        }
        $pos = $this->columnPos($e);
        if ($pos === null || $pos < 0) {
            return null;
        }
        return $this->info->columns[$pos]->collation;
    }

    private static function literalInt(?Expr $e): ?int
    {
        if ($e === null) {
            return null;
        }
        if ($e->kind === Expr::LIT && (\is_int($e->value) || \is_float($e->value))) {
            return (int) $e->value;
        }
        if ($e->kind === Expr::UNARY
            && ($e->op === '-' || $e->op === '+')
            && $e->operand?->kind === Expr::LIT
            && (\is_int($e->operand->value) || \is_float($e->operand->value))) {
            $value = (int) $e->operand->value;
            return $e->op === '-' ? -$value : $value;
        }
        return null;
    }
}
