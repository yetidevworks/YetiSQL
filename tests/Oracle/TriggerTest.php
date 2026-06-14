<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Oracle;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Differential coverage for triggers: BEFORE/AFTER on INSERT/UPDATE/DELETE with
 * NEW/OLD row references, WHEN gating, UPDATE OF column lists, multi-statement
 * bodies, trigger cascades (one trigger's write firing another), the
 * recursive_triggers-OFF guard, DROP TRIGGER, and INSTEAD OF triggers that make
 * a view insertable/updatable/deletable. Each scenario runs on both engines and
 * the final query's results are compared.
 *
 * Note: SQLite leaves the firing order of multiple same-event triggers
 * undefined, so that case is deliberately not exercised here.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class TriggerTest extends TestCase
{
    /** @return iterable<string,array{0:list<string>,1:string}> */
    public static function scenarios(): iterable
    {
        $cases = [
            'after insert logs NEW' => [
                [
                    'CREATE TABLE t (id INTEGER PRIMARY KEY, v INTEGER)',
                    'CREATE TABLE log (id INTEGER PRIMARY KEY, msg TEXT)',
                    "CREATE TRIGGER ai AFTER INSERT ON t BEGIN INSERT INTO log(msg) VALUES ('ins '||NEW.id||':'||NEW.v); END",
                    'INSERT INTO t VALUES (1,10),(2,20)',
                ],
                'SELECT id, msg FROM log ORDER BY id',
            ],
            'after update logs OLD and NEW' => [
                [
                    'CREATE TABLE t (id INTEGER PRIMARY KEY, v INTEGER)',
                    'CREATE TABLE log (id INTEGER PRIMARY KEY, o INTEGER, n INTEGER)',
                    'INSERT INTO t VALUES (1,10),(2,20)',
                    'CREATE TRIGGER au AFTER UPDATE ON t BEGIN INSERT INTO log(o,n) VALUES (OLD.v, NEW.v); END',
                    'UPDATE t SET v = v + 5',
                ],
                'SELECT o, n FROM log ORDER BY id',
            ],
            'after delete logs OLD' => [
                [
                    'CREATE TABLE t (id INTEGER PRIMARY KEY, v INTEGER)',
                    'CREATE TABLE log (id INTEGER PRIMARY KEY, d INTEGER)',
                    'INSERT INTO t VALUES (1,10),(2,20),(3,30)',
                    'CREATE TRIGGER ad AFTER DELETE ON t BEGIN INSERT INTO log(d) VALUES (OLD.id); END',
                    'DELETE FROM t WHERE v >= 20',
                ],
                'SELECT d FROM log ORDER BY id',
            ],
            'WHEN gates firing' => [
                [
                    'CREATE TABLE t (id INTEGER PRIMARY KEY, v INTEGER)',
                    'CREATE TABLE log (id INTEGER PRIMARY KEY, x INTEGER)',
                    'CREATE TRIGGER aiw AFTER INSERT ON t WHEN NEW.v > 15 BEGIN INSERT INTO log(x) VALUES (NEW.v); END',
                    'INSERT INTO t VALUES (1,10),(2,20),(3,5),(4,30)',
                ],
                'SELECT x FROM log ORDER BY id',
            ],
            'before insert maintains counter' => [
                [
                    'CREATE TABLE t (id INTEGER PRIMARY KEY, v INTEGER)',
                    'CREATE TABLE cnt (k TEXT PRIMARY KEY, n INTEGER)',
                    "INSERT INTO cnt VALUES ('rows', 0)",
                    "CREATE TRIGGER bi BEFORE INSERT ON t BEGIN UPDATE cnt SET n = n + 1 WHERE k = 'rows'; END",
                    'INSERT INTO t VALUES (1,10),(2,20),(3,30)',
                ],
                'SELECT n FROM cnt',
            ],
            'update of specific column' => [
                [
                    'CREATE TABLE t (id INTEGER PRIMARY KEY, a INTEGER, b INTEGER)',
                    'CREATE TABLE log (id INTEGER PRIMARY KEY, m TEXT)',
                    'INSERT INTO t VALUES (1,1,1)',
                    "CREATE TRIGGER uo AFTER UPDATE OF a ON t BEGIN INSERT INTO log(m) VALUES ('a changed'); END",
                    'UPDATE t SET b = 2',
                    'UPDATE t SET a = 9',
                ],
                'SELECT m FROM log ORDER BY id',
            ],
            'multi-statement body' => [
                [
                    'CREATE TABLE t (id INTEGER PRIMARY KEY, v INTEGER)',
                    'CREATE TABLE a (id INTEGER PRIMARY KEY, x INTEGER)',
                    'CREATE TABLE b (id INTEGER PRIMARY KEY, y INTEGER)',
                    'CREATE TRIGGER multi AFTER INSERT ON t BEGIN '
                        . 'INSERT INTO a(x) VALUES (NEW.v); INSERT INTO b(y) VALUES (NEW.v + 1); END',
                    'INSERT INTO t VALUES (1,10)',
                ],
                'SELECT (SELECT x FROM a), (SELECT y FROM b)',
            ],
            'trigger cascade' => [
                [
                    'CREATE TABLE t1 (id INTEGER PRIMARY KEY)',
                    'CREATE TABLE t2 (id INTEGER PRIMARY KEY)',
                    'CREATE TABLE t3 (id INTEGER PRIMARY KEY)',
                    'CREATE TRIGGER c1 AFTER INSERT ON t1 BEGIN INSERT INTO t2 VALUES (NEW.id * 10); END',
                    'CREATE TRIGGER c2 AFTER INSERT ON t2 BEGIN INSERT INTO t3 VALUES (NEW.id * 10); END',
                    'INSERT INTO t1 VALUES (1),(2)',
                ],
                'SELECT id FROM t3 ORDER BY id',
            ],
            'recursive trigger does not loop' => [
                [
                    'CREATE TABLE t (id INTEGER PRIMARY KEY, v INTEGER)',
                    'CREATE TRIGGER r AFTER INSERT ON t BEGIN INSERT INTO t VALUES (NEW.id + 1, NEW.v); END',
                    'INSERT INTO t VALUES (1, 100)',
                ],
                'SELECT COUNT(*) FROM t',
            ],
            'drop trigger stops firing' => [
                [
                    'CREATE TABLE t (id INTEGER PRIMARY KEY, v INTEGER)',
                    'CREATE TABLE log (id INTEGER PRIMARY KEY, x INTEGER)',
                    'CREATE TRIGGER ai2 AFTER INSERT ON t BEGIN INSERT INTO log(x) VALUES (NEW.v); END',
                    'INSERT INTO t VALUES (1,10)',
                    'DROP TRIGGER ai2',
                    'INSERT INTO t VALUES (2,20)',
                ],
                'SELECT x FROM log ORDER BY id',
            ],
            'instead of insert on view' => [
                [
                    'CREATE TABLE base (id INTEGER PRIMARY KEY, v INTEGER)',
                    'CREATE VIEW vw AS SELECT id, v FROM base',
                    'CREATE TRIGGER io INSTEAD OF INSERT ON vw BEGIN INSERT INTO base VALUES (NEW.id, NEW.v * 2); END',
                    'INSERT INTO vw VALUES (1, 5),(2, 7)',
                ],
                'SELECT id, v FROM base ORDER BY id',
            ],
            'instead of delete on view' => [
                [
                    'CREATE TABLE base (id INTEGER PRIMARY KEY, v INTEGER)',
                    'INSERT INTO base VALUES (1,10),(2,20),(3,30)',
                    'CREATE VIEW vw AS SELECT id, v FROM base',
                    'CREATE TRIGGER iod INSTEAD OF DELETE ON vw BEGIN DELETE FROM base WHERE id = OLD.id; END',
                    'DELETE FROM vw WHERE v >= 20',
                ],
                'SELECT id FROM base ORDER BY id',
            ],
            'instead of update on view' => [
                [
                    'CREATE TABLE base (id INTEGER PRIMARY KEY, v INTEGER)',
                    'INSERT INTO base VALUES (1,10),(2,20)',
                    'CREATE VIEW vw AS SELECT id, v FROM base',
                    'CREATE TRIGGER iou INSTEAD OF UPDATE ON vw BEGIN UPDATE base SET v = NEW.v WHERE id = OLD.id; END',
                    'UPDATE vw SET v = v + 100',
                ],
                'SELECT id, v FROM base ORDER BY id',
            ],
        ];
        foreach ($cases as $name => $case) {
            yield $name => $case;
        }
    }

    /**
     * @param list<string> $setup
     */
    #[DataProvider('scenarios')]
    public function testMatchesSqlite(array $setup, string $query): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        $real = new RealPDO('sqlite::memory:');
        $real->setAttribute(RealPDO::ATTR_ERRMODE, RealPDO::ERRMODE_EXCEPTION);

        foreach ($setup as $stmt) {
            $yeti->exec($stmt);
            $real->exec($stmt);
        }

        self::assertSame(
            $real->query($query)->fetchAll(RealPDO::FETCH_NUM),
            $yeti->query($query)->fetchAll(YetiPDO::FETCH_NUM),
            $query,
        );
    }

    public function testTriggerPersistsAcrossReopen(): void
    {
        $file = \sys_get_temp_dir() . '/yetisql_trg_' . \getmypid() . '.ysql';
        @\unlink($file);

        $db = new YetiPDO("yetisql:$file");
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v INTEGER)');
        $db->exec('CREATE TABLE log (id INTEGER PRIMARY KEY, x INTEGER)');
        $db->exec('CREATE TRIGGER ai AFTER INSERT ON t BEGIN INSERT INTO log(x) VALUES (NEW.v); END');
        unset($db);

        $reopened = new YetiPDO("yetisql:$file");
        $reopened->exec('INSERT INTO t VALUES (1, 42)');
        $rows = $reopened->query('SELECT x FROM log')->fetchAll(YetiPDO::FETCH_NUM);
        @\unlink($file);

        self::assertSame([[42]], $rows);
    }
}
