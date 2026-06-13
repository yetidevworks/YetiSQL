<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

/** BEGIN / COMMIT / ROLLBACK / SAVEPOINT / RELEASE. */
final class TransactionStatement implements Statement
{
    public const BEGIN = 'begin';
    public const COMMIT = 'commit';
    public const ROLLBACK = 'rollback';
    public const SAVEPOINT = 'savepoint';
    public const RELEASE = 'release';

    public function __construct(
        public string $action,
        public ?string $savepoint = null,
    ) {
    }
}
