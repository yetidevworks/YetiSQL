<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

/** A joined table: INNER/LEFT/CROSS with an ON predicate or USING column list. */
final class JoinClause
{
    public const INNER = 'INNER';
    public const LEFT = 'LEFT';
    public const CROSS = 'CROSS';

    public function __construct(
        public string $type,
        public TableRef $table,
        public ?Expr $on = null,
        /** @var list<string> */
        public array $using = [],
        public bool $natural = false,
    ) {
    }
}
