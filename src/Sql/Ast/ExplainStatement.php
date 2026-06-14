<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

/**
 * EXPLAIN [QUERY PLAN] <statement>: introspection that returns the compiled
 * VDBE program (or a high-level plan) for the wrapped statement instead of
 * executing it.
 */
final class ExplainStatement implements Statement
{
    public function __construct(
        public Statement $inner,
        public bool $queryPlan = false,
    ) {
    }
}
