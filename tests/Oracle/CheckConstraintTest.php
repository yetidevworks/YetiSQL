<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Oracle;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Differential coverage for CHECK constraint enforcement (column- and
 * table-level, named and anonymous) on INSERT/UPDATE. Each scenario runs the
 * same statement script against pdo_sqlite and YetiSQL and asserts they agree on
 * whether the script errored and, if not, on the rows the verification query
 * returns. A separate test confirms a CHECK survives closing and reopening a
 * file-backed database.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class CheckConstraintTest extends TestCase
{
    /**
     * @return iterable<string,array{0:list<string>,1:string}>
     */
    public static function scenarios(): iterable
    {
        $cases = [
            'column check rejects' => [
                ['CREATE TABLE t(x INT CHECK (x > 0))', 'INSERT INTO t VALUES (-1)'],
                'SELECT count(*) FROM t',
            ],
            'column check passes' => [
                ['CREATE TABLE t(x INT CHECK (x > 0))', 'INSERT INTO t VALUES (5)'],
                'SELECT x FROM t',
            ],
            'null satisfies check' => [
                ['CREATE TABLE t(x INT CHECK (x > 0))', 'INSERT INTO t VALUES (NULL)'],
                'SELECT x FROM t',
            ],
            'table check rejects' => [
                ['CREATE TABLE t(a INT, b INT, CHECK (a < b))', 'INSERT INTO t VALUES (3, 2)'],
                'SELECT count(*) FROM t',
            ],
            'table check passes' => [
                ['CREATE TABLE t(a INT, b INT, CHECK (a < b))', 'INSERT INTO t VALUES (1, 2)'],
                'SELECT a, b FROM t',
            ],
            'named constraint rejects' => [
                ['CREATE TABLE t(a INT, b INT, CONSTRAINT ck CHECK (a < b))', 'INSERT INTO t VALUES (3, 2)'],
                'SELECT count(*) FROM t',
            ],
            'or ignore skips violation' => [
                ['CREATE TABLE t(x INT CHECK (x > 0))', 'INSERT OR IGNORE INTO t VALUES (-1)', 'INSERT INTO t VALUES (4)'],
                'SELECT x FROM t ORDER BY x',
            ],
            'update violation rejected, row unchanged' => [
                [
                    'CREATE TABLE t(x INT CHECK (x > 0))',
                    'INSERT INTO t VALUES (5)',
                    'UPDATE t SET x = -3 WHERE x = 5',
                ],
                'SELECT x FROM t',
            ],
            'update or ignore skips' => [
                [
                    'CREATE TABLE t(id INTEGER PRIMARY KEY, x INT CHECK (x > 0))',
                    'INSERT INTO t VALUES (1, 5), (2, 6)',
                    'UPDATE OR IGNORE t SET x = -1',
                ],
                'SELECT id, x FROM t ORDER BY id',
            ],
            'multiple checks on one column' => [
                [
                    'CREATE TABLE t(x INT CHECK (x > 0) CHECK (x < 100))',
                    'INSERT INTO t VALUES (50)',
                    'INSERT INTO t VALUES (200)',
                ],
                'SELECT x FROM t',
            ],
            'check across columns on update' => [
                [
                    'CREATE TABLE t(id INTEGER PRIMARY KEY, a INT, b INT, CHECK (a <= b))',
                    'INSERT INTO t VALUES (1, 2, 5)',
                    'UPDATE t SET a = 9 WHERE id = 1',
                ],
                'SELECT a, b FROM t',
            ],
            'check with expression and string' => [
                [
                    "CREATE TABLE t(name TEXT CHECK (length(name) >= 2))",
                    "INSERT INTO t VALUES ('ok')",
                    "INSERT INTO t VALUES ('x')",
                ],
                'SELECT name FROM t',
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

        [$yRows, $yErr] = $this->runScript($yeti, $script, $verify);
        [$rRows, $rErr] = $this->runScript($real, $script, $verify);

        if ($rErr || $yErr) {
            self::assertTrue($rErr, 'YetiSQL errored but SQLite did not');
            self::assertTrue($yErr, 'SQLite errored but YetiSQL did not');
            return;
        }
        self::assertSame($rRows, $yRows);
    }

    public function testCheckSurvivesReload(): void
    {
        $file = \sys_get_temp_dir() . '/yetisql_check_' . \getmypid() . '_' . \uniqid() . '.ysql';
        foreach (['', '-journal', '-wal'] as $suffix) {
            @\unlink($file . $suffix);
        }

        try {
            $db = new YetiPDO("yetisql:{$file}");
            $db->setAttribute(YetiPDO::ATTR_ERRMODE, YetiPDO::ERRMODE_EXCEPTION);
            $db->exec('CREATE TABLE t(x INT CHECK (x > 0))');
            $db->exec('INSERT INTO t VALUES (5)');
            unset($db);

            $reopened = new YetiPDO("yetisql:{$file}");
            $reopened->setAttribute(YetiPDO::ATTR_ERRMODE, YetiPDO::ERRMODE_EXCEPTION);
            $threw = false;
            try {
                $reopened->exec('INSERT INTO t VALUES (-9)');
            } catch (\Throwable) {
                $threw = true;
            }
            self::assertTrue($threw, 'CHECK constraint should still be enforced after reopening');
            self::assertSame([[5]], $reopened->query('SELECT x FROM t')->fetchAll(YetiPDO::FETCH_NUM));
        } finally {
            foreach (['', '-journal', '-wal'] as $suffix) {
                @\unlink($file . $suffix);
            }
        }
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
