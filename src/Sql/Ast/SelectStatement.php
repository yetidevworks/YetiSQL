<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

final class SelectStatement implements Statement
{
    public function __construct(
        /** @var list<ResultColumn> */
        public array $columns = [],
        public bool $distinct = false,
        public ?TableRef $from = null,
        /** @var list<JoinClause> */
        public array $joins = [],
        public ?Expr $where = null,
        /** @var list<Expr> */
        public array $groupBy = [],
        public ?Expr $having = null,
        /** @var list<OrderTerm> */
        public array $orderBy = [],
        public ?Expr $limit = null,
        public ?Expr $offset = null,
        /** Compound operator joining this to $compound: UNION / UNION ALL / INTERSECT / EXCEPT. */
        public ?string $compoundOp = null,
        public ?SelectStatement $compound = null,
        /** @var list<array{0:Expr}> raw VALUES rows when this is a VALUES clause */
        public array $valuesRows = [],
        /**
         * Common table expressions in scope for this statement.
         * @var list<array{name:string,columns:?list<string>,select:SelectStatement}>
         */
        public array $with = [],
        public bool $recursive = false,
    ) {
    }
}
