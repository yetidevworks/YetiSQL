<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

use YetiDevWorks\YetiSQL\Engine\Blob;

/**
 * A single expression-tree node. Rather than a class per node kind, this is a
 * tagged union: `kind` selects which fields are meaningful. Keeps the AST
 * compact and the tree-walking evaluator's dispatch a single match().
 */
final class Expr
{
    // Integer tags so the evaluator's per-node dispatch compiles to a jump table
    // rather than a chain of string comparisons (this is the single hottest
    // switch in the engine).
    public const LIT = 1;          // value
    public const COL = 2;          // table, name
    public const BIN = 3;          // op, left, right
    public const UNARY = 4;        // op, operand
    public const FUNC = 5;         // name, args, distinct, star
    public const PARAM = 6;        // name (?, ?n, :name ...)
    public const CAST = 7;         // operand, typeName
    public const CASE_ = 8;        // subject?, whens [[when,then]...], elseExpr?
    public const IN = 9;           // operand, list[]|subquery, not
    public const BETWEEN = 10;     // operand, low, high, not
    public const LIKE = 11;        // operand(left), right(pattern), escape?, op(LIKE/GLOB/REGEXP), not
    public const ISNULL = 12;      // operand, not
    public const COLLATE = 13;     // operand, collation
    public const SUBQUERY = 14;    // select
    public const EXISTS = 15;      // select, not

    public function __construct(
        public int $kind,
        public null|int|float|string|Blob $value = null,
        public ?string $table = null,
        public ?string $name = null,
        public ?string $op = null,
        public ?Expr $left = null,
        public ?Expr $right = null,
        public ?Expr $operand = null,
        /** @var list<Expr> */
        public array $args = [],
        public bool $distinct = false,
        public bool $star = false,
        public bool $not = false,
        public ?string $typeName = null,
        public ?Expr $subject = null,
        /** @var list<array{0:Expr,1:Expr}> */
        public array $whens = [],
        public ?Expr $elseExpr = null,
        /** @var list<Expr> */
        public array $list = [],
        public ?Expr $low = null,
        public ?Expr $high = null,
        public ?Expr $escape = null,
        public ?string $collation = null,
        public mixed $select = null,
        /** OVER (...) window specification, when this FUNC is a window call. */
        public ?WindowSpec $window = null,
    ) {
    }

    public static function lit(null|int|float|string|Blob $v): self
    {
        return new self(self::LIT, value: $v);
    }

    public static function col(?string $table, string $name): self
    {
        return new self(self::COL, table: $table, name: $name);
    }

    public static function bin(string $op, Expr $l, Expr $r): self
    {
        return new self(self::BIN, op: $op, left: $l, right: $r);
    }

    public static function unary(string $op, Expr $operand): self
    {
        return new self(self::UNARY, op: $op, operand: $operand);
    }

    public static function param(string $name): self
    {
        return new self(self::PARAM, name: $name);
    }
}
