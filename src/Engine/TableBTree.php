<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Engine;

use Generator;

/**
 * A rowid-keyed table b-tree (a "table b-tree" in SQLite terms).
 *
 * Cells live in leaf pages keyed by an integer rowid; interior pages route
 * searches. Payloads larger than the inline limit spill onto a chain of
 * overflow pages. Deletes remove the cell without rebalancing (space is
 * reclaimed lazily) — correctness holds, full compaction is a later concern.
 *
 * Leaf cell:     varint(payloadLen) varint(rowid) localPayload [overflowPage:4]
 * Interior cell: child:4 varint(rowidSeparator)   (child holds rowids <= sep)
 */
final class TableBTree
{
    public function __construct(
        private readonly Pager $pager,
        private int $rootPage,
    ) {
    }

    public function rootPage(): int
    {
        return $this->rootPage;
    }

    /** Allocate and initialise a brand-new empty table b-tree; return its root page. */
    public static function create(Pager $pager): int
    {
        $root = $pager->allocatePage();
        $pager->write($root, BTreePage::newLeaf($pager->pageSize(), isTable: true));
        return $root;
    }

    /** Fetch the full payload for a rowid, or null if absent. */
    public function get(int $rowid): ?string
    {
        $pageNo = $this->findLeaf($rowid);
        $page = $this->pager->readPage($pageNo);
        // Leaf cells are sorted by rowid, so binary-search rather than scan.
        $lo = 0;
        $hi = \count($page->cells) - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            [$rid, , $plen, $n1, $n2] = $this->parseLeafCell($page->cells[$mid]);
            if ($rid === $rowid) {
                return $this->readPayload($page->cells[$mid], $plen, $n1 + $n2);
            }
            if ($rid < $rowid) {
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        return null;
    }

    public function exists(int $rowid): bool
    {
        return $this->get($rowid) !== null;
    }

    /** The largest rowid currently stored, or 0 if empty. */
    public function maxRowid(): int
    {
        $pageNo = $this->rootPage;
        while (true) {
            $page = $this->pager->readPage($pageNo);
            if ($page->isLeaf()) {
                if ($page->cells === []) {
                    return 0;
                }
                $last = $page->cells[\count($page->cells) - 1];
                return $this->parseLeafCell($last)[0];
            }
            $pageNo = $page->rightChild;
        }
    }

    /** Insert or replace the row for $rowid with the given record payload. */
    public function put(int $rowid, string $payload): void
    {
        $existed = false;
        $delta = 0;
        $split = $this->insertInto($this->rootPage, $rowid, $payload, false, $existed, $delta);
        if ($split !== null) {
            $this->growRoot($split[0], $split[1]);
        }
    }

    /**
     * Insert the row only if $rowid is not already present, in a single tree
     * descent. Returns true if inserted, false if the rowid already existed (the
     * tree is left unchanged). This lets a plain INSERT enforce the rowid-unique
     * constraint without a separate exists() probe (which is a second full
     * descent plus a payload read).
     */
    public function putIfAbsent(int $rowid, string $payload): bool
    {
        $existed = false;
        $delta = 0;
        $split = $this->insertInto($this->rootPage, $rowid, $payload, true, $existed, $delta);
        if ($existed) {
            return false;
        }
        if ($split !== null) {
            $this->growRoot($split[0], $split[1]);
        }
        return true;
    }

    /** Remove the row for $rowid. Returns true if a row was deleted. */
    public function delete(int $rowid): bool
    {
        return $this->deleteFrom($this->rootPage, $rowid);
    }

    /**
     * In-order scan yielding [rowid, payload]. Optionally start at >= $from.
     *
     * @return Generator<int,array{0:int,1:string}>
     */
    public function scan(?int $from = null): Generator
    {
        yield from $this->scanPage($this->rootPage, $from);
    }

    /** @return Generator<int,int> */
    public function scanRowids(?int $from = null): Generator
    {
        yield from $this->scanRowidPage($this->rootPage, $from);
    }

    public function countRange(?int $low = null, bool $lowInc = true, ?int $high = null, bool $highInc = true): int
    {
        return $this->countPage($this->rootPage, $low, $lowInc, $high, $highInc);
    }

    // --- internals --------------------------------------------------------

    /** @return Generator<int,array{0:int,1:string}> */
    private function scanPage(int $pageNo, ?int $from): Generator
    {
        $page = $this->pager->readPage($pageNo);
        if ($page->isLeaf()) {
            foreach ($page->cells as $cell) {
                [$rid, , $plen, $n1, $n2] = $this->parseLeafCell($cell);
                if ($from !== null && $rid < $from) {
                    continue;
                }
                yield [$rid, $this->readPayload($cell, $plen, $n1 + $n2)];
            }
            return;
        }
        foreach ($page->cells as $cell) {
            [$child, $sep] = $this->parseInteriorCell($cell);
            if ($from === null || $from <= $sep) {
                yield from $this->scanPage($child, $from);
            }
        }
        yield from $this->scanPage($page->rightChild, $from);
    }

    /** @return Generator<int,int> */
    private function scanRowidPage(int $pageNo, ?int $from): Generator
    {
        $page = $this->pager->readPage($pageNo);
        if ($page->isLeaf()) {
            foreach ($page->cells as $cell) {
                $rid = $this->parseLeafCell($cell)[0];
                if ($from === null || $rid >= $from) {
                    yield $rid;
                }
            }
            return;
        }
        foreach ($page->cells as $cell) {
            [$child, $sep] = $this->parseInteriorCell($cell);
            if ($from === null || $from <= $sep) {
                yield from $this->scanRowidPage($child, $from);
            }
        }
        yield from $this->scanRowidPage($page->rightChild, $from);
    }

    private function countPage(?int $pageNo, ?int $low, bool $lowInc, ?int $high, bool $highInc): int
    {
        if ($pageNo === null || $pageNo === 0) {
            return 0;
        }

        $page = $this->pager->readPage($pageNo);
        if ($page->isLeaf()) {
            $cells = $page->cells;
            $n = \count($cells);
            if ($n === 0) {
                return 0;
            }

            $first = $this->parseLeafCell($cells[0])[0];
            $last = $this->parseLeafCell($cells[$n - 1])[0];
            if ($this->rowidInLowerBound($first, $low, $lowInc) && $this->rowidInUpperBound($last, $high, $highInc)) {
                return $n;
            }
            if (!$this->rowidInUpperBound($first, $high, $highInc) || !$this->rowidInLowerBound($last, $low, $lowInc)) {
                return 0;
            }

            $count = 0;
            foreach ($cells as $cell) {
                $rid = $this->parseLeafCell($cell)[0];
                if (!$this->rowidInLowerBound($rid, $low, $lowInc)) {
                    continue;
                }
                if (!$this->rowidInUpperBound($rid, $high, $highInc)) {
                    break;
                }
                $count++;
            }
            return $count;
        }

        $count = 0;
        foreach ($page->cells as $cell) {
            [$child, $sep] = $this->parseInteriorCell($cell);
            if (!$this->rowidInLowerBound($sep, $low, $lowInc)) {
                continue;
            }
            $count += $this->childFullyInRange($child, $low, $lowInc, $high, $highInc)
                ? $this->pager->readPage($child)->subtreeCount
                : $this->countPage($child, $low, $lowInc, $high, $highInc);
            if ($high !== null && $sep >= $high) {
                return $count;
            }
        }
        return $count + ($this->childFullyInRange($page->rightChild, $low, $lowInc, $high, $highInc)
            ? $this->pager->readPage($page->rightChild)->subtreeCount
            : $this->countPage($page->rightChild, $low, $lowInc, $high, $highInc));
    }

    private function childFullyInRange(int $pageNo, ?int $low, bool $lowInc, ?int $high, bool $highInc): bool
    {
        $page = $this->pager->readPage($pageNo);
        [$first, $last] = $this->pageRowidBounds($page);
        return $first !== null
            && $last !== null
            && $this->rowidInLowerBound($first, $low, $lowInc)
            && $this->rowidInUpperBound($last, $high, $highInc);
    }

    /** @return array{0:?int,1:?int} */
    private function pageRowidBounds(BTreePage $page): array
    {
        if ($page->cells === []) {
            return [null, null];
        }
        if ($page->isLeaf()) {
            return [
                $this->parseLeafCell($page->cells[0])[0],
                $this->parseLeafCell($page->cells[\count($page->cells) - 1])[0],
            ];
        }

        $firstChild = $this->parseInteriorCell($page->cells[0])[0];
        return [
            $this->pageRowidBounds($this->pager->readPage($firstChild))[0],
            $this->pageRowidBounds($this->pager->readPage($page->rightChild))[1],
        ];
    }

    private function rowidInLowerBound(int $rowid, ?int $low, bool $lowInc): bool
    {
        return $low === null || $rowid > $low || ($lowInc && $rowid === $low);
    }

    private function rowidInUpperBound(int $rowid, ?int $high, bool $highInc): bool
    {
        return $high === null || $rowid < $high || ($highInc && $rowid === $high);
    }

    private function findLeaf(int $rowid): int
    {
        $pageNo = $this->rootPage;
        while (true) {
            $page = $this->pager->readPage($pageNo);
            if ($page->isLeaf()) {
                return $pageNo;
            }
            $pageNo = $this->childFor($page, $rowid);
        }
    }

    private function childFor(BTreePage $page, int $rowid): int
    {
        // Interior cells are ordered by separator key; binary-search the first
        // whose separator is >= the target rowid.
        $cells = $page->cells;
        $lo = 0;
        $hi = \count($cells) - 1;
        $found = -1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($rowid <= $this->parseInteriorCell($cells[$mid])[1]) {
                $found = $mid;
                $hi = $mid - 1;
            } else {
                $lo = $mid + 1;
            }
        }
        return $found === -1 ? $page->rightChild : $this->parseInteriorCell($cells[$found])[0];
    }

