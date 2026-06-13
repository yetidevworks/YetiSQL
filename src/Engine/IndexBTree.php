<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Engine;

use Generator;
use YetiDevWorks\YetiSQL\Types\Value;

/**
 * A key-comparable b-tree for secondary indexes (an "index b-tree" in SQLite
 * terms). Entries are key records of the form [indexedCol1, …, rowid]; the
 * trailing rowid both makes every entry unique and links back to the table row.
 *
 * Keys compare field-by-field using each column's collation (Value::compare),
 * which lets the planner answer equality, range, and prefix probes.
 *
 * Leaf cell:     varint(payloadLen) payload [overflowPage:4]
 * Interior cell: child:4 varint(payloadLen) payload [overflowPage:4]
 *   where payload = encoded separator key record.
 */
final class IndexBTree
{
    /**
     * @param list<string> $collations collation per indexed column (rowid uses BINARY)
     */
    public function __construct(
        private readonly Pager $pager,
        private int $rootPage,
        private array $collations = [],
    ) {
    }

    public static function create(Pager $pager): int
    {
        $root = $pager->allocatePage();
        $pager->write($root, BTreePage::newLeaf($pager->pageSize(), isTable: false));
        return $root;
    }

    /** Insert a key record (indexed values followed by the rowid). */
    public function put(array $key): void
    {
        $split = $this->insertInto($this->rootPage, $key);
        if ($split !== null) {
            $this->growRoot($split[0], $split[1]);
        }
    }

    /** Remove the entry exactly matching the key record. */
    public function delete(array $key): bool
    {
        $pageNo = $this->findLeaf($key);
        $page = $this->pager->readPage($pageNo);
        foreach ($page->cells as $i => $cell) {
            if ($this->compareKeys($this->cellKey($cell), $key) === 0) {
                \array_splice($page->cells, $i, 1);
                $this->pager->writePage($pageNo, $page);
                return true;
            }
        }
        return false;
    }

    /**
     * Yield key records in order, starting at the first key >= $lowKey (a full
     * or prefix key). A null $lowKey scans from the beginning.
     *
     * @return Generator<int,array<int,null|int|float|string|Blob>>
     */
    public function scanFrom(?array $lowKey = null): Generator
    {
        yield from $this->scanPageFrom($this->rootPage, $lowKey);
    }

    public function countLeadingRange(
        null|int|float|string|Blob $low,
        bool $lowInc,
        null|int|float|string|Blob $high,
        bool $highInc,
        string $collation,
    ): int {
        return $this->countLeadingPage($this->rootPage, $low, $lowInc, $high, $highInc, $collation);
    }

    // --- internals --------------------------------------------------------

    /** @return Generator<int,array<int,null|int|float|string|Blob>> */
    private function scanPageFrom(int $pageNo, ?array $lowKey): Generator
    {
        $page = $this->pager->readPage($pageNo);

        if ($page->isLeaf()) {
            foreach ($page->cells as $cell) {
                $key = $this->cellKey($cell);
                if ($lowKey === null || $this->compareKeys($key, $lowKey) >= 0) {
                    yield $key;
                }
            }
            return;
        }

        foreach ($page->cells as $cell) {
            [$child, $sep] = $this->parseInteriorCell($cell);
            // Skip subtrees entirely below the low bound.
            if ($lowKey !== null && $this->compareKeys($sep, $lowKey) < 0) {
                continue;
            }
            yield from $this->scanPageFrom($child, $lowKey);
            $lowKey = null; // everything after the first qualifying subtree is in range
        }
        yield from $this->scanPageFrom($page->rightChild, $lowKey);
    }

