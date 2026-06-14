<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Vdbe;

use YetiDevWorks\YetiSQL\Engine\Blob;

/**
 * A compiled VDBE program: a flat list of instructions plus the metadata the
 * VM and EXPLAIN need. Instructions are numeric tuples [op, p1, p2, p3, aux];
 * `aux` carries an opcode-specific payload (a literal, a parameter marker, or
 * an [operator, Expr] pair for BinOp).
 */
final class Program
{
    /**
     * @param list<array{0:int,1:int,2:int,3:int,4:mixed}> $instructions
     * @param list<string> $resultColumns output column names, in order
     */
    public function __construct(
        public array $instructions,
        public int $registerCount,
        public int $rootPage,
        public string $tableName,
        public array $resultColumns,
        public ?int $limit = null,
        public ?int $offset = null,
        /** Human-readable one-line plan summary, e.g. "SCAN users". */
        public string $planSummary = '',
    ) {
    }

    /**
     * Disassemble to EXPLAIN-style rows: [addr, opcode, p1, p2, p3, p4, comment].
     *
     * @return list<array{0:int,1:string,2:int,3:int,4:int,5:string,6:string}>
     */
    public function explainRows(): array
    {
        $rows = [];
        foreach ($this->instructions as $addr => [$op, $p1, $p2, $p3, $aux]) {
            $rows[] = [
                $addr,
                Opcode::name($op),
                $p1,
                $p2,
                $p3,
                $this->auxString($op, $aux),
                $this->comment($op, $p1, $p2, $p3, $aux),
            ];
        }
        return $rows;
    }

    private function auxString(int $op, mixed $aux): string
    {
        if ($aux === null) {
            return '';
        }
        if ($op === Opcode::BINOP && \is_array($aux)) {
            return (string) $aux[0];
        }
        if ($aux instanceof Blob) {
            return 'x\'' . \bin2hex($aux->bytes) . '\'';
        }
        if (\is_string($aux)) {
            return $aux;
        }
        return (string) $aux;
    }

    private function comment(int $op, int $p1, int $p2, int $p3, mixed $aux): string
    {
        return match ($op) {
            Opcode::OPEN_READ => "root={$p1} ({$this->tableName})",
            Opcode::REWIND => "if empty goto {$p2}",
            Opcode::NEXT => "goto {$p2} if more rows",
            Opcode::IF_FALSE => "if !r{$p1} goto {$p2}",
            Opcode::GOTO_ => "goto {$p2}",
            Opcode::COLUMN => "r{$p2} = column {$p1}",
            Opcode::ROWID => "r{$p2} = rowid",
            Opcode::LOAD => "r{$p2} = " . $this->auxString($op, $aux),
            Opcode::PARAM => "r{$p2} = param " . $this->auxString($op, $aux),
            Opcode::BINOP => "r{$p3} = r{$p1} " . $this->auxString($op, $aux) . " r{$p2}",
            Opcode::UNOP => "r{$p2} = " . $this->auxString($op, $aux) . " r{$p1}",
            Opcode::ISNULL => 'r' . $p2 . ' = r' . $p1 . ($p3 ? ' NOT NULL' : ' IS NULL'),
            Opcode::CAST => "r{$p2} = CAST(r{$p1} AS " . $this->auxString($op, $aux) . ')',
            Opcode::RESULT_ROW => "output r{$p1}.." . ($p1 + $p2 - 1),
            Opcode::HALT => '',
            default => '',
        };
    }
}
