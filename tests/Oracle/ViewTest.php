<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Oracle;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Differential coverage for CREATE VIEW: simple filtering views, views with an
 * explicit column list, aggregate/GROUP BY views, views joined against base
 * tables, views built on other views, `SELECT *` over a view, and views used
 * inside scalar subqueries. Every result is compared against pdo_sqlite.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class ViewTest extends TestCase
{
    private const SETUP = [
        'CREATE TABLE emp (id INTEGER PRIMARY KEY, name TEXT, dept TEXT, sal INTEGER)',
        "INSERT INTO emp VALUES (1,'A','eng',100),(2,'B','eng',200),(3,'C','sales',150),"
            . "(4,'D','sales',300),(5,'E','eng',120)",
        "CREATE VIEW eng AS SELECT id, name, sal FROM emp WHERE dept = 'eng'",
        'CREATE VIEW dept_tot(d, total) AS SELECT dept, SUM(sal) FROM emp GROUP BY dept',
        'CREATE VIEW hi AS SELECT * FROM emp WHERE sal >= 150',
        'CREATE VIEW eng_hi AS SELECT * FROM eng WHERE sal >= 120',
    ];

    /** @return iterable<string,array{0:string}> */
    public static function queries(): iterable
    {
        $cases = [
            'SELECT * FROM eng ORDER BY id',
            'SELECT name FROM eng WHERE sal > 100 ORDER BY id',
            'SELECT d, total FROM dept_tot ORDER BY d',
            'SELECT COUNT(*) FROM hi',
            'SELECT e.name, h.name FROM emp e JOIN hi h ON e.id = h.id ORDER BY e.id',
            'SELECT * FROM eng_hi ORDER BY id',
            'SELECT (SELECT COUNT(*) FROM eng), (SELECT MAX(sal) FROM hi)',
            'SELECT id, name FROM eng ORDER BY sal DESC, id',
            'SELECT COUNT(*) FROM hi WHERE id <= 4',
            'SELECT COUNT(*) FROM eng WHERE sal > 100',
            'SELECT v.d FROM dept_tot v WHERE v.total > 300 ORDER BY v.d',
            "SELECT type, name, tbl_name FROM sqlite_master WHERE type = 'view' ORDER BY name",
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

    public function testWritingToViewIsRejected(): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        foreach (self::SETUP as $ddl) {
            $yeti->exec($ddl);
        }

        $this->expectExceptionMessage('cannot modify eng because it is a view');
        $yeti->exec("INSERT INTO eng VALUES (9, 'X', 9)");
    }

    public function testDropViewRemovesItFromCatalog(): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        foreach (self::SETUP as $ddl) {
            $yeti->exec($ddl);
        }

        $yeti->exec('DROP VIEW hi');
        $remaining = $yeti->query("SELECT name FROM sqlite_master WHERE name = 'hi'")->fetchAll();
        self::assertSame([], $remaining);

        // IF EXISTS makes a second drop a no-op rather than an error.
        self::assertSame(0, $yeti->exec('DROP VIEW IF EXISTS hi'));
    }
}
