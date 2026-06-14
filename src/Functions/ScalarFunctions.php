<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Functions;

use YetiDevWorks\YetiSQL\Engine\Blob;
use YetiDevWorks\YetiSQL\Exception\SqlException;
use YetiDevWorks\YetiSQL\Types\Value;

/**
 * SQLite built-in scalar functions. Each entry maps a lower-cased name to a
 * callable receiving the already-evaluated argument list.
 *
 * NULL-propagation and argument-count behaviour follow SQLite's documented
 * semantics for the core functions.
 */
final class ScalarFunctions
{
    /** @return bool whether $name (lower-cased) is a known scalar function */
    public static function exists(string $name): bool
    {
        return \method_exists(self::class, 'fn_' . $name) || isset(self::ALIASES[$name]);
    }

    private const ALIASES = [
        'substring' => 'substr',
        'ucase' => 'upper',
        'lcase' => 'lower',
    ];

    /**
     * @param list<null|int|float|string|Blob> $args
     */
    public static function call(string $name, array $args): null|int|float|string|Blob
    {
        $name = self::ALIASES[$name] ?? $name;
        $method = 'fn_' . $name;
        if (!\method_exists(self::class, $method)) {
            throw new SqlException("no such function: $name");
        }
        return self::$method($args);
    }

    // --- string functions -------------------------------------------------

    private static function fn_length(array $a): null|int
    {
        if ($a[0] === null) {
            return null;
        }
        if ($a[0] instanceof Blob) {
            return \strlen($a[0]->bytes);
        }
        return \mb_strlen((string) Value::toText($a[0]), 'UTF-8');
    }

    private static function fn_lower(array $a): ?string
    {
        return $a[0] === null ? null : \mb_strtolower((string) Value::toText($a[0]), 'UTF-8');
    }

    private static function fn_upper(array $a): ?string
    {
        return $a[0] === null ? null : \mb_strtoupper((string) Value::toText($a[0]), 'UTF-8');
    }

    private static function fn_trim(array $a): ?string
    {
        return self::trimImpl($a, 'both');
    }

    private static function fn_ltrim(array $a): ?string
    {
        return self::trimImpl($a, 'left');
    }

    private static function fn_rtrim(array $a): ?string
    {
        return self::trimImpl($a, 'right');
    }

    private static function trimImpl(array $a, string $side): ?string
    {
        if ($a[0] === null) {
            return null;
        }
        $s = (string) Value::toText($a[0]);
        $chars = isset($a[1]) ? (string) Value::toText($a[1]) : " \t\n\r\0\x0B";
        if ($chars === '') {
            return $s;
        }
        return match ($side) {
            'left' => \ltrim($s, $chars),
            'right' => \rtrim($s, $chars),
            default => \trim($s, $chars),
        };
    }

    private static function fn_substr(array $a): ?string
    {
        if ($a[0] === null) {
            return null;
        }
        $s = (string) Value::toText($a[0]);
        $len = \mb_strlen($s, 'UTF-8');
        $start = (int) Value::toNumber($a[1] ?? 1);
        // SQLite: 1-based; negative counts from the end.
        if ($start < 0) {
            $start = \max($len + $start, 0);
            $offset = $start;
        } else {
            $offset = $start > 0 ? $start - 1 : 0;
        }
        if (\array_key_exists(2, $a) && $a[2] !== null) {
            $count = (int) Value::toNumber($a[2]);
            if ($count < 0) {
                $offset = \max(0, $offset + $count);
                $count = -$count;
            }
            return \mb_substr($s, $offset, $count, 'UTF-8');
        }
        return \mb_substr($s, $offset, null, 'UTF-8');
    }

    private static function fn_replace(array $a): ?string
    {
        if ($a[0] === null || ($a[1] ?? null) === null || ($a[2] ?? null) === null) {
            return null;
        }
        $search = (string) Value::toText($a[1]);
        if ($search === '') {
            return (string) Value::toText($a[0]);
        }
        return \str_replace($search, (string) Value::toText($a[2]), (string) Value::toText($a[0]));
    }

    private static function fn_instr(array $a): null|int
    {
        if ($a[0] === null || ($a[1] ?? null) === null) {
            return null;
        }
        $hay = (string) Value::toText($a[0]);
        $needle = (string) Value::toText($a[1]);
        if ($needle === '') {
            return 1;
        }
        $pos = \mb_strpos($hay, $needle, 0, 'UTF-8');
        return $pos === false ? 0 : $pos + 1;
    }

    private static function fn_char(array $a): string
    {
        $out = '';
        foreach ($a as $code) {
            $out .= \mb_chr((int) Value::toNumber($code), 'UTF-8');
        }
        return $out;
    }

