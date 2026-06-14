<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

final class CreateTriggerStatement implements Statement
{
    public const BEFORE = 'BEFORE';
    public const AFTER = 'AFTER';
    public const INSTEAD_OF = 'INSTEAD OF';

    public const INSERT = 'INSERT';
    public const UPDATE = 'UPDATE';
    public const DELETE = 'DELETE';

    public function __construct(
        public string $name,
        public string $timing,
        public string $event,
        public string $table,
        /** @var list<string> columns named in UPDATE OF (...), or [] for any column */
        public array $updateOfColumns = [],
        public ?Expr $when = null,
        /** @var list<Statement> body statements between BEGIN and END */
        public array $body = [],
        public bool $ifNotExists = false,
        /** The original CREATE TRIGGER SQL text, stored verbatim in the schema. */
        public string $sql = '',
    ) {
    }
}