    private function countLeadingPage(
        int $pageNo,
        null|int|float|string|Blob $low,
        bool $lowInc,
        null|int|float|string|Blob $high,
        bool $highInc,
        string $collation,
    ): int {
        $page = $this->pager->readPage($pageNo);
        $count = 0;

        if ($page->isLeaf()) {
            $cells = $page->cells;
            $n = \count($cells);
            if ($n === 0) {
                return 0;
            }

            $first = $this->cellLeading($cells[0], 0);
            $last = $this->cellLeading($cells[$n - 1], 0);
            if ($this->leadingInLowerBound($first, $low, $lowInc, $collation)
                && $this->leadingInUpperBound($last, $high, $highInc, $collation)) {
                return $n;
            }
            if (!$this->leadingInUpperBound($first, $high, $highInc, $collation)
                || !$this->leadingInLowerBound($last, $low, $lowInc, $collation)) {
                return 0;
            }

            foreach ($page->cells as $cell) {
                $leading = $this->cellLeading($cell, 0);
                if ($low !== null) {
                    $cmp = Value::compare($leading, $low, $collation);
                    if ($cmp < 0 || ($cmp === 0 && !$lowInc)) {
                        continue;
                    }
                }
                if ($high !== null) {
                    $cmp = Value::compare($leading, $high, $collation);
                    if ($cmp > 0 || ($cmp === 0 && !$highInc)) {
                        break;
                    }
                }
                $count++;
            }
            return $count;
        }

        foreach ($page->cells as $cell) {
            [$child, $sep] = $this->parseInteriorCellLeading($cell);
            if ($low !== null) {
                $cmp = Value::compare($sep, $low, $collation);
                if ($cmp < 0 || ($cmp === 0 && !$lowInc)) {
                    continue;
                }
            }
            $count += $this->countLeadingPage($child, $low, $lowInc, $high, $highInc, $collation);
            if ($high !== null) {
                $cmp = Value::compare($sep, $high, $collation);
                if ($cmp > 0 || ($cmp === 0 && !$highInc)) {
                    return $count;
                }
            }
        }

        return $count + $this->countLeadingPage($page->rightChild, $low, $lowInc, $high, $highInc, $collation);
    }

    private function leadingInLowerBound(
        null|int|float|string|Blob $value,
        null|int|float|string|Blob $low,
        bool $lowInc,
        string $collation,
    ): bool {
        if ($low === null) {
            return true;
        }
        $cmp = Value::compare($value, $low, $collation);
        return $cmp > 0 || ($cmp === 0 && $lowInc);
    }

    private function leadingInUpperBound(
        null|int|float|string|Blob $value,
        null|int|float|string|Blob $high,
        bool $highInc,
        string $collation,
    ): bool {
        if ($high === null) {
            return true;
        }
        $cmp = Value::compare($value, $high, $collation);
        return $cmp < 0 || ($cmp === 0 && $highInc);
    }

    private function findLeaf(array $key): int
    {
        $pageNo = $this->rootPage;
        while (true) {
            $page = $this->pager->readPage($pageNo);
            if ($page->isLeaf()) {
                return $pageNo;
            }
            $pageNo = $this->childFor($page, $key);
        }
    }

