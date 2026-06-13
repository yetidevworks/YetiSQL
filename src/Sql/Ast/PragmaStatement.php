<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

final class PragmaStatement implements Statement
{
    public function __construct(
        public string $name,
        public ?Expr $value = null,
        public ?string $schema = null,
    ) {
    }
}
