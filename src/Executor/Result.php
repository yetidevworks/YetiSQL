<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Executor;

use YetiDevWorks\YetiSQL\Engine\Blob;

/**
 * The outcome of executing one statement: either a row set (columns + rows)
 * for SELECT/PRAGMA, or an affected-row count for DML/DDL.
 */
final class Result
{
    /** @var \Iterator<int,list<null|int|float|string|Blob>>|null */
    private ?\Iterator $cursorRows = null;
    private bool $cursorStarted = false;

    public function __construct(
        /** @var list<string>|null column names for a row-returning statement */
        public ?array $columns = null,
        /** @var list<list<null|int|float|string|Blob>> */
        public array $rows = [],
        public int $rowCount = 0,
        public int $lastInsertId = 0,
        ?iterable $cursorRows = null,
    ) {
        if ($cursorRows !== null) {
            $this->cursorRows = $cursorRows instanceof \Iterator
                ? $cursorRows
                : new \ArrayIterator($cursorRows);
        }
    }

    public function isQuery(): bool
    {
        return $this->columns !== null;
    }

    public static function affected(int $count, int $lastInsertId = 0): self
    {
        return new self(rowCount: $count, lastInsertId: $lastInsertId);
    }

    /** @param iterable<int,list<null|int|float|string|Blob>> $rows */
    public static function cursor(array $columns, iterable $rows): self
    {
        return new self(columns: $columns, cursorRows: $rows);
    }

    public function fetchRow(int $offset): ?array
    {
        if (isset($this->rows[$offset])) {
            return $this->rows[$offset] ?? null;
        }
        while ($this->cursorRows !== null && \count($this->rows) <= $offset) {
            $this->fetchCursorRow();
        }
        return $this->rows[$offset] ?? null;
    }

    public function materializeRows(): array
    {
        if ($this->cursorRows === null) {
            return $this->rows;
        }
        while ($this->fetchCursorRow() !== null) {
        }
        $this->rowCount = \count($this->rows);
        return $this->rows;
    }

    private function fetchCursorRow(): ?array
    {
        if ($this->cursorRows === null) {
            return null;
        }
        if (!$this->cursorStarted) {
            $this->cursorRows->rewind();
            $this->cursorStarted = true;
        } else {
            $this->cursorRows->next();
        }
        if (!$this->cursorRows->valid()) {
            $this->cursorRows = null;
            $this->rowCount = \count($this->rows);
            return null;
        }
        /** @var list<null|int|float|string|Blob> $row */
        $row = $this->cursorRows->current();
        $this->rows[] = $row;
        return $row;
    }
}
