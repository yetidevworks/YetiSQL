<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

final class InsertStatement implements Statement
{
    public function __construct(
        public string $table,
        /** @var list<string>|null explicit column list, or null for all columns */
        public ?array $columns = null,
        /** @var list<list<Expr>> literal VALUES rows */
        public array $rows = [],
        public ?SelectStatement $select = null,
        public bool $orReplace = false,
        public bool $orIgnore = false,
        public bool $defaultValues = false,
        /** @var list<ResultColumn>|null RETURNING result columns, or null */
        public ?array $returning = null,
        /** ON CONFLICT upsert clause, or null. */
        public ?UpsertClause $upsert = null,
    ) {
    }
}
