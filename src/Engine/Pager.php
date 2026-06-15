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
 *
 * WAL mode (PRAGMA journal_mode=WAL): on commit the *new* image of every changed
 * page is appended as a frame to "<path>-wal" (ending each transaction with a
 * commit marker carrying the post-commit page count), fsync'd, and the main file
 * is left untouched. Reads see WAL-resident pages from the in-memory cache, which
 * always holds them once committed or replayed. A checkpoint copies WAL pages
 * into the main file and resets the log; recovery on open replays the committed
 * prefix of the WAL, discarding any half-written trailing transaction.
 */
final class Pager
{
    public const MAGIC = "YetiSQL\x00";
    public const DEFAULT_PAGE_SIZE = 4096;
    public const HEADER_SIZE = 100;
    public const MASTER_ROOT = 2;
    /**
     * On-disk schema format. Bumped to 5 when the b-tree page header grew from
     * 12 to 16 bytes to carry persisted subtree row counts. Files written by an
     * earlier format are NOT readable (the b-tree page layout differs), so we
     * reject them on open rather than silently misreading page contents.
     */
    public const SCHEMA_FORMAT = 5;

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
    /**
     * Open savepoints (sub-transactions), innermost last. Each snapshots the
     * bytes of every page dirtied so far this transaction plus the allocator /
     * header fields, so ROLLBACK TO can restore exactly the state at that point.
     * (Pages are mutated in place before writePage(), so a lazy pre-image log
     * cannot capture the right bytes — an eager snapshot is the correct model.)
     *
     * @var list<array{name:string,pages:array<int,?string>,dirty:array<int,true>,pageCount:int,freelistHead:int,freelistCount:int,schemaCookie:int,changeCounter:int}>
     */
    private array $savepoints = [];

    private bool $inTxn = false;
    private int $pageSize = self::DEFAULT_PAGE_SIZE;
    private int $pageCount = 0;
    private int $freelistHead = 0;
    private int $freelistCount = 0;
    private int $schemaCookie = 0;
    private int $changeCounter = 0;
    private bool $locked = false;

    private const WAL_MAGIC = "YWAL\x00";
    private const WAL_HEADER_SIZE = 12; // magic(5) + pageSize(4) + reserved(3)

    private bool $walMode = false;
    private mixed $walHandle = null;
    /** @var array<int,true> page numbers whose latest committed image lives in the WAL, not the main file */
    private array $walFrames = [];

    /** WAL file size at our last sync — a cheap signal that another process committed. */
    private int $lastWalSize = 0;
    /** Max time (ms) to wait for a contended file lock before raising "database is locked". */
    private int $busyTimeoutMs = 5000;
    /** Set when a cross-process reload changed the schema cookie; consumed by Database. */
    private bool $schemaMayHaveChanged = false;

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
        // Unbuffered reads: PHP's userspace stream buffer can serve stale bytes
        // for a re-seek within the buffered region, so a reader would miss another
        // process's committed write. Unbuffered fread()s hit the (cross-process
        // coherent) OS page cache. We cache whole pages ourselves, so the stream
        // buffer bought us nothing anyway.
        \stream_set_read_buffer($this->handle, 0);

        if ($exists) {
            // Roll back only a *hot* journal (one left by a dead writer).
            // recoverIfNeeded() uses a non-blocking exclusive lock to tell a hot
            // journal from a live writer's in-flight commit, so open() never
            // blocks on, nor wrongly rolls back, another connection's write.
            $this->recoverIfNeeded();
            $this->readHeader();
            if ($this->walMode) {
                $this->openWalHandle(false);
                $this->recoverWal();
                $this->lastWalSize = $this->currentWalSize();
            }
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
        if ($h['schemafmt'] !== self::SCHEMA_FORMAT) {
            throw new YetiSQLException(\sprintf(
                'unsupported YetiSQL schema format %d (this build reads format %d); '
                . 'the database was written by an incompatible version',
                $h['schemafmt'],
                self::SCHEMA_FORMAT,
            ));
        }
        $this->pageSize = $h['pageSize'] === 0 ? self::DEFAULT_PAGE_SIZE : $h['pageSize'];
        $this->pageCount = $h['pages'];
        $this->freelistHead = $h['freehead'];
        $this->freelistCount = $h['freecount'];
        $this->schemaCookie = $h['cookie'];
        $this->changeCounter = $h['change'];
        // Write/read version bytes encode the durability mode: 2 = WAL, else
        // rollback journal. (Only meaningful for file-backed databases.)
        $this->walMode = !$this->memory && $h['write'] === 2;
    }