    /**
     * Recursive insert. Returns null, or [separatorRowid, newRightPage] if the
     * page at $pageNo split and the caller must absorb the new sibling. When
     * $onlyIfAbsent is set and $rowid is already present, sets $existed and makes
     * no modification.
     *
     * @return array{0:int,1:int}|null
     */
    private function insertInto(int $pageNo, int $rowid, string $payload, bool $onlyIfAbsent, bool &$existed, int &$delta): ?array
    {
        $page = $this->pager->readPage($pageNo);

        if ($page->isLeaf()) {
            // Check presence within the (already-loaded) leaf before allocating a
            // cell, so an insert-if-absent costs no extra page reads or overflow
            // pages when the key collides.
            if ($onlyIfAbsent && $this->leafHas($page, $rowid)) {
                $existed = true;
                return null;
            }
            $cell = $this->makeLeafCell($rowid, $payload);
            $inserted = $this->insertLeafCell($page, $rowid, $cell);
            $delta = $inserted ? 1 : 0;
            $page->subtreeCount += $delta;
            if ($page->fits($this->pager->pageSize())) {
                $this->pager->writePage($pageNo, $page);
                return null;
            }
            return $this->splitLeaf($pageNo, $page);
        }

        // Interior: find which child to descend into.
        $childIndex = $this->childIndexFor($page, $rowid);
        $childPage = $childIndex === -1 ? $page->rightChild : $this->parseInteriorCell($page->cells[$childIndex])[0];

        $split = $this->insertInto($childPage, $rowid, $payload, $onlyIfAbsent, $existed, $delta);
        if ($existed) {
            return null;
        }
        if ($split === null) {
            if ($delta !== 0) {
                $page->subtreeCount += $delta;
                $this->pager->writePage($pageNo, $page);
            }
            return null;
        }

        [$sepKey, $newRight] = $split;
        // The descended child now holds the lower half; $newRight the upper.
        $newCell = $this->makeInteriorCell($childPage, $sepKey);
        if ($childIndex === -1) {
            $page->appendCell($newCell);
            $page->rightChild = $newRight;
        } else {
            $page->insertCell($childIndex, $newCell);
            $page->replaceCell($childIndex + 1, $this->makeInteriorCell($newRight, $this->parseInteriorCell($page->cells[$childIndex + 1])[1]));
        }

        if ($page->fits($this->pager->pageSize())) {
            $this->refreshSubtreeCount($page);
            $this->pager->writePage($pageNo, $page);
            return null;
        }
        return $this->splitInterior($pageNo, $page);
    }

