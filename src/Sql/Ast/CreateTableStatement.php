<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

final class CreateTableStatement implements Statement
{
    public function __construct(
        public string $name,
        /** @var list<ColumnDef> */
        public array $columns = [],
        public bool $ifNotExists = false,
        public bool $withoutRowid = false,
        public bool $temporary = false,
        /** @var list<string> column names forming a table-level PRIMARY KEY */
        public array $primaryKeyColumns = [],
        /** @var list<list<string>> column-name groups with a table-level UNIQUE constraint */
        public array $uniqueConstraints = [],
        /** @var list<ForeignKey> FOREIGN KEY constraints (column- and table-level) */
        public array $foreignKeys = [],
        /** @var list<CheckConstraint> CHECK constraints (column- and table-level) */
        public array $checks = [],
        /** The original CREATE SQL text, stored verbatim in the schema. */
        public string $sql = '',
    ) {
    }
}
