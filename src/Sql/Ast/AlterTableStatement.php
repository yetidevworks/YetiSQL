<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

final class AlterTableStatement implements Statement
{
    public const RENAME_TABLE = 'rename_table';
    public const RENAME_COLUMN = 'rename_column';
    public const ADD_COLUMN = 'add_column';
    public const DROP_COLUMN = 'drop_column';

    public function __construct(
        public string $table,
        public string $action,
        /** New table name (RENAME TO) or new column name (RENAME COLUMN). */
        public ?string $newName = null,
        /** Existing column name (RENAME COLUMN / DROP COLUMN). */
        public ?string $columnName = null,
        /** Column to append (ADD COLUMN). */
        public ?ColumnDef $column = null,
    ) {
    }
}
