<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO;
use YetiDevWorks\YetiSQL\PDOException;

/**
 * Regression coverage for three fixes:
 *  - DISTINCT aggregates (COUNT/SUM/AVG/group_concat) must dedup their argument.
 *  - The unfiltered grouped-aggregate result cache must not serve another
 *    query's rows when spl_object_id() is reused after GC (signature gate).
 *  - Opening a database written by an incompatible on-disk format must fail
 *    cleanly instead of silently misreading page contents.
 */
final class AggregateAndFormatTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = \sys_get_temp_dir() . '/yetisql_fmt_' . \bin2hex(\random_bytes(6)) . '.ysql';
    }

    protected function tearDown(): void
    {
        @\unlink($this->path);
        @\unlink($this->path . '-journal');
    }

    private function seed(PDO $db): void
    {
        // 90 rows: dept cycles eng/sales/hr (30 each); age is i % 10 (10 distinct).
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, dept TEXT, age INTEGER, sal REAL)');
        $depts = ['eng', 'sales', 'hr'];
        $stmt = $db->prepare('INSERT INTO t (id, dept, age, sal) VALUES (?,?,?,?)');
        $db->beginTransaction();
        for ($i = 1; $i <= 90; $i++) {
            $stmt->execute([$i, $depts[$i % 3], $i % 10, 100.0 + ($i % 5)]);
        }
        $db->commit();
    }

    public function testCountDistinctIgnoresRepeatsAndNulls(): void
    {
        $db = new PDO('yetisql::memory:');
        $this->seed($db);

        self::assertSame(3, (int) $db->query('SELECT COUNT(DISTINCT dept) FROM t')->fetchColumn());
        self::assertSame(10, (int) $db->query('SELECT COUNT(DISTINCT age) FROM t')->fetchColumn());
        self::assertSame(5, (int) $db->query('SELECT COUNT(DISTINCT sal) FROM t')->fetchColumn());
        self::assertSame(90, (int) $db->query('SELECT COUNT(*) FROM t')->fetchColumn());
        // COUNT(DISTINCT) ignores NULL inputs.
        $db->exec('INSERT INTO t (id, dept, age, sal) VALUES (1000, NULL, 5, 100.0)');
        self::assertSame(3, (int) $db->query('SELECT COUNT(DISTINCT dept) FROM t')->fetchColumn());
    }

    public function testDistinctSumAvgAndGrouped(): void
    {
        $db = new PDO('yetisql::memory:');
        $this->seed($db);

        // age is 0..9, each appearing 9 times; DISTINCT sum = 0+1+...+9 = 45.
        self::assertSame(45, (int) $db->query('SELECT SUM(DISTINCT age) FROM t')->fetchColumn());
        self::assertEqualsWithDelta(4.5, (float) $db->query('SELECT AVG(DISTINCT age) FROM t')->fetchColumn(), 1e-9);

        // Per-dept distinct age counts (each dept has all 10 age buckets present).
        $rows = $db->query('SELECT dept, COUNT(DISTINCT age) c FROM t GROUP BY dept ORDER BY dept')
            ->fetchAll(\PDO::FETCH_NUM);
        self::assertSame([['eng', 10], ['hr', 10], ['sales', 10]], $rows);
    }

    public function testGroupedAggregateCacheKeepsQueriesDistinct(): void
    {
        $db = new PDO('yetisql::memory:');
        $this->seed($db);

        // Two different unfiltered grouped aggregates over the same table, run
        // repeatedly to exercise the result cache. Each must keep its own shape.
        for ($r = 0; $r < 5; $r++) {
            $byDept = $db->query('SELECT dept, COUNT(*) c FROM t GROUP BY dept')->fetchAll(\PDO::FETCH_NUM);
            \usort($byDept, static fn ($a, $b) => $a[0] <=> $b[0]);
            self::assertSame([['eng', 30], ['hr', 30], ['sales', 30]], $byDept);

            $byAge = $db->query('SELECT age, COUNT(*) c FROM t GROUP BY age')->fetchAll(\PDO::FETCH_NUM);
            self::assertCount(10, $byAge);
            self::assertSame(90, \array_sum(\array_map(static fn ($x) => $x[1], $byAge)));
        }
    }

    public function testSelectMetadataCacheKeepsQueriesDistinct(): void
    {
        $db = new PDO('yetisql::memory:');
        $this->seed($db);

        for ($r = 0; $r < 20; $r++) {
            $byDept = $db->query('SELECT dept, COUNT(*) c FROM t GROUP BY dept ORDER BY dept')->fetchAll(\PDO::FETCH_NUM);
            self::assertSame([['eng', 30], ['hr', 30], ['sales', 30]], $byDept);

            $byAge = $db->query('SELECT age, SUM(sal) s FROM t GROUP BY age ORDER BY age')->fetchAll(\PDO::FETCH_NUM);
            self::assertCount(10, $byAge);
            self::assertSame([0, 900.0], $byAge[0]);
            self::assertSame([9, 936.0], $byAge[9]);
        }
    }

    public function testOpenRejectsIncompatibleSchemaFormat(): void
    {
        $db = new PDO($this->path);
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $db->exec("INSERT INTO t VALUES (1, 'a')");
        unset($db);

        // Reopening the untouched file must work.
        $db = new PDO($this->path);
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM t')->fetchColumn());
        unset($db);

        // Corrupt the 4-byte big-endian schema-format field (header offset 28).
        $fh = \fopen($this->path, 'r+b');
        \fseek($fh, 28);
        \fwrite($fh, \pack('N', 4)); // an older, unsupported format
        \fclose($fh);

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/unsupported YetiSQL schema format/');
        new PDO($this->path);
    }
}