    private function writeHeader(): void
    {
        $version = $this->walMode ? 2 : 1;
        $header = self::MAGIC
            . \pack('n', $this->pageSize)
            . \pack('C', $version)
            . \pack('C', $version)
            . \pack('N', $this->pageCount)
            . \pack('N', $this->freelistHead)
            . \pack('N', $this->freelistCount)
            . \pack('N', $this->schemaCookie)
            . \pack('N', self::SCHEMA_FORMAT) // schema format
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
            // Now that no other writer can be mid-commit, re-sync to the latest
            // committed state. Without this a writer that opened (or last synced)
            // before another process committed would flush stale-derived pages
            // over that commit — losing the update or corrupting the b-tree.
            if ($this->committedStateChanged()) {
                $this->reloadCommittedState();
            }
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

        if ($this->walMode && !$this->memory) {
            $this->commitToWal();
            $this->unlock();
        } elseif (!$this->memory) {
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
        $this->savepoints = [];
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
        $this->savepoints = [];
        $this->dirty = [];
        $this->committedPageCount = -1;
        $this->inTxn = false;
        if (!$this->memory) {
            $this->unlock();
        }
    }

    /** Whether at least one savepoint is currently open. */
    public function hasSavepoints(): bool
    {
        return $this->savepoints !== [];
    }

    /**
     * Open a savepoint, snapshotting the bytes of every page dirtied so far this
     * transaction plus the allocator/header fields. Requires a transaction.
     */
    public function savepoint(string $name): void
    {
        if (!$this->inTxn) {
            return; // Database opens a transaction before the first savepoint
        }
        $pages = [];
        foreach (\array_keys($this->dirty) as $pageNo) {
            // read() materializes a deferred page, so this captures current bytes.
            $pages[$pageNo] = $this->read($pageNo);
        }
        $this->savepoints[] = [
            'name' => $name,
            'pages' => $pages,
            'dirty' => $this->dirty,
            'pageCount' => $this->pageCount,
            'freelistHead' => $this->freelistHead,
            'freelistCount' => $this->freelistCount,
            'schemaCookie' => $this->schemaCookie,
            'changeCounter' => $this->changeCounter,
        ];
    }

    /**
     * Release (commit) a savepoint and any nested inside it: the markers are
     * discarded but their changes fold into the enclosing scope (nothing undone).
     */
    public function releaseSavepoint(string $name): void
    {
        $i = $this->findSavepoint($name);
        if ($i === null) {
            throw new YetiSQLException("no such savepoint: $name");
        }
        $this->savepoints = \array_slice($this->savepoints, 0, $i);
    }

    /**
     * Roll back to a savepoint without ending the transaction: restore every
     * page to its content at that savepoint, revert the allocator/header
     * snapshot, and discard nested savepoints. The savepoint stays open so it
     * can be rolled back to again.
     */
    public function rollbackToSavepoint(string $name): void
    {
        $i = $this->findSavepoint($name);
        if ($i === null) {
            throw new YetiSQLException("no such savepoint: $name");
        }
        $sp = $this->savepoints[$i];
        foreach (\array_keys($this->dirty) as $pageNo) {
            unset($this->decoded[$pageNo], $this->pendingEncode[$pageNo]);
            if (\array_key_exists($pageNo, $sp['pages'])) {
                // Dirty at the savepoint: restore its snapshot bytes.
                $this->cache[$pageNo] = $sp['pages'][$pageNo];
            } elseif (\array_key_exists($pageNo, $this->undo) && $this->undo[$pageNo] !== null) {
                // First dirtied after the savepoint: revert to the committed image.
                $this->cache[$pageNo] = $this->undo[$pageNo];
            } else {
                // Allocated after the savepoint: drop it.
                unset($this->cache[$pageNo]);
            }
        }
        $this->dirty = $sp['dirty'];
        $this->pageCount = $sp['pageCount'];
        $this->freelistHead = $sp['freelistHead'];
        $this->freelistCount = $sp['freelistCount'];
        $this->schemaCookie = $sp['schemaCookie'];
        $this->changeCounter = $sp['changeCounter'];
        // Keep the target savepoint open; drop any nested inside it.
        $this->savepoints = \array_slice($this->savepoints, 0, $i + 1);
    }

    /** Index of the most recent savepoint with this name (case-insensitive), or null. */
    private function findSavepoint(string $name): ?int
    {
        for ($i = \count($this->savepoints) - 1; $i >= 0; $i--) {
            if (\strcasecmp($this->savepoints[$i]['name'], $name) === 0) {
                return $i;
            }
        }
        return null;
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
        if ($this->walMode && !$this->memory) {
            // Fold the WAL into the main file so a plain reopen sees everything.
            $this->checkpoint();
        }
        if ($this->walHandle !== null && \is_resource($this->walHandle)) {
            \fclose($this->walHandle);
            $this->walHandle = null;
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
        \clearstatcache(true, $jp);
        if (!\is_file($jp) || \filesize($jp) === 0) {
            return;
        }
        // A journal is "hot" (its writer died mid-commit) only if no live process
        // still holds the write lock. Probe with a non-blocking exclusive lock: if
        // a live writer owns it, the journal is just an in-flight commit, not hot,
        // so we must NOT roll it back. Hold the lock across recovery so the rollback
        // is atomic against other openers.
        $wouldBlock = false;
        if (!\flock($this->handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            return;
        }
        try {
            $this->recoverHotJournal($jp);
        } finally {
            \flock($this->handle, LOCK_UN);
        }
    }

    private function recoverHotJournal(string $jp): void
    {
        // Re-check under the lock: the writer may have finished and removed it.
        \clearstatcache(true, $jp);
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

    // --- write-ahead log --------------------------------------------------

    public function journalMode(): string
    {
        if ($this->memory) {
            return 'memory';
        }
        return $this->walMode ? 'wal' : 'delete';
    }

    /** Switch a file-backed database into WAL mode (idempotent). */
    public function enableWal(): void
    {
        if ($this->memory || $this->walMode || $this->inTxn) {
            return;
        }
        $this->walMode = true;
        $this->openWalHandle(true);
        // Persist the mode flag (write/read version = 2) into the main header.
        $this->writeHeader();
        $this->lockExclusive();
        $this->flushDirty();
        $this->fsync();
        $this->unlock();
    }

    /** Checkpoint and switch back to rollback-journal mode (idempotent). */
    public function disableWal(): void
    {
        if ($this->memory || !$this->walMode || $this->inTxn) {
            return;
        }
        $this->checkpoint();
        $this->walMode = false;
        $this->writeHeader();
        $this->lockExclusive();
        $this->flushDirty();
        $this->fsync();
        $this->unlock();
        if ($this->walHandle !== null && \is_resource($this->walHandle)) {
            \fclose($this->walHandle);
            $this->walHandle = null;
        }
        if (\is_file($this->walPath())) {
            @\unlink($this->walPath());
        }
        $this->walFrames = [];
    }

    private function walPath(): string
    {
        return $this->path . '-wal';
    }

    /** Open (and optionally reset) the WAL file handle, writing a fresh header when empty. */
    private function openWalHandle(bool $reset): void
    {
        if ($this->walHandle === null || !\is_resource($this->walHandle)) {
            $this->walHandle = \fopen($this->walPath(), 'c+b');
            if ($this->walHandle === false) {
                throw new YetiSQLException('unable to open WAL file');
            }
            \stream_set_read_buffer($this->walHandle, 0);
        }
        $size = \filesize($this->walPath());
        if ($reset || $size === false || $size < self::WAL_HEADER_SIZE) {
            \ftruncate($this->walHandle, 0);
            \fseek($this->walHandle, 0);
            \fwrite($this->walHandle, self::WAL_MAGIC . \pack('N', $this->pageSize) . "\x00\x00\x00");
            \fflush($this->walHandle);
            $this->walFrames = [];
        }
    }

    /** Append this transaction's dirty pages to the WAL, then a commit marker. */
    private function commitToWal(): void
    {
        $this->materializeAll();
        \ksort($this->dirty);
        \fseek($this->walHandle, 0, \SEEK_END);
        foreach (\array_keys($this->dirty) as $pageNo) {
            \fwrite($this->walHandle, \pack('N', $pageNo) . $this->cache[$pageNo]);
            $this->walFrames[$pageNo] = true;
        }
        // Commit marker: page number 0, then the post-commit page count.
        \fwrite($this->walHandle, \pack('N', 0) . \pack('N', $this->pageCount));
        $this->fsyncWal();
        $this->lastWalSize = $this->currentWalSize();
        $this->dirty = [];
    }

    private function fsyncWal(): void
    {
        \fflush($this->walHandle);
        if (\function_exists('fsync')) {
            @\fsync($this->walHandle);
        }
    }

    /**
     * Replay the committed prefix of the WAL into the page cache, dropping any
     * half-written trailing transaction (frames past the last commit marker).
     */
    private function recoverWal(): void
    {
        \fseek($this->walHandle, 0);
        $hdr = \fread($this->walHandle, self::WAL_HEADER_SIZE);
        if (\strlen($hdr) < self::WAL_HEADER_SIZE || \substr($hdr, 0, 5) !== self::WAL_MAGIC) {
            // No usable WAL; start a clean one.
            $this->openWalHandle(true);
            return;
        }

        $applied = [];          // pageNo => committed image (last writer wins)
        $pending = [];          // frames since the last commit marker
        $committedPageCount = $this->pageCount;
        while (true) {
            $pnRaw = \fread($this->walHandle, 4);
            if (\strlen($pnRaw) < 4) {
                break;
            }
            /** @var array{1:int} $pn */
            $pn = \unpack('N', $pnRaw);
            if ($pn[1] === 0) {
                $cntRaw = \fread($this->walHandle, 4);
                if (\strlen($cntRaw) < 4) {
                    break; // truncated marker: stop, discard $pending
                }
                /** @var array{1:int} $cnt */
                $cnt = \unpack('N', $cntRaw);
                foreach ($pending as $p => $data) {
                    $applied[$p] = $data;
                }
                $committedPageCount = $cnt[1];
                $pending = [];
                continue;
            }
            $data = \fread($this->walHandle, $this->pageSize);
            if (\strlen($data) < $this->pageSize) {
                break; // truncated frame: stop, discard $pending
            }
            $pending[$pn[1]] = $data;
        }

        foreach ($applied as $pageNo => $data) {
            $this->cache[$pageNo] = $data;
            $this->walFrames[$pageNo] = true;
            unset($this->decoded[$pageNo], $this->pendingEncode[$pageNo]);
        }
        $this->pageCount = $committedPageCount;
        if (isset($this->cache[1])) {
            $this->parseHeaderFrom($this->cache[1]);
            $this->pageCount = $committedPageCount; // marker is authoritative for extent
        }
    }

    /** Copy WAL-resident pages into the main file and reset the log. */
    public function checkpoint(): void
    {
        if ($this->memory || !$this->walMode || $this->walHandle === null) {
            return;
        }
        if ($this->walFrames !== []) {
            $this->lockExclusive();
            $this->materializeAll();
            foreach (\array_keys($this->walFrames) as $pageNo) {
                $data = $this->read($pageNo);
                \fseek($this->handle, ($pageNo - 1) * $this->pageSize);
                \fwrite($this->handle, $data);
            }
            \ftruncate($this->handle, $this->pageCount * $this->pageSize);
            $this->fsync();
            $this->unlock();
        }
        // Reset the WAL to just its header.
        \ftruncate($this->walHandle, self::WAL_HEADER_SIZE);
        \fseek($this->walHandle, self::WAL_HEADER_SIZE);
        $this->fsyncWal();
        $this->walFrames = [];
        $this->lastWalSize = $this->currentWalSize();
    }

    private function lockExclusive(): void
    {
        if ($this->locked || $this->memory) {
            return;
        }
        $this->acquire(LOCK_EX);
        $this->locked = true;
    }

    private function unlock(): void
    {
        if ($this->locked && $this->handle !== null && \is_resource($this->handle)) {
            \flock($this->handle, LOCK_UN);
            $this->locked = false;
        }
    }

    /**
     * Acquire an flock of the given mode, retrying without blocking until the
     * busy timeout elapses, then raising "database is locked" — so a contended
     * worker fails fast instead of hanging indefinitely.
     */
    private function acquire(int $mode): void
    {
        $deadline = \microtime(true) + ($this->busyTimeoutMs / 1000);
        $wouldBlock = false;
        while (true) {
            if (\flock($this->handle, $mode | LOCK_NB, $wouldBlock)) {
                return;
            }
            if (!$wouldBlock || \microtime(true) >= $deadline) {
                throw new YetiSQLException('database is locked');
            }
            \usleep(1000); // 1ms between attempts
        }
    }

    public function setBusyTimeout(int $ms): void
    {
        $this->busyTimeoutMs = \max(0, $ms);
    }

    public function busyTimeout(): int
    {
        return $this->busyTimeoutMs;
    }

    /**
     * Whether a cross-process reload changed the schema cookie since the last
     * call. The Database consumes this to know when to rebuild its catalog.
     */
    public function takeSchemaChangedFlag(): bool
    {
        $changed = $this->schemaMayHaveChanged;
        $this->schemaMayHaveChanged = false;
        return $changed;
    }

    /**
     * Re-sync to the latest committed on-disk state for a read, picking up other
     * processes' commits on a reused connection. Cheap when nothing changed (a
     * small header read / WAL-size check); only reloads — under a shared lock to
     * avoid a torn read of an in-flight commit — when another writer committed.
     * Never disturbs an open transaction's own uncommitted pages.
     */
    public function syncForRead(): void
    {
        if ($this->memory || $this->inTxn) {
            return;
        }
        if (!$this->committedStateChanged()) {
            return;
        }
        $this->acquire(LOCK_SH);
        try {
            $this->reloadCommittedState();
        } finally {
            \flock($this->handle, LOCK_UN);
        }
    }

    /**
     * Cheap test for whether another process committed since our last sync:
     * the WAL grew/shrank (WAL mode) or the on-disk change counter advanced.
     */
    private function committedStateChanged(): bool
    {
        if ($this->memory) {
            return false;
        }
        if ($this->walMode) {
            return $this->currentWalSize() !== $this->lastWalSize;
        }
        return $this->diskChangeCounter() !== $this->changeCounter;
    }

    /**
     * The change counter currently stored in the on-disk main-file header. Reads
     * via the main handle, which is unbuffered (stream_set_read_buffer 0) so it
     * sees another process's committed write rather than a stale cached buffer.
     */
    private function diskChangeCounter(): int
    {
        $raw = $this->rawRead(1, self::HEADER_SIZE);
        if (\strlen($raw) < 36 || \substr($raw, 0, 8) !== self::MAGIC) {
            return $this->changeCounter;
        }
        /** @var array{1:int} $u */
        $u = \unpack('N', \substr($raw, 32, 4)); // change counter is at byte offset 32
        return $u[1];
    }

    private function currentWalSize(): int
    {
        // Clear PHP's per-path stat cache, or filesize() can return a stale size
        // after another process appended to the WAL.
        \clearstatcache(true, $this->walPath());
        $sz = @\filesize($this->walPath());
        return $sz === false ? 0 : $sz;
    }

    /**
     * Drop the in-memory caches and reload committed state from disk (header,
     * and in WAL mode the committed WAL frames). Caller must hold a lock so the
     * read sees a consistent commit. Returns whether the schema cookie changed.
     */
    private function reloadCommittedState(): bool
    {
        $oldCookie = $this->schemaCookie;
        $this->cache = [];
        $this->decoded = [];
        $this->pendingEncode = [];
        $this->dirty = [];
        $this->walFrames = [];

        $raw = $this->rawRead(1, $this->pageSize);
        $this->cache[1] = $raw;
        $this->parseHeaderFrom($raw);

        if ($this->walMode) {
            $this->recoverWal();
            $this->lastWalSize = $this->currentWalSize();
        }

        if ($this->schemaCookie !== $oldCookie) {
            $this->schemaMayHaveChanged = true;
            return true;
        }
        return false;
    }
}
