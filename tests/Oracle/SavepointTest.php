<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Oracle;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Differential coverage for SAVEPOINT / RELEASE / ROLLBACK TO as real
 * sub-transactions: ROLLBACK TO undoes only the work since the savepoint and
 * keeps the transaction open, RELEASE folds changes into the enclosing scope,
 * nesting works, and a SAVEPOINT outside a transaction starts one implicitly.
 * Each scenario runs the same script against pdo_sqlite and YetiSQL and asserts
 * matching error-parity and final rows; a separate test covers file-backed
 * durability.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class SavepointTest extends TestCase
{
    /**
     * @return iterable<string,array{0:list<string>,1:string}>
     */
    public static function scenarios(): iterable
    {
        $cases = [
            'rollback to undoes inner only' => [
                [
                    'CREATE TABLE t(x INT)',
                    'BEGIN',
                    'INSERT INTO t VALUES (1)',
                    'SAVEPOINT s1',
                    'INSERT INTO t VALUES (2)',
                    'ROLLBACK TO s1',
                    'COMMIT',
                ],
                'SELECT x FROM t ORDER BY x',
            ],
            'release keeps changes' => [
                [
                    'CREATE TABLE t(x INT)',
                    'BEGIN',
                    'INSERT INTO t VALUES (1)',
                    'SAVEPOINT s1',
                    'INSERT INTO t VALUES (2)',
                    'RELEASE s1',
                    'COMMIT',
                ],
                'SELECT x FROM t ORDER BY x',
            ],
            'nested partial rollback' => [
                [
                    'CREATE TABLE t(x INT)',
                    'BEGIN',
                    'INSERT INTO t VALUES (1)',
                    'SAVEPOINT a',
                    'INSERT INTO t VALUES (2)',
                    'SAVEPOINT b',
                    'INSERT INTO t VALUES (3)',
                    'ROLLBACK TO b',
                    'INSERT INTO t VALUES (4)',
                    'ROLLBACK TO a',
                    'INSERT INTO t VALUES (5)',
                    'COMMIT',
                ],
                'SELECT x FROM t ORDER BY x',
            ],
            'implicit transaction released commits' => [
                [
                    'CREATE TABLE t(x INT)',
                    'SAVEPOINT s1',
                    'INSERT INTO t VALUES (1)',
                    'INSERT INTO t VALUES (2)',
                    'RELEASE s1',
                ],
                'SELECT x FROM t ORDER BY x',
            ],
            'implicit transaction rollback-to then release' => [
                [
                    'CREATE TABLE t(x INT)',
                    'SAVEPOINT s1',
                    'INSERT INTO t VALUES (1)',
                    'SAVEPOINT s2',
                    'INSERT INTO t VALUES (2)',
                    'ROLLBACK TO s2',
                    'INSERT INTO t VALUES (3)',
                    'RELEASE s1',
                ],
                'SELECT x FROM t ORDER BY x',
            ],
            'rollback to same savepoint twice' => [
                [
                    'CREATE TABLE t(x INT)',
                    'BEGIN',
                    'SAVEPOINT s',
                    'INSERT INTO t VALUES (1)',
                    'ROLLBACK TO s',
                    'INSERT INTO t VALUES (2)',
                    'ROLLBACK TO s',
                    'INSERT INTO t VALUES (3)',
                    'COMMIT',
                ],
                'SELECT x FROM t ORDER BY x',
            ],
            'case-insensitive savepoint name' => [
                [
                    'CREATE TABLE t(x INT)',
                    'BEGIN',
                    'SAVEPOINT Sp',
                    'INSERT INTO t VALUES (1)',
                    'ROLLBACK TO sP',
                    'COMMIT',
                ],
                'SELECT count(*) FROM t',
            ],
            'ddl inside savepoint rolled back' => [
                [
                    'BEGIN',
                    'CREATE TABLE t(x INT)',
                    'INSERT INTO t VALUES (1)',
                    'SAVEPOINT s',
                    'CREATE TABLE t2(y INT)',
                    'INSERT INTO t2 VALUES (9)',
                    'ROLLBACK TO s',
                    'COMMIT',
                ],
                // t2 was rolled back, so this errors on both engines (error-parity).
                'SELECT count(*) FROM t2',
            ],
            'page-splitting volume, rollback half' => [
                \array_merge(
                    ['CREATE TABLE t(x INT)', 'BEGIN'],
                    \array_map(static fn (int $i): string => "INSERT INTO t VALUES ($i)", \range(1, 60)),
                    ['SAVEPOINT s'],
                    \array_map(static fn (int $i): string => "INSERT INTO t VALUES ($i)", \range(61, 140)),
                    ['ROLLBACK TO s', 'COMMIT'],
                ),
                'SELECT count(*) AS c, coalesce(sum(x), 0) AS s FROM t',
            ],
            'rollback to unknown savepoint errors' => [
                [
                    'CREATE TABLE t(x INT)',
                    'BEGIN',
                    'INSERT INTO t VALUES (1)',
                    'ROLLBACK TO nope',
                ],
                'SELECT x FROM t',
            ],
            'release unknown savepoint errors' => [
                [
                    'CREATE TABLE t(x INT)',
                    'BEGIN',
                    'SAVEPOINT s',
                    'RELEASE nope',
                ],
                'SELECT count(*) FROM t',
            ],
            'release inner keeps outer open' => [
                [
                    'CREATE TABLE t(x INT)',
                    'BEGIN',
                    'SAVEPOINT a',
                    'INSERT INTO t VALUES (1)',
                    'SAVEPOINT b',
                    'INSERT INTO t VALUES (2)',
                    'RELEASE b',
                    'ROLLBACK TO a',
                    'COMMIT',
                ],
                'SELECT count(*) FROM t',
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

    public function testSavepointDurabilityAcrossReopen(): void
    {
        $file = \sys_get_temp_dir() . '/yetisql_sp_' . \getmypid() . '_' . \uniqid() . '.ysql';
        foreach (['', '-journal', '-wal'] as $suffix) {
            @\unlink($file . $suffix);
        }

        try {
            $db = new YetiPDO("yetisql:{$file}");
            $db->setAttribute(YetiPDO::ATTR_ERRMODE, YetiPDO::ERRMODE_EXCEPTION);
            $db->exec('CREATE TABLE t(x INT)');
            $db->exec('BEGIN');
            $db->exec('INSERT INTO t VALUES (1)');
            $db->exec('SAVEPOINT s');
            $db->exec('INSERT INTO t VALUES (2)');
            $db->exec('INSERT INTO t VALUES (3)');
            $db->exec('ROLLBACK TO s'); // drop 2, 3
            $db->exec('INSERT INTO t VALUES (4)');
            $db->exec('COMMIT'); // persist 1, 4
            unset($db);

            $reopened = new YetiPDO("yetisql:{$file}");
            $reopened->setAttribute(YetiPDO::ATTR_ERRMODE, YetiPDO::ERRMODE_EXCEPTION);
            self::assertSame(
                [[1], [4]],
                $reopened->query('SELECT x FROM t ORDER BY x')->fetchAll(YetiPDO::FETCH_NUM),
            );

            // A full ROLLBACK after savepoints must discard everything since BEGIN.
            $reopened->exec('BEGIN');
            $reopened->exec('INSERT INTO t VALUES (99)');
            $reopened->exec('SAVEPOINT z');
            $reopened->exec('INSERT INTO t VALUES (100)');
            $reopened->exec('ROLLBACK');
            self::assertSame(
                [[1], [4]],
                $reopened->query('SELECT x FROM t ORDER BY x')->fetchAll(YetiPDO::FETCH_NUM),
            );
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
