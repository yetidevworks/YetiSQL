<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Oracle;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Differential coverage for multi-column index planning: an equality prefix
 * across the leading columns of a composite index, optionally with a trailing
 * range. Each query must return exactly what pdo_sqlite returns whether or not
 * the planner uses the composite index.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class MultiColumnIndexTest extends TestCase
{
    /** @return iterable<string,array{0:string}> */
    public static function queries(): iterable
    {
        $cases = [
            'SELECT id FROM t WHERE a = 3 AND b = 5 ORDER BY id',
            "SELECT id FROM t WHERE a = 3 AND b = 5 AND c = 'y' ORDER BY id",
            'SELECT id FROM t WHERE a = 4 AND b >= 3 AND b <= 6 ORDER BY id',
            'SELECT id FROM t WHERE a = 4 AND b > 3 AND b < 6 ORDER BY id',
            'SELECT id FROM t WHERE a = 7 ORDER BY id',
            "SELECT id FROM t WHERE a = 2 AND b = 8 AND c > 'x' ORDER BY id",
            'SELECT id FROM t WHERE b = 5 ORDER BY id',
            'SELECT id FROM t WHERE a = 5 AND b = 5 AND id % 2 = 0 ORDER BY id',
            'SELECT id FROM t WHERE b = 2 AND a = 9 ORDER BY id',
            'SELECT COUNT(*) FROM t WHERE a = 3 AND b = 5',
            'SELECT COUNT(*) FROM t WHERE a = 4 AND b >= 3 AND b <= 6',
            'SELECT COUNT(*) FROM t WHERE a = 5 AND b = 5 AND id % 2 = 0',
            'SELECT id FROM t WHERE a = 3 AND b = 99 ORDER BY id',
            'SELECT id FROM t WHERE a = 6 AND b = 4 AND c IN (\'x\',\'z\') ORDER BY id',
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

        foreach ([$yeti, $real] as $db) {
            $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, a INTEGER, b INTEGER, c TEXT)');
            $db->exec('CREATE INDEX idx_abc ON t(a, b, c)');
            $n = 1;
            for ($a = 1; $a <= 10; $a++) {
                for ($b = 1; $b <= 10; $b++) {
                    foreach (['x', 'y', 'z'] as $c) {
                        $db->exec("INSERT INTO t VALUES ($n, $a, $b, '$c')");
                        $n++;
                    }
                }
            }
        }

        self::assertSame(
            $real->query($sql)->fetchAll(RealPDO::FETCH_NUM),
            $yeti->query($sql)->fetchAll(YetiPDO::FETCH_NUM),
            $sql,
        );
    }
}
