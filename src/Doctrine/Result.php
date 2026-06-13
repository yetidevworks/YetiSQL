<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Doctrine;

use Doctrine\DBAL\Driver\Result as ResultInterface;
use YetiDevWorks\YetiSQL\Engine\Blob;
use YetiDevWorks\YetiSQL\Executor\Result as EngineResult;

/**
 * DBAL driver result wrapping a YetiSQL engine result. Provides forward-only
 * cursor fetches plus the bulk fetch helpers DBAL expects. Blob values are
 * surfaced as their raw byte strings, matching pdo_sqlite.
 */
final class Result implements ResultInterface
{
    private int $cursor = 0;
    /** @var list<string> */
    private array $columns;
    /** @var list<list<null|int|float|string|Blob>> */
    private array $rows;
    private int $affected;

    public function __construct(?EngineResult $result)
    {
        $this->columns = $result?->columns ?? [];
        $this->rows = $result?->materializeRows() ?? [];
        $this->affected = $result?->rowCount ?? 0;
    }

    /** @return list<mixed>|false */
    public function fetchNumeric(): array|false
    {
        if ($this->columns === [] || $this->cursor >= \count($this->rows)) {
            return false;
        }
        return $this->normalizeRow($this->rows[$this->cursor++]);
    }

    /** @return array<string,mixed>|false */
    public function fetchAssociative(): array|false
    {
        $row = $this->fetchNumeric();
        if ($row === false) {
            return false;
        }
        return $this->associate($row);
    }

    public function fetchOne(): mixed
    {
        $row = $this->fetchNumeric();
        return $row === false ? false : ($row[0] ?? null);
    }

    /** @return list<list<mixed>> */
    public function fetchAllNumeric(): array
    {
        $out = [];
        while (($row = $this->fetchNumeric()) !== false) {
            $out[] = $row;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function fetchAllAssociative(): array
    {
        $out = [];
        while (($row = $this->fetchAssociative()) !== false) {
            $out[] = $row;
        }
        return $out;
    }

    /** @return list<mixed> */
    public function fetchFirstColumn(): array
    {
        $out = [];
        while (($row = $this->fetchNumeric()) !== false) {
            $out[] = $row[0] ?? null;
        }
        return $out;
    }

    public function rowCount(): int
    {
        return $this->affected;
    }

    public function columnCount(): int
    {
        return \count($this->columns);
    }

    public function getColumnName(int $index): string
    {
        return $this->columns[$index] ?? '';
    }

    public function free(): void
    {
        $this->rows = [];
        $this->cursor = 0;
    }

    /**
     * @param list<null|int|float|string|Blob> $row
     * @return list<mixed>
     */
    private function normalizeRow(array $row): array
    {
        return \array_map(
            static fn ($v) => $v instanceof Blob ? $v->bytes : $v,
            $row,
        );
    }

    /**
     * @param list<mixed> $row
     * @return array<string,mixed>
     */
    private function associate(array $row): array
    {
        $assoc = [];
        foreach ($this->columns as $i => $name) {
            $assoc[$name] = $row[$i] ?? null;
        }
        return $assoc;
    }
}
