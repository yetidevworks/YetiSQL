<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Oracle;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Differential coverage for GENERATED ALWAYS AS columns (STORED and VIRTUAL).
 * YetiSQL computes and stores both kinds at write time, which is value-identical
 * to SQLite for the deterministic expressions generated columns are allowed to
 * use, so reads, WHERE, indexes, and RETURNING must all match pdo_sqlite.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class GeneratedColumnTest extends TestCase
{
    /**
     * @return iterable<string,array{0:list<string>,1:string}>
     *   [setup statements, verification query]
     */
    public static function scenarios(): iterable
    {
        $cases = [
            'virtual sum' => [
                ['CREATE TABLE t (a INT, b INT, c AS (a + b))', 'INSERT INTO t(a,b) VALUES (3,4),(10,20)'],
                'SELECT a, b, c FROM t ORDER BY a',
            ],
            'stored product' => [
                ['CREATE TABLE t (a INT, b INT, c INT GENERATED ALWAYS AS (a * b) STORED)', 'INSERT INTO t(a,b) VALUES (3,4)'],
                'SELECT a, b, c FROM t',
            ],
            'text concatenation' => [
                ["CREATE TABLE t (first TEXT, last TEXT, fullname AS (first || ' ' || last))", "INSERT INTO t(first,last) VALUES ('Ann','Lee')"],
                'SELECT fullname FROM t',
            ],
            'select star includes generated' => [
                ['CREATE TABLE t (a INT, c AS (a * 2))', 'INSERT INTO t(a) VALUES (5)'],
                'SELECT * FROM t',
            ],
            'filter on generated' => [
                ['CREATE TABLE t (a INT, c AS (a * 2))', 'INSERT INTO t(a) VALUES (1),(2),(3),(4)'],
                'SELECT a FROM t WHERE c > 4 ORDER BY a',
            ],
            'order by generated' => [
                ['CREATE TABLE t (a INT, c AS (10 - a))', 'INSERT INTO t(a) VALUES (1),(2),(3)'],
                'SELECT a FROM t ORDER BY c',
            ],
            'update recomputes' => [
                ['CREATE TABLE t (a INT, c AS (a + 1))', 'INSERT INTO t(a) VALUES (5)', 'UPDATE t SET a = 10'],
                'SELECT a, c FROM t',
            ],
            'chained generated columns' => [
                ['CREATE TABLE t (a INT, b AS (a + 1), c AS (b + 1))', 'INSERT INTO t(a) VALUES (5)'],
                'SELECT a, b, c FROM t',
            ],
            'declared-type affinity applied' => [
                ['CREATE TABLE t (a INT, c TEXT AS (a + 1))', 'INSERT INTO t(a) VALUES (5)'],
                'SELECT c, typeof(c) FROM t',
            ],
            'real expression typeof' => [
                ['CREATE TABLE t (a INT, c AS (a * 1.5))', 'INSERT INTO t(a) VALUES (4)'],
                'SELECT c, typeof(c) FROM t',
            ],
            'function in expression' => [
                ["CREATE TABLE t (s TEXT, up AS (upper(s)), len AS (length(s)))", "INSERT INTO t(s) VALUES ('abc')"],
                'SELECT up, len FROM t',
            ],
            'generated with NULL input' => [
                ['CREATE TABLE t (a INT, c AS (a + 1))', 'INSERT INTO t(a) VALUES (NULL)'],
                'SELECT a, c FROM t',
            ],
            'unique generated rejects dup' => [
                ['CREATE TABLE t (a INT, c INT UNIQUE AS (a * 2))', 'INSERT INTO t(a) VALUES (1)', 'INSERT INTO t(a) VALUES (1)'],
                'SELECT a FROM t',
            ],
            'returning a generated column' => [
                ['CREATE TABLE t (id INTEGER PRIMARY KEY, a INT, c AS (a + 100))'],
                "INSERT INTO t(a) VALUES (5) RETURNING a, c",
            ],
            'reject insert into generated' => [
                ['CREATE TABLE t (a INT, c AS (a + 1))'],
                'INSERT INTO t(a, c) VALUES (1, 2)',
            ],
            'reject update of generated' => [
                ['CREATE TABLE t (a INT, c AS (a + 1))', 'INSERT INTO t(a) VALUES (1)'],
                'UPDATE t SET c = 9',
            ],
        ];
        foreach ($cases as $name => [$setup, $verify]) {
            yield $name => [$setup, $verify];
        }
    }

    /**
     * @param list<string> $setup
     */
    #[DataProvider('scenarios')]
    public function testMatchesSqlite(array $setup, string $verify): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        $real = new RealPDO('sqlite::memory:');
        $real->setAttribute(RealPDO::ATTR_ERRMODE, RealPDO::ERRMODE_EXCEPTION);

        [$yRows, $yErr] = $this->execAll($yeti, $setup, $verify);
        [$rRows, $rErr] = $this->execAll($real, $setup, $verify);

        if ($rErr || $yErr) {
            self::assertTrue($rErr, 'YetiSQL errored but SQLite did not');
            self::assertTrue($yErr, 'SQLite errored but YetiSQL did not');
            return;
        }
        self::assertSame($rRows, $yRows);
    }

    /**
     * @param list<string> $setup
     * @return array{0:list<array<int,mixed>>,1:bool}
     */
    private function execAll(RealPDO|YetiPDO $db, array $setup, string $verify): array
    {
        try {
            foreach ($setup as $sql) {
                $db->exec($sql);
            }
            return [$db->query($verify)->fetchAll($db::FETCH_NUM), false];
        } catch (\Throwable) {
            return [[], true];
        }
    }

    public function testStoredGeneratedSurvivesReopen(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'yeti_gen_') . '.ysql';
        @\unlink($path);
        try {
            $db = new YetiPDO('yetisql:' . $path);
            $db->exec('CREATE TABLE t (a INT, b INT, c INT GENERATED ALWAYS AS (a + b) STORED)');
            $db->exec('INSERT INTO t(a,b) VALUES (3,4)');
            $db = null;

            $db = new YetiPDO('yetisql:' . $path);
            self::assertSame(
                [[3, 4, 7]],
                $db->query('SELECT a, b, c FROM t')->fetchAll(YetiPDO::FETCH_NUM),
            );
            // The generated column is still recomputed on a fresh write after reload.
            $db->exec('UPDATE t SET a = 100');
            self::assertSame([[104]], $db->query('SELECT c FROM t')->fetchAll(YetiPDO::FETCH_NUM));
        } finally {
            @\unlink($path);
            @\unlink($path . '-journal');
        }
    }
}