    private static function fn_unicode(array $a): null|int
    {
        if ($a[0] === null) {
            return null;
        }
        $s = (string) Value::toText($a[0]);
        return $s === '' ? null : \mb_ord(\mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8');
    }

    private static function fn_hex(array $a): string
    {
        $v = $a[0];
        $bytes = $v instanceof Blob ? $v->bytes : (string) Value::toText($v);
        return \strtoupper(\bin2hex($bytes));
    }

    private static function fn_quote(array $a): string
    {
        $v = $a[0];
        if ($v === null) {
            return 'NULL';
        }
        if (\is_int($v) || \is_float($v)) {
            return (string) Value::toText($v);
        }
        if ($v instanceof Blob) {
            return "X'" . \strtoupper(\bin2hex($v->bytes)) . "'";
        }
        return "'" . \str_replace("'", "''", (string) $v) . "'";
    }

    private static function fn_printf(array $a): ?string
    {
        return self::fn_format($a);
    }

    private static function fn_format(array $a): ?string
    {
        if (($a[0] ?? null) === null) {
            return null;
        }
        $fmt = (string) Value::toText($a[0]);
        $args = \array_slice($a, 1);
        $args = \array_map(static function ($v) {
            if ($v instanceof Blob) {
                return $v->bytes;
            }
            return $v;
        }, $args);
        return @\vsprintf($fmt, $args);
    }

    // --- numeric functions ------------------------------------------------

    private static function fn_abs(array $a): null|int|float
    {
        if ($a[0] === null) {
            return null;
        }
        $n = Value::toNumber($a[0]);
        return \abs($n);
    }

    private static function fn_round(array $a): null|float|int
    {
        if ($a[0] === null) {
            return null;
        }
        $n = (float) Value::toNumber($a[0]);
        $digits = isset($a[1]) ? (int) Value::toNumber($a[1]) : 0;
        return \round($n, $digits);
    }

    private static function fn_sign(array $a): null|int
    {
        if ($a[0] === null) {
            return null;
        }
        $n = Value::toNumber($a[0]);
        return $n <=> 0;
    }

    private static function fn_max(array $a): null|int|float|string|Blob
    {
        return self::extreme($a, true);
    }

    private static function fn_min(array $a): null|int|float|string|Blob
    {
        return self::extreme($a, false);
    }

    private static function extreme(array $a, bool $max): null|int|float|string|Blob
    {
        $best = null;
        $have = false;
        foreach ($a as $v) {
            if ($v === null) {
                return null; // scalar min/max returns NULL if any arg is NULL
            }
            if (!$have) {
                $best = $v;
                $have = true;
                continue;
            }
            $cmp = Value::compare($v, $best);
            if (($max && $cmp > 0) || (!$max && $cmp < 0)) {
                $best = $v;
            }
        }
        return $best;
    }

    // --- conditional / type functions -------------------------------------

    private static function fn_coalesce(array $a): null|int|float|string|Blob
    {
        foreach ($a as $v) {
            if ($v !== null) {
                return $v;
            }
        }
        return null;
    }

    private static function fn_ifnull(array $a): null|int|float|string|Blob
    {
        return $a[0] ?? $a[1] ?? null;
    }

    private static function fn_nullif(array $a): null|int|float|string|Blob
    {
        return Value::compare($a[0], $a[1]) === 0 ? null : $a[0];
    }

    private static function fn_typeof(array $a): string
    {
        return match (Value::storageClass($a[0])) {
            Value::CLASS_NULL => 'null',
            Value::CLASS_NUMERIC => \is_int($a[0]) ? 'integer' : 'real',
            Value::CLASS_TEXT => 'text',
            default => 'blob',
        };
    }

    // --- misc -------------------------------------------------------------

    private static function fn_sqlite_version(array $a): string
    {
        return '3.45.0';
    }

    private static function fn_yetisql_version(array $a): string
    {
        return '0.1.0';
    }

    private static function fn_randomblob(array $a): Blob
    {
        $n = \max(1, (int) Value::toNumber($a[0] ?? 1));
        return new Blob(\random_bytes($n));
    }

    private static function fn_zeroblob(array $a): Blob
    {
        $n = \max(0, (int) Value::toNumber($a[0] ?? 0));
        return new Blob(\str_repeat("\x00", $n));
    }

    private static function fn_random(array $a): int
    {
        return \random_int(PHP_INT_MIN, PHP_INT_MAX);
    }

    private static function fn_iif(array $a): null|int|float|string|Blob
    {
        return Value::isTrue($a[0]) ? ($a[1] ?? null) : ($a[2] ?? null);
    }

    // --- JSON1 functions --------------------------------------------------

    /** Minify/validate a JSON document; NULL in, NULL out. */
    private static function fn_json(array $a): ?string
    {
        if (($a[0] ?? null) === null) {
            return null;
        }
        return Json::encode(Json::decode((string) Value::toText($a[0]), true));
    }

    private static function fn_json_valid(array $a): ?int
    {
        if (($a[0] ?? null) === null) {
            return null;
        }
        return Json::isValid((string) Value::toText($a[0])) ? 1 : 0;
    }

    private static function fn_json_type(array $a): ?string
    {
        if (($a[0] ?? null) === null) {
            return null;
        }
        $value = Json::decode((string) Value::toText($a[0]), true);
        if (\array_key_exists(1, $a)) {
            if ($a[1] === null) {
                return null;
            }
            $value = Json::resolve($value, Json::parsePath((string) Value::toText($a[1])));
            if ($value === Json::MISSING) {
                return null;
            }
        }
        return Json::typeName($value);
    }

    /** json_extract(x, path, ...): one path -> SQL value; many -> JSON array. */
    private static function fn_json_extract(array $a): null|int|float|string
    {
        if (($a[0] ?? null) === null) {
            return null;
        }
        $doc = Json::decode((string) Value::toText($a[0]), true);
        $paths = \array_slice($a, 1);
        if ($paths === []) {
            return null;
        }
        if (\count($paths) === 1) {
            if ($paths[0] === null) {
                return null;
            }
            $found = Json::resolve($doc, Json::parsePath((string) Value::toText($paths[0])));
            return $found === Json::MISSING ? null : Json::toSqlValue($found);
        }
        $out = [];
        foreach ($paths as $p) {
            $found = $p === null ? Json::MISSING : Json::resolve($doc, Json::parsePath((string) Value::toText($p)));
            $out[] = $found === Json::MISSING ? null : $found;
        }
        return Json::encode($out);
    }

    private static function fn_json_array(array $a): string
    {
        $out = [];
        foreach ($a as $v) {
            $out[] = Json::sqlToJson($v);
        }
        return Json::encode($out);
    }

    private static function fn_json_object(array $a): string
    {
        $n = \count($a);
        if ($n % 2 !== 0) {
            throw new SqlException('json_object() requires an even number of arguments');
        }
        // Built as text (not via stdClass) so duplicate keys and key order are
        // preserved exactly as SQLite does.
        $parts = [];
        for ($i = 0; $i + 1 < $n; $i += 2) {
            $parts[] = Json::encode((string) Value::toText($a[$i])) . ':' . Json::encode(Json::sqlToJson($a[$i + 1]));
        }
        return '{' . \implode(',', $parts) . '}';
    }

    private static function fn_json_array_length(array $a): ?int
    {
        if (($a[0] ?? null) === null) {
            return null;
        }
        $value = Json::decode((string) Value::toText($a[0]), true);
        if (\array_key_exists(1, $a)) {
            if ($a[1] === null) {
                return null;
            }
            $value = Json::resolve($value, Json::parsePath((string) Value::toText($a[1])));
            if ($value === Json::MISSING) {
                return null;
            }
        }
        return \is_array($value) ? \count($value) : 0;
    }

    /** json_quote(x): render a SQL value as JSON (NULL -> the JSON 'null'). */
    private static function fn_json_quote(array $a): string
    {
        return Json::encode(Json::sqlToJson($a[0] ?? null));
    }

    private static function fn_json_set(array $a): ?string
    {
        return self::jsonMutate($a, 'set');
    }

    private static function fn_json_insert(array $a): ?string
    {
        return self::jsonMutate($a, 'insert');
    }

    private static function fn_json_replace(array $a): ?string
    {
        return self::jsonMutate($a, 'replace');
    }

    /** @param list<null|int|float|string|Blob> $a */
    private static function jsonMutate(array $a, string $mode): ?string
    {
        $n = \count($a);
        if ($n % 2 === 0) {
            throw new SqlException("json_$mode() requires an odd number of arguments");
        }
        if (($a[0] ?? null) === null) {
            return null;
        }
        $doc = Json::decode((string) Value::toText($a[0]));
        for ($i = 1; $i + 1 < $n; $i += 2) {
            if ($a[$i] === null) {
                continue;
            }
            $segments = Json::parsePath((string) Value::toText($a[$i]));
            $doc = Json::setByPath($doc, $segments, Json::sqlToJson($a[$i + 1]), $mode);
        }
        return Json::encode($doc);
    }

    private static function fn_json_remove(array $a): ?string
    {
        if (($a[0] ?? null) === null) {
            return null;
        }
        $doc = Json::decode((string) Value::toText($a[0]));
        foreach (\array_slice($a, 1) as $path) {
            if ($path === null) {
                continue;
            }
            $doc = Json::removeByPath($doc, Json::parsePath((string) Value::toText($path)));
        }
        return Json::encode($doc);
    }

    private static function fn_json_patch(array $a): ?string
    {
        if (($a[0] ?? null) === null || ($a[1] ?? null) === null) {
            return null;
        }
        $target = Json::decode((string) Value::toText($a[0]));
        $patch = Json::decode((string) Value::toText($a[1]));
        return Json::encode(Json::mergePatch($target, $patch));
    }
}
