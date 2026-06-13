<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Doctrine;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use YetiDevWorks\YetiSQL\Engine\Database;

/**
 * DBAL driver connection backed by a YetiSQL engine.
 */
final class Connection implements ConnectionInterface
{
    public function __construct(private readonly Database $db)
    {
    }

    public function prepare(string $sql): Statement
    {
        return new Statement($this->db, $sql);
    }

    public function query(string $sql): Result
    {
        return $this->prepare($sql)->execute();
    }

    public function exec(string $sql): int
    {
        $affected = 0;
        foreach ($this->db->parse($sql) as $stmt) {
            $affected = $this->db->execute($stmt)->rowCount;
        }
        return $affected;
    }

    public function quote(string $value): string
    {
        return "'" . \str_replace("'", "''", $value) . "'";
    }

    public function lastInsertId(): int
    {
        return $this->db->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollBack(): void
    {
        $this->db->rollback();
    }

    public function getNativeConnection(): Database
    {
        return $this->db;
    }

    public function getServerVersion(): string
    {
        // Report a SQLite version so DBAL's SQLite platform selects the dialect
        // features we implement.
        return '3.45.0';
    }
}