    /** @return array{0:int,1:int} [separatorRowid, newRightPage] */
    private function splitLeaf(int $pageNo, BTreePage $page): array
    {
        $mid = $this->leafSplitPoint($page->cells);
        $leftCells = \array_slice($page->cells, 0, $mid);
        $rightCells = \array_slice($page->cells, $mid);

        $left = new BTreePage(BTreePage::TABLE_LEAF);
        $left->setCells($leftCells);
        $this->refreshSubtreeCount($left);
        $right = new BTreePage(BTreePage::TABLE_LEAF);
        $right->setCells($rightCells);
        $this->refreshSubtreeCount($right);

        $newRight = $this->pager->allocatePage();
        $this->pager->write($pageNo, $left->encode($this->pager->pageSize()));
        $this->pager->write($newRight, $right->encode($this->pager->pageSize()));

        $sepKey = $this->parseLeafCell($leftCells[\count($leftCells) - 1])[0];
        return [$sepKey, $newRight];
    }

    /** @return array{0:int,1:int} [separatorRowid, newRightPage] */
    private function splitInterior(int $pageNo, BTreePage $page): array
    {
        $mid = $this->interiorSplitPoint($page->cells);
        // The middle cell's key is promoted; its child becomes the new page's
        // right-most child.
        $midCell = $page->cells[$mid];
        [$midChild, $sepKey] = $this->parseInteriorCell($midCell);

        $leftCells = \array_slice($page->cells, 0, $mid);
        $rightCells = \array_slice($page->cells, $mid + 1);

        $left = new BTreePage(BTreePage::TABLE_INTERIOR);
        $left->setCells($leftCells);
        $left->rightChild = $midChild;
        $this->refreshSubtreeCount($left);

        $right = new BTreePage(BTreePage::TABLE_INTERIOR);
        $right->setCells($rightCells);
        $right->rightChild = $page->rightChild;
        $this->refreshSubtreeCount($right);

        $newRight = $this->pager->allocatePage();
        $this->pager->write($pageNo, $left->encode($this->pager->pageSize()));
        $this->pager->write($newRight, $right->encode($this->pager->pageSize()));

        return [$sepKey, $newRight];
    }

