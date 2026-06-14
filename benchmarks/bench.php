#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * YetiSQL vs SQLite (pdo_sqlite) benchmark.
 *
 * SQLite (the C engine) is the gold standard; pure-PHP YetiSQL will be slower.
 * This measures *how much* slower across representative workloads, and shows
 * the payoff of index-based planning within YetiSQL itself.
 *
 *   php benchmarks/bench.php [rows]      (default 5000)
 */

require __DIR__ . '/../vendor/autoload.php';

use YetiDevWorks\YetiSQL\PDO as YetiPDO;

$ROWS = (int) ($argv[1] ?? 5000);
$LOOKUPS = 2000;

function timeit(callable $fn): float
{
    $t = \hrtime(true);
    $fn();
    return (\hrtime(true) - $t) / 1e9;
}

function fmt(float $s): string
{
    return $s < 1 ? \sprintf('%6.1f ms', $s * 1000) : \sprintf('%6.2f s ', $s);
}

/** @return array<string,float> */
function runSuite(object $db, int $rows, int $lookups, bool $withIndex): array
{
    $cities = ['NYC', 'LA', 'SF', 'CHI', 'BOS'];
    $r = [];

    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, age INTEGER, city TEXT, score REAL)');

    // 1. Bulk insert (one transaction).
    $r['insert'] = timeit(function () use ($db, $rows, $cities) {
        $db->beginTransaction();
        $stmt = $db->prepare('INSERT INTO users (id, name, age, city, score) VALUES (?,?,?,?,?)');
        for ($i = 1; $i <= $rows; $i++) {
            $stmt->execute([$i, 'user' . $i, ($i % 80) + 18, $cities[$i % 5], ($i % 100) / 10]);
        }
        $db->commit();
    });

    // Secondary table for join / correlated-subquery workloads: ~one post per
    // user, foreign key user_id. Setup only (not part of the insert metric).
    $db->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, body TEXT)');
    $db->beginTransaction();
    $pstmt = $db->prepare('INSERT INTO posts (id, user_id, body) VALUES (?,?,?)');
    for ($i = 1; $i <= $rows; $i++) {
        $pstmt->execute([$i, (($i * 31) % $rows) + 1, 'post' . $i]);
    }
    $db->commit();

    if ($withIndex) {
        $r['create_index'] = timeit(fn () => $db->exec('CREATE INDEX idx_city ON users(city)')
            + $db->exec('CREATE INDEX idx_age ON users(age)')
            + $db->exec('CREATE INDEX idx_posts_user ON posts(user_id)'));
    }

    // 2. Point lookups by primary key (rowid).
    $r['pk_lookup'] = timeit(function () use ($db, $lookups, $rows) {
        $stmt = $db->prepare('SELECT name, age FROM users WHERE id = ?');
        for ($i = 0; $i < $lookups; $i++) {
            $stmt->execute([($i * 7919 % $rows) + 1]);
            $stmt->fetch(YetiPDO::FETCH_ASSOC);
        }
    });

    // 3. Lookups by indexed column (or full scan when no index). Each matches
    //    ~20% of rows, so fewer iterations than the point lookups.
    $r['col_lookup'] = timeit(function () use ($db) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE city = ?');
        $cities = ['NYC', 'LA', 'SF', 'CHI', 'BOS'];
        for ($i = 0; $i < 200; $i++) {
            $stmt->execute([$cities[$i % 5]]);
            $stmt->fetch();
        }
    });

    // 4. Range query on age (indexed when withIndex).
    $r['range'] = timeit(function () use ($db) {
        for ($i = 0; $i < 200; $i++) {
            $db->query('SELECT COUNT(*) FROM users WHERE age BETWEEN 30 AND 40')->fetch();
        }
    });

    // 5. Full-scan aggregate.
    $r['aggregate'] = timeit(function () use ($db) {
        for ($i = 0; $i < 50; $i++) {
            $db->query('SELECT city, COUNT(*), AVG(age), SUM(score) FROM users GROUP BY city')->fetchAll();
        }
    });

    // 6. Updates by primary key.
    $r['update'] = timeit(function () use ($db, $rows) {
        $db->beginTransaction();
        $stmt = $db->prepare('UPDATE users SET score = score + 1 WHERE id = ?');
        for ($i = 1; $i <= 1000; $i++) {
            $stmt->execute([($i * 7919 % $rows) + 1]);
        }
        $db->commit();
    });

    // 7. Join (users ⋈ posts on user_id). With the posts.user_id index this is
    //    an index nested-loop seek; without it, the executor builds a transient
    //    hash table for the equality join instead of rescanning the inner table.
    //    The driving side is bounded so both paths stay measurable.
    $r['join'] = timeit(function () use ($db) {
        for ($i = 0; $i < 3; $i++) {
            $db->query('SELECT COUNT(*) FROM users u JOIN posts p ON p.user_id = u.id WHERE u.id <= 100')->fetch();
        }
    });

    // 8. Correlated subquery: per outer user, count their posts. With an index
    //    this is a covered seek; without one, COUNT(*) over inner_col = outer_col
    //    builds a transient count map instead of rescanning the inner table.
    $r['correlated'] = timeit(function () use ($db) {
        $db->query('SELECT u.id, (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id) FROM users u WHERE u.id <= 100')->fetchAll();
    });

    return $r;
}

