<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Types;

use YetiDevWorks\YetiSQL\Engine\Blob;

/**
 * Helpers over the engine's value space (null/int/float/string/Blob), encoding
 * SQLite's storage-class comparison order and its text<->number coercions.
 *
 * Storage-class sort order: NULL < numbers < TEXT < BLOB. Numbers compare by
 * value, TEXT by collation, BLOB byte-wise.
 */
final class Value
{
    public const CLASS_NULL = 0;
    public const CLASS_NUMERIC = 1;
    public const CLASS_TEXT = 2;
    public const CLASS_BLOB = 3;

    public static function storageClass(null|int|float|string|Blob $v): int
    {
        return match (true) {
            $v === null => self::CLASS_NULL,
            \is_int($v), \is_float($v) => self::CLASS_NUMERIC,
            $v instanceof Blob => self::CLASS_BLOB,
            default => self::CLASS_TEXT,
        };
    }

    /**
     * Three-way comparison following SQLite's storage-class ordering. Two NULLs
     * compare equal (as they do for sorting/DISTINCT, not for `=`).
     */
    public static function compare(
        null|int|float|string|Blob $a,
        null|int|float|string|Blob $b,
        string $collation = Collation::BINARY,
    ): int {
        $ca = self::storageClass($a);
        $cb = self::storageClass($b);
        if ($ca !== $cb) {
            return $ca <=> $cb;
        }
        return match ($ca) {
            self::CLASS_NULL => 0,
            self::CLASS_NUMERIC => self::compareNumeric($a, $b),
            self::CLASS_TEXT => Collation::compare($collation, (string) $a, (string) $b),
            default => ((string) $a) <=> ((string) $b), // BLOB bytes
        };
    }

    private static function compareNumeric(int|float $a, int|float $b): int
    {
        // Compare as-is; PHP promotes to float when mixed, which matches SQLite
        // for the value ranges we support.
        return $a <=> $b;
    }

    /** SQLite truthiness for WHERE/HAVING/CASE-WHEN: non-zero number is true. */
    public static function isTrue(null|int|float|string|Blob $v): bool
    {
        if ($v === null) {
            return false;
        }
        if (\is_int($v) || \is_float($v)) {
            return $v != 0;
        }
        return self::toNumber((string) $v) != 0;
    }

    /** Whether the (trimmed) string is a complete decimal numeric literal. */
    public static function looksNumeric(string $s): bool
    {
        return \preg_match('/^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?\s*$/', $s) === 1;
    }

    /** Parse a fully-numeric string into int or float. */
    public static function parseNumber(string $s): int|float
    {
        $s = \trim($s);
        if (\preg_match('/^[+-]?\d+$/', $s) === 1) {
            $asInt = (int) $s;
            // Guard against silent overflow: fall back to float if it doesn't round-trip.
            if ((string) $asInt === \ltrim($s, '+')) {
                return $asInt;
            }
            return (float) $s;
        }
        return (float) $s;
    }

    /**
     * CAST-style leading-numeric extraction: parse the longest numeric prefix,
     * returning 0 when there is none. Used for arithmetic on text/blob operands.
     */
    public static function toNumber(null|int|float|string|Blob $v): int|float
    {
        if ($v === null) {
            return 0;
        }
        if (\is_int($v) || \is_float($v)) {
            return $v;
        }
        $s = \ltrim((string) $v);
        if (\preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?/', $s, $m) === 1) {
            $num = $m[0];
            if (\preg_match('/^[+-]?\d+$/', $num) === 1) {
                $asInt = (int) $num;
                if ((string) $asInt === \ltrim($num, '+')) {
                    return $asInt;
                }
            }
            return (float) $num;
        }
        return 0;
    }

    /** Render a REAL the way SQLite does: %.15g with a guaranteed decimal point. */
    public static function floatToText(float $f): string
    {
        if (\is_nan($f)) {
            return '';
        }
        if (\is_infinite($f)) {
            return $f > 0 ? 'Inf' : '-Inf';
        }
        $s = \sprintf('%.15g', $f);
        if (\preg_match('/[.eEnN]/', $s) !== 1) {
            $s .= '.0';
        }
        return $s;
    }

    /** Render any value to its TEXT form (for output / concatenation). */
    public static function toText(null|int|float|string|Blob $v): ?string
    {
        return match (true) {
            $v === null => null,
            \is_int($v) => (string) $v,
            \is_float($v) => self::floatToText($v),
            $v instanceof Blob => $v->bytes,
            default => $v,
        };
    }
}
