<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO;

/**
 * Behavioural compatibility fixes that are not pure differential cases:
 * bindParam() binds by reference (read at execute time), expression indexes are
 * rejected rather than silently ignored, and MATCH raises an unsupported error
 * instead of being mistaken for LIKE.
 */
final class StatementCompatTest extends TestCase
{
    public function testBindParamReadsVariableAtExecuteTime(): void
    {
        $db = new PDO('yetisql::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE t(x INT)');

        $stmt = $db->prepare('INSERT INTO t VALUES (?)');
        $value = 1;
        $stmt->bindParam(1, $value);
        $value = 42; // changed after bind, before execute
        $stmt->execute();

        self::assertSame([[42]], $db->query('SELECT x FROM t')->fetchAll(PDO::FETCH_NUM));
    }

    public function testBindParamRebindsAcrossExecutes(): void
    {
        $db = new PDO('yetisql::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE t(x INT)');

        $stmt = $db->prepare('INSERT INTO t VALUES (?)');
        $value = 0;
        $stmt->bindParam(1, $value);
        foreach ([10, 20, 30] as $value) {
            $stmt->execute();
        }

        self::assertSame([[10], [20], [30]], $db->query('SELECT x FROM t ORDER BY x')->fetchAll(PDO::FETCH_NUM));
    }

    public function testBindValueOverridesEarlierBindParam(): void
    {
        $db = new PDO('yetisql::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE t(x INT)');

        $stmt = $db->prepare('INSERT INTO t VALUES (?)');
        $value = 5;
        $stmt->bindParam(1, $value);
        $stmt->bindValue(1, 99); // a later bindValue on the same param wins
        $value = 123;
        $stmt->execute();

        self::assertSame([[99]], $db->query('SELECT x FROM t')->fetchAll(PDO::FETCH_NUM));
    }

    public function testExpressionIndexRejected(): void
    {
        $db = new PDO('yetisql::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE t(x TEXT)');

        $this->expectException(\Throwable::class);
        $db->exec('CREATE INDEX i ON t(lower(x))');
    }

    public function testPlainColumnIndexStillAllowed(): void
    {
        $db = new PDO('yetisql::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE t(x TEXT)');
        $db->exec('CREATE INDEX i ON t(x)'); // must not throw

        self::assertSame([[0]], $db->query('SELECT count(*) FROM t')->fetchAll(PDO::FETCH_NUM));
    }

    public function testMatchRaisesUnsupported(): void
    {
        $db = new PDO('yetisql::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE t(x TEXT)');
        $db->exec("INSERT INTO t VALUES ('hello')");

        $this->expectException(\Throwable::class);
        $db->query("SELECT x FROM t WHERE x MATCH 'hello'")->fetchAll();
    }
}