    private function growRoot(int $sepKey, int $newRight): void
    {
        // Move current root content to a fresh page, then rewrite the root as a
        // two-child interior so the table's root page number stays stable.
        $leftNew = $this->pager->allocatePage();
        $this->pager->write($leftNew, $this->pager->read($this->rootPage));

        $root = new BTreePage(BTreePage::TABLE_INTERIOR);
        $root->setCells([$this->makeInteriorCell($leftNew, $sepKey)]);
        $root->rightChild = $newRight;
        $this->refreshSubtreeCount($root);
        $this->pager->write($this->rootPage, $root->encode($this->pager->pageSize()));
    }

    private function deleteFrom(int $pageNo, int $rowid): bool
    {
        $page = $this->pager->readPage($pageNo);
        if ($page->isLeaf()) {
            foreach ($page->cells as $i => $cell) {
                [$rid, , $plen, $n1, $n2] = $this->parseLeafCell($cell);
                if ($rid === $rowid) {
                    if ($plen > BTreePage::maxLocal($this->pager->pageSize())) {
                        $this->freeOverflow($cell, $n1 + $n2);
                    }
                    $page->removeCell($i);
                    $this->refreshSubtreeCount($page);
                    $this->pager->writePage($pageNo, $page);
                    return true;
                }
            }
            return false;
        }

        $child = $this->childFor($page, $rowid);
        if (!$this->deleteFrom($child, $rowid)) {
            return false;
        }
        $page->subtreeCount--;
        $this->pager->writePage($pageNo, $page);
        return true;
    }

    private function refreshSubtreeCount(BTreePage $page): void
    {
        if (!$page->isTable()) {
            return;
        }
        if ($page->isLeaf()) {
            $page->subtreeCount = \count($page->cells);
            return;
        }

        $count = 0;
        foreach ($page->cells as $cell) {
            $count += $this->pager->readPage($this->parseInteriorCell($cell)[0])->subtreeCount;
        }
        $count += $this->pager->readPage($page->rightChild)->subtreeCount;
        $page->subtreeCount = $count;
    }

