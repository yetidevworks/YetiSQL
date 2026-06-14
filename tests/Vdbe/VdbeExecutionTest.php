<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Vdbe;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Runs single-table SELECTs through the VDBE VM (PRAGMA vdbe=on) and checks the
 * bytecode path produces the same results as pdo_sqlite. Covers filtering,
 * arithmetic/comparison/logical operators, NULL handling, projection of
 * expressions, rowid access, CAST, and literal LIMIT/OFFSET — the subset the
 * compiler accepts. Queries it can't compile transparently fall back, so the
 * results still match.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class VdbeExecutionTest extends TestCase
{
    private const SETUP = [
        'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT, age INTEGER, score REAL, nick TEXT)',
        "INSERT INTO t VALUES (1,'A',30,1.5,'aa'),(2,'B',40,2.0,NULL),(3,'C',25,3.5,'cc'),"
            . "(4,'D',55,4.0,NULL),(5,'E',40,5.5,'ee')",
    ];

    /** @return iterable<string,array{0:string}> */
    public static function queries(): iterable
    {
        $cases = [
            'SELECT id, name, age FROM t WHERE age > 30',
            'SELECT * FROM t WHERE age >= 30 AND age <= 50',
            'SELECT id FROM t WHERE name = \'B\' OR age < 28',
            'SELECT id, age + 1, age * 2 FROM t WHERE id <> 2',
            'SELECT id, age - 10, age % 7 FROM t',
            'SELECT id FROM t WHERE NOT (age > 40)',
            'SELECT id, nick FROM t WHERE nick IS NULL',
            'SELECT id, nick FROM t WHERE nick IS NOT NULL',
            'SELECT id FROM t WHERE nick = \'aa\'',
            'SELECT id, name FROM t WHERE age <> 40 AND id >= 2',
            'SELECT id, score FROM t WHERE score > 2.5',
            'SELECT id, age FROM t WHERE id = 3',
            'SELECT id FROM t WHERE rowid = 4',
            'SELECT id, CAST(score AS INTEGER) FROM t',
            'SELECT id, age FROM t WHERE age > 20 LIMIT 3',
            'SELECT id, age FROM t WHERE age > 20 LIMIT 2 OFFSET 1',
            'SELECT id, -age, age FROM t WHERE age IS NOT NULL',
            'SELECT id, name || \'!\' FROM t WHERE id <= 2',
            'SELECT id FROM t WHERE age = 40 AND nick IS NULL',
            'SELECT id, age / 0 FROM t WHERE id = 1',
        ];
        foreach ($cases as $sql) {
            yield $sql => [$sql];
        }
    }

    #[DataProvider('queries')]
    public function testVdbeMatchesSqlite(string $sql): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        $real = new RealPDO('sqlite::memory:');
        $real->setAttribute(RealPDO::ATTR_ERRMODE, RealPDO::ERRMODE_EXCEPTION);
        foreach (self::SETUP as $stmt) {
            $yeti->exec($stmt);
            $real->exec($stmt);
        }
        $yeti->exec('PRAGMA vdbe=on');

        self::assertSame(
            $real->query($sql)->fetchAll(RealPDO::FETCH_NUM),
            $yeti->query($sql)->fetchAll(YetiPDO::FETCH_NUM),
            $sql,
        );
    }

    public function testVdbeAgreesWithTreeWalker(): void
    {
        // The same query, with and without the VM, must produce identical rows.
        $sql = 'SELECT id, age + 1, name FROM t WHERE age >= 30 AND nick IS NOT NULL';

        $walk = new YetiPDO('yetisql::memory:');
        $vm = new YetiPDO('yetisql::memory:');
        foreach (self::SETUP as $stmt) {
            $walk->exec($stmt);
            $vm->exec($stmt);
        }
        $vm->exec('PRAGMA vdbe=on');

        self::assertSame(
            $walk->query($sql)->fetchAll(YetiPDO::FETCH_NUM),
            $vm->query($sql)->fetchAll(YetiPDO::FETCH_NUM),
        );
    }

    public function testExplainEmitsBytecode(): void
    {
        $db = new YetiPDO('yetisql::memory:');
        foreach (self::SETUP as $stmt) {
            $db->exec($stmt);
        }

        $rows = $db->query('EXPLAIN SELECT id, name FROM t WHERE age > 30 LIMIT 2')
            ->fetchAll(YetiPDO::FETCH_NUM);

        $opcodes = \array_map(static fn (array $r): string => $r[1], $rows);
        // A scan program opens a cursor, rewinds, loops, and halts.
        self::assertContains('OpenRead', $opcodes);
        self::assertContains('Rewind', $opcodes);
        self::assertContains('ResultRow', $opcodes);
        self::assertContains('Next', $opcodes);
        self::assertSame('Halt', \end($opcodes));
    }

    public function testExplainQueryPlanReportsScan(): void
    {
        $db = new YetiPDO('yetisql::memory:');
        foreach (self::SETUP as $stmt) {
            $db->exec($stmt);
        }
        $rows = $db->query('EXPLAIN QUERY PLAN SELECT * FROM t WHERE age > 30')
            ->fetchAll(YetiPDO::FETCH_NUM);
        self::assertSame('SCAN t', $rows[0][3]);
    }
}
