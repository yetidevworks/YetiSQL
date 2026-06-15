#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * YetiSQL (file-backed, durable) vs a local MySQL/MariaDB server.
 *
 * This is an inherently apples-to-oranges comparison and the numbers should be
 * read with that in mind:
 *
 *   - YetiSQL runs *in-process* (pure PHP, no IPC). Here it is file-backed, so
 *     it pays real disk I/O and journals every commit for durability.
 *   - MySQL/MariaDB is a *client-server* engine. Every statement crosses a
 *     socket to a separate C process, which is far faster per-row internally
 *     but pays a round-trip on every query. Commits are durable via the InnoDB
 *     redo log.
 *
 * So for chatty, many-tiny-statement workloads (point lookups, per-row updates)
 * the socket round-trip dominates the server and YetiSQL's in-process model can
 * look competitive or better; for heavy scans/aggregates the C engine's raw
 * speed wins. Bulk insert/update are wrapped in ONE transaction on both sides,
 * so each pays its durable-commit cost only once.
 *
 *   php benchmarks/bench_mysql.php [rows]      (default 5000)
 *
 * MySQL connection (env overrides, all optional):
 *   MYSQL_SOCKET   unix socket path   (default: auto-detected, else /tmp/mysql.sock)
 *   MYSQL_HOST     TCP host           (used only if MYSQL_SOCKET is empty)
 *   MYSQL_PORT     TCP port           (default 3306)
 *   MYSQL_USER     user               (default: current OS user)
 *   MYSQL_PASS     password           (default: empty)
 *   MYSQL_DB       scratch database   (default: yetisql_bench; created + dropped)
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
    return $s < 1 ? \sprintf('%7.1f ms', $s * 1000) : \sprintf('%7.2f s ', $s);
}

/**
 * Run the workload suite against a PDO-shaped connection.
 *
 * $dialect is 'mysql' or 'yeti' and only affects DDL (MySQL needs a key length
 * for indexed text, so the indexed columns are VARCHAR there; the executed
 * queries are byte-for-byte identical across engines).
 *
 * @return array<string,float>
 */
function runSuite(object $db, int $rows, int $lookups, string $dialect): array
{
    $cities = ['NYC', 'LA', 'SF', 'CHI', 'BOS'];
    $r = [];

    if ($dialect === 'mysql') {
        $db->exec('DROP TABLE IF EXISTS posts');
        $db->exec('DROP TABLE IF EXISTS users');
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name VARCHAR(64), age INTEGER, city VARCHAR(16), score DOUBLE) ENGINE=InnoDB');
    } else {
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, age INTEGER, city TEXT, score REAL)');
    }

    // 1. Bulk insert (one transaction => one durable commit).
    $r['insert'] = timeit(function () use ($db, $rows, $cities) {
        $db->beginTransaction();
        $stmt = $db->prepare('INSERT INTO users (id, name, age, city, score) VALUES (?,?,?,?,?)');
        for ($i = 1; $i <= $rows; $i++) {
            $stmt->execute([$i, 'user' . $i, ($i % 80) + 18, $cities[$i % 5], ($i % 100) / 10]);
        }
        $db->commit();
    });

    // Secondary table for join / correlated workloads (setup, not measured).
    if ($dialect === 'mysql') {
        $db->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, body VARCHAR(64)) ENGINE=InnoDB');
    } else {
        $db->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, body TEXT)');
    }
    $db->beginTransaction();
    $pstmt = $db->prepare('INSERT INTO posts (id, user_id, body) VALUES (?,?,?)');
    for ($i = 1; $i <= $rows; $i++) {
        $pstmt->execute([$i, (($i * 31) % $rows) + 1, 'post' . $i]);
    }
    $db->commit();

    $r['create_index'] = timeit(fn () => $db->exec('CREATE INDEX idx_city ON users(city)')
        + $db->exec('CREATE INDEX idx_age ON users(age)')
        + $db->exec('CREATE INDEX idx_posts_user ON posts(user_id)'));

    // 2. Point lookups by primary key.
    $r['pk_lookup'] = timeit(function () use ($db, $lookups, $rows) {
        $stmt = $db->prepare('SELECT name, age FROM users WHERE id = ?');
        for ($i = 0; $i < $lookups; $i++) {
            $stmt->execute([($i * 7919 % $rows) + 1]);
            $stmt->fetch(\PDO::FETCH_ASSOC);
        }
    });

    // 3. Indexed-column COUNT (~20% of rows per query).
    $r['col_lookup'] = timeit(function () use ($db) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE city = ?');
        $cities = ['NYC', 'LA', 'SF', 'CHI', 'BOS'];
        for ($i = 0; $i < 200; $i++) {
            $stmt->execute([$cities[$i % 5]]);
            $stmt->fetch();
        }
    });

    // 4. Range query on indexed age.
    $r['range'] = timeit(function () use ($db) {
        for ($i = 0; $i < 200; $i++) {
            $db->query('SELECT COUNT(*) FROM users WHERE age BETWEEN 30 AND 40')->fetch();
        }
    });

    // 5. Full-scan group-by aggregate.
    $r['aggregate'] = timeit(function () use ($db) {
        for ($i = 0; $i < 50; $i++) {
            $db->query('SELECT city, COUNT(*), AVG(age), SUM(score) FROM users GROUP BY city')->fetchAll();
        }
    });

    // 6. Updates by primary key (one transaction => one durable commit).
    $r['update'] = timeit(function () use ($db, $rows) {
        $db->beginTransaction();
        $stmt = $db->prepare('UPDATE users SET score = score + 1 WHERE id = ?');
        for ($i = 1; $i <= 1000; $i++) {
            $stmt->execute([($i * 7919 % $rows) + 1]);
        }
        $db->commit();
    });

    // 7. Join (users join posts on user_id), driving side bounded.
    $r['join'] = timeit(function () use ($db) {
        for ($i = 0; $i < 3; $i++) {
            $db->query('SELECT COUNT(*) FROM users u JOIN posts p ON p.user_id = u.id WHERE u.id <= 100')->fetch();
        }
    });

    // 8. Correlated subquery: per outer user, count their posts.
    $r['correlated'] = timeit(function () use ($db) {
        $db->query('SELECT u.id, (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id) FROM users u WHERE u.id <= 100')->fetchAll();
    });

    return $r;
}

