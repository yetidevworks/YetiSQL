<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Functions;

use YetiDevWorks\YetiSQL\Engine\Blob;
use YetiDevWorks\YetiSQL\Exception\SqlException;
use YetiDevWorks\YetiSQL\Types\Value;

/**
 * Shared JSON1 machinery: parse/serialize, type classification, JSONPath
 * resolution, and the structural edits used by the json_* scalar functions,
 * the `->`/`->>` operators, and the json_each/json_tree table-valued functions.
 *
 * Decoded documents use PHP arrays for JSON arrays and stdClass for JSON
 * objects, so an empty array `[]` stays distinct from an empty object `{}`.
 *
 * KNOWN DIVERGENCE: SQLite tags values produced by json() and the arrow
 * operators with a JSON "subtype" so a later json function treats them as JSON
 * rather than text. YetiSQL has no value subtype, so feeding one JSON
 * function's text output into another
 * embeds it as a string (e.g. json_array(json('[1]')) yields ["[1]"], not
 * [[1]]). Plain-column inputs match SQLite. See README "Not yet implemented".
 */
final class Json
{
    /** Sentinel returned by resolve() when a path matches nothing. */
    public const MISSING = "\0__json_missing__\0";

    /**
     * Parse JSON text, throwing the SQLite-style error on malformed input.
     * Cached results must only be used by read-only JSON operations because
     * object nodes are shared.
     */
    public static function decode(string $json, bool $readOnlyCache = false): mixed
    {
        /** @var array<string,mixed> $cache */
        static $cache = [];
        if ($readOnlyCache && \strlen($json) <= 8192 && \array_key_exists($json, $cache)) {
            return $cache[$json];
        }

        $value = \json_decode($json, false);
        if (\json_last_error() !== \JSON_ERROR_NONE) {
            throw new SqlException('malformed JSON');
        }
        if ($readOnlyCache && \strlen($json) <= 8192) {
            if (\count($cache) >= 4096) {
                $cache = [];
            }
            $cache[$json] = $value;
        }
        return $value;
    }

    public static function isValid(string $json): bool
    {
        \json_decode($json, false);
        return \json_last_error() === \JSON_ERROR_NONE;
    }

    /** Serialize a decoded value to SQLite-compatible (compact) JSON text. */
    public static function encode(mixed $value): string
    {
        $json = \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        return $json === false ? 'null' : $json;
    }