    private function childFor(BTreePage $page, array $key): int
    {
        // Separators are ordered; binary-search the first cell whose separator
        // is >= the search key.
        $cells = $page->cells;
        $lo = 0;
        $hi = \count($cells) - 1;
        $found = -1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($this->compareKeys($key, $this->parseInteriorCell($cells[$mid])[1]) <= 0) {
                $found = $mid;
                $hi = $mid - 1;
            } else {
                $lo = $mid + 1;
            }
        }
        return $found === -1 ? $page->rightChild : $this->parseInteriorCell($cells[$found])[0];
    }

    /** @return array{0:array<int,mixed>,1:int}|null [separatorKey, newRightPage] */
    private function insertInto(int $pageNo, array $key): ?array
    {
        $page = $this->pager->readPage($pageNo);

        if ($page->isLeaf()) {
            $this->insertLeafCell($page, $key);
            if ($page->fits($this->pager->pageSize())) {
                $this->pager->writePage($pageNo, $page);
                return null;
            }
            return $this->splitLeaf($pageNo, $page);
        }

        $childIndex = $this->childIndexFor($page, $key);
        $childPage = $childIndex === -1
            ? $page->rightChild
            : $this->parseInteriorCell($page->cells[$childIndex])[0];

        $split = $this->insertInto($childPage, $key);
        if ($split === null) {
            return null;
        }

        [$sepKey, $newRight] = $split;
        $newCell = $this->makeInteriorCell($childPage, $sepKey);
        if ($childIndex === -1) {
            $page->cells[] = $newCell;
            $page->rightChild = $newRight;
        } else {
            $existingSep = $this->parseInteriorCell($page->cells[$childIndex])[1];
            \array_splice($page->cells, $childIndex, 0, [$newCell]);
            $page->cells[$childIndex + 1] = $this->makeInteriorCell($newRight, $existingSep);
        }

        if ($page->fits($this->pager->pageSize())) {
            $this->pager->writePage($pageNo, $page);
            return null;
        }
        return $this->splitInterior($pageNo, $page);
    }

    private function insertLeafCell(BTreePage $page, array $key): void
    {
        $cell = $this->makeLeafCell($key);
        $cells = $page->cells;
        $n = \count($cells);
        if ($n === 0 || $this->compareKeys($key, $this->cellKey($cells[$n - 1])) > 0) {
            $page->cells[] = $cell;
            return;
        }
        // Binary-search the first cell whose key is >= the new key.
        $lo = 0;
        $hi = $n - 1;
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($this->compareKeys($this->cellKey($cells[$mid]), $key) < 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        \array_splice($page->cells, $lo, 0, [$cell]);
    }

    /** @return array{0:array<int,mixed>,1:int} */
    private function splitLeaf(int $pageNo, BTreePage $page): array
    {
        $mid = $this->splitPoint($page->cells);
        $leftCells = \array_slice($page->cells, 0, $mid);
        $rightCells = \array_slice($page->cells, $mid);

        $left = new BTreePage(BTreePage::INDEX_LEAF);
        $left->cells = $leftCells;
        $right = new BTreePage(BTreePage::INDEX_LEAF);
        $right->cells = $rightCells;

        $newRight = $this->pager->allocatePage();
        $this->pager->write($pageNo, $left->encode($this->pager->pageSize()));
        $this->pager->write($newRight, $right->encode($this->pager->pageSize()));

        return [$this->cellKey($leftCells[\count($leftCells) - 1]), $newRight];
    }

    /** @return array{0:array<int,mixed>,1:int} */
    private function splitInterior(int $pageNo, BTreePage $page): array
    {
        $mid = $this->splitPointInterior($page->cells);
        [$midChild, $sepKey] = $this->parseInteriorCell($page->cells[$mid]);

        $leftCells = \array_slice($page->cells, 0, $mid);
        $rightCells = \array_slice($page->cells, $mid + 1);

        $left = new BTreePage(BTreePage::INDEX_INTERIOR);
        $left->cells = $leftCells;
        $left->rightChild = $midChild;

        $right = new BTreePage(BTreePage::INDEX_INTERIOR);
        $right->cells = $rightCells;
        $right->rightChild = $page->rightChild;

        $newRight = $this->pager->allocatePage();
        $this->pager->write($pageNo, $left->encode($this->pager->pageSize()));
        $this->pager->write($newRight, $right->encode($this->pager->pageSize()));

        return [$sepKey, $newRight];
    }

    private function growRoot(array $sepKey, int $newRight): void
    {
        $leftNew = $this->pager->allocatePage();
        $this->pager->write($leftNew, $this->pager->read($this->rootPage));

        $root = new BTreePage(BTreePage::INDEX_INTERIOR);
        $root->cells = [$this->makeInteriorCell($leftNew, $sepKey)];
        $root->rightChild = $newRight;
        $this->pager->write($this->rootPage, $root->encode($this->pager->pageSize()));
    }

    private function childIndexFor(BTreePage $page, array $key): int
    {
        $cells = $page->cells;
        $lo = 0;
        $hi = \count($cells) - 1;
        $found = -1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($this->compareKeys($key, $this->parseInteriorCell($cells[$mid])[1]) <= 0) {
                $found = $mid;
                $hi = $mid - 1;
            } else {
                $lo = $mid + 1;
            }
        }
        return $found;
    }

    /** @param list<string> $cells */
    private function splitPoint(array $cells): int
    {
        $usable = BTreePage::usable($this->pager->pageSize());
        $costs = \array_map(static fn (string $c): int => \strlen($c) + 2, $cells);
        $total = \array_sum($costs);
        $n = \count($cells);
        $best = -1;
        $bestDiff = PHP_INT_MAX;
        $left = 0;
        for ($k = 1; $k < $n; $k++) {
            $left += $costs[$k - 1];
            $right = $total - $left;
            if ($left <= $usable && $right <= $usable && \abs($left - $right) < $bestDiff) {
                $bestDiff = \abs($left - $right);
                $best = $k;
            }
        }
        return $best === -1 ? \intdiv($n, 2) : $best;
    }

    /** @param list<string> $cells */
    private function splitPointInterior(array $cells): int
    {
        $usable = BTreePage::usable($this->pager->pageSize());
        $costs = \array_map(static fn (string $c): int => \strlen($c) + 2, $cells);
        $total = \array_sum($costs);
        $n = \count($cells);
        $best = -1;
        $bestDiff = PHP_INT_MAX;
        $leftPrefix = 0;
        for ($k = 1; $k < $n - 1; $k++) {
            $leftPrefix += $costs[$k - 1];
            $right = $total - $leftPrefix - $costs[$k];
            if ($leftPrefix <= $usable && $right <= $usable && \abs($leftPrefix - $right) < $bestDiff) {
                $bestDiff = \abs($leftPrefix - $right);
                $best = $k;
            }
        }
        return $best === -1 ? \intdiv($n, 2) : $best;
    }

    // --- key comparison & cell codec -------------------------------------

    private function compareKeys(array $a, array $b): int
    {
        $n = \min(\count($a), \count($b));
        for ($i = 0; $i < $n; $i++) {
            $coll = $this->collations[$i] ?? 'BINARY';
            $c = Value::compare($a[$i], $b[$i], $coll);
            if ($c !== 0) {
                return $c;
            }
        }
        return \count($a) <=> \count($b);
    }

    private function makeLeafCell(array $key): string
    {
        return $this->payloadBody(RecordCodec::encode($key));
    }

    private function makeInteriorCell(int $child, array $key): string
    {
        return \pack('N', $child) . $this->payloadBody(RecordCodec::encode($key));
    }

    private function payloadBody(string $payload): string
    {
        $maxLocal = BTreePage::maxLocal($this->pager->pageSize());
        if (\strlen($payload) <= $maxLocal) {
            return Varint::encode(\strlen($payload)) . $payload;
        }
        $local = \substr($payload, 0, $maxLocal);
        $overflow = $this->writeOverflow(\substr($payload, $maxLocal));
        return Varint::encode(\strlen($payload)) . $local . \pack('N', $overflow);
    }

    /** @return array<int,null|int|float|string|Blob> */
    private function cellKey(string $cell): array
    {
        // Leaf cell: payload starts at offset 0.
        return RecordCodec::decode($this->readPayload($cell, 0));
    }

    /** @return array{0:int,1:array<int,mixed>} [child, separatorKey] */
    private function parseInteriorCell(string $cell): array
    {
        /** @var array{1:int} $c */
        $c = \unpack('N', \substr($cell, 0, 4));
        return [$c[1], RecordCodec::decode($this->readPayload($cell, 4))];
    }

    /** @return array{0:int,1:null|int|float|string|Blob} */
    private function parseInteriorCellLeading(string $cell): array
    {
        /** @var array{1:int} $c */
        $c = \unpack('N', \substr($cell, 0, 4));
        return [$c[1], $this->cellLeading($cell, 4)];
    }

    private function cellLeading(string $cell, int $offset): null|int|float|string|Blob
    {
        return RecordCodec::decodeColumn($this->readPayload($cell, $offset), 0);
    }

    private function readPayload(string $cell, int $offset): string
    {
        [$plen, $n] = Varint::decode($cell, $offset);
        $maxLocal = BTreePage::maxLocal($this->pager->pageSize());
        $start = $offset + $n;
        if ($plen <= $maxLocal) {
            return \substr($cell, $start, $plen);
        }
        $local = \substr($cell, $start, $maxLocal);
        /** @var array{1:int} $ov */
        $ov = \unpack('N', \substr($cell, $start + $maxLocal, 4));
        return $local . $this->readOverflow($ov[1], $plen - $maxLocal);
    }

    private function writeOverflow(string $data): int
    {
        $pageSize = $this->pager->pageSize();
        $perPage = $pageSize - 4;
        $chunks = \str_split($data, $perPage);
        $pageNos = [];
        for ($i = 0, $c = \count($chunks); $i < $c; $i++) {
            $pageNos[] = $this->pager->allocatePage();
        }
        foreach ($chunks as $i => $chunk) {
            $next = $pageNos[$i + 1] ?? 0;
            $body = \pack('N', $next) . $chunk;
            $body .= \str_repeat("\x00", $pageSize - \strlen($body));
            $this->pager->write($pageNos[$i], $body);
        }
        return $pageNos[0];
    }

    private function readOverflow(int $pageNo, int $remaining): string
    {
        $pageSize = $this->pager->pageSize();
        $out = '';
        while ($pageNo !== 0 && $remaining > 0) {
            $page = $this->pager->read($pageNo);
            /** @var array{1:int} $n */
            $n = \unpack('N', \substr($page, 0, 4));
            $take = \min($remaining, $pageSize - 4);
            $out .= \substr($page, 4, $take);
            $remaining -= $take;
            $pageNo = $n[1];
        }
        return $out;
    }
}
