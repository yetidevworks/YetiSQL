<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Engine;

/**
 * Resolved metadata for a secondary index: the table column positions it covers
 * (in order), their collations, its storage root page, and uniqueness.
 */
final class IndexInfo
{
    public function __construct(
        public string $name,
        public string $table,
        public int $rootPage,
        /** @var list<int> table column positions, in index order */
        public array $columnPositions,
        /** @var list<string> collation per indexed column */
        public array $collations,
        public bool $unique = false,
        /** Partial-index WHERE predicate, or null for a full index. */
        public ?\YetiDevWorks\YetiSQL\Sql\Ast\Expr $partialWhere = null,
    ) {
    }

    public function leadingColumn(): int
    {
        return $this->columnPositions[0];
    }
}
