<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

/**
 * The OVER (...) specification attached to a window-function call.
 *
 * @phpstan-type Frame array{units:string,startKind:string,startVal:?Expr,endKind:string,endVal:?Expr}
 */
final class WindowSpec
{
    public function __construct(
        /** @var list<Expr> PARTITION BY expressions */
        public array $partitionBy = [],
        /** @var list<OrderTerm> ORDER BY terms within the window */
        public array $orderBy = [],
        /**
         * Frame specification, or null for the default frame. Bound kinds are
         * one of: 'unboundedPreceding', 'preceding', 'currentRow', 'following',
         * 'unboundedFollowing'.
         *
         * @var array{units:string,startKind:string,startVal:?Expr,endKind:string,endVal:?Expr}|null
         */
        public ?array $frame = null,
    ) {
    }
}
