<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Engine;

/**
 * Resolved metadata for a table: its columns, storage root page, and how its
 * rowid relates to declared columns.
 */
final class TableInfo
{
    /** @var array<string,int> lower-cased column name => position */
    public array $columnIndex = [];

    /** @var list<int> positions of GENERATED columns (empty for most tables) */
    public array $generatedPositions = [];

    public function __construct(
        public string $name,
        public int $rootPage,
        /** @var list<ColumnInfo> */
        public array $columns,
        /** Position of the INTEGER PRIMARY KEY column aliasing rowid, or -1. */
        public int $rowidAlias = -1,
        public bool $autoincrement = false,
        public bool $withoutRowid = false,
        public string $sql = '',
        /** @var list<\YetiDevWorks\YetiSQL\Sql\Ast\ForeignKey> FOREIGN KEY constraints on this table */
        public array $foreignKeys = [],
        /** @var list<\YetiDevWorks\YetiSQL\Sql\Ast\CheckConstraint> CHECK constraints on this table */
        public array $checks = [],
    ) {
        foreach ($columns as $i => $col) {
            $this->columnIndex[\strtolower($col->name)] = $i;
            if ($col->generated !== null) {
                $this->generatedPositions[] = $i;
            }
        }
    }

    public function columnCount(): int
    {
        return \count($this->columns);
    }

    /** Resolve a column name to its position, or null. Case-insensitive. */
    public function columnPos(string $name): ?int
    {
        return $this->columnIndex[\strtolower($name)] ?? null;
    }

    public function hasRowidAlias(): bool
    {
        return $this->rowidAlias >= 0;
    }
}