    /** SQLite json_type() name for a decoded value. */
    public static function typeName(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            \is_int($value) => 'integer',
            \is_float($value) => 'real',
            \is_string($value) => 'text',
            \is_array($value) => 'array',
            default => 'object',
        };
    }

    /**
     * Render a decoded JSON value as a SQL scalar: scalars come back as native
     * SQL values (true/false -> 1/0), objects and arrays as JSON text. Used by
     * `->>` and single-path json_extract.
     */
    public static function toSqlValue(mixed $value): null|int|float|string
    {
        return match (true) {
            $value === null => null,
            $value === true => 1,
            $value === false => 0,
            \is_int($value), \is_float($value), \is_string($value) => $value,
            default => self::encode($value),
        };
    }

    /**
     * Convert a SQL argument into the JSON value it represents when fed to a
     * JSON constructor/mutator (json_array, json_set, ...). A plain string
     * becomes a JSON string (no subtype tracking — see class note).
     */
    public static function sqlToJson(null|int|float|string|Blob $v): mixed
    {
        return $v instanceof Blob ? $v->bytes : $v;
    }

    /**
     * Parse a JSONPath ("$", "$.a.b", "$[0]", "$.a[#-1]") into a list of
     * segments. Throws on a path that does not begin with "$".
     *
     * @return list<array{kind:string,name?:string,index?:int,fromEnd?:bool}>
     */
    public static function parsePath(string $path): array
    {
        /** @var array<string,list<array{kind:string,name?:string,index?:int,fromEnd?:bool}>> $cache */
        static $cache = [];
        if (isset($cache[$path])) {
            return $cache[$path];
        }
        $segments = self::parsePathUncached($path);
        if (\count($cache) >= 256) {
            $cache = [];
        }
        $cache[$path] = $segments;
        return $segments;
    }

    /**
     * @return list<array{kind:string,name?:string,index?:int,fromEnd?:bool}>
     */
    private static function parsePathUncached(string $path): array
    {
        if ($path === '' || $path[0] !== '$') {
            throw new SqlException('JSON path error');
        }
        $segments = [];
        $i = 1;
        $n = \strlen($path);
        while ($i < $n) {
            $ch = $path[$i];
            if ($ch === '.') {
                $i++;
                if ($i < $n && $path[$i] === '"') {
                    $i++;
                    $start = $i;
                    while ($i < $n && $path[$i] !== '"') {
                        $i++;
                    }
                    if ($i >= $n) {
                        throw new SqlException('JSON path error');
                    }
                    $segments[] = ['kind' => 'key', 'name' => \substr($path, $start, $i - $start)];
                    $i++; // closing quote
                } else {
                    $start = $i;
                    while ($i < $n && $path[$i] !== '.' && $path[$i] !== '[') {
                        $i++;
                    }
                    if ($i === $start) {
                        throw new SqlException('JSON path error');
                    }
                    $segments[] = ['kind' => 'key', 'name' => \substr($path, $start, $i - $start)];
                }
            } elseif ($ch === '[') {
                $i++;
                $start = $i;
                while ($i < $n && $path[$i] !== ']') {
                    $i++;
                }
                if ($i >= $n) {
                    throw new SqlException('JSON path error');
                }
                $segments[] = self::indexSegment(\substr($path, $start, $i - $start));
                $i++; // closing bracket
            } else {
                throw new SqlException('JSON path error');
            }
        }
        return $segments;
    }

    /** @return array{kind:string,index:int,fromEnd:bool} */
    private static function indexSegment(string $token): array
    {
        $token = \trim($token);
        if ($token !== '' && $token[0] === '#') {
            $rest = \substr($token, 1);
            if ($rest === '') {
                return ['kind' => 'index', 'index' => 0, 'fromEnd' => true];
            }
            if (\preg_match('/^-\d+$/', $rest) === 1) {
                // "#-N" counts back from the end: count + (-N) = last-but-(N-1).
                return ['kind' => 'index', 'index' => (int) $rest, 'fromEnd' => true];
            }
            throw new SqlException('JSON path error');
        }
        if (\preg_match('/^\d+$/', $token) !== 1) {
            throw new SqlException('JSON path error');
        }
        return ['kind' => 'index', 'index' => (int) $token, 'fromEnd' => false];
    }

    /**
     * Segments for the `->`/`->>` right operand shorthand: an integer indexes
     * an array; a string beginning with "$" is a full path; any other string is
     * a single object label.
     *
     * @return list<array{kind:string,name?:string,index?:int,fromEnd?:bool}>
     */
    public static function arrowSegments(null|int|float|string|Blob $operand): array
    {
        if (\is_int($operand) || \is_float($operand)) {
            // A negative arrow index counts from the end of the array.
            $idx = (int) $operand;
            return [['kind' => 'index', 'index' => $idx, 'fromEnd' => $idx < 0]];
        }
        $text = (string) Value::toText($operand);
        if ($text !== '' && $text[0] === '$') {
            return self::parsePath($text);
        }
        return [['kind' => 'key', 'name' => $text]];
    }

    /**
     * Walk $value by $segments. Returns the located value, or self::MISSING when
     * a segment does not resolve (used to distinguish a stored JSON null from an
     * absent path).
     *
     * @param list<array{kind:string,name?:string,index?:int,fromEnd?:bool}> $segments
     */
    public static function resolve(mixed $value, array $segments): mixed
    {
        foreach ($segments as $seg) {
            if ($seg['kind'] === 'key') {
                if (!$value instanceof \stdClass || !\property_exists($value, $seg['name'])) {
                    return self::MISSING;
                }
                $value = $value->{$seg['name']};
            } else {
                if (!\is_array($value)) {
                    return self::MISSING;
                }
                $idx = $seg['fromEnd'] ? \count($value) + $seg['index'] : $seg['index'];
                if (!\array_key_exists($idx, $value)) {
                    return self::MISSING;
                }
                $value = $value[$idx];
            }
        }
        return $value;
    }

    /**
     * Apply json_set/json_insert/json_replace semantics. $mode is 'set' (create
     * or overwrite), 'insert' (only if absent), or 'replace' (only if present).
     * Missing intermediate parents make the edit a no-op, matching SQLite.
     *
     * @param list<array{kind:string,name?:string,index?:int,fromEnd?:bool}> $segments
     */
    public static function setByPath(mixed $root, array $segments, mixed $newValue, string $mode): mixed
    {
        if ($segments === []) {
            // Whole-document replace: set/replace overwrite, insert keeps it.
            return $mode === 'insert' ? $root : $newValue;
        }
        return self::editSet($root, $segments, 0, $newValue, $mode);
    }

    /** @param list<array{kind:string,name?:string,index?:int,fromEnd?:bool}> $segments */
    private static function editSet(mixed $node, array $segments, int $depth, mixed $newValue, string $mode): mixed
    {
        $seg = $segments[$depth];
        $last = $depth === \count($segments) - 1;

        if ($seg['kind'] === 'key') {
            if (!$node instanceof \stdClass) {
                return $node; // parent of the wrong type: no-op
            }
            $exists = \property_exists($node, $seg['name']);
            if ($last) {
                if (($mode === 'insert' && $exists) || ($mode === 'replace' && !$exists)) {
                    return $node;
                }
                $node->{$seg['name']} = $newValue;
                return $node;
            }
            if (!$exists) {
                return $node; // intermediate parent missing: no-op
            }
            $node->{$seg['name']} = self::editSet($node->{$seg['name']}, $segments, $depth + 1, $newValue, $mode);
            return $node;
        }

        if (!\is_array($node)) {
            return $node;
        }
        $idx = $seg['fromEnd'] ? \count($node) + $seg['index'] : $seg['index'];
        $exists = \array_key_exists($idx, $node);
        if ($last) {
            if ($mode === 'replace' && !$exists) {
                return $node;
            }
            if ($mode === 'insert' && $exists) {
                return $node;
            }
            if ($exists) {
                $node[$idx] = $newValue;
            } else {
                $node[] = $newValue; // append (index past the end)
            }
            return $node;
        }
        if (!$exists) {
            return $node;
        }
        $node[$idx] = self::editSet($node[$idx], $segments, $depth + 1, $newValue, $mode);
        return $node;
    }

    /**
     * Remove the element at $segments. A missing element is a no-op.
     *
     * @param list<array{kind:string,name?:string,index?:int,fromEnd?:bool}> $segments
     */
    public static function removeByPath(mixed $root, array $segments): mixed
    {
        if ($segments === []) {
            return null; // json_remove(x, '$') removes everything
        }
        return self::editRemove($root, $segments, 0);
    }

    /** @param list<array{kind:string,name?:string,index?:int,fromEnd?:bool}> $segments */
    private static function editRemove(mixed $node, array $segments, int $depth): mixed
    {
        $seg = $segments[$depth];
        $last = $depth === \count($segments) - 1;

        if ($seg['kind'] === 'key') {
            if (!$node instanceof \stdClass || !\property_exists($node, $seg['name'])) {
                return $node;
            }
            if ($last) {
                unset($node->{$seg['name']});
                return $node;
            }
            $node->{$seg['name']} = self::editRemove($node->{$seg['name']}, $segments, $depth + 1);
            return $node;
        }

        if (!\is_array($node)) {
            return $node;
        }
        $idx = $seg['fromEnd'] ? \count($node) + $seg['index'] : $seg['index'];
        if (!\array_key_exists($idx, $node)) {
            return $node;
        }
        if ($last) {
            \array_splice($node, $idx, 1);
            return $node;
        }
        $node[$idx] = self::editRemove($node[$idx], $segments, $depth + 1);
        return $node;
    }

    /** RFC 7396 JSON Merge Patch (json_patch). */
    public static function mergePatch(mixed $target, mixed $patch): mixed
    {
        if (!$patch instanceof \stdClass) {
            return $patch;
        }
        if (!$target instanceof \stdClass) {
            $target = new \stdClass();
        }
        foreach (\get_object_vars($patch) as $key => $value) {
            if ($value === null) {
                unset($target->{$key});
            } else {
                $target->{$key} = self::mergePatch($target->{$key} ?? null, $value);
            }
        }
        return $target;
    }
}
