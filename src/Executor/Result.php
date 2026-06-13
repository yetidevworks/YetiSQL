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
    public function __construct(
        /** @var list<string>|null column names for a row-returning statement */
        public ?array $columns = null,
        /** @var list<list<null|int|float|string|Blob>> */
        public array $rows = [],
        public int $rowCount = 0,
        public int $lastInsertId = 0,
    ) {
    }

    public function isQuery(): bool
    {
        return $this->columns !== null;
    }

    public static function affected(int $count, int $lastInsertId = 0): self
    {
        return new self(rowCount: $count, lastInsertId: $lastInsertId);
    }
}
