<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO;

/**
 * The index access path must return exactly the rows a full scan would. These
 * tests run each query against an indexed and a non-indexed copy of the same
 * data and assert identical results, then check index maintenance across
 * UPDATE/DELETE and persistence across reopen.
 */
final class IndexPlanningTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = \sys_get_temp_dir() . '/yetisql_idx_' . \bin2hex(\random_bytes(6)) . '.ysql';
    }

    protected function tearDown(): void
    {
        @\unlink($this->path);
        @\unlink($this->path . '-journal');
    }

    private function seed(PDO $db, bool $withIndexes): void
    {
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT, age INTEGER, city TEXT)');
        $cities = ['NYC', 'LA', 'SF', 'CHI', 'BOS'];
        $stmt = $db->prepare('INSERT INTO t (id, name, age, city) VALUES (?,?,?,?)');
        $db->beginTransaction();
        for ($i = 1; $i <= 300; $i++) {
            $stmt->execute([$i, 'user' . $i, ($i % 70) + 10, $cities[$i % 5]]);
        }
        $db->commit();
        if ($withIndexes) {
            $db->exec('CREATE INDEX idx_age ON t(age)');
            $db->exec('CREATE INDEX idx_city ON t(city)');
        }
    }

    /** @return iterable<string,array{0:string}> */
    public static function queries(): iterable
    {
        foreach ([
            'SELECT id, name FROM t WHERE id = 250',
            'SELECT id FROM t WHERE id > 480 ORDER BY id',
            'SELECT id FROM t WHERE id BETWEEN 100 AND 110 ORDER BY id',
            'SELECT id FROM t WHERE id IN (5, 50, 500) ORDER BY id',
            'SELECT COUNT(*) FROM t WHERE age = 25',
            'SELECT id FROM t WHERE age = 25 ORDER BY id',
            'SELECT id FROM t WHERE age > 75 ORDER BY id',
            'SELECT id FROM t WHERE age BETWEEN 20 AND 22 ORDER BY id',
            'SELECT COUNT(*) FROM t WHERE age > 20 AND age <= 22',
            'SELECT id FROM t WHERE age > 20 AND age <= 22 ORDER BY id',
            "SELECT COUNT(*) FROM t WHERE city = 'SF'",
            "SELECT COUNT(*) FROM t WHERE city IN ('SF','SF','BOS')",
            "SELECT city FROM t WHERE city = 'SF' ORDER BY id",
            "SELECT id FROM t WHERE city = 'NYC' ORDER BY id",
            "SELECT id FROM t WHERE city IN ('SF','SF','BOS') ORDER BY id",
            "SELECT id FROM t WHERE city IN ('SF','BOS') ORDER BY id",
            "SELECT id FROM t WHERE age = 25 AND city = 'LA' ORDER BY id",
            "SELECT id FROM t WHERE age > 60 AND city = 'NYC' ORDER BY id",
        ] as $q) {
            yield $q => [$q];
        }
    }

    #[DataProvider('queries')]
    public function testIndexedMatchesScan(string $query): void
    {
        $scanDb = new PDO('yetisql::memory:');
        $this->seed($scanDb, withIndexes: false);

        $idxDb = new PDO('yetisql::memory:');
        $this->seed($idxDb, withIndexes: true);

        $expected = $scanDb->query($query)->fetchAll(PDO::FETCH_NUM);
        $actual = $idxDb->query($query)->fetchAll(PDO::FETCH_NUM);

        self::assertSame($expected, $actual, "Index path diverged for: $query");
    }

    public function testIndexMaintainedAcrossUpdateDelete(): void
    {
        $db = new PDO('yetisql::memory:');
        $this->seed($db, withIndexes: true);

        // Move a row out of city='NYC' (id=5 -> 5%5=0 -> NYC).
        $before = (int) $db->query("SELECT COUNT(*) FROM t WHERE city='NYC'")->fetchColumn();
        $db->exec("UPDATE t SET city='SF' WHERE id=5");
        $after = (int) $db->query("SELECT COUNT(*) FROM t WHERE city='NYC'")->fetchColumn();
        self::assertSame($before - 1, $after, 'index must reflect the UPDATE');
        self::assertSame([], $db->query("SELECT id FROM t WHERE city='NYC' AND id=5")->fetchAll(PDO::FETCH_NUM));

        // Delete by indexed predicate; index must drop the entry.
        $db->exec("DELETE FROM t WHERE id=10");
        self::assertFalse($db->query('SELECT id FROM t WHERE id=10')->fetch(PDO::FETCH_NUM));
        $age = (int) ($db->query('SELECT age FROM t WHERE id=80')->fetchColumn());
        $countByAge = (int) $db->query("SELECT COUNT(*) FROM t WHERE age=$age")->fetchColumn();
        $scanCount = 0;
        foreach ($db->query('SELECT age FROM t')->fetchAll(PDO::FETCH_NUM) as $r) {
            if ((int) $r[0] === $age) {
                $scanCount++;
            }
        }
        self::assertSame($scanCount, $countByAge, 'indexed age count must equal scan count');
    }

    public function testCoveredCountCacheInvalidatesAfterWrite(): void
    {
        $db = new PDO('yetisql::memory:');
        $this->seed($db, withIndexes: true);

        self::assertSame(60, (int) $db->query("SELECT COUNT(*) FROM t WHERE city='SF'")->fetchColumn());
        self::assertSame(60, (int) $db->query("SELECT COUNT(*) FROM t WHERE city='SF'")->fetchColumn());

        $db->exec("INSERT INTO t (id, name, age, city) VALUES (1000, 'new', 42, 'SF')");

        self::assertSame(61, (int) $db->query("SELECT COUNT(*) FROM t WHERE city='SF'")->fetchColumn());
    }

    public function testScanCountCacheInvalidatesAfterWrite(): void
    {
        $db = new PDO('yetisql::memory:');
        $this->seed($db, withIndexes: false);

        self::assertSame(60, (int) $db->query("SELECT COUNT(*) FROM t WHERE city='SF'")->fetchColumn());
        self::assertSame(60, (int) $db->query("SELECT COUNT(*) FROM t WHERE city='SF'")->fetchColumn());

        $db->exec("INSERT INTO t (id, name, age, city) VALUES (1000, 'new', 42, 'SF')");

        self::assertSame(61, (int) $db->query("SELECT COUNT(*) FROM t WHERE city='SF'")->fetchColumn());
    }

    public function testIndexPersistsAcrossReopen(): void
    {
        $db = new PDO('yetisql:' . $this->path);
        $this->seed($db, withIndexes: true);
        $db->getEngine()->close();

        $db2 = new PDO('yetisql:' . $this->path);
        // The reopened index must agree with a full scan of the persisted data.
        $indexed = \array_map('intval', $db2->query("SELECT id FROM t WHERE city='SF' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
        $scan = [];
        foreach ($db2->query('SELECT id, city FROM t ORDER BY id')->fetchAll(PDO::FETCH_NUM) as $r) {
            if ($r[1] === 'SF') {
                $scan[] = (int) $r[0];
            }
        }
        self::assertSame($scan, $indexed);
        self::assertNotSame(0, \count($indexed));
        $db2->getEngine()->close();
    }
}
