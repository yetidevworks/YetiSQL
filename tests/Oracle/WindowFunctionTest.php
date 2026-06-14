<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Oracle;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Differential coverage for window functions: ranking (ROW_NUMBER / RANK /
 * DENSE_RANK / NTILE / PERCENT_RANK / CUME_DIST), running and whole-partition
 * aggregates, navigation (LAG / LEAD / FIRST_VALUE / LAST_VALUE / NTH_VALUE),
 * explicit ROWS / RANGE / GROUPS frames, PARTITION BY, and window calls nested
 * inside larger expressions. Every result is compared against pdo_sqlite.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class WindowFunctionTest extends TestCase
{
    private const SETUP = [
        'CREATE TABLE emp (id INTEGER PRIMARY KEY, dept TEXT, mgr INTEGER, salary INTEGER)',
        "INSERT INTO emp VALUES (1,'a',NULL,100),(2,'a',1,200),(3,'b',1,150),"
            . "(4,'b',2,150),(5,'b',2,300),(6,'a',3,200),(7,'c',NULL,50)",
    ];

    /** @return iterable<string,array{0:string}> */
    public static function queries(): iterable
    {
        $cases = [
            // ranking
            'SELECT id, dept, salary, ROW_NUMBER() OVER (PARTITION BY dept ORDER BY salary DESC) rn FROM emp ORDER BY id',
            'SELECT id, dept, RANK() OVER (PARTITION BY dept ORDER BY salary DESC) r, '
                . 'DENSE_RANK() OVER (PARTITION BY dept ORDER BY salary DESC) dr FROM emp ORDER BY id',
            'SELECT id, salary, PERCENT_RANK() OVER (ORDER BY salary) pr, '
                . 'CUME_DIST() OVER (ORDER BY salary) cd FROM emp ORDER BY id',
            'SELECT id, dept, NTILE(3) OVER (ORDER BY salary) nt FROM emp ORDER BY id',
            'SELECT id, salary, ROW_NUMBER() OVER () rn FROM emp ORDER BY id',
            // aggregates as windows
            'SELECT id, dept, salary, SUM(salary) OVER (PARTITION BY dept ORDER BY salary) run FROM emp ORDER BY id',
            'SELECT id, dept, salary, SUM(salary) OVER (PARTITION BY dept) tot FROM emp ORDER BY id',
            'SELECT id, dept, COUNT(*) OVER (PARTITION BY dept) c FROM emp ORDER BY id',
            'SELECT id, dept, salary, SUM(salary) OVER (ORDER BY salary) rs FROM emp ORDER BY id',
            'SELECT id, dept, AVG(salary) OVER (PARTITION BY dept) a, '
                . 'AVG(salary) OVER (ORDER BY id) b FROM emp ORDER BY id',
            // navigation
            'SELECT id, salary, LAG(salary) OVER (ORDER BY id) prev, '
                . 'LEAD(salary,1,-1) OVER (ORDER BY id) nxt FROM emp ORDER BY id',
            'SELECT id, dept, salary, FIRST_VALUE(salary) OVER (PARTITION BY dept ORDER BY salary) fv, '
                . 'LAST_VALUE(salary) OVER (PARTITION BY dept ORDER BY salary) lv FROM emp ORDER BY id',
            'SELECT id, dept, salary, NTH_VALUE(salary,2) OVER '
                . '(PARTITION BY dept ORDER BY salary ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING) nv '
                . 'FROM emp ORDER BY id',
            // explicit frames
            'SELECT id, salary, AVG(salary) OVER (ORDER BY id ROWS BETWEEN 1 PRECEDING AND 1 FOLLOWING) ma FROM emp ORDER BY id',
            'SELECT id, salary, MAX(salary) OVER (ORDER BY id ROWS UNBOUNDED PRECEDING) cm FROM emp ORDER BY id',
            'SELECT id, salary, SUM(salary) OVER (ORDER BY salary GROUPS BETWEEN CURRENT ROW AND CURRENT ROW) g FROM emp ORDER BY id',
            // NULLs in order key
            'SELECT id, mgr, ROW_NUMBER() OVER (ORDER BY mgr) rn FROM emp ORDER BY id',
            // window calls inside expressions / CASE, and ORDER BY a window result
            'SELECT id, salary, salary - LAG(salary) OVER (ORDER BY id) AS delta FROM emp ORDER BY id',
            "SELECT id, CASE WHEN ROW_NUMBER() OVER (ORDER BY salary DESC)=1 THEN 'top' ELSE 'rest' END g FROM emp ORDER BY id",
            'SELECT id, RANK() OVER (ORDER BY salary DESC) r FROM emp ORDER BY r, id',
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
