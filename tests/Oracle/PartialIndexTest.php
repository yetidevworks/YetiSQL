<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Oracle;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Differential coverage for partial indexes (CREATE INDEX ... WHERE): a unique
 * partial index constrains only rows matching its predicate, rows update in and
 * out of the predicate, and queries still see every row (the planner must not
 * use a partial index to drop rows outside the predicate). Separate tests cover
 * the PRAGMA index_list partial flag and survival across a reopen.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class PartialIndexTest extends TestCase
{
    /**
     * @return iterable<string,array{0:list<string>,1:string}>
     */
    public static function scenarios(): iterable
    {
        $base = [
            'CREATE TABLE t(id INTEGER PRIMARY KEY, k INT, active INT)',
            'CREATE UNIQUE INDEX u ON t(k) WHERE active = 1',
        ];
        $cases = [
            'duplicate outside predicate allowed' => [
                [...$base, 'INSERT INTO t VALUES (1, 5, 1)', 'INSERT INTO t VALUES (2, 5, 0)'],
                'SELECT id, k, active FROM t ORDER BY id',
            ],
            'duplicate inside predicate rejected' => [
                [...$base, 'INSERT INTO t VALUES (1, 5, 1)', 'INSERT INTO t VALUES (2, 5, 1)'],
                'SELECT count(*) FROM t',
            ],
            'update into predicate conflicts' => [
                [
                    ...$base,
                    'INSERT INTO t VALUES (1, 5, 1)',
                    'INSERT INTO t VALUES (2, 5, 0)',
                    'UPDATE t SET active = 1 WHERE id = 2',
                ],
                'SELECT id, active FROM t ORDER BY id',
            ],
            'update out of predicate frees uniqueness' => [
                [
                    ...$base,
                    'INSERT INTO t VALUES (1, 5, 1)',
                    'UPDATE t SET active = 0 WHERE id = 1',
                    'INSERT INTO t VALUES (2, 5, 1)',
                ],
                'SELECT id, k, active FROM t ORDER BY id',
            ],
            'query returns rows outside the predicate' => [
                [
                    ...$base,
                    'INSERT INTO t VALUES (1, 5, 1)',
                    'INSERT INTO t VALUES (2, 5, 0)',
                    'INSERT INTO t VALUES (3, 5, 0)',
                    'INSERT INTO t VALUES (4, 9, 1)',
                ],
                'SELECT id FROM t WHERE k = 5 ORDER BY id',
            ],
            'non-unique partial index query correctness' => [
                [
                    'CREATE TABLE t(id INTEGER PRIMARY KEY, k INT, active INT)',
                    'CREATE INDEX p ON t(k) WHERE active = 1',
                    'INSERT INTO t VALUES (1, 5, 1), (2, 5, 0), (3, 5, 1), (4, 7, 0)',
                ],
                'SELECT id, k FROM t WHERE k = 5 ORDER BY id',
            ],
            'count is not skewed by partial index' => [
                [
                    'CREATE TABLE t(id INTEGER PRIMARY KEY, k INT, active INT)',
                    'CREATE INDEX p ON t(k) WHERE active = 1',
                    'INSERT INTO t VALUES (1, 5, 1), (2, 5, 0), (3, 5, 0)',
                ],
                'SELECT count(*) FROM t WHERE k = 5',
            ],
        ];
        foreach ($cases as $name => [$script, $verify]) {
            yield $name => [$script, $verify];
        }
    }

    /**
     * @param list<string> $script
     */
    #[DataProvider('scenarios')]
    public function testMatchesSqlite(array $script, string $verify): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        $real = new RealPDO('sqlite::memory:');
        $real->setAttribute(RealPDO::ATTR_ERRMODE, RealPDO::ERRMODE_EXCEPTION);

        [$yRows, $yErr] = $this->runScript($yeti, $script, $verify);
        [$rRows, $rErr] = $this->runScript($real, $script, $verify);

        if ($rErr || $yErr) {
            self::assertTrue($rErr, 'YetiSQL errored but SQLite did not');
            self::assertTrue($yErr, 'SQLite errored but YetiSQL did not');
            return;
        }
        self::assertSame($rRows, $yRows);
    }

    public function testIndexListReportsPartialFlag(): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        $real = new RealPDO('sqlite::memory:');
        $real->setAttribute(RealPDO::ATTR_ERRMODE, RealPDO::ERRMODE_EXCEPTION);

        foreach ([
            'CREATE TABLE t(k INT, a INT)',
            'CREATE INDEX p ON t(k) WHERE a = 1',
            'CREATE INDEX f ON t(a)',
        ] as $sql) {
            $yeti->exec($sql);
            $real->exec($sql);
        }

        $query = 'SELECT name, "partial" FROM pragma_index_list(\'t\') ORDER BY name';
        self::assertSame(
            $real->query($query)->fetchAll(RealPDO::FETCH_NUM),
            $yeti->query($query)->fetchAll(YetiPDO::FETCH_NUM),
        );
    }

    public function testPartialUniquenessSurvivesReload(): void
    {
        $file = \sys_get_temp_dir() . '/yetisql_partial_' . \getmypid() . '_' . \uniqid() . '.ysql';
        foreach (['', '-journal', '-wal'] as $suffix) {
            @\unlink($file . $suffix);
        }

        try {
            $db = new YetiPDO("yetisql:{$file}");
            $db->setAttribute(YetiPDO::ATTR_ERRMODE, YetiPDO::ERRMODE_EXCEPTION);
            $db->exec('CREATE TABLE t(id INTEGER PRIMARY KEY, k INT, active INT)');
            $db->exec('CREATE UNIQUE INDEX u ON t(k) WHERE active = 1');
            $db->exec('INSERT INTO t VALUES (1, 5, 1)');
            unset($db);

            $reopened = new YetiPDO("yetisql:{$file}");
            $reopened->setAttribute(YetiPDO::ATTR_ERRMODE, YetiPDO::ERRMODE_EXCEPTION);

            // A duplicate outside the predicate is still allowed after reopen.
            $reopened->exec('INSERT INTO t VALUES (2, 5, 0)');

            // A duplicate inside the predicate is still rejected after reopen.
            $threw = false;
            try {
                $reopened->exec('INSERT INTO t VALUES (3, 5, 1)');
            } catch (\Throwable) {
                $threw = true;
            }
            self::assertTrue($threw, 'partial unique constraint should survive reopen');
            self::assertSame(
                [[1], [2]],
                $reopened->query('SELECT id FROM t ORDER BY id')->fetchAll(YetiPDO::FETCH_NUM),
            );
        } finally {
            foreach (['', '-journal', '-wal'] as $suffix) {
                @\unlink($file . $suffix);
            }
        }
    }

    /**
     * @param list<string> $script
     * @return array{0:list<array<int,mixed>>,1:bool}
     */
    private function runScript(RealPDO|YetiPDO $db, array $script, string $verify): array
    {
        try {
            foreach ($script as $sql) {
                $db->exec($sql);
            }
            return [$db->query($verify)->fetchAll($db::FETCH_NUM), false];
        } catch (\Throwable) {
            return [[], true];
        }
    }
}
