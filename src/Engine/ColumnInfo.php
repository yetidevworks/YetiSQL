<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Engine;

use YetiDevWorks\YetiSQL\Sql\Ast\Expr;
use YetiDevWorks\YetiSQL\Types\Affinity;

/** Resolved metadata for one column of a table. */
final class ColumnInfo
{
    public function __construct(
        public string $name,
        public ?string $declaredType,
        public Affinity $affinity,
        public bool $notNull = false,
        public bool $primaryKey = false,
        public ?Expr $default = null,
        public string $collation = 'BINARY',
    ) {
    }
}
