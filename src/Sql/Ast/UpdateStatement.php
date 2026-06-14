<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

final class UpdateStatement implements Statement
{
    public function __construct(
        public string $table,
        /** @var list<array{0:string,1:Expr}> column = expression assignments */
        public array $set = [],
        public ?Expr $where = null,
        public bool $orReplace = false,
        public bool $orIgnore = false,
        /** @var list<ResultColumn>|null RETURNING result columns, or null */
        public ?array $returning = null,
    ) {
    }
}
