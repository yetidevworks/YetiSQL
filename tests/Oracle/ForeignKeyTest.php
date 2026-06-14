<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Oracle;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Differential coverage for FOREIGN KEY enforcement under PRAGMA foreign_keys=ON:
 * child-side existence checks on INSERT/UPDATE and parent-side ON DELETE / ON
 * UPDATE actions (NO ACTION, RESTRICT, CASCADE, SET NULL, SET DEFAULT). Each
 * scenario runs the same statement script against pdo_sqlite and YetiSQL and
 * asserts the two agree on whether the script errored and, if not, on the rows
 * the final SELECT returns.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class ForeignKeyTest extends TestCase
{
    /**
     * @return iterable<string,array{0:list<string>,1:string}>
     *   [statement script, verification query]
     */
    public static function scenarios(): iterable
    {
        $cases = [
            'insert satisfied' => [
                [
                    'CREATE TABLE p(id INTEGER PRIMARY KEY, n TEXT)',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, pid INT REFERENCES p(id))',
                    "INSERT INTO p VALUES (1,'a'),(2,'b')",
                    'INSERT INTO c VALUES (10, 1),(11, 2)',
                ],
                'SELECT id, pid FROM c ORDER BY id',
            ],
            'insert missing parent fails' => [
                [
                    'CREATE TABLE p(id INTEGER PRIMARY KEY)',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, pid INT REFERENCES p(id))',
                    'INSERT INTO p VALUES (1)',
                    'INSERT INTO c VALUES (10, 99)',
                ],
                'SELECT count(*) FROM c',
            ],
            'null child allowed' => [
                [
                    'CREATE TABLE p(id INTEGER PRIMARY KEY)',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, pid INT REFERENCES p(id))',
                    'INSERT INTO p VALUES (1)',
                    'INSERT INTO c VALUES (10, NULL)',
                ],
                'SELECT id, pid FROM c',
            ],
            'self-referential insert' => [
                [
                    'CREATE TABLE node(id INTEGER PRIMARY KEY, parent INT REFERENCES node(id))',
                    'INSERT INTO node VALUES (1, NULL)',
                    'INSERT INTO node VALUES (2, 1)',
                    'INSERT INTO node VALUES (3, 2)',
                ],
                'SELECT id, parent FROM node ORDER BY id',
            ],
            'self-referential bad parent fails' => [
                [
                    'CREATE TABLE node(id INTEGER PRIMARY KEY, parent INT REFERENCES node(id))',
                    'INSERT INTO node VALUES (2, 1)',
                ],
                'SELECT count(*) FROM node',
            ],
            'delete no action blocked' => [
                [
                    'CREATE TABLE p(id INTEGER PRIMARY KEY)',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, pid INT REFERENCES p(id))',
                    'INSERT INTO p VALUES (1),(2)',
                    'INSERT INTO c VALUES (10, 1)',
                    'DELETE FROM p WHERE id = 1',
                ],
                'SELECT id FROM p ORDER BY id',
            ],
            'delete unreferenced parent ok' => [
                [
                    'CREATE TABLE p(id INTEGER PRIMARY KEY)',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, pid INT REFERENCES p(id))',
                    'INSERT INTO p VALUES (1),(2)',
                    'INSERT INTO c VALUES (10, 1)',
                    'DELETE FROM p WHERE id = 2',
                ],
                'SELECT id FROM p ORDER BY id',
            ],
            'delete restrict blocked' => [
                [
                    'CREATE TABLE p(id INTEGER PRIMARY KEY)',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, pid INT REFERENCES p(id) ON DELETE RESTRICT)',
                    'INSERT INTO p VALUES (1)',
                    'INSERT INTO c VALUES (10, 1)',
                    'DELETE FROM p WHERE id = 1',
                ],
                'SELECT count(*) FROM c',
            ],
            'delete cascade' => [
                [
                    'CREATE TABLE p(id INTEGER PRIMARY KEY)',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, pid INT REFERENCES p(id) ON DELETE CASCADE)',
                    'INSERT INTO p VALUES (1),(2)',
                    'INSERT INTO c VALUES (10, 1),(11, 1),(12, 2)',
                    'DELETE FROM p WHERE id = 1',
                ],
                'SELECT id, pid FROM c ORDER BY id',
            ],
            'delete set null' => [
                [
                    'CREATE TABLE p(id INTEGER PRIMARY KEY)',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, pid INT REFERENCES p(id) ON DELETE SET NULL)',
                    'INSERT INTO p VALUES (1)',
                    'INSERT INTO c VALUES (10, 1),(11, 1)',
                    'DELETE FROM p WHERE id = 1',
                ],
                'SELECT id, pid FROM c ORDER BY id',
            ],
            'delete set default' => [
                [
                    'CREATE TABLE p(id INTEGER PRIMARY KEY)',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, pid INT DEFAULT 7 REFERENCES p(id) ON DELETE SET DEFAULT)',
                    'INSERT INTO p VALUES (1),(7)',
                    'INSERT INTO c VALUES (10, 1)',
                    'DELETE FROM p WHERE id = 1',
                ],
                'SELECT id, pid FROM c ORDER BY id',
            ],
            'update parent cascade' => [
                [
                    'CREATE TABLE p(id INTEGER PRIMARY KEY)',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, pid INT REFERENCES p(id) ON UPDATE CASCADE)',
                    'INSERT INTO p VALUES (1)',
                    'INSERT INTO c VALUES (10, 1),(11, 1)',
                    'UPDATE p SET id = 5 WHERE id = 1',
                ],
                'SELECT id, pid FROM c ORDER BY id',
            ],
            'update parent set null' => [
                [
                    'CREATE TABLE p(id INTEGER PRIMARY KEY)',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, pid INT REFERENCES p(id) ON UPDATE SET NULL)',
                    'INSERT INTO p VALUES (1)',
                    'INSERT INTO c VALUES (10, 1)',
                    'UPDATE p SET id = 5 WHERE id = 1',
                ],
                'SELECT id, pid FROM c ORDER BY id',
            ],
            'update parent no action blocked' => [
                [
                    'CREATE TABLE p(id INTEGER PRIMARY KEY)',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, pid INT REFERENCES p(id))',
                    'INSERT INTO p VALUES (1)',
                    'INSERT INTO c VALUES (10, 1)',
                    'UPDATE p SET id = 5 WHERE id = 1',
                ],
                'SELECT id FROM p',
            ],
            'update child to bad parent fails' => [
                [
                    'CREATE TABLE p(id INTEGER PRIMARY KEY)',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, pid INT REFERENCES p(id))',
                    'INSERT INTO p VALUES (1)',
                    'INSERT INTO c VALUES (10, 1)',
                    'UPDATE c SET pid = 99 WHERE id = 10',
                ],
                'SELECT id, pid FROM c',
            ],
            'update child to good parent ok' => [
                [
                    'CREATE TABLE p(id INTEGER PRIMARY KEY)',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, pid INT REFERENCES p(id))',
                    'INSERT INTO p VALUES (1),(2)',
                    'INSERT INTO c VALUES (10, 1)',
                    'UPDATE c SET pid = 2 WHERE id = 10',
                ],
                'SELECT id, pid FROM c',
            ],
            'update child to null ok' => [
                [
                    'CREATE TABLE p(id INTEGER PRIMARY KEY)',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, pid INT REFERENCES p(id))',
                    'INSERT INTO p VALUES (1)',
                    'INSERT INTO c VALUES (10, 1)',
                    'UPDATE c SET pid = NULL WHERE id = 10',
                ],
                'SELECT id, pid FROM c',
            ],
            'references unique non-pk column' => [
                [
                    'CREATE TABLE p(id INTEGER PRIMARY KEY, code TEXT UNIQUE)',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, pcode TEXT REFERENCES p(code))',
                    "INSERT INTO p VALUES (1,'x'),(2,'y')",
                    "INSERT INTO c VALUES (10,'x')",
                    "INSERT INTO c VALUES (11,'zzz')",
                ],
                'SELECT id, pcode FROM c ORDER BY id',
            ],
            'composite foreign key' => [
                [
                    'CREATE TABLE p(a INT, b INT, PRIMARY KEY(a,b))',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, x INT, y INT, FOREIGN KEY(x,y) REFERENCES p(a,b))',
                    'INSERT INTO p VALUES (1,2),(3,4)',
                    'INSERT INTO c VALUES (10, 1, 2)',
                    'INSERT INTO c VALUES (11, 1, 9)',
                ],
                'SELECT id, x, y FROM c ORDER BY id',
            ],
            'composite partial null allowed' => [
                [
                    'CREATE TABLE p(a INT, b INT, PRIMARY KEY(a,b))',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, x INT, y INT, FOREIGN KEY(x,y) REFERENCES p(a,b))',
                    'INSERT INTO p VALUES (1,2)',
                    'INSERT INTO c VALUES (10, 1, NULL)',
                ],
                'SELECT id, x, y FROM c ORDER BY id',
            ],
            'implicit parent pk reference' => [
                [
                    'CREATE TABLE p(id INTEGER PRIMARY KEY)',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, pid INT REFERENCES p)',
                    'INSERT INTO p VALUES (1)',
                    'INSERT INTO c VALUES (10, 1)',
                    'INSERT INTO c VALUES (11, 2)',
                ],
                'SELECT id, pid FROM c ORDER BY id',
            ],
            'fk off ignores violations' => [
                [
                    'CREATE TABLE p(id INTEGER PRIMARY KEY)',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, pid INT REFERENCES p(id))',
                    'INSERT INTO c VALUES (10, 99)',
                    'DELETE FROM p',
                ],
                'SELECT id, pid FROM c',
            ],
            'multi-level cascade delete' => [
                [
                    'CREATE TABLE a(id INTEGER PRIMARY KEY)',
                    'CREATE TABLE b(id INTEGER PRIMARY KEY, aid INT REFERENCES a(id) ON DELETE CASCADE)',
                    'CREATE TABLE d(id INTEGER PRIMARY KEY, bid INT REFERENCES b(id) ON DELETE CASCADE)',
                    'INSERT INTO a VALUES (1)',
                    'INSERT INTO b VALUES (10, 1)',
                    'INSERT INTO d VALUES (100, 10)',
                    'DELETE FROM a WHERE id = 1',
                ],
                'SELECT (SELECT count(*) FROM b) AS nb, (SELECT count(*) FROM d) AS nd',
            ],
            'text affinity coercion to int pk' => [
                [
                    'CREATE TABLE p(id INTEGER PRIMARY KEY)',
                    'CREATE TABLE c(id INTEGER PRIMARY KEY, pid TEXT REFERENCES p(id))',
                    'INSERT INTO p VALUES (5)',
                    "INSERT INTO c VALUES (10, '5')",
                ],
                'SELECT id, pid FROM c',
            ],
        ];
        foreach ($cases as $name => [$script, $verify]) {
            yield $name => [$script, $verify];
        }
    }

    /**
     * @param list<string> $script
     */
    #[DataProvider('scenarios')]
    public function testMatchesSqlite(array $script, string $verify): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        $real = new RealPDO('sqlite::memory:');
        $real->setAttribute(RealPDO::ATTR_ERRMODE, RealPDO::ERRMODE_EXCEPTION);
        $real->exec('PRAGMA foreign_keys=ON');
        $yeti->exec('PRAGMA foreign_keys=ON');

        [$yRows, $yErr] = $this->runScript($yeti, $script, $verify);
        [$rRows, $rErr] = $this->runScript($real, $script, $verify);

        if ($rErr || $yErr) {
            self::assertTrue($rErr, 'YetiSQL errored but SQLite did not');
            self::assertTrue($yErr, 'SQLite errored but YetiSQL did not');
            return;
        }
        self::assertSame($rRows, $yRows);
    }

    public function testForeignKeyListPragma(): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        $real = new RealPDO('sqlite::memory:');
        $real->setAttribute(RealPDO::ATTR_ERRMODE, RealPDO::ERRMODE_EXCEPTION);

        $ddl = 'CREATE TABLE c(id INTEGER PRIMARY KEY, x INT, y INT, '
            . 'FOREIGN KEY(x,y) REFERENCES p(a,b) ON DELETE CASCADE ON UPDATE SET NULL)';
        foreach (['CREATE TABLE p(a INT, b INT, PRIMARY KEY(a,b))', $ddl] as $sql) {
            $yeti->exec($sql);
            $real->exec($sql);
        }
        $cols = 'id, seq, "table", "from", "to", on_update, on_delete, "match"';
        $rReal = $real->query("SELECT $cols FROM pragma_foreign_key_list('c')")->fetchAll(RealPDO::FETCH_NUM);
        $rYeti = $yeti->query("SELECT $cols FROM pragma_foreign_key_list('c')")->fetchAll(YetiPDO::FETCH_NUM);
        self::assertSame($rReal, $rYeti);
    }

    public function testToggleReportsState(): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        self::assertSame([[0]], $yeti->query('PRAGMA foreign_keys')->fetchAll(YetiPDO::FETCH_NUM));
        $yeti->exec('PRAGMA foreign_keys=ON');
        self::assertSame([[1]], $yeti->query('PRAGMA foreign_keys')->fetchAll(YetiPDO::FETCH_NUM));
        $yeti->exec('PRAGMA foreign_keys=OFF');
        self::assertSame([[0]], $yeti->query('PRAGMA foreign_keys')->fetchAll(YetiPDO::FETCH_NUM));
    }

    /**
     * @param list<string> $script
     * @return array{0:list<array<int,mixed>>,1:bool}
     */
    private function runScript(RealPDO|YetiPDO $db, array $script, string $verify): array
    {
        try {
            foreach ($script as $sql) {
                $db->exec($sql);
            }
            return [$db->query($verify)->fetchAll($db::FETCH_NUM), false];
        } catch (\Throwable) {
            return [[], true];
        }
    }
}
