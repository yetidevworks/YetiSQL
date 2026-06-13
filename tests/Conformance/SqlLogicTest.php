<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO;

/**
 * A small sqllogictest-style conformance corpus: each case sets up a schema and
 * asserts the exact result of a query against documented SQLite behaviour. This
 * corpus grows over time; it complements the differential oracle by pinning
 * behaviour even where the pdo_sqlite extension is unavailable.
 */
final class SqlLogicTest extends TestCase
{
    /** @return iterable<string,array{0:list<string>,1:string,2:list<list<mixed>>}> */
    public static function cases(): iterable
    {
        $base = [
            'CREATE TABLE t (a INTEGER, b TEXT, c REAL)',
            "INSERT INTO t VALUES (1,'x',1.5),(2,'y',2.5),(3,'z',NULL)",
        ];

        yield 'integer division' => [[], 'SELECT 7/2, 7%2, 7.0/2', [[3, 1, 3.5]]];
        yield 'concat with null' => [[], "SELECT 'a' || NULL", [[null]]];
        yield 'coalesce' => [[], 'SELECT coalesce(NULL, NULL, 5, 6)', [[5]]];
        yield 'order by + limit' => [$base, 'SELECT a FROM t ORDER BY a DESC LIMIT 2', [[3], [2]]];
        yield 'count and sum' => [$base, 'SELECT COUNT(*), SUM(a) FROM t', [[3, 6]]];
        yield 'group by' => [
            $base,
            'SELECT c IS NULL AS n, COUNT(*) FROM t GROUP BY n ORDER BY n',
            [[0, 2], [1, 1]],
        ];
        yield 'where with text compare' => [$base, "SELECT a FROM t WHERE b > 'x' ORDER BY a", [[2], [3]]];
        yield 'case expression' => [
            $base,
            "SELECT CASE a WHEN 1 THEN 'one' WHEN 2 THEN 'two' ELSE 'many' END FROM t ORDER BY a",
            [['one'], ['two'], ['many']],
        ];
        yield 'distinct' => [
            ['CREATE TABLE d (v INTEGER)', 'INSERT INTO d VALUES (1),(1),(2),(3),(3)'],
            'SELECT DISTINCT v FROM d ORDER BY v',
            [[1], [2], [3]],
        ];
        yield 'null sorts first' => [$base, 'SELECT c FROM t ORDER BY c', [[null], [1.5], [2.5]]];
    }

    /**
     * @param list<string> $setup
     * @param list<list<mixed>> $expected
     */
    #[DataProvider('cases')]
    public function testCase(array $setup, string $query, array $expected): void
    {
        $db = new PDO('yetisql::memory:');
        foreach ($setup as $sql) {
            $db->exec($sql);
        }
        $rows = $db->query($query)->fetchAll(PDO::FETCH_NUM);

        self::assertSame(
            $this->canon($expected),
            $this->canon($rows),
        );
    }

    /**
     * @param list<list<mixed>> $rows
     * @return list<list<string>>
     */
    private function canon(array $rows): array
    {
        return \array_map(
            static fn (array $r): array => \array_map(static function ($v): string {
                if ($v === null) {
                    return 'NULL';
                }
                if (\is_float($v) || (\is_int($v) && false)) {
                    return 'f:' . \rtrim(\rtrim(\sprintf('%.6f', (float) $v), '0'), '.');
                }
                if (\is_int($v) || (\is_string($v) && \preg_match('/^-?\d+$/', $v) === 1)) {
                    return 'n:' . (string) (int) $v;
                }
                if (\is_numeric($v)) {
                    return 'f:' . \rtrim(\rtrim(\sprintf('%.6f', (float) $v), '0'), '.');
                }
                return 's:' . $v;
            }, $r),
            $rows,
        );
    }
}
