<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Engine;

/**
 * Marker wrapper distinguishing a BLOB (binary) value from a TEXT string.
 *
 * The engine deals in native PHP scalars (null/int/float/string) for speed;
 * a plain string is TEXT. Binary data is wrapped so the record codec can pick
 * the BLOB serial type and so comparisons sort it after text, matching SQLite.
 */
final class Blob
{
    public function __construct(public readonly string $bytes)
    {
    }

    public function __toString(): string
    {
        return $this->bytes;
    }
}
