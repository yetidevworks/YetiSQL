<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL;

/**
 * Mirrors \PDOException: carries a SQLSTATE in $code-string form via errorInfo.
 */
class PDOException extends \RuntimeException
{
    /** @var array{0:string,1:?int,2:?string} */
    public array $errorInfo = ['HY000', null, null];
}
