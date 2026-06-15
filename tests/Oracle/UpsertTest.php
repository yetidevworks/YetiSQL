<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Oracle;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Differential coverage for INSERT ... ON CONFLICT (UPSERT): DO NOTHING and
 * DO UPDATE, the "excluded" pseudo-table, an optional DO UPDATE ... WHERE, the
 * conflict target matching a specific unique index, and the resulting row
 * counts — all checked against pdo_sqlite.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class UpsertTest extends TestCase
{
    /**
     * @return iterable<string,array{0:list<string>,1:string}>
     */
    public static function scenarios(): iterable
    {
        $unique = [
            'CREATE TABLE r(id INTEGER PRIMARY KEY, uid INT, kind TEXT, rating INT, UNIQUE(uid, kind))',
            "INSERT INTO r(uid, kind, rating) VALUES (1, 'a', 3)",
        ];
        $cases = [
            'do update sets from excluded' => [
                [...$unique, "INSERT INTO r(uid, kind, rating) VALUES (1, 'a', 5) ON CONFLICT(uid, kind) DO UPDATE SET rating = excluded.rating"],
                'SELECT id, uid, kind, rating FROM r ORDER BY id',
            ],
            'do update inserts when no conflict' => [
                [...$unique, "INSERT INTO r(uid, kind, rating) VALUES (2, 'a', 9) ON CONFLICT(uid, kind) DO UPDATE SET rating = excluded.rating"],
                'SELECT id, uid, kind, rating FROM r ORDER BY id',
            ],
            'do nothing with target' => [
                [...$unique, "INSERT INTO r(uid, kind, rating) VALUES (1, 'a', 5) ON CONFLICT(uid, kind) DO NOTHING"],
                'SELECT id, uid, kind, rating FROM r ORDER BY id',
            ],
            'do nothing without target' => [
                [...$unique, "INSERT INTO r(uid, kind, rating) VALUES (1, 'a', 5) ON CONFLICT DO NOTHING"],
                'SELECT rating FROM r ORDER BY id',
            ],
            'do update where predicate true' => [
                [
                    'CREATE TABLE r(uid INT PRIMARY KEY, rating INT)',
                    'INSERT INTO r VALUES (1, 5)',
                    'INSERT INTO r VALUES (1, 8) ON CONFLICT(uid) DO UPDATE SET rating = excluded.rating WHERE excluded.rating > r.rating',
                ],
                'SELECT uid, rating FROM r',
            ],
            'do update where predicate false leaves row' => [
                [
                    'CREATE TABLE r(uid INT PRIMARY KEY, rating INT)',
                    'INSERT INTO r VALUES (1, 5)',
                    'INSERT INTO r VALUES (1, 3) ON CONFLICT(uid) DO UPDATE SET rating = excluded.rating WHERE excluded.rating > r.rating',
                ],
                'SELECT uid, rating FROM r',
            ],
            'counter folds existing and excluded' => [
                [
                    'CREATE TABLE c(k TEXT PRIMARY KEY, n INT)',
                    "INSERT INTO c(k, n) VALUES ('x', 1) ON CONFLICT(k) DO UPDATE SET n = c.n + excluded.n",
                    "INSERT INTO c(k, n) VALUES ('x', 1) ON CONFLICT(k) DO UPDATE SET n = c.n + excluded.n",
                    "INSERT INTO c(k, n) VALUES ('x', 10) ON CONFLICT(k) DO UPDATE SET n = c.n + excluded.n",
                ],
                'SELECT k, n FROM c',
            ],
            'conflict on integer primary key' => [
                [
                    'CREATE TABLE t(id INTEGER PRIMARY KEY, v TEXT)',
                    "INSERT INTO t VALUES (1, 'a')",
                    "INSERT INTO t VALUES (1, 'b') ON CONFLICT(id) DO UPDATE SET v = excluded.v",
                ],
                'SELECT id, v FROM t',
            ],
            'unqualified column in set refers to target row' => [
                [
                    'CREATE TABLE t(k TEXT PRIMARY KEY, hits INT)',
                    "INSERT INTO t VALUES ('a', 1)",
                    "INSERT INTO t VALUES ('a', 99) ON CONFLICT(k) DO UPDATE SET hits = hits + 1",
                ],
                'SELECT k, hits FROM t',
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

        [$yRows, $yErr] = $this->runScript($yeti, $script, $verify);
        [$rRows, $rErr] = $this->runScript($real, $script, $verify);

        if ($rErr || $yErr) {
            self::assertTrue($rErr, 'YetiSQL errored but SQLite did not');
            self::assertTrue($yErr, 'SQLite errored but YetiSQL did not');
            return;
        }
        self::assertSame($rRows, $yRows);
    }

    public function testDoUpdateReportsRowCount(): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        $yeti->setAttribute(YetiPDO::ATTR_ERRMODE, YetiPDO::ERRMODE_EXCEPTION);
        $yeti->exec('CREATE TABLE t(k TEXT PRIMARY KEY, v INT)');

        self::assertSame(1, $yeti->exec("INSERT INTO t VALUES ('a', 1) ON CONFLICT(k) DO UPDATE SET v = excluded.v"));
        // Conflicting insert that updates: one row affected.
        self::assertSame(1, $yeti->exec("INSERT INTO t VALUES ('a', 2) ON CONFLICT(k) DO UPDATE SET v = excluded.v"));
        // Conflicting insert that does nothing: zero rows affected.
        self::assertSame(0, $yeti->exec("INSERT INTO t VALUES ('a', 3) ON CONFLICT(k) DO NOTHING"));
        self::assertSame([['a', 2]], $yeti->query('SELECT k, v FROM t')->fetchAll(YetiPDO::FETCH_NUM));
    }

    /**
     * @param list<string> $script
     * @return array{0:list<array<int,mixed>>,1:bool}
     */
    private function runScript(RealPDO|YetiPDO $db, array $script, string $verify): array
    {
        $db->setAttribute($db::ATTR_ERRMODE, $db::ERRMODE_EXCEPTION);
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
