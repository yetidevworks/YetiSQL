<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

final class CreateIndexStatement implements Statement
{
    public function __construct(
        public string $name,
        public string $table,
        /** @var list<OrderTerm> indexed columns/expressions with optional sort order */
        public array $columns = [],
        public bool $unique = false,
        public bool $ifNotExists = false,
        public ?Expr $where = null,
        public string $sql = '',
    ) {
    }
}
