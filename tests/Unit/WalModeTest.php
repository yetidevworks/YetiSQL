<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * WAL (write-ahead log) journal mode: enabling/reading the mode, committing
 * through the log, reopen recovery (replaying committed frames), discarding a
 * half-written trailing transaction after a crash, explicit checkpointing, and
 * switching back to rollback (delete) mode.
 */
final class WalModeTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = \sys_get_temp_dir() . '/yetisql_wal_' . \getmypid() . '_' . \uniqid() . '.ysql';
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        foreach (['', '-wal', '-journal'] as $suffix) {
            if (\is_file($this->file . $suffix)) {
                @\unlink($this->file . $suffix);
            }
        }
    }

    public function testEnableAndReadMode(): void
    {
        $db = new YetiPDO("yetisql:{$this->file}");
        self::assertSame([['wal']], $db->query('PRAGMA journal_mode=wal')->fetchAll(YetiPDO::FETCH_NUM));
        self::assertSame([['wal']], $db->query('PRAGMA journal_mode')->fetchAll(YetiPDO::FETCH_NUM));
    }

    public function testInMemoryReportsMemoryMode(): void
    {
        $db = new YetiPDO('yetisql::memory:');
        self::assertSame([['memory']], $db->query('PRAGMA journal_mode')->fetchAll(YetiPDO::FETCH_NUM));
    }

    public function testCommitsAreVisibleWithinSession(): void
    {
        $db = new YetiPDO("yetisql:{$this->file}");
        $db->exec('PRAGMA journal_mode=wal');
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        for ($i = 1; $i <= 50; $i++) {
            $db->exec("INSERT INTO t VALUES ($i, 'v$i')");
        }
        self::assertSame([[50]], $db->query('SELECT COUNT(*) FROM t')->fetchAll(YetiPDO::FETCH_NUM));
        self::assertFileExists($this->file . '-wal');
    }

    public function testReopenReplaysWalWithoutCheckpoint(): void
    {
        $db = new YetiPDO("yetisql:{$this->file}");
        $db->exec('PRAGMA journal_mode=wal');
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $db->exec("INSERT INTO t VALUES (1,'a'),(2,'b'),(3,'c')");
        unset($db); // no explicit close/checkpoint; the WAL holds the committed data

        $reopened = new YetiPDO("yetisql:{$this->file}");
        self::assertSame(
            [[1, 'a'], [2, 'b'], [3, 'c']],
            $reopened->query('SELECT id, v FROM t ORDER BY id')->fetchAll(YetiPDO::FETCH_NUM),
        );
        self::assertSame([['wal']], $reopened->query('PRAGMA journal_mode')->fetchAll(YetiPDO::FETCH_NUM));
    }

    public function testCheckpointFoldsWalIntoMainFile(): void
    {
        $db = new YetiPDO("yetisql:{$this->file}");
        $db->exec('PRAGMA journal_mode=wal');
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $db->exec("INSERT INTO t VALUES (1,'a'),(2,'b')");
        $db->query('PRAGMA wal_checkpoint');

        \clearstatcache();
        self::assertLessThan(64, (int) \filesize($this->file . '-wal'), 'WAL should be reset to its header');
        self::assertSame([[2]], $db->query('SELECT COUNT(*) FROM t')->fetchAll(YetiPDO::FETCH_NUM));

        // Writes after a checkpoint keep working and remain durable on reopen.
        $db->exec("INSERT INTO t VALUES (3,'c')");
        unset($db);
        $reopened = new YetiPDO("yetisql:{$this->file}");
        self::assertSame([[3]], $reopened->query('SELECT COUNT(*) FROM t')->fetchAll(YetiPDO::FETCH_NUM));
    }

    public function testCrashWithHalfWrittenTransactionIsDiscarded(): void
    {
        $db = new YetiPDO("yetisql:{$this->file}");
        $db->exec('PRAGMA journal_mode=wal');
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $db->exec("INSERT INTO t VALUES (1,'a'),(2,'b'),(3,'c')");
        unset($db);

        // Simulate a crash partway through the next transaction: a frame header
        // followed by a truncated page body and no commit marker.
        $h = \fopen($this->file . '-wal', 'ab');
        \fwrite($h, \pack('N', 7) . \str_repeat("\xAB", 100));
        \fclose($h);

        $reopened = new YetiPDO("yetisql:{$this->file}");
        self::assertSame(
            [[1, 'a'], [2, 'b'], [3, 'c']],
            $reopened->query('SELECT id, v FROM t ORDER BY id')->fetchAll(YetiPDO::FETCH_NUM),
        );
        // Still writable after recovery.
        $reopened->exec("INSERT INTO t VALUES (4,'d')");
        self::assertSame([[4]], $reopened->query('SELECT COUNT(*) FROM t')->fetchAll(YetiPDO::FETCH_NUM));
    }

    public function testSwitchBackToDeleteMode(): void
    {
        $db = new YetiPDO("yetisql:{$this->file}");
        $db->exec('PRAGMA journal_mode=wal');
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $db->exec("INSERT INTO t VALUES (1,'a')");

        self::assertSame([['delete']], $db->query('PRAGMA journal_mode=delete')->fetchAll(YetiPDO::FETCH_NUM));
        \clearstatcache();
        self::assertFileDoesNotExist($this->file . '-wal');

        $db->exec("INSERT INTO t VALUES (2,'b')");
        unset($db);
        $reopened = new YetiPDO("yetisql:{$this->file}");
        self::assertSame([[2]], $reopened->query('SELECT COUNT(*) FROM t')->fetchAll(YetiPDO::FETCH_NUM));
    }
}
