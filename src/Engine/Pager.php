<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Engine;

use YetiDevWorks\YetiSQL\Exception\YetiSQLException;

/**
 * Owns the database file: fixed-size paged I/O, an in-memory page cache, the
 * file header (page 1), the freelist, and crash-safe transactions backed by a
 * rollback journal plus advisory flock() locking.
 *
 * Transaction model (rollback journal): pages are mutated only in cache during
 * a write transaction. On commit we write the original images of every changed
 * page to "<path>-journal", fsync it, apply the dirty pages to the main file,
 * fsync, then delete the journal. The journal's existence on open means a prior
 * commit was interrupted, so we restore the originals — a crash mid-write rolls
 * back cleanly. An in-memory database keeps the same API with no file/journal.
 */
final class Pager
{
    public const MAGIC = "YetiSQL\x00";
    public const DEFAULT_PAGE_SIZE = 4096;
    public const HEADER_SIZE = 100;
    public const MASTER_ROOT = 2;

    private mixed $handle = null;
    private readonly bool $memory;

    /** @var array<int,string> decoded page buffers keyed by 1-based page number */
    private array $cache = [];
    /** @var array<int,BTreePage> parsed b-tree pages, a hot-path read cache */
    private array $decoded = [];
    private int $decodedCap = 8192;
    /**
     * Page numbers whose authoritative state lives only in $decoded and has not
     * yet been serialized into $cache. writePage() defers the encode() so that
     * filling a single leaf with N rows costs O(N) re-encodes at flush time
     * instead of O(N) re-encodes per insert (O(N^2) total). Any path that needs
     * the raw page bytes (read(), flushDirty(), decoded-cache eviction) must
     * materialize the page first.
     *
     * @var array<int,true>
     */
    private array $pendingEncode = [];
    /** @var array<int,true> dirty page numbers awaiting flush */
    private array $dirty = [];
    /** @var array<int,?string> undo images captured this txn; null = page newly allocated */
    private array $undo = [];

    private bool $inTxn = false;
    private int $pageSize = self::DEFAULT_PAGE_SIZE;
    private int $pageCount = 0;
    private int $freelistHead = 0;
    private int $freelistCount = 0;
    private int $schemaCookie = 0;
    private int $changeCounter = 0;
    private bool $locked = false;

    public function __construct(private readonly string $path)
    {
        $this->memory = ($path === ':memory:' || $path === '');
        $this->open();
    }

    public function pageSize(): int
    {
        return $this->pageSize;
    }

    public function pageCount(): int
    {
        return $this->pageCount;
    }

    public function schemaCookie(): int
    {
        return $this->schemaCookie;
    }

    public function bumpSchemaCookie(): void
    {
        $this->schemaCookie++;
        $this->writeHeader();
    }

    public function isMemory(): bool
    {
        return $this->memory;
    }

    private function open(): void
    {
        if ($this->memory) {
            $this->initNewDatabase();
            return;
        }

        $exists = \is_file($this->path) && \filesize($this->path) > 0;
        $this->handle = \fopen($this->path, $exists ? 'c+b' : 'w+b');
        if ($this->handle === false) {
            throw new YetiSQLException("unable to open database file: {$this->path}");
        }

        if ($exists) {
            $this->recoverIfNeeded();
            $this->readHeader();
        } else {
            $this->initNewDatabase();
        }
    }

    private function initNewDatabase(): void
    {
        $this->pageSize = self::DEFAULT_PAGE_SIZE;
        $this->pageCount = 0;
        $this->freelistHead = 0;
        $this->freelistCount = 0;
        $this->schemaCookie = 0;
        $this->changeCounter = 0;

        // Page 1 holds the header; page 2 is the (empty leaf) schema-table root.
        $this->pageCount = 2;
        $this->cache[1] = $this->blankPage();
        $this->cache[2] = BTreePage::newLeaf($this->pageSize, isTable: true);
        $this->writeHeader();
        $this->dirty[1] = true;
        $this->dirty[2] = true;

        if (!$this->memory) {
            $this->lockExclusive();
            $this->flushDirty();
            $this->fsync();
        } else {
            $this->dirty = [];
        }
    }

    private function blankPage(): string
    {
        return \str_repeat("\x00", $this->pageSize);
    }

    private function readHeader(): void
    {
        $raw = $this->rawRead(1, self::DEFAULT_PAGE_SIZE);
        $this->parseHeaderFrom($raw);
        $this->cache[1] = $raw;
    }

