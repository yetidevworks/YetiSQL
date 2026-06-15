<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql\Ast;

/**
 * The ON CONFLICT (upsert) clause of an INSERT statement:
 *
 *   ON CONFLICT [(target-columns) [WHERE predicate]] DO NOTHING
 *   ON CONFLICT [(target-columns) [WHERE predicate]] DO UPDATE SET ... [WHERE predicate]
 *
 * In the DO UPDATE assignments and WHERE, the special "excluded" row holds the
 * values that the failed INSERT would have written.
 */
final class UpsertClause
{
    public function __construct(
        /** @var list<string>|null conflict-target columns, or null for no target */
        public ?array $target = null,
        /** Partial-index predicate after the target columns (rarely used). */
        public ?Expr $targetWhere = null,
        public bool $doNothing = false,
        /** @var list<array{0:string,1:Expr}> column = expression assignments for DO UPDATE */
        public array $set = [],
        /** Optional WHERE on DO UPDATE: when false for the row, it is left unchanged. */
        public ?Expr $updateWhere = null,
    ) {
    }
}
