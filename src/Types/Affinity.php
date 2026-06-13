<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Types;

use YetiDevWorks\YetiSQL\Engine\Blob;

/**
 * SQLite column type affinity and the coercion rules that flow from it.
 *
 * Affinity is derived from the declared type name by the rules in
 * https://www.sqlite.org/datatype3.html#determination_of_column_affinity and
 * governs how values are stored and compared.
 */
enum Affinity: string
{
    case TEXT = 'TEXT';
    case NUMERIC = 'NUMERIC';
    case INTEGER = 'INTEGER';
    case REAL = 'REAL';
    case BLOB = 'BLOB';

    /** Derive affinity from a declared column type (the 5-rule algorithm). */
    public static function fromDeclaredType(?string $declared): self
    {
        if ($declared === null || $declared === '') {
            return self::BLOB; // no datatype => BLOB (a.k.a. NONE) affinity
        }
        $t = \strtoupper($declared);

        if (\str_contains($t, 'INT')) {
            return self::INTEGER;
        }
        if (\str_contains($t, 'CHAR') || \str_contains($t, 'CLOB') || \str_contains($t, 'TEXT')) {
            return self::TEXT;
        }
        if (\str_contains($t, 'BLOB')) {
            return self::BLOB;
        }
        if (\str_contains($t, 'REAL') || \str_contains($t, 'FLOA') || \str_contains($t, 'DOUB')) {
            return self::REAL;
        }
        return self::NUMERIC;
    }

    /**
     * Apply this affinity to a value as SQLite does when storing it into a
     * column or when an operand of a comparison carries the affinity.
     */
    public function apply(null|int|float|string|Blob $value): null|int|float|string|Blob
    {
        if ($value === null) {
            return null;
        }
        return match ($this) {
            self::TEXT => self::toText($value),
            self::NUMERIC, self::INTEGER, self::REAL => self::toNumericLike($value, $this),
            self::BLOB => $value,
        };
    }

    private static function toText(null|int|float|string|Blob $value): string|Blob
    {
        if (\is_string($value) || $value instanceof Blob) {
            return $value;
        }
        if (\is_int($value)) {
            return (string) $value;
        }
        return Value::floatToText((float) $value);
    }

    /**
     * NUMERIC/INTEGER/REAL affinity: convert TEXT that looks numeric into an
     * INTEGER or REAL; leave non-numeric text and blobs untouched. INTEGER and
     * NUMERIC also collapse a float with no fractional part to an int.
     */
    private static function toNumericLike(null|int|float|string|Blob $value, Affinity $aff): null|int|float|string|Blob
    {
        if ($value instanceof Blob) {
            return $value;
        }
        if (\is_int($value)) {
            return $aff === self::REAL ? (float) $value : $value;
        }
        if (\is_float($value)) {
            if ($aff === self::REAL) {
                return $value;
            }
            // NUMERIC/INTEGER: lossless float -> int collapse.
            if (\is_finite($value) && (float) (int) $value === $value) {
                return (int) $value;
            }
            return $value;
        }
        // string
        if (!Value::looksNumeric($value)) {
            return $value; // leave as TEXT
        }
        $n = Value::parseNumber($value);
        if ($aff === self::REAL) {
            return (float) $n;
        }
        if (\is_float($n) && \is_finite($n) && (float) (int) $n === $n) {
            return (int) $n;
        }
        return $n;
    }
}
