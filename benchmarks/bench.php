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

    if ($withIndex) {
        $r['create_index'] = timeit(fn () => $db->exec('CREATE INDEX idx_city ON users(city)') + $db->exec('CREATE INDEX idx_age ON users(age)'));
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

    return $r;
}

function newYeti(): YetiPDO
{
    $db = new YetiPDO('yetisql::memory:');
    $db->setAttribute(YetiPDO::ATTR_ERRMODE, YetiPDO::ERRMODE_EXCEPTION);
    return $db;
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
echo "The gap between those two columns is what index-based planning buys.\n";
