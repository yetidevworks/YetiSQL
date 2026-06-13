<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

/** One entry in a SELECT result list: an expression, `*`, or `table.*`. */
final class ResultColumn
{
    public function __construct(
        public ?Expr $expr = null,
        public bool $star = false,
        public ?string $tableStar = null,
        public ?string $alias = null,
    ) {
    }
}
