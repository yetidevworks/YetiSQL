<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

final class DeleteStatement implements Statement
{
    public function __construct(
        public string $table,
        public ?Expr $where = null,
        /** @var list<ResultColumn>|null RETURNING result columns, or null */
        public ?array $returning = null,
    ) {
    }
}
