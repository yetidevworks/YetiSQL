<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Executor;

use YetiDevWorks\YetiSQL\Engine\Blob;
use YetiDevWorks\YetiSQL\Engine\RecordCodec;
use YetiDevWorks\YetiSQL\Engine\TableBTree;
use YetiDevWorks\YetiSQL\Engine\TableInfo;
use YetiDevWorks\YetiSQL\Exception\SqlException;
use YetiDevWorks\YetiSQL\Types\Affinity;

/**
 * The set of table rows currently visible to expression evaluation. One frame
 * per table reference in the FROM/JOIN clause; column references resolve by
 * searching frames (qualified by alias when given).
 */
final class RowEnv
{
    /** @var list<array{alias:string,info:TableInfo,values:array<int,null|int|float|string|Blob>,rowid:int,payload:?string,offsets:?array<int,array{0:int,1:int}>,tree:?TableBTree}> */
    public array $frames = [];

    public function addFrame(string $alias, TableInfo $info, array $values, int $rowid): void
    {
        $this->frames[] = [
            'alias' => \strtolower($alias),
            'info' => $info,
            'values' => $values,
            'rowid' => $rowid,
            'payload' => null,
            'offsets' => null,
            'tree' => null,
        ];
    }

    /**
     * Add a frame whose column values are decoded lazily from the raw record on
     * first access. A full table scan that filters on one column never pays to
     * materialize the columns it ignores.
     */
    public function addLazyFrame(string $alias, TableInfo $info, string $payload, int $rowid): void
    {
        $this->frames[] = [
            'alias' => \strtolower($alias),
            'info' => $info,
            'values' => [],
            'rowid' => $rowid,
            'payload' => $payload,
            'offsets' => null,
            'tree' => null,
        ];
    }

    /**
     * Add a frame for a row located by an index scan: the row's payload is not
     * fetched until a column not already covered by the index is read.
     *
     * @param array<int,null|int|float|string|Blob> $values known column values from the index key
     */
    public function addDeferredFrame(string $alias, TableInfo $info, TableBTree $tree, int $rowid, array $values = []): void
    {
        $this->frames[] = [
            'alias' => \strtolower($alias),
            'info' => $info,
            'values' => $values,
            'rowid' => $rowid,
            'payload' => null,
            'offsets' => null,
            'tree' => $tree,
        ];
    }

    /**
     * Add a frame addressable ONLY by its qualifier (never by the underlying
     * table name, and never by an unqualified column reference). Used for the
     * UPSERT "excluded" pseudo-table, which shares the target's TableInfo but
     * must not collide with the target's own columns: in DO UPDATE an unqualified
     * column means the target row, and "excluded" requires the explicit prefix.
     */
    public function addAliasFrame(string $alias, TableInfo $info, array $values, int $rowid): void
    {
        $this->frames[] = [
            'alias' => \strtolower($alias),
            'info' => $info,
            'values' => $values,
            'rowid' => $rowid,
            'payload' => null,
            'offsets' => null,
            'tree' => null,
            'aliasOnly' => true,
        ];
    }

    /**
     * Whether a frame should be ignored when resolving a (possibly qualified)
     * column reference: alias-only frames are reachable solely via their exact
     * qualifier, all others by alias or underlying table name.
     *
     * @param array<string,mixed> $frame
     */
    private function frameSkipped(array $frame, ?string $tableLc): bool
    {
        $aliasOnly = $frame['aliasOnly'] ?? false;
        if ($tableLc === null) {
            return $aliasOnly;
        }
        if ($frame['alias'] === $tableLc) {
            return false;
        }
        return $aliasOnly || \strtolower($frame['info']->name) !== $tableLc;
    }

    /**
     * Read the value of frame $fi's column $pos, decoding from the raw record on
     * demand for lazy frames and caching the result.
     */
    public function valueAt(int $fi, int $pos): null|int|float|string|Blob
    {
        $frame = &$this->frames[$fi];
        if (\array_key_exists($pos, $frame['values'])) {
            return $frame['values'][$pos];
        }
        if ($pos === $frame['info']->rowidAlias) {
            return $frame['rowid'];
        }
        $payload = $frame['payload'];
        if ($payload === null) {
            // Deferred index-scan row: fetch the payload now, on first access.
            if ($frame['tree'] !== null) {
                $payload = $frame['payload'] = $frame['tree']->get($frame['rowid']);
            }
            if ($payload === null) {
                return null;
            }
        }
        $offsets = $frame['offsets'] ?? ($frame['offsets'] = RecordCodec::columnOffsets($payload));
        if (!isset($offsets[$pos])) {
            // Column absent from this record: added via ALTER TABLE ADD COLUMN
            // after the row was written, so serve its declared default.
            return $frame['values'][$pos] = $frame['info']->columns[$pos]->defaultValue;
        }
        [$type, $bodyOff] = $offsets[$pos];
        return $frame['values'][$pos] = RecordCodec::decodeAt($payload, $type, $bodyOff);
    }

