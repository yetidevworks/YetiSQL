<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Engine;

use YetiDevWorks\YetiSQL\Sql\Ast\Expr;
use YetiDevWorks\YetiSQL\Types\Affinity;

/** Resolved metadata for one column of a table. */
final class ColumnInfo
{
    public function __construct(
        public string $name,
        public ?string $declaredType,
        public Affinity $affinity,
        public bool $notNull = false,
        public bool $primaryKey = false,
        public ?Expr $default = null,
        public string $collation = 'BINARY',
        /**
         * The column's DEFAULT resolved to a constant scalar (affinity applied),
         * returned for rows written before the column was added via ALTER TABLE.
         */
        public null|int|float|string|Blob $defaultValue = null,
        /** GENERATED ALWAYS AS (expr): recomputed and stored on every write. */
        public ?Expr $generated = null,
        /**
         * Verbatim DEFAULT source text as written in the CREATE TABLE statement
         * (e.g. "''", "5", "CURRENT_TIMESTAMP"), used to report `dflt_value` from
         * PRAGMA table_info exactly as SQLite does, rather than the evaluated value.
         */
        public ?string $defaultSql = null,
    ) {
    }
}
