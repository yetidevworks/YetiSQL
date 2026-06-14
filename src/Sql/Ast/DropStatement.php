<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

/** DROP TABLE | DROP INDEX | DROP VIEW | DROP TRIGGER. */
final class DropStatement implements Statement
{
    public const TABLE = 'table';
    public const INDEX = 'index';
    public const VIEW = 'view';
    public const TRIGGER = 'trigger';

    public function __construct(
        public string $kind,
        public string $name,
        public bool $ifExists = false,
    ) {
    }
}
