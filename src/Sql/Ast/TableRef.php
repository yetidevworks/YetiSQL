<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

/** A table reference in a FROM clause, optionally a subquery with an alias. */
final class TableRef
{
    public function __construct(
        public ?string $name = null,
        public ?string $alias = null,
        public ?SelectStatement $subquery = null,
        /** Table-valued function name (e.g. pragma_table_info), or null. */
        public ?string $func = null,
        /** @var list<Expr> arguments to the table-valued function */
        public array $funcArgs = [],
    ) {
    }

    public function effectiveName(): string
    {
        return $this->alias ?? $this->name ?? '';
    }
}
