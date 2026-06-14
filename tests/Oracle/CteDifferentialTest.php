<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Oracle;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Differential coverage for common table expressions (WITH): non-recursive CTEs
 * used in FROM / joins / subqueries, explicit column lists, chained CTEs, and
 * recursive CTEs (counters, hierarchy walks, UNION de-duplication). Also covers
 * `SELECT *` over a derived table, which CTE materialization exercises.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class CteDifferentialTest extends TestCase
{
    private const SETUP = [
        'CREATE TABLE emp (id INTEGER PRIMARY KEY, name TEXT, mgr INTEGER, dept TEXT, sal INTEGER)',
        "INSERT INTO emp VALUES (1,'CEO',NULL,'exec',500),(2,'VP1',1,'eng',300),(3,'VP2',1,'sales',280),"
            . "(4,'E1',2,'eng',150),(5,'E2',2,'eng',140),(6,'S1',3,'sales',120),(7,'S2',6,'sales',100)",
    ];

    /** @return iterable<string,array{0:string}> */
    public static function queries(): iterable
    {
        $cases = [
            "WITH eng AS (SELECT * FROM emp WHERE dept = 'eng') SELECT name FROM eng ORDER BY id",
            'WITH x(who, pay) AS (SELECT name, sal FROM emp WHERE sal > 200) SELECT who FROM x ORDER BY pay DESC',
            "WITH a AS (SELECT * FROM emp WHERE sal >= 140), b AS (SELECT * FROM a WHERE dept = 'eng') "
                . 'SELECT name FROM b ORDER BY id',
            'WITH big AS (SELECT * FROM emp WHERE sal >= 150) '
                . 'SELECT e.name, b.name FROM emp e JOIN big b ON e.mgr = b.id ORDER BY e.id',
            'WITH d AS (SELECT dept, COUNT(*) c, SUM(sal) s FROM emp GROUP BY dept) SELECT dept, c, s FROM d ORDER BY dept',
            'WITH t AS (SELECT id FROM emp WHERE sal > 200) SELECT (SELECT COUNT(*) FROM t), (SELECT MAX(id) FROM t)',
            'SELECT name FROM (SELECT * FROM emp) x ORDER BY id',
            'SELECT * FROM (SELECT id, name FROM emp WHERE id <= 3) y ORDER BY id',
            // recursive
            'WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < 10) SELECT x FROM n ORDER BY x',
            'WITH RECURSIVE sub(id, name, lvl) AS ('
                . 'SELECT id, name, 0 FROM emp WHERE id = 1 '
                . 'UNION ALL SELECT e.id, e.name, sub.lvl + 1 FROM emp e JOIN sub ON e.mgr = sub.id) '
                . 'SELECT name, lvl FROM sub ORDER BY lvl, id',
            'WITH RECURSIVE r(x) AS (SELECT 1 UNION SELECT (x + 2) % 7 FROM r WHERE x < 100) SELECT x FROM r ORDER BY x',
            'WITH RECURSIVE c(i, t) AS (SELECT 1, 1 UNION ALL SELECT i + 1, t + i + 1 FROM c WHERE i < 6) SELECT i, t FROM c ORDER BY i',
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
}
