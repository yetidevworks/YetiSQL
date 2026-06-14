<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Oracle;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Differential coverage for the RETURNING clause on INSERT / UPDATE / DELETE:
 * one result row per affected row, projecting the post-write image (the deleted
 * image for DELETE). Each query must return exactly what pdo_sqlite returns.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class ReturningTest extends TestCase
{
    private const SETUP = [
        'CREATE TABLE t (id INTEGER PRIMARY KEY, n TEXT, v INTEGER)',
        "INSERT INTO t VALUES (1,'a',10),(2,'b',20),(3,'c',30)",
        'CREATE TABLE u (id INTEGER PRIMARY KEY, e TEXT UNIQUE)',
        "INSERT INTO u VALUES (1,'a'),(2,'b')",
    ];

    /** @return iterable<string,array{0:string}> */
    public static function queries(): iterable
    {
        $cases = [
            "INSERT INTO t(n,v) VALUES ('x',5) RETURNING id, n, v",
            "INSERT INTO t(n,v) VALUES ('x',5),('y',6),('z',7) RETURNING id",
            "INSERT INTO t(n,v) VALUES ('x',5) RETURNING *",
            "INSERT INTO t(n,v) VALUES ('x',5) RETURNING id, v * 2 AS dbl",
            "INSERT INTO t(n,v) VALUES ('x',5) RETURNING rowid, upper(n)",
            'INSERT INTO t DEFAULT VALUES RETURNING id',
            'UPDATE t SET v = v + 100 WHERE id <= 2 RETURNING id, v',
            'UPDATE t SET v = 99 RETURNING *',
            "UPDATE t SET n = n || '!' WHERE id = 2 RETURNING id, n",
            'UPDATE t SET v = v WHERE id = 999 RETURNING id',
            'DELETE FROM t WHERE id = 2 RETURNING id, n, v',
            'DELETE FROM t WHERE v >= 20 RETURNING id, v',
            'DELETE FROM t RETURNING id',
            'DELETE FROM t WHERE id = 999 RETURNING id',
            // with conflict resolution
            "INSERT OR IGNORE INTO u VALUES (3,'a') RETURNING id, e",
            "INSERT OR IGNORE INTO u VALUES (3,'z') RETURNING id, e",
            "INSERT OR REPLACE INTO u VALUES (5,'a') RETURNING id, e",
        ];
        foreach ($cases as $sql) {
            yield $sql => [$sql];
        }
    }

    #[DataProvider('queries')]
    public function testMatchesSqlite(string $sql): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        $real = new RealPDO('sqlite::memory:');
        $real->setAttribute(RealPDO::ATTR_ERRMODE, RealPDO::ERRMODE_EXCEPTION);
        foreach (self::SETUP as $ddl) {
            $yeti->exec($ddl);
            $real->exec($ddl);
        }

        self::assertSame(
            $real->query($sql)->fetchAll(RealPDO::FETCH_NUM),
            $yeti->query($sql)->fetchAll(YetiPDO::FETCH_NUM),
            $sql,
        );
    }

    public function testReturningColumnNamesMatchSqlite(): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        $real = new RealPDO('sqlite::memory:');
        $real->setAttribute(RealPDO::ATTR_ERRMODE, RealPDO::ERRMODE_EXCEPTION);
        foreach (self::SETUP as $ddl) {
            $yeti->exec($ddl);
            $real->exec($ddl);
        }

        $sql = "INSERT INTO t(n,v) VALUES ('x',5) RETURNING id, n AS label, v";
        self::assertSame(
            $real->query($sql)->fetch(RealPDO::FETCH_ASSOC),
            $yeti->query($sql)->fetch(YetiPDO::FETCH_ASSOC),
        );
    }
}
