<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Exception;

use RuntimeException;

/**
 * Base class for every exception thrown by the engine.
 *
 * Carries an optional SQLSTATE-style code so the PDO-shaped layer can populate
 * errorInfo() the way pdo_sqlite does.
 */
class YetiSQLException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $sqlState = 'HY000',
        int $code = 1,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