    public function resolveColumn(?string $table, string $name): null|int|float|string|Blob
    {
        $lname = \strtolower($name);
        $tableLc = $table !== null ? \strtolower($table) : null;

        // rowid pseudo-columns.
        if ($tableLc === null && \in_array($lname, ['rowid', '_rowid_', 'oid'], true)) {
            // Only if no real column of that name exists in scope.
            if (!$this->anyHasColumn($lname)) {
                return $this->frames[0]['rowid'] ?? null;
            }
        }

        $found = null;
        $matches = 0;
        foreach ($this->frames as $fi => $frame) {
            if ($this->frameSkipped($frame, $tableLc)) {
                continue;
            }
            $pos = $frame['info']->columnPos($name);
            if ($pos !== null) {
                $found = $this->valueAt($fi, $pos);
                $matches++;
            } elseif ($tableLc !== null && \in_array($lname, ['rowid', '_rowid_', 'oid'], true)) {
                return $frame['rowid'];
            }
        }

        if ($matches === 0) {
            throw new SqlException("no such column: " . ($table !== null ? "$table.$name" : $name));
        }
        if ($matches > 1) {
            throw new SqlException("ambiguous column name: $name");
        }
        return $found;
    }

    /**
     * Fast-path resolution of a column reference to a concrete [frameIndex,
     * position] slot, for the common case of a single unambiguous real column.
     * Returns null for rowid pseudo-columns, ambiguous names, aliases, or
     * misses — the caller then falls back to the fully general resolveColumn().
     * Because a query's frame layout is identical for every row it scans, the
     * evaluator memoizes this slot per column expression.
     *
     * @return array{0:int,1:int}|null
     */
    public function resolveSlot(?string $table, string $name): ?array
    {
        $lname = \strtolower($name);
        if ($table === null && ($lname === 'rowid' || $lname === '_rowid_' || $lname === 'oid')) {
            return null; // pseudo-column; let the general path decide
        }
        $tableLc = $table !== null ? \strtolower($table) : null;

        $foundFi = -1;
        $foundPos = -1;
        $matches = 0;
        foreach ($this->frames as $fi => $frame) {
            if ($this->frameSkipped($frame, $tableLc)) {
                continue;
            }
            $pos = $frame['info']->columnPos($name);
            if ($pos !== null) {
                $foundFi = $fi;
                $foundPos = $pos;
                $matches++;
            }
        }
        return $matches === 1 ? [$foundFi, $foundPos] : null;
    }

    /** The declared affinity of a column reference, or null if not a known column. */
    public function columnAffinity(?string $table, string $name): ?Affinity
    {
        $col = $this->findColumnInfo($table, $name);
        return $col?->affinity;
    }

    public function columnCollation(?string $table, string $name): ?string
    {
        return $this->findColumnInfo($table, $name)?->collation;
    }

    private function findColumnInfo(?string $table, string $name): ?\YetiDevWorks\YetiSQL\Engine\ColumnInfo
    {
        $tableLc = $table !== null ? \strtolower($table) : null;
        foreach ($this->frames as $frame) {
            if ($this->frameSkipped($frame, $tableLc)) {
                continue;
            }
            $pos = $frame['info']->columnPos($name);
            if ($pos !== null) {
                return $frame['info']->columns[$pos];
            }
        }
        return null;
    }

    /** Whether a (possibly qualified) column name resolves in scope. */
    public function hasColumn(?string $table, string $name): bool
    {
        $lname = \strtolower($name);
        if ($table === null && \in_array($lname, ['rowid', '_rowid_', 'oid'], true)) {
            return true;
        }
        return $this->findColumnInfo($table, $name) !== null;
    }

    private function anyHasColumn(string $lname): bool
    {
        foreach ($this->frames as $frame) {
            if (($frame['aliasOnly'] ?? false) === false && $frame['info']->columnPos($lname) !== null) {
                return true;
            }
        }
        return false;
    }
}
