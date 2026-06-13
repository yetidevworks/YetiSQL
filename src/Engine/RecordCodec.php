<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Engine;

/**
 * Encodes/decodes a row (a list of values) using SQLite's serial-type record
 * format: a header of varint serial types preceded by the header length,
 * followed by the tightly packed value bytes.
 *
 * Serial types:
 *   0           NULL
 *   1..6        big-endian signed int of 1,2,3,4,6,8 bytes
 *   7           IEEE-754 64-bit float
 *   8           integer 0   (zero body bytes)
 *   9           integer 1   (zero body bytes)
 *   N>=12 even  BLOB of (N-12)/2 bytes
 *   N>=13 odd   TEXT of (N-13)/2 bytes
 *
 * Accepted PHP value types: null, int, float, string (TEXT), Blob (binary).
 */
final class RecordCodec
{
    /**
     * @param list<null|int|float|string|Blob> $values
     */
    public static function encode(array $values): string
    {
        $serials = '';
        $body = '';

        foreach ($values as $v) {
            if ($v === null) {
                $serials .= Varint::encode(0);
            } elseif (\is_int($v)) {
                [$type, $bytes] = self::encodeInt($v);
                $serials .= Varint::encode($type);
                $body .= $bytes;
            } elseif (\is_float($v)) {
                $serials .= Varint::encode(7);
                $body .= self::packFloat($v);
            } elseif ($v instanceof Blob) {
                $len = \strlen($v->bytes);
                $serials .= Varint::encode(12 + $len * 2);
                $body .= $v->bytes;
            } else {
                // TEXT
                $s = (string) $v;
                $len = \strlen($s);
                $serials .= Varint::encode(13 + $len * 2);
                $body .= $s;
            }
        }

        // Header length is self-describing: it counts its own varint too.
        $headerBodyLen = \strlen($serials);
        $sizeOfLen = Varint::size($headerBodyLen + 1);
        // Re-derive if adding the length varint pushed the total into another byte.
        $headerLen = $headerBodyLen + $sizeOfLen;
        if (Varint::size($headerLen) !== $sizeOfLen) {
            $headerLen = $headerBodyLen + Varint::size($headerLen);
        }

        return Varint::encode($headerLen) . $serials . $body;
    }

    /**
     * @return list<null|int|float|string|Blob>
     */
    public static function decode(string $record): array
    {
        [$headerLen, $hOff] = Varint::decode($record, 0);
        $serialTypes = [];
        $p = $hOff;
        while ($p < $headerLen) {
            [$type, $n] = Varint::decode($record, $p);
            $serialTypes[] = $type;
            $p += $n;
        }

        $values = [];
        $body = $headerLen;
        foreach ($serialTypes as $type) {
            [$value, $consumed] = self::decodeValue($type, $record, $body);
            $values[] = $value;
            $body += $consumed;
        }

        return $values;
    }

    /**
     * Decode only the column at $index, skipping the rest. Used by the VM's
     * lazy column access so a query never materializes columns it ignores.
     */
    public static function decodeColumn(string $record, int $index): null|int|float|string|Blob
    {
        [$headerLen, $hOff] = Varint::decode($record, 0);
        $p = $hOff;
        $body = $headerLen;
        $i = 0;
        while ($p < $headerLen) {
            [$type, $n] = Varint::decode($record, $p);
            $p += $n;
            $len = self::bodyLength($type);
            if ($i === $index) {
                return self::decodeValue($type, $record, $body)[0];
            }
            $body += $len;
            $i++;
        }
        return null;
    }

    /**
     * Parse just the record header into a per-column [serialType, bodyOffset]
     * map, without decoding any values. Lets a caller decode only the columns it
     * actually reads (via decodeAt), one body seek each, instead of paying to
     * materialize the whole row.
     *
     * @return array<int,array{0:int,1:int}>
     */
    public static function columnOffsets(string $record): array
    {
        [$headerLen, $p] = Varint::decode($record, 0);
        $offsets = [];
        $body = $headerLen;
        $i = 0;
        while ($p < $headerLen) {
            [$type, $n] = Varint::decode($record, $p);
            $p += $n;
            $offsets[$i++] = [$type, $body];
            $body += self::bodyLength($type);
        }
        return $offsets;
    }

