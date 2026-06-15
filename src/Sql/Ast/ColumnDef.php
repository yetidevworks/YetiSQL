<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

/** A column definition within CREATE TABLE. */
final class ColumnDef
{
    public function __construct(
        public string $name,
        public ?string $typeName = null,
        public bool $primaryKey = false,
        public bool $autoincrement = false,
        public bool $notNull = false,
        public bool $unique = false,
        public bool $primaryKeyDesc = false,
        public ?Expr $default = null,
        public ?string $collation = null,
        /** Verbatim SQL text of the DEFAULT clause's value, for re-serialization. */
        public ?string $defaultSql = null,
        /** GENERATED ALWAYS AS (expr) generation expression, or null. */
        public ?Expr $generated = null,
        /** Whether the generated column is STORED (vs VIRTUAL); informational. */
        public bool $generatedStored = false,
        /** Verbatim "(expr)" source text of the generation expression, or null. */
        public ?string $generatedSql = null,
        /** Column-level REFERENCES clause captured as a single-column ForeignKey, or null. */
        public ?ForeignKey $reference = null,
        /** @var list<CheckConstraint> column-level CHECK constraints */
        public array $checks = [],
    ) {
    }
}