    /**
     * Pick the most balanced leaf split index where both halves fit a page.
     * A single insert keeps the page below 2x usable, so a feasible point
     * always exists.
     *
     * @param list<string> $cells
     */
    private function leafSplitPoint(array $cells): int
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
            if ($left <= $usable && $right <= $usable) {
                $diff = \abs($left - $right);
                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $best = $k;
                }
            }
        }
        return $best === -1 ? \intdiv($n, 2) : $best;
    }

    /**
     * Pick the interior split index to promote (cells[k]); halves exclude it.
     *
     * @param list<string> $cells
     */
    private function interiorSplitPoint(array $cells): int
    {
        $usable = BTreePage::usable($this->pager->pageSize());
        $costs = \array_map(static fn (string $c): int => \strlen($c) + 2, $cells);
        $total = \array_sum($costs);
        $n = \count($cells);

        $best = -1;
        $bestDiff = PHP_INT_MAX;
        $leftPrefix = 0; // cost of cells[0..k-1]
        for ($k = 1; $k < $n - 1; $k++) {
            $leftPrefix += $costs[$k - 1];
            $right = $total - $leftPrefix - $costs[$k];
            if ($leftPrefix <= $usable && $right <= $usable) {
                $diff = \abs($leftPrefix - $right);
                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $best = $k;
                }
            }
        }
        return $best === -1 ? \intdiv($n, 2) : $best;
    }

    private function childIndexFor(BTreePage $page, int $rowid): int
    {
        // First interior cell whose separator is >= rowid (binary search), or -1
        // for the right-most child.
        $cells = $page->cells;
        $lo = 0;
        $hi = \count($cells) - 1;
        $found = -1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($rowid <= $this->parseInteriorCell($cells[$mid])[1]) {
                $found = $mid;
                $hi = $mid - 1;
            } else {
                $lo = $mid + 1;
            }
        }
        return $found;
    }

    /** Whether $rowid is present in this leaf (binary search, no payload read). */
    private function leafHas(BTreePage $page, int $rowid): bool
    {
        $cells = $page->cells;
        $lo = 0;
        $hi = \count($cells) - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            $rid = $this->parseLeafCell($cells[$mid])[0];
            if ($rid === $rowid) {
                return true;
            }
            if ($rid < $rowid) {
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        return false;
    }

    private function insertLeafCell(BTreePage $page, int $rowid, string $cell): bool
    {
        $n = \count($page->cells);
        if ($n === 0) {
            $page->appendCell($cell);
            return true;
        }

        // Cells are kept sorted by rowid. Bulk loads insert in ascending order,
        // so check the tail first: an append is the overwhelmingly common case
        // and avoids scanning the whole page (which made bulk insert O(n^2)).
        $lastRid = $this->parseLeafCell($page->cells[$n - 1])[0];
        if ($rowid > $lastRid) {
            $page->appendCell($cell);
            return true;
        }
        if ($rowid === $lastRid) {
            $page->replaceCell($n - 1, $cell);
            return false;
        }

        // Otherwise binary-search the insertion point (O(log n) cell parses).
        $lo = 0;
        $hi = $n - 1;
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            $rid = $this->parseLeafCell($page->cells[$mid])[0];
            if ($rid === $rowid) {
                $page->replaceCell($mid, $cell); // replace
                return false;
            }
            if ($rid < $rowid) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        // $lo is the first cell with rowid >= $rowid (and != since tail handled).
        $page->insertCell($lo, $cell);
        return true;
    }

    // --- cell encoding/decoding ------------------------------------------

    private function makeLeafCell(int $rowid, string $payload): string
    {
        $plen = \strlen($payload);
        $maxLocal = BTreePage::maxLocal($this->pager->pageSize());
        if ($plen <= $maxLocal) {
            return Varint::encode($plen) . Varint::encode($rowid) . $payload;
        }
        $local = \substr($payload, 0, $maxLocal);
        $overflow = \substr($payload, $maxLocal);
        $firstOverflow = $this->writeOverflow($overflow);
        return Varint::encode($plen) . Varint::encode($rowid) . $local . \pack('N', $firstOverflow);
    }

    private function makeInteriorCell(int $child, int $sepKey): string
    {
        return \pack('N', $child) . Varint::encode($sepKey);
    }

    /** @return array{0:int,1:int,2:int,3:int,4:int} [rowid, headerEnd, plen, n1, n2] */
    private function parseLeafCell(string $cell): array
    {
        [$plen, $n1] = Varint::decode($cell, 0);
        [$rowid, $n2] = Varint::decode($cell, $n1);
        return [$rowid, $n1 + $n2, $plen, $n1, $n2];
    }

    /** @return array{0:int,1:int} [childPage, separatorRowid] */
    private function parseInteriorCell(string $cell): array
    {
        /** @var array{1:int} $c */
        $c = \unpack('N', \substr($cell, 0, 4));
        [$sep] = Varint::decode($cell, 4);
        return [$c[1], $sep];
    }

    private function readPayload(string $cell, int $plen, int $headerLen): string
    {
        $maxLocal = BTreePage::maxLocal($this->pager->pageSize());
        if ($plen <= $maxLocal) {
            return \substr($cell, $headerLen, $plen);
        }
        $local = \substr($cell, $headerLen, $maxLocal);
        /** @var array{1:int} $ov */
        $ov = \unpack('N', \substr($cell, $headerLen + $maxLocal, 4));
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

    private function freeOverflow(string $cell, int $headerLen): void
    {
        $maxLocal = BTreePage::maxLocal($this->pager->pageSize());
        /** @var array{1:int} $ov */
        $ov = \unpack('N', \substr($cell, $headerLen + $maxLocal, 4));
        $pageNo = $ov[1];
        while ($pageNo !== 0) {
            $page = $this->pager->read($pageNo);
            /** @var array{1:int} $n */
            $n = \unpack('N', \substr($page, 0, 4));
            $this->pager->freePage($pageNo);
            $pageNo = $n[1];
        }
    }
}
