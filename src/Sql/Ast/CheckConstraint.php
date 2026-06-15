<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

/**
 * A CHECK constraint (column- or table-level). The expression is evaluated
 * against each inserted/updated row; the row is rejected when it evaluates to
 * false (NULL and true both pass, per SQLite).
 */
final class CheckConstraint
{
    public function __construct(
        public Expr $expr,
        /** Verbatim SQL of the check expression, used in the failure message. */
        public string $sql = '',
        /** Optional CONSTRAINT name, used in the failure message when present. */
        public ?string $name = null,
    ) {
    }
}
