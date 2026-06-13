<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Engine;

/**
 * SQLite-style big-endian variable-length integers (1-9 bytes).
 *
 * Each byte uses its high bit as a continuation flag; the low 7 bits carry
 * data, most-significant group first. The 9th byte (if present) contributes a
 * full 8 bits, allowing the entire 64-bit range to be encoded.
 *
 * Values are treated as unsigned 64-bit on the wire but PHP ints are signed
 * 64-bit, so the full range round-trips bit-for-bit.
 */
final class Varint
{
    /** Encode an integer to its varint byte string. */
    public static function encode(int $value): string
    {
        // Fast path for the overwhelmingly common small, non-negative case.
        if ($value >= 0 && $value <= 0x7F) {
            return \chr($value);
        }

        // Work on the raw 64 bits regardless of sign.
        $u = $value;
        $bytes = [];

        // Special handling of the 9-byte form: 8 groups of 7 bits + 1 group of 8.
        // Detect whether the value needs the full 9 bytes (bit 63..57 set beyond
        // what 8 sevens can hold). We build little-endian then reverse.
        if (($u & ~0x7FFFFFFFFFFFFF) !== 0) {
            // Needs 9 bytes: last byte is the full low 8 bits.
            $last = $u & 0xFF;
            $u = ($u >> 8) & 0x00FFFFFFFFFFFFFF; // logical shift of remaining 56 bits
            for ($i = 0; $i < 8; $i++) {
                $bytes[] = ($u & 0x7F) | 0x80;
                $u >>= 7;
            }
            $out = '';
            for ($i = 7; $i >= 0; $i--) {
                $out .= \chr($bytes[$i]);
            }
            return $out . \chr($last);
        }

        do {
            $bytes[] = ($u & 0x7F) | 0x80;
            $u = ($u >> 7) & 0x01FFFFFFFFFFFFFF; // keep it logical, never sign-extend
        } while ($u !== 0);

        $bytes[0] &= 0x7F; // least-significant group is emitted last and carries no continuation bit
        $out = '';
        for ($i = count($bytes) - 1; $i >= 0; $i--) {
            $out .= \chr($bytes[$i]);
        }
        return $out;
    }

    /**
     * Decode a varint from $buf starting at $offset.
     *
     * @return array{0:int,1:int} [value, bytesConsumed]
     */
    public static function decode(string $buf, int $offset = 0): array
    {
        $result = 0;
        for ($i = 0; $i < 8; $i++) {
            $byte = \ord($buf[$offset + $i]);
            if (($byte & 0x80) === 0) {
                $result = ($result << 7) | $byte;
                return [$result, $i + 1];
            }
            $result = ($result << 7) | ($byte & 0x7F);
        }
        // 9th byte contributes a full 8 bits.
        $byte = \ord($buf[$offset + 8]);
        $result = ($result << 8) | $byte;
        return [$result, 9];
    }

    /** Number of bytes the value would occupy when encoded. */
    public static function size(int $value): int
    {
        return \strlen(self::encode($value));
    }
}
