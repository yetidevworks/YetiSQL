<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

/** An ORDER BY term. */
final class OrderTerm
{
    public function __construct(
        public Expr $expr,
        public bool $desc = false,
        public ?string $collation = null,
        public ?bool $nullsFirst = null,
    ) {
    }
}
