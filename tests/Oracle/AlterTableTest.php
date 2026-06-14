<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Oracle;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Differential coverage for ALTER TABLE: RENAME TO, RENAME COLUMN, ADD COLUMN
 * (with and without defaults, NOT NULL, chained adds) and DROP COLUMN, including
 * interaction with secondary indexes and the grouped-aggregate path over a
 * freshly-added column. Each scenario's mutations and final query run on both
 * YetiSQL and pdo_sqlite and the results are compared.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class AlterTableTest extends TestCase
{
    private const SETUP = [
        'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT, age INTEGER)',
        "INSERT INTO t VALUES (1,'A',30),(2,'B',40),(3,'C',50),(4,'D',40)",
        'CREATE INDEX idx_age ON t(age)',
    ];

    /** @return iterable<string,array{0:list<string>,1:string}> */
    public static function scenarios(): iterable
    {
        $cases = [
            'rename table' => [
                ['ALTER TABLE t RENAME TO emp'],
                'SELECT * FROM emp ORDER BY id',
            ],
            'rename table then indexed lookup' => [
                ['ALTER TABLE t RENAME TO emp'],
                'SELECT id, name FROM emp WHERE age = 40 ORDER BY id',
            ],
            'rename column' => [
                ['ALTER TABLE t RENAME COLUMN name TO fullname'],
                'SELECT id, fullname, age FROM t ORDER BY id',
            ],
            'rename indexed column then lookup' => [
                ['ALTER TABLE t RENAME COLUMN age TO years'],
                'SELECT id FROM t WHERE years = 40 ORDER BY id',
            ],
            'add column with text default' => [
                ["ALTER TABLE t ADD COLUMN city TEXT DEFAULT 'NYC'"],
                'SELECT id, city FROM t ORDER BY id',
            ],
            'add column with int default' => [
                ['ALTER TABLE t ADD COLUMN score INTEGER DEFAULT 0'],
                'SELECT id, score FROM t ORDER BY id',
            ],
            'add nullable column' => [
                ['ALTER TABLE t ADD COLUMN nick TEXT'],
                'SELECT id, nick FROM t ORDER BY id',
            ],
            'add NOT NULL column with default' => [
                ['ALTER TABLE t ADD COLUMN active INTEGER NOT NULL DEFAULT 1'],
                'SELECT id, active FROM t ORDER BY id',
            ],
            'add then insert' => [
                ["ALTER TABLE t ADD COLUMN tag TEXT DEFAULT 'x'", "INSERT INTO t (id,name,age,tag) VALUES (9,'Z',99,'y')"],
                'SELECT id, tag FROM t ORDER BY id',
            ],
            'chained adds' => [
                ['ALTER TABLE t ADD COLUMN a INTEGER DEFAULT 7', "ALTER TABLE t ADD COLUMN b TEXT DEFAULT 'q'"],
                'SELECT id, a, b FROM t ORDER BY id',
            ],
            'drop column' => [
                ['ALTER TABLE t DROP COLUMN name'],
                'SELECT * FROM t ORDER BY id',
            ],
            'add then drop another, virtual default survives' => [
                ["ALTER TABLE t ADD COLUMN extra TEXT DEFAULT 'z'", 'ALTER TABLE t DROP COLUMN name'],
                'SELECT * FROM t ORDER BY id',
            ],
            'group by added column' => [
                ["ALTER TABLE t ADD COLUMN grp TEXT DEFAULT 'g'"],
                'SELECT grp, COUNT(*), SUM(age) FROM t GROUP BY grp ORDER BY grp',
            ],
        ];
        foreach ($cases as $name => $case) {
            yield $name => $case;
        }
    }

    /**
     * @param list<string> $mutations
     */
    #[DataProvider('scenarios')]
    public function testMatchesSqlite(array $mutations, string $query): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        $real = new RealPDO('sqlite::memory:');
        $real->setAttribute(RealPDO::ATTR_ERRMODE, RealPDO::ERRMODE_EXCEPTION);

        foreach ([...self::SETUP, ...$mutations] as $stmt) {
            $yeti->exec($stmt);
            $real->exec($stmt);
        }

        self::assertSame(
            $real->query($query)->fetchAll(RealPDO::FETCH_NUM),
            $yeti->query($query)->fetchAll(YetiPDO::FETCH_NUM),
            $query,
        );
    }

    public function testAlterPersistsAcrossReopen(): void
    {
        $file = \sys_get_temp_dir() . '/yetisql_alter_' . \getmypid() . '.ysql';
        @\unlink($file);

        $db = new YetiPDO("yetisql:$file");
        $db->exec('CREATE TABLE p (id INTEGER PRIMARY KEY, x INTEGER)');
        $db->exec('INSERT INTO p VALUES (1,5),(2,6)');
        $db->exec("ALTER TABLE p ADD COLUMN y TEXT DEFAULT 'd'");
        $db->exec('ALTER TABLE p RENAME COLUMN x TO xx');
        unset($db);

        $reopened = new YetiPDO("yetisql:$file");
        $rows = $reopened->query('SELECT id, xx, y FROM p ORDER BY id')->fetchAll(YetiPDO::FETCH_NUM);
        @\unlink($file);

        self::assertSame([[1, 5, 'd'], [2, 6, 'd']], $rows);
    }

    public function testDropColumnUsedByIndexIsRejected(): void
    {
        $db = new YetiPDO('yetisql::memory:');
        foreach (self::SETUP as $stmt) {
            $db->exec($stmt);
        }
        $this->expectExceptionMessage('cannot drop column');
        $db->exec('ALTER TABLE t DROP COLUMN age');
    }
}