/** File-backed, durable YetiSQL connection on a fresh scratch file. */
function newYetiFile(): array
{
    $path = \sys_get_temp_dir() . '/yetisql_bench_mysql_' . \getmypid() . '.ysql';
    foreach (['', '-wal', '-journal'] as $s) {
        if (\is_file($path . $s)) {
            @\unlink($path . $s);
        }
    }
    $db = new YetiPDO('yetisql:' . $path);
    $db->setAttribute(YetiPDO::ATTR_ERRMODE, YetiPDO::ERRMODE_EXCEPTION);
    return [$db, $path];
}

function cleanupYetiFile(string $path): void
{
    foreach (['', '-wal', '-journal'] as $s) {
        if (\is_file($path . $s)) {
            @\unlink($path . $s);
        }
    }
}

function newMysql(string $db): PDO
{
    $socket = \getenv('MYSQL_SOCKET');
    if ($socket === false) {
        $socket = @\file_exists('/tmp/mysql.sock') ? '/tmp/mysql.sock' : '';
    }
    $user = \getenv('MYSQL_USER') ?: (\function_exists('posix_getpwuid')
        ? (\posix_getpwuid(\posix_geteuid())['name'] ?? 'root')
        : (\trim((string) \shell_exec('whoami')) ?: 'root'));
    $pass = (string) (\getenv('MYSQL_PASS') ?: '');

    if ($socket !== '') {
        $rootDsn = "mysql:unix_socket=$socket";
        $dsn = "mysql:unix_socket=$socket;dbname=$db";
    } else {
        $host = \getenv('MYSQL_HOST') ?: '127.0.0.1';
        $port = \getenv('MYSQL_PORT') ?: '3306';
        $rootDsn = "mysql:host=$host;port=$port";
        $dsn = "mysql:host=$host;port=$port;dbname=$db";
    }

    $admin = new PDO($rootDsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec("CREATE DATABASE IF NOT EXISTS `$db`");
    unset($admin);

    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    return $pdo;
}

// --- run ------------------------------------------------------------------

$dbName = \getenv('MYSQL_DB') ?: 'yetisql_bench';

try {
    $mysql = newMysql($dbName);
} catch (Throwable $e) {
    \fwrite(\STDERR, "Could not connect to MySQL/MariaDB: {$e->getMessage()}\n");
    \fwrite(\STDERR, "Set MYSQL_SOCKET / MYSQL_HOST / MYSQL_USER / MYSQL_PASS as needed.\n");
    exit(1);
}

$serverVer = $mysql->getAttribute(PDO::ATTR_SERVER_VERSION);

echo "YetiSQL (file-backed, durable) vs MySQL/MariaDB $serverVer — $ROWS rows, $LOOKUPS lookups\n";
echo "Both durable; lower is faster.\n";
echo \str_repeat('=', 78) . "\n";

$mysqlRes = runSuite($mysql, $ROWS, $LOOKUPS, 'mysql');

[$yeti, $yetiPath] = newYetiFile();
$yetiRes = runSuite($yeti, $ROWS, $LOOKUPS, 'yeti');
unset($yeti);
cleanupYetiFile($yetiPath);

// Drop the scratch database.
try {
    $mysql->exec("DROP DATABASE IF EXISTS `$dbName`");
} catch (Throwable $e) {
    // leave it; not fatal for the benchmark
}

$labels = [
    'insert' => 'bulk insert (1 txn)',
    'create_index' => 'create 3 indexes',
    'pk_lookup' => "PK lookups (×$LOOKUPS)",
    'col_lookup' => 'city COUNT (×200, ~20% rows)',
    'range' => 'range query (×200)',
    'aggregate' => 'group-by aggregate (×50)',
    'update' => 'PK updates (×1000, 1 txn)',
    'join' => 'join users⋈posts (×3, ≤100)',
    'correlated' => 'correlated subquery (≤100)',
];

\printf("%-30s %13s %13s %11s\n", 'workload', 'MariaDB', 'YetiSQL(file)', 'Yeti/MySQL');
echo \str_repeat('-', 78) . "\n";
foreach ($labels as $key => $label) {
    $m = $mysqlRes[$key] ?? 0.0;
    $y = $yetiRes[$key] ?? 0.0;
    $ratio = $m > 0 ? \sprintf('%.1fx', $y / $m) : '-';
    \printf("%-30s %13s %13s %11s\n", $label, fmt($m), fmt($y), $ratio);
}
echo \str_repeat('-', 78) . "\n";
echo "Yeti/MySQL > 1 means MariaDB was faster; < 1 means YetiSQL was faster.\n";
echo "Chatty per-row workloads (PK lookups/updates) pay MySQL a socket round-trip\n";
echo "per statement; heavy scans/aggregates favor the C engine's raw speed.\n";
