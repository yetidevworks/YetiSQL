<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Oracle;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Differential coverage for UNIQUE / PRIMARY KEY enforcement and the INSERT/
 * UPDATE conflict-resolution clauses (`REPLACE INTO`, `INSERT OR REPLACE`,
 * `INSERT OR IGNORE`, `UPDATE OR REPLACE/IGNORE`). UNIQUE and non-integer/
 * composite PRIMARY KEY constraints are backed by auto-created unique indexes,
 * so each scenario must end in the same table state — or the same error — as
 * pdo_sqlite.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class ConflictResolutionTest extends TestCase
{
    /**
     * @return iterable<string,array{0:list<string>,1:list<string>,2:string}>
     *   [setup DDL+seed, mutating statements, verification query]
     */
    public static function scenarios(): iterable
    {
        $u = [
            'CREATE TABLE u (id INTEGER PRIMARY KEY, email TEXT UNIQUE, name TEXT)',
            "INSERT INTO u VALUES (1,'a@x','ann'),(2,'b@x','bob')",
        ];
        $verify = 'SELECT id, email, name FROM u ORDER BY id';

        $cases = [
            'plain INSERT dup UNIQUE errors' => [$u, ["INSERT INTO u VALUES (3,'a@x','cy')"], $verify],
            'plain INSERT dup rowid errors' => [$u, ["INSERT INTO u VALUES (1,'c@x','cy')"], $verify],
            'INSERT distinct ok' => [$u, ["INSERT INTO u VALUES (3,'c@x','cy')"], $verify],
            'REPLACE INTO on UNIQUE' => [$u, ["REPLACE INTO u VALUES (5,'a@x','cy')"], $verify],
            'INSERT OR REPLACE on UNIQUE' => [$u, ["INSERT OR REPLACE INTO u VALUES (5,'a@x','cy')"], $verify],
            'REPLACE on rowid PK' => [$u, ["REPLACE INTO u VALUES (1,'z@x','zed')"], $verify],
            'REPLACE conflicting on BOTH pk and unique' => [$u, ["REPLACE INTO u VALUES (2,'a@x','new')"], $verify],
            'INSERT OR IGNORE on UNIQUE skips' => [$u, ["INSERT OR IGNORE INTO u VALUES (5,'a@x','cy')"], $verify],
            'INSERT OR IGNORE on rowid skips' => [$u, ["INSERT OR IGNORE INTO u VALUES (1,'c@x','cy')"], $verify],

            // UPDATE enforcement
            'UPDATE into dup UNIQUE errors' => [$u, ["UPDATE u SET email='a@x' WHERE id=2"], $verify],
            'UPDATE to own value ok' => [$u, ["UPDATE u SET email='a@x', name='AA' WHERE id=1"], $verify],
            'UPDATE rowid into existing errors' => [$u, ['UPDATE u SET id=1 WHERE id=2'], $verify],
            'UPDATE OR IGNORE dup leaves row' => [$u, ["UPDATE OR IGNORE u SET email='a@x' WHERE id=2"], $verify],
            'UPDATE OR REPLACE dup' => [$u, ["UPDATE OR REPLACE u SET email='a@x' WHERE id=2"], $verify],
            'UPDATE non-unique column' => [$u, ["UPDATE u SET name='X'"], $verify],

            // multiple NULLs are distinct in a UNIQUE column
            'UNIQUE allows multiple NULLs' => [
                ['CREATE TABLE t (id INTEGER PRIMARY KEY, e TEXT UNIQUE)'],
                ["INSERT INTO t VALUES (1,NULL),(2,NULL),(3,'x')"],
                'SELECT id, e FROM t ORDER BY id',
            ],

            // composite PRIMARY KEY (no rowid alias)
            'composite PK dup errors' => [
                ['CREATE TABLE c (a TEXT, b TEXT, x TEXT, PRIMARY KEY(a,b))', "INSERT INTO c VALUES ('p','q','1')"],
                ["INSERT INTO c VALUES ('p','q','2')"],
                'SELECT a, b, x FROM c ORDER BY a, b',
            ],
            'composite PK REPLACE' => [
                ['CREATE TABLE c (a TEXT, b TEXT, x TEXT, PRIMARY KEY(a,b))', "INSERT INTO c VALUES ('p','q','1')"],
                ["REPLACE INTO c VALUES ('p','q','2')"],
                'SELECT a, b, x FROM c ORDER BY a, b',
            ],
            'composite PK distinct ok' => [
                ['CREATE TABLE c (a TEXT, b TEXT, x TEXT, PRIMARY KEY(a,b))', "INSERT INTO c VALUES ('p','q','1')"],
                ["INSERT INTO c VALUES ('p','r','2')"],
                'SELECT a, b, x FROM c ORDER BY a, b',
            ],

            // single non-integer PRIMARY KEY
            'text PK dup errors' => [
                ["CREATE TABLE t (code TEXT PRIMARY KEY, n INT)", "INSERT INTO t VALUES ('x',1)"],
                ["INSERT INTO t VALUES ('x',2)"],
                'SELECT code, n FROM t ORDER BY code',
            ],
            'text PK REPLACE' => [
                ["CREATE TABLE t (code TEXT PRIMARY KEY, n INT)", "INSERT INTO t VALUES ('x',1)"],
                ["REPLACE INTO t VALUES ('x',2)"],
                'SELECT code, n FROM t ORDER BY code',
            ],

            // table-level multi-column UNIQUE
            'multicol UNIQUE dup errors' => [
                ['CREATE TABLE m (id INTEGER PRIMARY KEY, a TEXT, b TEXT, UNIQUE(a,b))', "INSERT INTO m VALUES (1,'p','q')"],
                ["INSERT INTO m VALUES (2,'p','q')"],
                'SELECT id, a, b FROM m ORDER BY id',
            ],
            'multicol UNIQUE distinct ok' => [
                ['CREATE TABLE m (id INTEGER PRIMARY KEY, a TEXT, b TEXT, UNIQUE(a,b))', "INSERT INTO m VALUES (1,'p','q')"],
                ["INSERT INTO m VALUES (2,'p','s')"],
                'SELECT id, a, b FROM m ORDER BY id',
            ],

            // two separate UNIQUE constraints on one table
            'two UNIQUE constraints, second violated' => [
                ['CREATE TABLE w (id INTEGER PRIMARY KEY, a TEXT UNIQUE, b TEXT UNIQUE)', "INSERT INTO w VALUES (1,'p','q')"],
                ["INSERT INTO w VALUES (2,'r','q')"],
                'SELECT id, a, b FROM w ORDER BY id',
            ],
        ];
        foreach ($cases as $name => $case) {
            yield $name => $case;
        }
    }

    /**
     * @param list<string> $setup
     * @param list<string> $ops
     */
    #[DataProvider('scenarios')]
    public function testMatchesSqlite(array $setup, array $ops, string $verify): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        $real = new RealPDO('sqlite::memory:');
        $real->setAttribute(RealPDO::ATTR_ERRMODE, RealPDO::ERRMODE_EXCEPTION);

        [$yState, $yErr] = $this->apply($yeti, $setup, $ops, $verify);
        [$rState, $rErr] = $this->apply($real, $setup, $ops, $verify);

        if ($rErr || $yErr) {
            self::assertTrue($rErr, 'YetiSQL errored but SQLite did not');
            self::assertTrue($yErr, 'SQLite errored but YetiSQL did not');
            return;
        }
        self::assertSame($rState, $yState);
    }

    /**
     * @param list<string> $setup
     * @param list<string> $ops
     * @return array{0:list<array<int,mixed>>,1:bool} [final rows, whether a statement errored]
     */
    private function apply(RealPDO|YetiPDO $db, array $setup, array $ops, string $verify): array
    {
        foreach ($setup as $sql) {
            $db->exec($sql);
        }
        $errored = false;
        try {
            foreach ($ops as $sql) {
                $db->exec($sql);
            }
        } catch (\Throwable) {
            $errored = true;
        }
        return [$db->query($verify)->fetchAll($db::FETCH_NUM), $errored];
    }

    public function testReplaceAndEnforcementSurviveReopen(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'yeti_conflict_') . '.ysql';
        @\unlink($path);
        try {
            $db = new YetiPDO('yetisql:' . $path);
            $db->exec('CREATE TABLE u (id INTEGER PRIMARY KEY, email TEXT UNIQUE, n TEXT)');
            $db->exec("INSERT INTO u VALUES (1,'a@x','ann'),(2,'b@x','bob')");
            $db = null;

            // Reopen: the auto-created unique index must reload and still enforce.
            $db = new YetiPDO('yetisql:' . $path);
            $threw = false;
            try {
                $db->exec("INSERT INTO u VALUES (3,'a@x','cy')");
            } catch (\Throwable) {
                $threw = true;
            }
            self::assertTrue($threw, 'reloaded unique index should reject a duplicate');

            $db->exec("REPLACE INTO u VALUES (9,'a@x','rep')");
            self::assertSame(
                [[2, 'b@x', 'bob'], [9, 'a@x', 'rep']],
                $db->query('SELECT id, email, n FROM u ORDER BY id')->fetchAll(YetiPDO::FETCH_NUM),
            );
        } finally {
            @\unlink($path);
            @\unlink($path . '-journal');
        }
    }
}
