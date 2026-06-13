<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Doctrine;

use Doctrine\DBAL\Driver\AbstractSQLiteDriver;
use SensitiveParameter;
use YetiDevWorks\YetiSQL\Engine\Database;

/**
 * Doctrine DBAL driver for YetiSQL.
 *
 * Extends AbstractSQLiteDriver so DBAL reuses its SQLite platform (SQL grammar)
 * and exception converter; we only supply the connection. Because YetiSQL
 * speaks the SQLite dialect, the SQLite platform's generated SQL is what our
 * parser already understands.
 *
 * Usage:
 *   $conn = DriverManager::getConnection([
 *       'driverClass' => YetiDevWorks\YetiSQL\Doctrine\Driver::class,
 *       'path'        => 'app.ysql',     // or 'memory' => true
 *   ]);
 */
final class Driver extends AbstractSQLiteDriver
{
    /** @param array<string,mixed> $params */
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): Connection {
        $path = ':memory:';
        if (isset($params['path']) && $params['path'] !== '') {
            $path = (string) $params['path'];
        } elseif (!empty($params['memory'])) {
            $path = ':memory:';
        }

        return new Connection(new Database($path));
    }
}
