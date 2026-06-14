<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Concurrency;

use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Two live connections to the same file (the reused-connection case, e.g. a
 * persistent worker) must stay coherent: a read on one connection sees what
 * another connection has committed, including schema changes. This is the
 * read-side half of the concurrency fix and needs no separate process.
 */
final class ConnectionCoherenceTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = \tempnam(\sys_get_temp_dir(), 'yeti_coh_') . '.ysql';
        @\unlink($this->path);
    }

    protected function tearDown(): void
    {
        foreach (['', '-journal', '-wal'] as $suffix) {
            @\unlink($this->path . $suffix);
        }
    }

    public function testReaderSeesOtherConnectionsCommit(): void
    {
        $a = new YetiPDO('yetisql:' . $this->path);
        $a->exec('CREATE TABLE t(id INTEGER PRIMARY KEY, v INT)');
        $a->exec('INSERT INTO t VALUES (1, 10)');

        // B opens now, caching v=10.
        $b = new YetiPDO('yetisql:' . $this->path);
        self::assertSame(10, (int) $b->query('SELECT v FROM t WHERE id = 1')->fetchColumn());

        $a->exec('UPDATE t SET v = 99 WHERE id = 1');
        self::assertSame(99, (int) $b->query('SELECT v FROM t WHERE id = 1')->fetchColumn());

        $a->exec('INSERT INTO t VALUES (2, 20)');
        self::assertSame(2, (int) $b->query('SELECT count(*) FROM t')->fetchColumn());
    }

    public function testReaderSeesSchemaChangeFromOtherConnection(): void
    {
        $a = new YetiPDO('yetisql:' . $this->path);
        $a->exec('CREATE TABLE t(id INTEGER PRIMARY KEY)');
        $b = new YetiPDO('yetisql:' . $this->path);
        // Force B to load its catalog.
        self::assertSame(0, (int) $b->query('SELECT count(*) FROM t')->fetchColumn());

        $a->exec('CREATE TABLE t2(x INT)');
        $a->exec('INSERT INTO t2 VALUES (7)');

        self::assertSame(7, (int) $b->query('SELECT x FROM t2')->fetchColumn());
    }

    public function testWalModeReaderSeesOtherConnectionsCommit(): void
    {
        $a = new YetiPDO('yetisql:' . $this->path);
        $a->exec('PRAGMA journal_mode=WAL');
        $a->exec('CREATE TABLE t(id INTEGER PRIMARY KEY, v INT)');
        $a->exec('INSERT INTO t VALUES (1, 1)');

        $b = new YetiPDO('yetisql:' . $this->path);
        self::assertSame(1, (int) $b->query('SELECT v FROM t WHERE id = 1')->fetchColumn());

        $a->exec('UPDATE t SET v = 42 WHERE id = 1');
        self::assertSame(42, (int) $b->query('SELECT v FROM t WHERE id = 1')->fetchColumn());
    }
}
