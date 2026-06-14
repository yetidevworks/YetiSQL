<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

final class CreateViewStatement implements Statement
{
    public function __construct(
        public string $name,
        public SelectStatement $select,
        /** @var list<string> explicit column names, or [] to infer from the select */
        public array $columns = [],
        public bool $ifNotExists = false,
        public bool $temporary = false,
        /** The original CREATE VIEW SQL text, stored verbatim in the schema. */
        public string $sql = '',
    ) {
    }
}
