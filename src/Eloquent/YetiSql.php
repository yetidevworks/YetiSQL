<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Eloquent;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use YetiDevWorks\YetiSQL\PDO;

/**
 * Registers the `yetisql` Eloquent driver.
 *
 * Usage with the Eloquent Capsule:
 *
 *   $capsule = new \Illuminate\Database\Capsule\Manager();
 *   $capsule->addConnection([
 *       'driver'   => 'yetisql',
 *       'database' => __DIR__ . '/app.ysql',   // or ':memory:'
 *       'prefix'   => '',
 *   ]);
 *   YetiSql::register($capsule);
 *   $capsule->setAsGlobal();
 *   $capsule->bootEloquent();
 *
 * In a full Laravel app, call YetiSql::register(app('db')) from a service
 * provider's boot() method instead.
 */
final class YetiSql
{
    /**
     * Wire the `yetisql` driver resolver into a Capsule or DatabaseManager.
     */
    public static function register(Capsule|DatabaseManager $manager): void
    {
        $db = $manager instanceof Capsule ? $manager->getDatabaseManager() : $manager;

        $db->extend('yetisql', static function (array $config, string $name): Connection {
            return self::makeConnection($config, $name);
        });
    }

    /**
     * Build a YetiSQL-backed Eloquent connection from a connection config array.
     *
     * @param array<string,mixed> $config
     */
    public static function makeConnection(array $config, string $name): Connection
    {
        $database = (string) ($config['database'] ?? ':memory:');
        $prefix = (string) ($config['prefix'] ?? '');

        $pdo = new PDO('yetisql:' . $database);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $config['name'] ??= $name;

        return new Connection($pdo, $database, $prefix, $config);
    }
}
