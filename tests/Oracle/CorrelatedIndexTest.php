<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Oracle;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Correlated subqueries whose inner predicate is `col = <outer column>` should
 * use an index on that column (the planner treats the outer column as a
 * per-row constant). These assert that the index-accelerated results match
 * pdo_sqlite — including after writes, which must invalidate any cached counts.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class CorrelatedIndexTest extends TestCase
{
    /** @param array<int,YetiPDO|RealPDO> $dbs */
    private function seed(array $dbs, bool $withIndex = true): void
    {
        foreach ($dbs as $db) {
            $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
            $db->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, score INTEGER)');
            if ($withIndex) {
                $db->exec('CREATE INDEX idx_posts_user ON posts(user_id)');
            }
            for ($i = 1; $i <= 20; $i++) {
                $db->exec("INSERT INTO users VALUES ($i, 'u$i')");
            }
            for ($i = 1; $i <= 200; $i++) {
                $db->exec('INSERT INTO posts VALUES (' . $i . ', ' . (($i * 7) % 20 + 1) . ', ' . ($i % 11) . ')');
            }
        }
    }

    public function testCorrelatedIndexedSubqueriesMatchSqlite(): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        $real = new RealPDO('sqlite::memory:');
        $real->setAttribute(RealPDO::ATTR_ERRMODE, RealPDO::ERRMODE_EXCEPTION);
        $this->seed([$yeti, $real]);

        $queries = [
            'SELECT u.id, (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id) c FROM users u ORDER BY u.id',
            'SELECT u.id FROM users u WHERE (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id) >= 12 ORDER BY u.id',
            'SELECT u.id FROM users u WHERE EXISTS (SELECT 1 FROM posts p WHERE p.user_id = u.id AND p.score = 0) ORDER BY u.id',
            'SELECT u.id, (SELECT MAX(p.score) FROM posts p WHERE p.user_id = u.id) m FROM users u ORDER BY u.id',
            'SELECT u.id, (SELECT SUM(p.score) FROM posts p WHERE p.user_id = u.id) s FROM users u ORDER BY u.id',
        ];

        foreach ($queries as $sql) {
            self::assertSame(
                $real->query($sql)->fetchAll(RealPDO::FETCH_NUM),
                $yeti->query($sql)->fetchAll(YetiPDO::FETCH_NUM),
                $sql,
            );
        }

        // A write must be reflected by the next correlated count (no stale cache).
        $countSql = 'SELECT u.id, (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id) c FROM users u ORDER BY u.id';
        foreach ([$yeti, $real] as $db) {
            $db->exec('INSERT INTO posts VALUES (999, 1, 5)');
            $db->exec('DELETE FROM posts WHERE user_id = 2');
        }
        self::assertSame(
            $real->query($countSql)->fetchAll(RealPDO::FETCH_NUM),
            $yeti->query($countSql)->fetchAll(YetiPDO::FETCH_NUM),
            'correlated counts after write',
        );
    }

    public function testCorrelatedUnindexedCountSubqueriesMatchSqlite(): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        $real = new RealPDO('sqlite::memory:');
        $real->setAttribute(RealPDO::ATTR_ERRMODE, RealPDO::ERRMODE_EXCEPTION);
        $this->seed([$yeti, $real], withIndex: false);

        $countSql = 'SELECT u.id, (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id) c FROM users u ORDER BY u.id';
        self::assertSame(
            $real->query($countSql)->fetchAll(RealPDO::FETCH_NUM),
            $yeti->query($countSql)->fetchAll(YetiPDO::FETCH_NUM),
            'uncached unindexed correlated counts',
        );
        self::assertSame(
            $real->query($countSql)->fetchAll(RealPDO::FETCH_NUM),
            $yeti->query($countSql)->fetchAll(YetiPDO::FETCH_NUM),
            'cached unindexed correlated counts',
        );

        foreach ([$yeti, $real] as $db) {
            $db->exec('INSERT INTO posts VALUES (999, 1, 5)');
            $db->exec('DELETE FROM posts WHERE user_id = 2');
        }
        self::assertSame(
            $real->query($countSql)->fetchAll(RealPDO::FETCH_NUM),
            $yeti->query($countSql)->fetchAll(YetiPDO::FETCH_NUM),
            'uncached unindexed correlated counts after write',
        );
    }

    public function testCorrelatedUnindexedCountHonorsNocaseCollation(): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        $real = new RealPDO('sqlite::memory:');
        $real->setAttribute(RealPDO::ATTR_ERRMODE, RealPDO::ERRMODE_EXCEPTION);
        foreach ([$yeti, $real] as $db) {
            $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
            $db->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_name TEXT COLLATE NOCASE)');
            $db->exec("INSERT INTO users VALUES (1, 'ann'), (2, 'BOB'), (3, 'Cy')");
            $db->exec("INSERT INTO posts VALUES (1, 'ANN'), (2, 'ann'), (3, 'bob'), (4, 'BOB'), (5, 'cy')");
        }

        $sql = 'SELECT u.id, (SELECT COUNT(*) FROM posts p WHERE p.user_name = u.name) c FROM users u ORDER BY u.id';
        self::assertSame(
            $real->query($sql)->fetchAll(RealPDO::FETCH_NUM),
            $yeti->query($sql)->fetchAll(YetiPDO::FETCH_NUM),
            'uncached correlated counts with NOCASE collation',
        );
    }
}