    /** Populate header fields from a page-1 buffer (disk or cached). */
    private function parseHeaderFrom(string $raw): void
    {
        if (\substr($raw, 0, 8) !== self::MAGIC) {
            throw new YetiSQLException('not a YetiSQL database (bad magic)');
        }
        /** @var array<string,int> $h */
        $h = \unpack(
            'npageSize/Cwrite/Cread/Npages/Nfreehead/Nfreecount/Ncookie/Nschemafmt/Nchange',
            \substr($raw, 8, 31),
        );
        $this->pageSize = $h['pageSize'] === 0 ? self::DEFAULT_PAGE_SIZE : $h['pageSize'];
        $this->pageCount = $h['pages'];
        $this->freelistHead = $h['freehead'];
        $this->freelistCount = $h['freecount'];
        $this->schemaCookie = $h['cookie'];
        $this->changeCounter = $h['change'];
    }

    private function writeHeader(): void
    {
        $header = self::MAGIC
            . \pack('n', $this->pageSize)
            . \pack('C', 1)
            . \pack('C', 1)
            . \pack('N', $this->pageCount)
            . \pack('N', $this->freelistHead)
            . \pack('N', $this->freelistCount)
            . \pack('N', $this->schemaCookie)
            . \pack('N', 5) // schema format
            . \pack('N', $this->changeCounter)
            . \pack('N', self::MASTER_ROOT)
            . \pack('N', 1) // text encoding: UTF-8
            . \pack('N', 0); // application id

        $page = $this->cache[1] ?? $this->blankPage();
        $page = $header . \substr($page, \strlen($header));
        $page = \substr($page, 0, $this->pageSize)
            . \str_repeat("\x00", \max(0, $this->pageSize - \strlen($page)));
        $page = \substr($page, 0, $this->pageSize);
        // Route through write() so the header change is captured for undo/journal.
        if ($this->inTxn) {
            $this->write(1, $page);
        } else {
            $this->cache[1] = $page;
            $this->dirty[1] = true;
        }
    }

    /**
     * Read a parsed b-tree page, cached across reads. The returned object is
     * shared — callers that mutate a page must decode a fresh copy via
     * BTreePage::decode(read(...)) and write() it back (which invalidates the
     * cache). Read-only paths (lookups, scans, navigation) use this.
     */
    public function readPage(int $pageNo): BTreePage
    {
        if (isset($this->decoded[$pageNo])) {
            return $this->decoded[$pageNo];
        }
        if (\count($this->decoded) >= $this->decodedCap) {
            $this->materializeAll();
            $this->decoded = [];
        }
        return $this->decoded[$pageNo] = BTreePage::decode($this->read($pageNo));
    }

    /**
     * Serialize a page whose mutated state is still only held as a decoded
     * object, refreshing its $cache entry. No-op if the page is not pending.
     */
    private function materialize(int $pageNo): void
    {
        if (isset($this->pendingEncode[$pageNo])) {
            $this->cache[$pageNo] = $this->decoded[$pageNo]->encode($this->pageSize);
            unset($this->pendingEncode[$pageNo]);
        }
    }

    /** Materialize every deferred page (before a flush or a decoded-cache wipe). */
    private function materializeAll(): void
    {
        foreach (\array_keys($this->pendingEncode) as $pageNo) {
            $this->cache[$pageNo] = $this->decoded[$pageNo]->encode($this->pageSize);
        }
        $this->pendingEncode = [];
    }

    /** Read a decoded page buffer (from cache or disk). */
    public function read(int $pageNo): string
    {
        if (isset($this->pendingEncode[$pageNo])) {
            $this->materialize($pageNo);
        }
        if (isset($this->cache[$pageNo])) {
            return $this->cache[$pageNo];
        }
        if ($pageNo < 1 || $pageNo > $this->pageCount) {
            throw new YetiSQLException("page $pageNo out of range (count={$this->pageCount})");
        }
        $raw = $this->rawRead($pageNo, $this->pageSize);
        $this->cache[$pageNo] = $raw;
        return $raw;
    }

