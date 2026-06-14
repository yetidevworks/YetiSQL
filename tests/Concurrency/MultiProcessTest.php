<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Concurrency;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Proves that concurrent OS processes writing the same on-disk database do not
 * lose updates or corrupt the file. Each worker is a forked process with its own
 * connection (the realistic PHP-FPM / multi-request model). Without the
 * re-validate-under-the-write-lock fix in the Pager, a writer that opened before
 * another process committed would flush stale-derived pages over that commit.
 *
 * Requires ext-pcntl; skipped where it is unavailable (e.g. Windows).
 */
#[RequiresPhpExtension('pcntl')]
final class MultiProcessTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = \tempnam(\sys_get_temp_dir(), 'yeti_mp_') . '.ysql';
        @\unlink($this->path);
    }

    protected function tearDown(): void
    {
        foreach (['', '-journal', '-wal'] as $suffix) {
            @\unlink($this->path . $suffix);
        }
    }

    public function testConcurrentIncrementsRollbackJournal(): void
    {
        $this->runConcurrent(journalMode: null);
    }

    public function testConcurrentIncrementsWal(): void
    {
        $this->runConcurrent(journalMode: 'WAL');
    }

    /**
     * A writer blocked by another process's held write lock fails with "database
     * is locked" once busy_timeout elapses, rather than hanging forever — while
     * opening the connection still works (open is lock-free). The lock holder is
     * a separate child process that opens its OWN connection after the fork (so no
     * shared file descriptor); coordination is via a socket pair, not timing.
     */
    public function testBusyTimeoutFailsFastWhileLockHeld(): void
    {
        $db = new YetiPDO('yetisql:' . $this->path);
        $db->exec('CREATE TABLE t(id INTEGER PRIMARY KEY, v INT)');
        $db->exec('INSERT INTO t VALUES (1, 100)');
        $db = null; // close before forking so the child inherits no handle

        [$parentSock, $childSock] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);

        $pid = \pcntl_fork();
        if ($pid === -1) {
            self::fail('pcntl_fork failed');
        }
        if ($pid === 0) {
            \fclose($parentSock);
            $holder = new YetiPDO('yetisql:' . $this->path);
            $holder->exec('BEGIN');
            $holder->exec('UPDATE t SET v = 1 WHERE id = 1'); // holds the exclusive lock
            \fwrite($childSock, 'L');                          // signal: lock held
            \fread($childSock, 1);                             // wait for parent to finish probing
            $holder->exec('COMMIT');
            $holder = null;
            \fclose($childSock);
            exit(0);
        }

        \fclose($childSock);
        self::assertSame('L', \fread($parentSock, 1), 'child failed to take the lock');

        // Open succeeds despite the held lock; the contended write times out fast.
        $c = new YetiPDO('yetisql:' . $this->path);
        $c->exec('PRAGMA busy_timeout=200');
        $start = \microtime(true);
        $locked = false;
        try {
            $c->exec('UPDATE t SET v = 2 WHERE id = 1');
        } catch (\Throwable $e) {
            $locked = \str_contains($e->getMessage(), 'locked');
        }
        $elapsed = (\microtime(true) - $start) * 1000;
        $c = null;

        \fwrite($parentSock, 'D'); // release the holder
        \pcntl_waitpid($pid, $status);
        \fclose($parentSock);

        self::assertTrue($locked, 'contended write should have failed with "database is locked"');
        self::assertLessThan(5000, $elapsed, 'write should have failed fast, not hung');

        // The holder's committed value survived; the contended write did not apply.
        $db = new YetiPDO('yetisql:' . $this->path);
        self::assertSame(1, (int) $db->query('SELECT v FROM t WHERE id = 1')->fetchColumn());
    }

    private function runConcurrent(?string $journalMode): void
    {
        $workers = 8;
        $perWorker = 120;
        $expected = $workers * $perWorker;

        $db = new YetiPDO('yetisql:' . $this->path);
        if ($journalMode !== null) {
            $db->exec("PRAGMA journal_mode=$journalMode");
        }
        $db->exec('CREATE TABLE counter(id INTEGER PRIMARY KEY, n INTEGER)');
        $db->exec('INSERT INTO counter VALUES (1, 0)');
        $db->exec('CREATE TABLE log(id INTEGER PRIMARY KEY AUTOINCREMENT, worker INT, seq INT)');
        $db = null;

        /** @var list<int> $pids */
        $pids = [];
        for ($w = 0; $w < $workers; $w++) {
            $pid = \pcntl_fork();
            if ($pid === -1) {
                self::fail('pcntl_fork failed');
            }
            if ($pid === 0) {
                $code = 0;
                try {
                    $c = new YetiPDO('yetisql:' . $this->path);
                    $c->exec('PRAGMA busy_timeout=20000');
                    if ($journalMode !== null) {
                        $c->exec("PRAGMA journal_mode=$journalMode");
                    }
                    for ($i = 0; $i < $perWorker; $i++) {
                        // Read-modify-write of one shared row: the classic lost-update test.
                        $c->exec('UPDATE counter SET n = n + 1 WHERE id = 1');
                        // A growing table exercises page allocation / b-tree splits
                        // under contention, where structural corruption would surface.
                        $c->exec("INSERT INTO log(worker, seq) VALUES ($w, $i)");
                    }
                    $c = null;
                } catch (\Throwable) {
                    $code = 1;
                }
                exit($code);
            }
            $pids[] = $pid;
        }

        $failures = 0;
        foreach ($pids as $pid) {
            \pcntl_waitpid($pid, $status);
            if (!\pcntl_wifexited($status) || \pcntl_wexitstatus($status) !== 0) {
                $failures++;
            }
        }
        self::assertSame(0, $failures, 'a worker process errored');

        $db = new YetiPDO('yetisql:' . $this->path);
        $final = (int) $db->query('SELECT n FROM counter WHERE id = 1')->fetchColumn();
        $logRows = (int) $db->query('SELECT count(*) FROM log')->fetchColumn();
        $distinct = (int) $db->query(
            'SELECT count(*) FROM (SELECT DISTINCT worker, seq FROM log)',
        )->fetchColumn();
        $distinctIds = (int) $db->query('SELECT count(DISTINCT id) FROM log')->fetchColumn();

        self::assertSame($expected, $final, 'lost updates on the shared counter');
        self::assertSame($expected, $logRows, 'log row count mismatch (lost or corrupt rows)');
        self::assertSame($expected, $distinct, 'duplicate or missing (worker, seq) pairs');
        self::assertSame($expected, $distinctIds, 'colliding autoincrement ids across processes');
    }
}
