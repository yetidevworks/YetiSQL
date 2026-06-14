<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Vdbe;

use YetiDevWorks\YetiSQL\Engine\Pager;
use YetiDevWorks\YetiSQL\Engine\RecordCodec;
use YetiDevWorks\YetiSQL\Engine\TableBTree;
use YetiDevWorks\YetiSQL\Engine\TableInfo;
use YetiDevWorks\YetiSQL\Executor\Evaluator;
use YetiDevWorks\YetiSQL\Executor\RowEnv;
use YetiDevWorks\YetiSQL\Types\Affinity;
use YetiDevWorks\YetiSQL\Types\Value;

/**
 * Executes a compiled {@see Program}: a register machine driving a table scan.
 * Arithmetic, comparison, and unary opcodes delegate to the tree-walking
 * evaluator's primitives, so the bytecode path produces byte-for-byte the same
 * results (NULL handling, affinity, collation) as the interpreter it mirrors.
 */
final class Vm
{
    /**
     * @return array{0:list<string>,1:list<list<null|int|float|string|\YetiDevWorks\YetiSQL\Engine\Blob>>}
     */
    public function run(Program $program, TableInfo $info, string $alias, Pager $pager, Evaluator $eval): array
    {
        $code = $program->instructions;
        $n = \count($code);
        $reg = [];
        $rows = [];

        $tree = null;
        $gen = null;
        $env = null;
        $rowid = 0;
        $payload = null;
        $decoded = [];
        $offsets = null;

        $limit = $program->limit;
        $offset = $program->offset ?? 0;
        $emitted = 0;
        $skipped = 0;

        $pc = 0;
        while ($pc < $n) {
            [$op, $p1, $p2, $p3, $aux] = $code[$pc];
            switch ($op) {
                case Opcode::OPEN_READ:
                    $tree = new TableBTree($pager, $p1);
                    $pc++;
                    break;

                case Opcode::REWIND:
                    $gen = $tree->scan();
                    if (!$gen->valid()) {
                        $pc = $p2;
                        break;
                    }
                    [$rowid, $payload] = $gen->current();
                    $env = null;
                    $decoded = [];
                    $offsets = null;
                    $pc++;
                    break;

                case Opcode::NEXT:
                    $gen->next();
                    if ($gen->valid()) {
                        [$rowid, $payload] = $gen->current();
                        $env = null;
                        $decoded = [];
                        $offsets = null;
                        $pc = $p2;
                        break;
                    }
                    $pc++;
                    break;

                case Opcode::COLUMN:
                    if (!\array_key_exists($p1, $decoded)) {
                        $offsets ??= RecordCodec::columnOffsets($payload);
                        if (isset($offsets[$p1])) {
                            [$serialType, $bodyOff] = $offsets[$p1];
                            $decoded[$p1] = RecordCodec::decodeAt($payload, $serialType, $bodyOff);
                        } else {
                            // Column absent from this record: added via ALTER TABLE
                            // ADD COLUMN after the row was written, so serve its
                            // declared default (matching RowEnv::valueAt).
                            $decoded[$p1] = $info->columns[$p1]->defaultValue;
                        }
                    }
                    $reg[$p2] = $decoded[$p1];
                    $pc++;
                    break;

                case Opcode::ROWID:
                    $reg[$p2] = $rowid;
                    $pc++;
                    break;

                case Opcode::LOAD:
                    $reg[$p2] = $aux;
                    $pc++;
                    break;

                case Opcode::PARAM:
                    $reg[$p2] = $eval->resolveParam((string) $aux);
                    $pc++;
                    break;

                case Opcode::BINOP:
                    $fast = $this->combineBinaryFast($aux[0], $reg[$p1], $reg[$p2], $aux[2] ?? null, $aux[3] ?? null, $aux[4] ?? 'BINARY');
                    if ($fast !== false) {
                        $reg[$p3] = $fast;
                        $pc++;
                        break;
                    }
                    if ($env === null) {
                        $env = new RowEnv();
                        $env->addLazyFrame($alias, $info, $payload, $rowid);
                    }
                    $reg[$p3] = $eval->combineBinary($aux[0], $reg[$p1], $reg[$p2], $aux[1], $env);
                    $pc++;
                    break;

                case Opcode::UNOP:
                    $reg[$p2] = $eval->combineUnary((string) $aux, $reg[$p1]);
                    $pc++;
                    break;

                case Opcode::ISNULL:
                    $reg[$p2] = (($reg[$p1] === null) === ($p3 === 0)) ? 1 : 0;
                    $pc++;
                    break;

                case Opcode::CAST:
                    $reg[$p2] = $eval->cast($reg[$p1], (string) $aux);
                    $pc++;
                    break;

                case Opcode::IF_FALSE:
                    $v = $reg[$p1];
                    if ($v === null || !Value::isTrue($v)) {
                        $pc = $p2;
                    } else {
                        $pc++;
                    }
                    break;

                case Opcode::RESULT_ROW:
                    if ($skipped < $offset) {
                        $skipped++;
                        $pc++;
                        break;
                    }
                    if ($limit !== null && $emitted >= $limit) {
                        return [$program->resultColumns, $rows];
                    }
                    $row = [];
                    for ($i = 0; $i < $p2; $i++) {
                        $row[] = $reg[$p1 + $i];
                    }
                    $rows[] = $row;
                    $emitted++;
                    $pc++;
                    break;

                case Opcode::GOTO_:
                    $pc = $p2;
                    break;

                case Opcode::HALT:
                    return [$program->resultColumns, $rows];

                default:
                    $pc++;
                    break;
            }
        }

        return [$program->resultColumns, $rows];
    }

    private function combineBinaryFast(string $op, mixed $l, mixed $r, ?Affinity $la, ?Affinity $ra, string $collation): mixed
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
            // NULL-safe equality, otherwise identical to `=` (same comparison
            // affinity and collation).
            if ($l === null || $r === null) {
                $eq = $l === null && $r === null;
            } else {
                [$l, $r] = $this->applyComparisonAffinity($l, $r, $la, $ra);
                $eq = Value::compare($l, $r, $collation) === 0;
            }
            return ($eq === ($op === 'IS')) ? 1 : 0;
        }
        if (!\in_array($op, ['=', '<>', '<', '<=', '>', '>='], true)) {
            return false;
        }
        if ($l === null || $r === null) {
            return null;
        }

        [$l, $r] = $this->applyComparisonAffinity($l, $r, $la, $ra);
        $cmp = Value::compare($l, $r, $collation);
        return match ($op) {
            '=' => $cmp === 0 ? 1 : 0,
            '<>' => $cmp !== 0 ? 1 : 0,
            '<' => $cmp < 0 ? 1 : 0,
            '<=' => $cmp <= 0 ? 1 : 0,
            '>' => $cmp > 0 ? 1 : 0,
            '>=' => $cmp >= 0 ? 1 : 0,
            default => false,
        };
    }

    /** @return array{0:mixed,1:mixed} */
    private function applyComparisonAffinity(mixed $l, mixed $r, ?Affinity $la, ?Affinity $ra): array
    {
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
}
