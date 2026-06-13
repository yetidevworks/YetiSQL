<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL;

use YetiDevWorks\YetiSQL\Engine\Database;
use YetiDevWorks\YetiSQL\Exception\YetiSQLException;

/**
 * A PDO-API-shaped facade over the YetiSQL engine.
 *
 * It deliberately mirrors \PDO's surface (prepare/query/exec, transactions,
 * fetch modes, attributes, error handling) so code written against pdo_sqlite
 * ports with minimal change. It is NOT a subclass of \PDO (real PDO drivers are
 * C extensions), but the constants use \PDO's numeric values so \PDO::FETCH_*
 * and YetiSQL\PDO::FETCH_* are interchangeable.
 *
 * DSN forms: "yetisql:/path/to/app.ysql", "yetisql::memory:", or a bare path.
 */
class PDO
{
    // Fetch modes (same numeric values as \PDO).
    public const FETCH_LAZY = 1;
    public const FETCH_ASSOC = 2;
    public const FETCH_NUM = 3;
    public const FETCH_BOTH = 4;
    public const FETCH_OBJ = 5;
    public const FETCH_COLUMN = 7;
    public const FETCH_KEY_PAIR = 12;

    // Attributes.
    public const ATTR_AUTOCOMMIT = 0;
    public const ATTR_ERRMODE = 3;
    public const ATTR_DEFAULT_FETCH_MODE = 19;
    public const ATTR_DRIVER_NAME = 16;

    // Error modes.
    public const ERRMODE_SILENT = 0;
    public const ERRMODE_WARNING = 1;
    public const ERRMODE_EXCEPTION = 2;

    // Parameter types.
    public const PARAM_NULL = 0;
    public const PARAM_INT = 1;
    public const PARAM_STR = 2;
    public const PARAM_LOB = 3;
    public const PARAM_BOOL = 5;

    private Database $db;
    private int $errmode = self::ERRMODE_EXCEPTION;
    private int $defaultFetchMode = self::FETCH_BOTH;

    /** @var array{0:string,1:?int,2:?string} */
    private array $errorInfo = ['', null, null];

    /** @param array<int,mixed>|null $options */
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        $path = $this->parseDsn($dsn);
        try {
            $this->db = new Database($path);
        } catch (YetiSQLException $e) {
            throw $this->makeException($e);
        }
        foreach ($options ?? [] as $attr => $value) {
            $this->setAttribute($attr, $value);
        }
    }

    private function parseDsn(string $dsn): string
    {
        if (\str_starts_with($dsn, 'yetisql:')) {
            $rest = \substr($dsn, \strlen('yetisql:'));
            return $rest === '' ? ':memory:' : $rest;
        }
        if (\str_starts_with($dsn, 'sqlite:')) {
            // Tolerate sqlite: DSNs for drop-in convenience.
            $rest = \substr($dsn, \strlen('sqlite:'));
            return $rest === '' ? ':memory:' : $rest;
        }
        return $dsn;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        try {
            $stmts = $this->db->parse($query);
            return new PDOStatement($this, $this->db, $query, $stmts, $this->defaultFetchMode);
        } catch (YetiSQLException $e) {
            return $this->fail($e);
        }
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        $stmt = $this->prepare($query);
        if ($fetchMode !== null) {
            $stmt->setFetchMode($fetchMode, ...$args);
        }
        return $stmt->execute() ? $stmt : false;
    }

    public function exec(string $statement): int|false
    {
        try {
            $stmts = $this->db->parse($statement);
            $affected = 0;
            foreach ($stmts as $stmt) {
                $affected = $this->db->execute($stmt)->rowCount;
            }
            $this->clearError();
            return $affected;
        } catch (YetiSQLException $e) {
            return $this->fail($e);
        }
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return (string) $this->db->lastInsertId();
    }

    public function beginTransaction(): bool
    {
        if ($this->db->inTransaction()) {
            throw $this->makeException(new YetiSQLException('There is already an active transaction'));
        }
        $this->db->beginTransaction();
        return true;
    }

    public function commit(): bool
    {
        $this->db->commit();
        return true;
    }

    public function rollBack(): bool
    {
        $this->db->rollback();
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->db->inTransaction();
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        switch ($attribute) {
            case self::ATTR_ERRMODE:
                $this->errmode = (int) $value;
                break;
            case self::ATTR_DEFAULT_FETCH_MODE:
                $this->defaultFetchMode = (int) $value;
                break;
        }
        return true;
    }

    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            self::ATTR_ERRMODE => $this->errmode,
            self::ATTR_DEFAULT_FETCH_MODE => $this->defaultFetchMode,
            self::ATTR_DRIVER_NAME => 'yetisql',
            default => null,
        };
    }

    public function errorCode(): ?string
    {
        return $this->errorInfo[0] === '' ? null : $this->errorInfo[0];
    }

    /** @return array{0:string,1:?int,2:?string} */
    public function errorInfo(): array
    {
        return $this->errorInfo;
    }

    public function quote(string $string, int $type = self::PARAM_STR): string
    {
        return "'" . \str_replace("'", "''", $string) . "'";
    }

    public function getEngine(): Database
    {
        return $this->db;
    }

    public function errorMode(): int
    {
        return $this->errmode;
    }

    // --- error plumbing ---------------------------------------------------

    public function recordError(YetiSQLException $e): void
    {
        $this->errorInfo = [$e->sqlState, $e->getCode(), $e->getMessage()];
    }

    private function clearError(): void
    {
        $this->errorInfo = ['00000', null, null];
    }

    /** @return never|false */
    private function fail(YetiSQLException $e): mixed
    {
        $this->recordError($e);
        if ($this->errmode === self::ERRMODE_EXCEPTION) {
            throw $this->makeException($e);
        }
        if ($this->errmode === self::ERRMODE_WARNING) {
            \trigger_error($e->getMessage(), E_USER_WARNING);
        }
        return false;
    }

    public function makeException(YetiSQLException $e): PDOException
    {
        $ex = new PDOException($e->getMessage(), (int) $e->getCode(), $e);
        $ex->errorInfo = [$e->sqlState, $e->getCode(), $e->getMessage()];
        return $ex;
    }
}