function newYeti(): YetiPDO
{
    $db = new YetiPDO('yetisql::memory:');
    $db->setAttribute(YetiPDO::ATTR_ERRMODE, YetiPDO::ERRMODE_EXCEPTION);
    return $db;
}

/**
 * Measure WAL vs the rollback journal on a file-backed database. The difference
 * only appears with disk durability and many separate commits — each rollback
 * commit pays two fsyncs plus journal create/delete and scattered in-place
 * writes, while a WAL commit is one fsync and a sequential append. (In-memory
 * databases never touch disk, so WAL is inert there and not measured.)
 *
 * @return array<string,float>
 */
function runWalSuite(string $journalMode, int $rows): array
{
    $path = \sys_get_temp_dir() . '/yetisql_bench_' . \getmypid() . '_' . $journalMode . '.ysql';
    foreach (['', '-wal', '-journal'] as $s) {
        if (\is_file($path . $s)) {
            @\unlink($path . $s);
        }
    }

    $db = new YetiPDO('yetisql:' . $path);
    $db->setAttribute(YetiPDO::ATTR_ERRMODE, YetiPDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=' . $journalMode);
    $db->exec('CREATE TABLE u (id INTEGER PRIMARY KEY, v TEXT)');

    $r = [];

    // Many small autocommit INSERTs — one durable commit per row.
    $r['autocommit_insert'] = timeit(function () use ($db, $rows) {
        $stmt = $db->prepare('INSERT INTO u (id, v) VALUES (?, ?)');
        for ($i = 1; $i <= $rows; $i++) {
            $stmt->execute([$i, 'v' . $i]);
        }
    });

    // Many small autocommit UPDATEs — again one commit per row.
    $r['autocommit_update'] = timeit(function () use ($db, $rows) {
        $stmt = $db->prepare('UPDATE u SET v = v WHERE id = ?');
        for ($i = 1; $i <= $rows; $i++) {
            $stmt->execute([$i]);
        }
    });

    unset($db);
    foreach (['', '-wal', '-journal'] as $s) {
        if (\is_file($path . $s)) {
            @\unlink($path . $s);
        }
    }
    return $r;
}

function newSqlite(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

echo "YetiSQL vs SQLite — $ROWS rows, $LOOKUPS lookups (lower is faster)\n";
echo \str_repeat('=', 78) . "\n";

$sqlite = runSuite(newSqlite(), $ROWS, $LOOKUPS, withIndex: true);
$yetiIdx = runSuite(newYeti(), $ROWS, $LOOKUPS, withIndex: true);
$yetiScan = runSuite(newYeti(), $ROWS, $LOOKUPS, withIndex: false);

$labels = [
    'insert' => 'bulk insert',
    'pk_lookup' => "PK lookups (×$LOOKUPS)",
    'col_lookup' => 'city COUNT (×200, ~20% rows)',
    'range' => 'range query (×200)',
    'aggregate' => 'group-by aggregate (×50)',
    'update' => 'PK updates (×1000)',
    'join' => 'join users⋈posts (×3, ≤100)',
    'correlated' => 'correlated subquery (≤100)',
];

\printf("%-28s %12s %12s %12s %9s\n", 'workload', 'SQLite', 'YetiSQL+idx', 'YetiSQL scan', 'vs SQLite');
echo \str_repeat('-', 78) . "\n";
foreach ($labels as $key => $label) {
    $s = $sqlite[$key] ?? 0.0;
    $yi = $yetiIdx[$key] ?? 0.0;
    $ys = $yetiScan[$key] ?? 0.0;
    $ratio = $s > 0 ? \sprintf('%.0fx', $yi / $s) : '-';
    \printf("%-28s %12s %12s %12s %9s\n", $label, fmt($s), fmt($yi), fmt($ys), $ratio);
}
echo \str_repeat('-', 78) . "\n";
echo "'YetiSQL+idx' uses indexes; 'YetiSQL scan' has no secondary indexes.\n";
echo "The gap between those columns is what persistent indexes buy. Equality joins\n";
echo "without indexes can still use a transient hash table; unindexed equality\n";
echo "COUNT(*) correlated subqueries can use a transient count map.\n";

// --- WAL vs rollback journal (file-backed durability) ---------------------
$WAL_OPS = 2000;
$walRollback = runWalSuite('delete', $WAL_OPS);
$walWal = runWalSuite('wal', $WAL_OPS);

echo "\n";
echo "Durability: WAL vs rollback journal — file-backed, $WAL_OPS ops (lower is faster)\n";
echo \str_repeat('=', 78) . "\n";
\printf("%-34s %14s %14s %9s\n", 'workload', 'rollback', 'WAL', 'speedup');
echo \str_repeat('-', 78) . "\n";
$walLabels = [
    'autocommit_insert' => 'INSERTs, one commit each',
    'autocommit_update' => 'UPDATEs, one commit each',
];
foreach ($walLabels as $key => $label) {
    $ro = $walRollback[$key] ?? 0.0;
    $wa = $walWal[$key] ?? 0.0;
    $speedup = $wa > 0 ? \sprintf('%.1fx', $ro / $wa) : '-';
    \printf("%-34s %14s %14s %9s\n", $label, fmt($ro), fmt($wa), $speedup);
}
echo \str_repeat('-', 78) . "\n";
echo "WAL replaces two fsyncs + journal create/delete per commit with one fsync and\n";
echo "a sequential append, so the win scales with the number of commits. A single\n";
echo "big transaction commits once and sees no difference; in-memory never hits disk.\n";
