<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Vdbe;

/**
 * VDBE opcodes. Each instruction is a numeric tuple [op, p1, p2, p3, aux]
 * executed by {@see Vm} against a register file. The layout mirrors SQLite's
 * register virtual machine: cursor/control opcodes drive a table scan while the
 * arithmetic/comparison opcodes evaluate WHERE and projection expressions into
 * registers, reusing the tree-walking evaluator's primitives for exact NULL,
 * affinity, and collation semantics.
 */
final class Opcode
{
    // --- cursor & control flow -------------------------------------------
    public const INIT = 1;        // goto aux (program entry)
    public const OPEN_READ = 2;   // open a read cursor on table rooted at p1
    public const REWIND = 3;      // position cursor at first row; if empty, goto p2
    public const NEXT = 4;        // advance cursor; if a row remains, goto p2
    public const HALT = 5;        // stop execution
    public const GOTO_ = 6;       // goto p2
    public const RESULT_ROW = 7;  // emit registers [p1 .. p1+p2-1] as one output row
    public const IF_FALSE = 8;    // if register p1 is false or NULL, goto p2

    // --- value loads ------------------------------------------------------
    public const COLUMN = 9;      // r[p2] = current row's column p1
    public const ROWID = 10;      // r[p2] = current row's rowid
    public const LOAD = 11;       // r[p2] = literal (aux)
    public const PARAM = 12;      // r[p2] = bound parameter (aux = marker)

    // --- expression operators --------------------------------------------
    public const BINOP = 13;      // r[p3] = r[p1] <op> r[p2]   (aux = [op, exprNode])
    public const UNOP = 14;       // r[p2] = <op> r[p1]         (aux = op)
    public const ISNULL = 15;     // r[p2] = (r[p1] IS [NOT] NULL)  (p3 = not flag)
    public const CAST = 16;       // r[p2] = CAST(r[p1] AS aux)

    /** @var array<int,string> */
    private const NAMES = [
        self::INIT => 'Init',
        self::OPEN_READ => 'OpenRead',
        self::REWIND => 'Rewind',
        self::NEXT => 'Next',
        self::HALT => 'Halt',
        self::GOTO_ => 'Goto',
        self::RESULT_ROW => 'ResultRow',
        self::IF_FALSE => 'IfNot',
        self::COLUMN => 'Column',
        self::ROWID => 'Rowid',
        self::LOAD => 'Load',
        self::PARAM => 'Param',
        self::BINOP => 'BinOp',
        self::UNOP => 'UnOp',
        self::ISNULL => 'IsNull',
        self::CAST => 'Cast',
    ];

    public static function name(int $op): string
    {
        return self::NAMES[$op] ?? ('Op' . $op);
    }
}
