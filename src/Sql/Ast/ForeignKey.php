<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

/** A FOREIGN KEY constraint captured from CREATE TABLE. */
final class ForeignKey
{
    public const NO_ACTION = 'NO ACTION';
    public const RESTRICT = 'RESTRICT';
    public const CASCADE = 'CASCADE';
    public const SET_NULL = 'SET NULL';
    public const SET_DEFAULT = 'SET DEFAULT';

    /**
     * @param list<string> $columns    child column names
     * @param list<string> $refColumns parent column names (empty = parent PRIMARY KEY)
     */
    public function __construct(
        public array $columns,
        public string $refTable,
        public array $refColumns = [],
        public string $onDelete = self::NO_ACTION,
        public string $onUpdate = self::NO_ACTION,
    ) {
    }
}