    /**
     * Decode a sparse set of column positions in one header pass.
     *
     * @param list<int> $positions
     * @return array<int,null|int|float|string|Blob>
     */
    public static function decodeColumns(string $record, array $positions): array
    {
        if ($positions === []) {
            return [];
        }
        $want = [];
        $max = -1;
        foreach ($positions as $pos) {
            $want[$pos] = true;
            if ($pos > $max) {
                $max = $pos;
            }
        }

        [$headerLen, $p] = Varint::decode($record, 0);
        $body = $headerLen;
        $i = 0;
        $values = [];
        while ($p < $headerLen && $i <= $max) {
            [$type, $n] = Varint::decode($record, $p);
            $p += $n;
            $len = self::bodyLength($type);
            if (isset($want[$i])) {
                $values[$i] = self::decodeValue($type, $record, $body)[0];
            }
            $body += $len;
            $i++;
        }
        foreach ($want as $pos => $_) {
            $values[$pos] ??= null;
        }
        return $values;
    }

    /** Decode a single value given its serial type and body offset. */
    public static function decodeAt(string $record, int $type, int $bodyOff): null|int|float|string|Blob
    {
        return self::decodeValue($type, $record, $bodyOff)[0];
    }

    /** @return array{0:int,1:string} [serialType, bodyBytes] */
    private static function encodeInt(int $v): array
    {
        if ($v === 0) {
            return [8, ''];
        }
        if ($v === 1) {
            return [9, ''];
        }
        if ($v >= -128 && $v <= 127) {
            return [1, \pack('c', $v)];
        }
        if ($v >= -32768 && $v <= 32767) {
            return [2, \substr(\pack('N', $v & 0xFFFF), 2)];
        }
        if ($v >= -8388608 && $v <= 8388607) {
            return [3, \substr(\pack('N', $v & 0xFFFFFF), 1)];
        }
        if ($v >= -2147483648 && $v <= 2147483647) {
            return [4, \pack('N', $v & 0xFFFFFFFF)];
        }
        if ($v >= -140737488355328 && $v <= 140737488355327) {
            // 48-bit: take low 6 bytes of the 64-bit big-endian form.
            return [5, \substr(self::packI64($v), 2)];
        }
        return [6, self::packI64($v)];
    }

    /** @return array{0:null|int|float|string|Blob,1:int} [value, bytesConsumed] */
    private static function decodeValue(int $type, string $buf, int $off): array
    {
        return match (true) {
            $type === 0 => [null, 0],
            $type === 1 => [self::unpackInt($buf, $off, 1), 1],
            $type === 2 => [self::unpackInt($buf, $off, 2), 2],
            $type === 3 => [self::unpackInt($buf, $off, 3), 3],
            $type === 4 => [self::unpackInt($buf, $off, 4), 4],
            $type === 5 => [self::unpackInt($buf, $off, 6), 6],
            $type === 6 => [self::unpackInt($buf, $off, 8), 8],
            $type === 7 => [self::unpackFloat(\substr($buf, $off, 8)), 8],
            $type === 8 => [0, 0],
            $type === 9 => [1, 0],
            ($type & 1) === 0 => [new Blob(\substr($buf, $off, ($type - 12) >> 1)), ($type - 12) >> 1],
            default => [\substr($buf, $off, ($type - 13) >> 1), ($type - 13) >> 1],
        };
    }

    private static function bodyLength(int $type): int
    {
        return match (true) {
            $type === 0, $type === 8, $type === 9 => 0,
            $type === 1 => 1,
            $type === 2 => 2,
            $type === 3 => 3,
            $type === 4 => 4,
            $type === 5 => 6,
            $type === 6, $type === 7 => 8,
            ($type & 1) === 0 => ($type - 12) >> 1,
            default => ($type - 13) >> 1,
        };
    }

    private static function packI64(int $v): string
    {
        // Big-endian two's complement 64-bit.
        return \pack('J', $v);
    }

    private static function unpackInt(string $buf, int $off, int $len): int
    {
        if ($len === 8) {
            // Full width: unpack as big-endian 64-bit. PHP's signed int already
            // carries the two's-complement interpretation we want.
            /** @var array{1:int} $u */
            $u = \unpack('J', \substr($buf, $off, 8));
            return $u[1];
        }

        $bytes = \substr($buf, $off, $len);
        $val = 0;
        for ($i = 0; $i < $len; $i++) {
            $val = ($val << 8) | \ord($bytes[$i]);
        }
        // Sign-extend from the top bit of the stored width.
        $signBit = 1 << ($len * 8 - 1);
        if ($val & $signBit) {
            $val -= 1 << ($len * 8);
        }
        return $val;
    }

    private static function packFloat(float $f): string
    {
        // pack 'E' = big-endian IEEE-754 double.
        return \pack('E', $f);
    }

    private static function unpackFloat(string $bytes): float
    {
        /** @var array{1:float} $u */
        $u = \unpack('E', $bytes);
        return $u[1];
    }
}