    /** Replace a page's contents, recording an undo image if in a transaction. */
    public function write(int $pageNo, string $data): void
    {
        if (\strlen($data) !== $this->pageSize) {
            throw new YetiSQLException('page write size mismatch');
        }
        if ($this->inTxn && !\array_key_exists($pageNo, $this->undo)) {
            // Capture the pre-image: null means the page did not exist before.
            $this->undo[$pageNo] = ($pageNo <= $this->committedPageCount())
                ? $this->read($pageNo)
                : null;
        }
        $this->cache[$pageNo] = $data;
        $this->dirty[$pageNo] = true;
        unset($this->decoded[$pageNo], $this->pendingEncode[$pageNo]);
    }

    /**
     * Write a mutated b-tree page back, keeping the parsed object hot in the
     * decode cache. This is the fast path for in-place leaf/interior updates:
     * because the same object was just mutated and re-encoded, there is no need
     * to throw it away and re-parse it from bytes on the next read — which is
     * what makes bulk inserts into the same page O(n) instead of O(n^2).
     */
    public function writePage(int $pageNo, BTreePage $page): void
    {
        // Capture the undo pre-image from the committed bytes BEFORE marking the
        // page pending — read() here returns the still-clean $cache entry.
        if ($this->inTxn && !\array_key_exists($pageNo, $this->undo)) {
            $this->undo[$pageNo] = ($pageNo <= $this->committedPageCount())
                ? $this->read($pageNo)
                : null;
        }
        $this->dirty[$pageNo] = true;
        if (!isset($this->decoded[$pageNo]) && \count($this->decoded) >= $this->decodedCap) {
            $this->materializeAll();
            $this->decoded = [];
        }
        $this->decoded[$pageNo] = $page;
        // Defer encode(): the bytes are reproduced from $page on demand (read or
        // flush). The stale $cache entry, if any, is now superseded by $decoded.
        $this->pendingEncode[$pageNo] = true;
    }

    private int $committedPageCount = -1;

    private function committedPageCount(): int
    {
        return $this->committedPageCount >= 0 ? $this->committedPageCount : $this->pageCount;
    }

    /** Allocate a fresh page, reusing the freelist when possible. */
    public function allocatePage(): int
    {
        if ($this->freelistHead !== 0) {
            $pageNo = $this->freelistHead;
            $page = $this->read($pageNo);
            /** @var array{1:int} $next */
            $next = \unpack('N', \substr($page, 0, 4));
            $this->freelistHead = $next[1];
            $this->freelistCount--;
            $this->write($pageNo, $this->blankPage());
            $this->writeHeader();
            return $pageNo;
        }
        $this->pageCount++;
        $pageNo = $this->pageCount;
        $this->write($pageNo, $this->blankPage());
        $this->writeHeader();
        return $pageNo;
    }

    /** Return a page to the freelist. */
    public function freePage(int $pageNo): void
    {
        $page = \pack('N', $this->freelistHead) . \str_repeat("\x00", $this->pageSize - 4);
        $this->write($pageNo, $page);
        $this->freelistHead = $pageNo;
        $this->freelistCount++;
        $this->writeHeader();
    }

    public function beginTransaction(): void
    {
        if ($this->inTxn) {
            return;
        }
        if (!$this->memory) {
            $this->lockExclusive();
        }
        $this->committedPageCount = $this->pageCount;
        $this->inTxn = true;
        $this->undo = [];
    }

    public function commit(): void
    {
        if (!$this->inTxn) {
            return;
        }
        $this->changeCounter++;
        $this->writeHeader();

        if (!$this->memory) {
            $this->writeJournal();
            $this->flushDirty();
            $this->fsync();
            $this->deleteJournal();
            $this->unlock();
        } else {
            // Refresh $cache to the committed image so the next transaction's
            // undo capture reads committed bytes, not a later in-place mutation.
            $this->materializeAll();
            $this->dirty = [];
        }

        $this->undo = [];
        $this->committedPageCount = -1;
        $this->inTxn = false;
    }

    public function rollback(): void
    {
        if (!$this->inTxn) {
            return;
        }
        // Undo images were never applied to the main file, so restoring cache
        // and the page count is sufficient.
        foreach ($this->undo as $pageNo => $original) {
            if ($original === null) {
                unset($this->cache[$pageNo]);
            } else {
                $this->cache[$pageNo] = $original;
            }
            unset($this->dirty[$pageNo], $this->decoded[$pageNo], $this->pendingEncode[$pageNo]);
        }
        // Header fields revert from the restored page-1 image (works for both
        // file- and memory-backed pagers; the main file was never touched).
        $this->parseHeaderFrom($this->cache[1]);
        $this->undo = [];
        $this->dirty = [];
        $this->committedPageCount = -1;
        $this->inTxn = false;
        if (!$this->memory) {
            $this->unlock();
        }
    }

