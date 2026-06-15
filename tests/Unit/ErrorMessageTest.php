<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO;
use YetiDevWorks\YetiSQL\PDOException;

/**
 * Pins the exact failure-message wording (and SQLSTATE) YetiSQL emits for the
 * constraint and unsupported-feature paths, so the user-facing text does not
 * drift. The differential Oracle tests only check error-parity, not message text.
 */
final class ErrorMessageTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('yetisql::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $db;
    }

    public function testAnonymousCheckMessageUsesExpressionText(): void
    {
        $db = $this->db();
        $db->exec('CREATE TABLE t(x INT CHECK (x > 0))');

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('CHECK constraint failed: x > 0');
        $db->exec('INSERT INTO t VALUES (-1)');
    }

    public function testNamedCheckMessageUsesConstraintName(): void
    {
        $db = $this->db();
        $db->exec('CREATE TABLE t(a INT, b INT, CONSTRAINT ck CHECK (a < b))');

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('CHECK constraint failed: ck');
        $db->exec('INSERT INTO t VALUES (3, 2)');
    }

    public function testCheckSetsIntegrityConstraintSqlstate(): void
    {
        $db = $this->db();
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $db->exec('CREATE TABLE t(x INT CHECK (x > 0))');
        $db->exec('INSERT INTO t VALUES (-1)');

        $info = $db->errorInfo();
        self::assertSame('23000', $info[0]);
        self::assertStringContainsString('CHECK constraint failed', (string) $info[2]);
    }

    public function testPartialUniqueMessageNamesTableAndColumn(): void
    {
        $db = $this->db();
        $db->exec('CREATE TABLE t(id INTEGER PRIMARY KEY, k INT, active INT)');
        $db->exec('CREATE UNIQUE INDEX u ON t(k) WHERE active = 1');
        $db->exec('INSERT INTO t VALUES (1, 5, 1)');

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('UNIQUE constraint failed: t.k');
        $db->exec('INSERT INTO t VALUES (2, 5, 1)');
    }

    public function testExpressionIndexMessage(): void
    {
        $db = $this->db();
        $db->exec('CREATE TABLE t(x TEXT)');

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('expression indexes are not supported');
        $db->exec('CREATE INDEX i ON t(lower(x))');
    }

    public function testMatchUnsupportedMessage(): void
    {
        $db = $this->db();
        $db->exec('CREATE TABLE t(x TEXT)');
        $db->exec("INSERT INTO t VALUES ('hello')");

        // Like pdo_sqlite, MATCH fails as a runtime error when a row is evaluated,
        // surfaced as a PDOException under ERRMODE_EXCEPTION.
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('unable to use function MATCH in the requested context');
        $db->query("SELECT x FROM t WHERE x MATCH 'hello'")->fetchAll();
    }

    public function testMatchOnEmptyTableDoesNotError(): void
    {
        $db = $this->db();
        $db->exec('CREATE TABLE t(x TEXT)'); // no rows

        // No row is evaluated, so (as in SQLite) the unsupported MATCH never fires.
        self::assertSame([], $db->query("SELECT x FROM t WHERE x MATCH 'hello'")->fetchAll(PDO::FETCH_NUM));
    }

    public function testUnknownSavepointRollbackMessage(): void
    {
        $db = $this->db();
        $db->exec('BEGIN');

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('no such savepoint: nope');
        $db->exec('ROLLBACK TO nope');
    }

    public function testUnknownSavepointReleaseMessage(): void
    {
        $db = $this->db();
        $db->exec('BEGIN');
        $db->exec('SAVEPOINT s');

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('no such savepoint: nope');
        $db->exec('RELEASE nope');
    }
}
