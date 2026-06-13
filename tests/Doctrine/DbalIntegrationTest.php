<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\Doctrine\Driver;

/**
 * Drives YetiSQL through the real Doctrine DBAL stack (DriverManager,
 * QueryBuilder, schema manager, transactions) — the v1 acceptance gate. These
 * exercise the same machinery DBAL's own functional suite uses.
 */
final class DbalIntegrationTest extends TestCase
{
    private function conn(): Connection
    {
        return DriverManager::getConnection([
            'driverClass' => Driver::class,
            'memory' => true,
        ]);
    }

    private function seed(Connection $c): void
    {
        $c->executeStatement('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT,
            age INTEGER
        )');
        $c->executeStatement('CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            title TEXT
        )');
    }

    public function testConnectAndServerVersion(): void
    {
        $c = $this->conn();
        self::assertSame('yetisql', $c->getDriver() instanceof Driver ? 'yetisql' : 'x');
        self::assertTrue($c->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform);
    }

    public function testInsertViaQueryBuilderAndSelect(): void
    {
        $c = $this->conn();
        $this->seed($c);

        $c->insert('users', ['name' => 'Alice', 'email' => 'a@x.io', 'age' => 30]);
        $c->insert('users', ['name' => 'Bob', 'email' => 'b@x.io', 'age' => 25]);
        self::assertSame('2', (string) $c->lastInsertId());

        $qb = $c->createQueryBuilder();
        $qb->select('id', 'name', 'age')
            ->from('users')
            ->where('age >= :min')
            ->orderBy('age', 'DESC')
            ->setParameter('min', 26, ParameterType::INTEGER);

        $rows = $qb->executeQuery()->fetchAllAssociative();
        self::assertCount(1, $rows);
        self::assertSame('Alice', $rows[0]['name']);
        self::assertSame(30, (int) $rows[0]['age']);
    }

    public function testPreparedPositionalAndNamedParams(): void
    {
        $c = $this->conn();
        $this->seed($c);

        $stmt = $c->prepare('INSERT INTO users (name, age) VALUES (?, ?)');
        $stmt->bindValue(1, 'Carol');
        $stmt->bindValue(2, 35, ParameterType::INTEGER);
        $stmt->executeStatement();

        $found = $c->fetchAssociative('SELECT name, age FROM users WHERE name = :n', ['n' => 'Carol']);
        self::assertSame('Carol', $found['name']);
        self::assertSame(35, (int) $found['age']);
    }

    public function testUpdateAndDelete(): void
    {
        $c = $this->conn();
        $this->seed($c);
        $c->insert('users', ['name' => 'Dave', 'age' => 40]);
        $c->insert('users', ['name' => 'Eve', 'age' => 22]);

        $affected = $c->update('users', ['age' => 41], ['name' => 'Dave']);
        self::assertSame(1, $affected);
        self::assertSame(41, (int) $c->fetchOne('SELECT age FROM users WHERE name = ?', ['Dave']));

        $deleted = $c->delete('users', ['name' => 'Eve']);
        self::assertSame(1, $deleted);
        self::assertSame(1, (int) $c->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function testJoinThroughQueryBuilder(): void
    {
        $c = $this->conn();
        $this->seed($c);
        $c->insert('users', ['name' => 'Alice', 'age' => 30]);
        $uid = (int) $c->lastInsertId();
        $c->insert('posts', ['user_id' => $uid, 'title' => 'Hello']);
        $c->insert('posts', ['user_id' => $uid, 'title' => 'World']);

        $rows = $c->createQueryBuilder()
            ->select('u.name', 'p.title')
            ->from('users', 'u')
            ->innerJoin('u', 'posts', 'p', 'p.user_id = u.id')
            ->orderBy('p.title')
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertCount(2, $rows);
        self::assertSame('Alice', $rows[0]['name']);
        self::assertSame('Hello', $rows[0]['title']);
    }

    public function testTransactionCommitAndRollback(): void
    {
        $c = $this->conn();
        $this->seed($c);

        $c->beginTransaction();
        $c->insert('users', ['name' => 'Temp', 'age' => 1]);
        $c->rollBack();
        self::assertSame(0, (int) $c->fetchOne('SELECT COUNT(*) FROM users'));

        $c->beginTransaction();
        $c->insert('users', ['name' => 'Keep', 'age' => 2]);
        $c->commit();
        self::assertSame(1, (int) $c->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function testSchemaManagerListsTablesAndColumns(): void
    {
        $c = $this->conn();
        $this->seed($c);

        $sm = $c->createSchemaManager();
        $tables = $sm->listTableNames();
        \sort($tables);
        self::assertSame(['posts', 'users'], $tables);

        $columns = $sm->listTableColumns('users');
        $names = \array_keys($columns);
        self::assertContains('id', $names);
        self::assertContains('name', $names);
        self::assertContains('email', $names);
        self::assertContains('age', $names);
    }
}