    public function inTransaction(): bool
    {
        return $this->inTxn;
    }

    public function close(): void
    {
        if ($this->inTxn) {
            $this->rollback();
        }
        if ($this->handle !== null && \is_resource($this->handle)) {
            $this->unlock();
            \fclose($this->handle);
            $this->handle = null;
        }
    }

    // --- low-level file helpers -------------------------------------------

    private function rawRead(int $pageNo, int $size): string
    {
        \fseek($this->handle, ($pageNo - 1) * $this->pageSize);
        $data = \fread($this->handle, $size);
        if ($data === false) {
            throw new YetiSQLException("read failed for page $pageNo");
        }
        if (\strlen($data) < $size) {
            $data .= \str_repeat("\x00", $size - \strlen($data));
        }
        return $data;
    }

    private function flushDirty(): void
    {
        $this->materializeAll();
        \ksort($this->dirty);
        foreach (\array_keys($this->dirty) as $pageNo) {
            $data = $this->cache[$pageNo];
            \fseek($this->handle, ($pageNo - 1) * $this->pageSize);
            \fwrite($this->handle, $data);
        }
        $this->dirty = [];
    }

    private function fsync(): void
    {
        \fflush($this->handle);
        if (\function_exists('fsync')) {
            @\fsync($this->handle);
        }
    }

    private function journalPath(): string
    {
        return $this->path . '-journal';
    }

    private function writeJournal(): void
    {
        if ($this->undo === []) {
            return;
        }
        $j = \fopen($this->journalPath(), 'w+b');
        if ($j === false) {
            throw new YetiSQLException('unable to create journal');
        }
        // Header: magic + original page count so recovery can truncate growth.
        \fwrite($j, "YJRN\x00" . \pack('N', $this->committedPageCount()) . \pack('N', $this->pageSize));
        foreach ($this->undo as $pageNo => $original) {
            if ($original === null) {
                continue; // newly allocated; recovery truncates instead
            }
            \fwrite($j, \pack('N', $pageNo) . $original);
        }
        \fflush($j);
        if (\function_exists('fsync')) {
            @\fsync($j);
        }
        \fclose($j);
    }

    private function deleteJournal(): void
    {
        if (\is_file($this->journalPath())) {
            @\unlink($this->journalPath());
        }
    }

    private function recoverIfNeeded(): void
    {
        $jp = $this->journalPath();
        if (!\is_file($jp) || \filesize($jp) === 0) {
            return;
        }
        $j = \fopen($jp, 'rb');
        if ($j === false) {
            return;
        }
        $magic = \fread($j, 5);
        if ($magic !== "YJRN\x00") {
            \fclose($j);
            @\unlink($jp);
            return;
        }
        /** @var array{1:int} $oc */
        $oc = \unpack('N', \fread($j, 4));
        $originalPageCount = $oc[1];
        /** @var array{1:int} $ps */
        $ps = \unpack('N', \fread($j, 4));
        $pageSize = $ps[1];

        // Restore each original page image, rolling the interrupted commit back.
        while (!\feof($j)) {
            $pnRaw = \fread($j, 4);
            if (\strlen($pnRaw) < 4) {
                break;
            }
            /** @var array{1:int} $pn */
            $pn = \unpack('N', $pnRaw);
            $data = \fread($j, $pageSize);
            if (\strlen($data) < $pageSize) {
                break;
            }
            \fseek($this->handle, ($pn[1] - 1) * $pageSize);
            \fwrite($this->handle, $data);
        }
        // Truncate any pages the interrupted txn had appended.
        \ftruncate($this->handle, $originalPageCount * $pageSize);
        \fflush($this->handle);
        if (\function_exists('fsync')) {
            @\fsync($this->handle);
        }
        \fclose($j);
        @\unlink($jp);
    }

    private function lockExclusive(): void
    {
        if ($this->locked || $this->memory) {
            return;
        }
        if (!\flock($this->handle, LOCK_EX)) {
            throw new YetiSQLException('could not acquire write lock');
        }
        $this->locked = true;
    }

    private function unlock(): void
    {
        if ($this->locked && $this->handle !== null && \is_resource($this->handle)) {
            \flock($this->handle, LOCK_UN);
            $this->locked = false;
        }
    }
}
