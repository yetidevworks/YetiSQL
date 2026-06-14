<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Functions;

use YetiDevWorks\YetiSQL\Engine\Blob;
use YetiDevWorks\YetiSQL\Types\Value;

/**
 * Aggregate function definitions. The executor drives these: it creates an
 * accumulator per group, feeds each row's argument value via step(), then
 * calls finalize() to obtain the group's result.
 */
final class Aggregates
{
    public static function isAggregate(string $name): bool
    {
        return \in_array(\strtolower($name), [
            'count', 'sum', 'total', 'avg', 'min', 'max', 'group_concat', 'string_agg',
            'json_group_array', 'json_group_object',
        ], true);
    }

    public static function newAccumulator(string $name): object
    {
        return new class ($name) {
            public int $count = 0;
            public int|float $sum = 0;
            public bool $sawFloat = false;
            public null|int|float|string|Blob $extreme = null;
            public bool $haveExtreme = false;
            /** @var list<string> */
            public array $parts = [];
            public string $sep = ',';
            public bool $any = false;
            /** @var list<mixed> values for json_group_array */
            public array $jsonArray = [];
            /** @var list<string> pre-encoded "key":value members for json_group_object */
            public array $jsonObject = [];
            /** @var array<string,true> seen argument keys, for DISTINCT aggregates */
            public array $seen = [];

            public function __construct(public string $name)
            {
            }
        };
    }

    /**
     * @param object $acc
     * @param list<null|int|float|string|Blob> $args evaluated argument values
     * @param bool $distinct when true (COUNT/SUM/AVG/... DISTINCT), a repeated
     *        argument value is ignored so each distinct value contributes once
     */
    public static function step(object $acc, array $args, bool $star, bool $distinct = false): void
    {
        // DISTINCT dedup applies to the (single) aggregate argument. NULLs are
        // dropped by each aggregate's own NULL handling below, so they never key
        // the seen-set; keying matches the engine's GROUP BY value identity.
        if ($distinct && !$star) {
            $v0 = $args[0] ?? null;
            if ($v0 !== null) {
                $key = self::distinctKey($v0);
                if (isset($acc->seen[$key])) {
                    return;
                }
                $acc->seen[$key] = true;
            }
        }

        $name = $acc->name;
        if ($name === 'count') {
            if ($star || ($args[0] ?? null) !== null) {
                $acc->count++;
            }
            return;
        }

        $v = $args[0] ?? null;
        if ($v === null && !\in_array($name, ['group_concat', 'string_agg', 'json_group_array', 'json_group_object'], true)) {
            return; // SQLite aggregates ignore NULL inputs
        }

        switch ($name) {
            case 'sum':
            case 'total':
            case 'avg':
                $acc->any = true;
                $n = Value::toNumber($v);
                if (\is_float($n)) {
                    $acc->sawFloat = true;
                }
                $acc->sum += $n;
                $acc->count++;
                break;

            case 'min':
            case 'max':
                if (!$acc->haveExtreme) {
                    $acc->extreme = $v;
                    $acc->haveExtreme = true;
                } else {
                    $cmp = Value::compare($v, $acc->extreme);
                    if (($name === 'max' && $cmp > 0) || ($name === 'min' && $cmp < 0)) {
                        $acc->extreme = $v;
                    }
                }
                break;

            case 'group_concat':
            case 'string_agg':
                if ($v === null) {
                    break;
                }
                $acc->parts[] = (string) Value::toText($v);
                $acc->sep = isset($args[1]) ? (string) Value::toText($args[1]) : ',';
                break;

            case 'json_group_array':
                $acc->any = true;
                $acc->jsonArray[] = Json::sqlToJson($v);
                break;

            case 'json_group_object':
                // Built as text members so duplicate keys are kept (as SQLite does).
                $acc->any = true;
                $acc->jsonObject[] = Json::encode((string) Value::toText($v))
                    . ':' . Json::encode(Json::sqlToJson($args[1] ?? null));
                break;
        }
    }

    /**
     * Type-tagged identity key for DISTINCT dedup. Mirrors the executor's
     * GROUP BY value keying so COUNT(DISTINCT x) agrees with GROUP BY x.
     */
    private static function distinctKey(int|float|string|Blob $v): string
    {
        return match (true) {
            \is_int($v) => 'i' . $v,
            \is_float($v) => 'f' . $v,
            $v instanceof Blob => 'b' . $v->bytes,
            default => 't' . $v,
        };
    }

    public static function finalize(object $acc): null|int|float|string|Blob
    {
        return match ($acc->name) {
            'count' => $acc->count,
            'sum' => $acc->any ? ($acc->sawFloat ? (float) $acc->sum : $acc->sum) : null,
            'total' => (float) $acc->sum,
            'avg' => $acc->count > 0 ? (float) $acc->sum / $acc->count : null,
            'min', 'max' => $acc->haveExtreme ? $acc->extreme : null,
            'group_concat', 'string_agg' => $acc->parts === []
                ? null
                : \implode($acc->sep ?? ',', $acc->parts),
            'json_group_array' => Json::encode($acc->jsonArray),
            'json_group_object' => '{' . \implode(',', $acc->jsonObject) . '}',
            default => null,
        };
    }
}
