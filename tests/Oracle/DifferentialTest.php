<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Oracle;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Differential / oracle testing: run identical SQL against the real pdo_sqlite
 * extension and YetiSQL, then assert the result sets match. This is the
 * strongest practical lever on "100% SQLite compatible" — divergences here are
 * real behavioural differences.
 *
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class DifferentialTest extends TestCase
{
    /** Shared schema + data applied to both engines before each query. */
    private const SETUP = [
        'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT, age INTEGER, score REAL, city TEXT)',
        "INSERT INTO t VALUES (1, 'Alice', 30, 9.5, 'NYC')",
        "INSERT INTO t VALUES (2, 'bob', 25, 7.0, 'LA')",
        "INSERT INTO t VALUES (3, 'Carol', 35, 8.25, 'NYC')",
        "INSERT INTO t VALUES (4, 'dave', 28, NULL, 'SF')",
        "INSERT INTO t VALUES (5, 'Eve', 42, 6.5, 'LA')",
        "INSERT INTO t VALUES (6, 'Frank', NULL, 5.0, 'NYC')",
    ];

    /** @return iterable<string,array{0:string}> */
    public static function queries(): iterable
    {
        $cases = [
            // projection / arithmetic
            'SELECT 1 + 2 * 3, 10 / 3, 10 % 3, 7.0 / 2',
            "SELECT 'a' || 'b' || 'c'",
            'SELECT -5, ~3, +9, abs(-4.5)',
            'SELECT 5 / 0, 5 % 0',
            // filtering
            'SELECT id, name FROM t WHERE age > 28 ORDER BY id',
            'SELECT id FROM t WHERE age IS NULL ORDER BY id',
            'SELECT id FROM t WHERE score IS NOT NULL ORDER BY id',
            "SELECT id FROM t WHERE name LIKE '%a%' ORDER BY id",
            "SELECT id FROM t WHERE name GLOB '*a*' ORDER BY id",
            'SELECT id FROM t WHERE age BETWEEN 26 AND 36 ORDER BY id',
            'SELECT id FROM t WHERE city IN (\'NYC\',\'SF\') ORDER BY id',
            'SELECT id FROM t WHERE id IN (1,1,2) ORDER BY id',
            'SELECT COUNT(*) FROM t WHERE id IN (1,1,2)',
            'SELECT id FROM t WHERE age > 30 OR score < 6 ORDER BY id',
            'SELECT id FROM t WHERE NOT (city = \'NYC\') ORDER BY id',
            // ordering / limit
            'SELECT name FROM t ORDER BY age DESC',
            'SELECT name FROM t ORDER BY score',
            'SELECT id FROM t ORDER BY id LIMIT 2 OFFSET 1',
            'SELECT DISTINCT city FROM t ORDER BY city',
            // aggregates
            'SELECT COUNT(*), COUNT(age), SUM(age), AVG(age), MIN(age), MAX(age) FROM t',
            'SELECT city, COUNT(*) FROM t GROUP BY city ORDER BY city',
            'SELECT city, COUNT(*) c FROM t GROUP BY city HAVING c > 1 ORDER BY city',
            'SELECT total(score), sum(score) FROM t',
            'SELECT group_concat(name) FROM t',
            // CASE / functions
            "SELECT id, CASE WHEN age IS NULL THEN 'unknown' WHEN age < 30 THEN 'young' ELSE 'old' END FROM t ORDER BY id",
            'SELECT upper(name), lower(name), length(name) FROM t WHERE id = 1',
            "SELECT substr(name,2,2), replace(name,'a','@'), trim('  hi  ') FROM t WHERE id=1",
            "SELECT coalesce(age, -1), ifnull(score, 0.0) FROM t WHERE id = 6",
            'SELECT typeof(age), typeof(score), typeof(name), typeof(99999999999) FROM t WHERE id=1',
            'SELECT round(8.255, 1), round(8.255), abs(-7)',
            // comparison affinity / typing
            "SELECT id FROM t WHERE age = '30'",
            'SELECT 1 = 1.0, \'1\' = 1, \'abc\' < \'abd\'',
            'SELECT cast(\'42abc\' AS INTEGER), cast(3.99 AS INTEGER), cast(7 AS TEXT)',
            // null handling
            'SELECT NULL = NULL, NULL IS NULL, 1 + NULL, NULL OR 1, NULL AND 0',
            // subqueries
            'SELECT id FROM t WHERE age > (SELECT AVG(age) FROM t) ORDER BY id',
            'SELECT (SELECT COUNT(*) FROM t)',
            // compound
            'SELECT id FROM t WHERE id < 3 UNION SELECT id FROM t WHERE id > 4 ORDER BY id',
        ];
        foreach ($cases as $sql) {
            yield $sql => [$sql];
        }
    }

    #[DataProvider('queries')]
    public function testMatchesSqlite(string $sql): void
    {
        $real = $this->loadReal();
        $yeti = $this->loadYeti();

        $expected = $this->runReal($real, $sql);
        $actual = $this->runYeti($yeti, $sql);

        self::assertSame(
            $this->normalize($expected),
            $this->normalize($actual),
            "Divergence for: $sql",
        );
    }

    private function loadReal(): RealPDO
    {
        $pdo = new RealPDO('sqlite::memory:');
        $pdo->setAttribute(RealPDO::ATTR_ERRMODE, RealPDO::ERRMODE_EXCEPTION);
        foreach (self::SETUP as $sql) {
            $pdo->exec($sql);
        }
        return $pdo;
    }

    private function loadYeti(): YetiPDO
    {
        $pdo = new YetiPDO('yetisql::memory:');
        foreach (self::SETUP as $sql) {
            $pdo->exec($sql);
        }
        return $pdo;
    }

    /** @return list<list<mixed>> */
    private function runReal(RealPDO $pdo, string $sql): array
    {
        return $pdo->query($sql)->fetchAll(RealPDO::FETCH_NUM);
    }

    /** @return list<list<mixed>> */
    private function runYeti(YetiPDO $pdo, string $sql): array
    {
        return $pdo->query($sql)->fetchAll(YetiPDO::FETCH_NUM);
    }

    /**
     * Canonicalise values so trivially-equivalent results compare equal:
     * numeric strings/ints/floats collapse to a normalized numeric token while
     * preserving the int-vs-real distinction loosely (SQLite and we both keep
     * 35.0 a real). Floats are rounded to mitigate formatting noise.
     *
     * @param list<list<mixed>> $rows
     * @return list<list<string>>
     */
    private function normalize(array $rows): array
    {
        return \array_map(
            fn (array $row): array => \array_map([$this, 'tok'], $row),
            $rows,
        );
    }

    private function tok(mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (\is_int($v) || (\is_string($v) && \preg_match('/^-?\d+$/', $v) === 1)) {
            return 'n:' . (string) (int) $v;
        }
        if (\is_float($v) || (\is_string($v) && \is_numeric($v))) {
            return 'f:' . \rtrim(\rtrim(\sprintf('%.6f', (float) $v), '0'), '.');
        }
        return 's:' . $v;
    }
}
