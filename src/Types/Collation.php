<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Types;

/**
 * Text collations, matching SQLite's three built-ins.
 *
 *   BINARY  byte-for-byte comparison (the default)
 *   NOCASE  case-insensitive for ASCII A-Z/a-z only (as SQLite does)
 *   RTRIM   like BINARY but ignoring trailing space characters
 */
final class Collation
{
    public const BINARY = 'BINARY';
    public const NOCASE = 'NOCASE';
    public const RTRIM = 'RTRIM';

    public static function compare(string $name, string $a, string $b): int
    {
        return match (\strtoupper($name)) {
            self::NOCASE => self::nocase($a, $b),
            self::RTRIM => self::rtrim($a, $b),
            default => $a <=> $b,
        };
    }

    public static function exists(string $name): bool
    {
        return \in_array(\strtoupper($name), [self::BINARY, self::NOCASE, self::RTRIM], true);
    }

    private static function nocase(string $a, string $b): int
    {
        // SQLite NOCASE folds only ASCII A-Z; emulate with a 7-bit lowercase.
        return self::asciiLower($a) <=> self::asciiLower($b);
    }

    private static function asciiLower(string $s): string
    {
        return \strtr(
            $s,
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'abcdefghijklmnopqrstuvwxyz',
        );
    }

    private static function rtrim(string $a, string $b): int
    {
        return \rtrim($a, ' ') <=> \rtrim($b, ' ');
    }
}
