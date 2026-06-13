<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Exception;

/**
 * Thrown for SQL-level problems: parse errors, unknown tables/columns,
 * constraint violations, type errors, etc.
 */
class SqlException extends YetiSQLException
{
    public static function parse(string $message): self
    {
        return new self('near syntax: ' . $message, 'HY000', 1);
    }

    public static function constraint(string $message): self
    {
        return new self($message, '23000', 19);
    }
}
