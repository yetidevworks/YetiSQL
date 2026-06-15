<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Oracle;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Differential coverage for schema introspection and ALTER round-tripping that
 * ORMs (notably Laravel's SQLite table-rebuild) depend on: PRAGMA table_info
 * reporting the verbatim DEFAULT text and the primary-key flag, and
 * ALTER TABLE ADD/DROP COLUMN preserving FOREIGN KEY / CHECK / generated-column
 * constraints. Regression guard for bugs found wiring YetiSQL into Koel.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class SchemaRoundTripTest extends TestCase
{
    private YetiPDO $yeti;
    private RealPDO $real;

    protected function setUp(): void
    {
        $this->yeti = new YetiPDO('yetisql::memory:');
        $this->real = new RealPDO('sqlite::memory:');
        foreach ([$this->yeti, $this->real] as $db) {
            $db->setAttribute($db::ATTR_ERRMODE, $db::ERRMODE_EXCEPTION);
        }
    }

    public function testTableInfoReportsVerbatimDefaultText(): void
    {
        $ddl = "CREATE TABLE t(
            a TEXT DEFAULT '', b TEXT DEFAULT 'hi', c TEXT DEFAULT 'it''s',
            d INT DEFAULT 5, e REAL DEFAULT 1.5, f INT DEFAULT -3,
            g TEXT DEFAULT NULL, h DATETIME DEFAULT CURRENT_TIMESTAMP,
            i INT DEFAULT (1 + 2), j TEXT DEFAULT (lower('X')), k BLOB DEFAULT x'41')";
        $this->bothExec($ddl);

        $query = "SELECT name, dflt_value FROM pragma_table_info('t') ORDER BY cid";
        self::assertSame(
            $this->real->query($query)->fetchAll(RealPDO::FETCH_NUM),
            $this->yeti->query($query)->fetchAll(YetiPDO::FETCH_NUM),
        );
    }

    public function testTableInfoReportsTableLevelPrimaryKey(): void
    {
        // A non-integer table-level PRIMARY KEY must still report pk=1, the way
        // an ORM detects it when rebuilding the table.
        $this->bothExec('CREATE TABLE t(id varchar NOT NULL, name TEXT, PRIMARY KEY(id))');

        $query = "SELECT name, pk FROM pragma_table_info('t') ORDER BY cid";
        self::assertSame(
            $this->real->query($query)->fetchAll(RealPDO::FETCH_NUM),
            $this->yeti->query($query)->fetchAll(YetiPDO::FETCH_NUM),
        );
    }

    public function testAddColumnPreservesForeignKey(): void
    {
        foreach ([
            'CREATE TABLE s(id varchar PRIMARY KEY)',
            'CREATE TABLE t(id varchar NOT NULL, sid varchar NOT NULL, foreign key(sid) references s(id) on delete cascade on update cascade, primary key(id))',
            'ALTER TABLE t ADD COLUMN extra integer',
        ] as $sql) {
            $this->bothExec($sql);
        }

        $query = "SELECT \"table\", \"from\", \"to\", on_delete, on_update FROM pragma_foreign_key_list('t')";
        self::assertSame(
            $this->real->query($query)->fetchAll(RealPDO::FETCH_NUM),
            $this->yeti->query($query)->fetchAll(YetiPDO::FETCH_NUM),
        );
    }

    public function testForeignKeyCascadesAfterAddColumn(): void
    {
        foreach ([
            'PRAGMA foreign_keys = ON',
            'CREATE TABLE s(id varchar PRIMARY KEY)',
            'CREATE TABLE t(id varchar PRIMARY KEY, sid varchar NOT NULL REFERENCES s(id) ON DELETE CASCADE)',
            'ALTER TABLE t ADD COLUMN extra integer',
            "INSERT INTO s VALUES ('s1'), ('s2')",
            "INSERT INTO t(id, sid) VALUES ('a', 's1'), ('b', 's1'), ('c', 's2')",
            "DELETE FROM s WHERE id = 's1'",
        ] as $sql) {
            $this->bothExec($sql);
        }

        $query = 'SELECT id, sid FROM t ORDER BY id';
        self::assertSame(
            $this->real->query($query)->fetchAll(RealPDO::FETCH_NUM),
            $this->yeti->query($query)->fetchAll(YetiPDO::FETCH_NUM),
        );
    }

    public function testAddColumnPreservesCheckConstraint(): void
    {
        foreach ([
            'CREATE TABLE t(id INTEGER PRIMARY KEY, n INT CHECK (n > 0))',
            'ALTER TABLE t ADD COLUMN extra integer',
        ] as $sql) {
            $this->bothExec($sql);
        }

        // The CHECK must still reject a violating row after the column was added.
        $rErr = $this->execFails($this->real, 'INSERT INTO t(id, n) VALUES (1, -1)');
        $yErr = $this->execFails($this->yeti, 'INSERT INTO t(id, n) VALUES (1, -1)');
        self::assertTrue($rErr, 'sqlite should reject');
        self::assertSame($rErr, $yErr);
    }

    public function testCompositeUniqueIndexEqualityDeleteAndUpdate(): void
    {
        // Exercises the multi-column index equality plan through the DELETE /
        // UPDATE (scanRowids) path — the site of the "Undefined array key
        // values" crash found via Koel's role-assignment pivot inserts.
        foreach ([
            'CREATE TABLE roles(id INTEGER PRIMARY KEY, name TEXT, guard TEXT)',
            'CREATE UNIQUE INDEX r_ng ON roles(name, guard)',
            "INSERT INTO roles VALUES (1,'admin','web'),(2,'user','web'),(3,'admin','api')",
            "DELETE FROM roles WHERE name='admin' AND guard='web'",
            "UPDATE roles SET guard='X' WHERE name='user' AND guard='web'",
        ] as $sql) {
            $this->bothExec($sql);
        }

        $query = 'SELECT id, name, guard FROM roles ORDER BY id';
        self::assertSame(
            $this->real->query($query)->fetchAll(RealPDO::FETCH_NUM),
            $this->yeti->query($query)->fetchAll(YetiPDO::FETCH_NUM),
        );
    }

    public function testBooleanLiterals(): void
    {
        $query = 'SELECT true, false, TRUE AND FALSE, (CASE WHEN 1 IS NULL THEN false ELSE true END), 5 > 3';
        self::assertSame(
            $this->real->query($query)->fetch(RealPDO::FETCH_NUM),
            $this->yeti->query($query)->fetch(YetiPDO::FETCH_NUM),
        );
    }

    private function bothExec(string $sql): void
    {
        $this->real->exec($sql);
        $this->yeti->exec($sql);
    }

    private function execFails(RealPDO|YetiPDO $db, string $sql): bool
    {
        try {
            $db->exec($sql);
            return false;
        } catch (\Throwable) {
            return true;
        }
    }
}
