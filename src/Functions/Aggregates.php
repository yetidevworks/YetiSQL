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

            public function __construct(public string $name)
            {
            }
        };
    }

    /**
     * @param object $acc
     * @param list<null|int|float|string|Blob> $args evaluated argument values
     */
    public static function step(object $acc, array $args, bool $star): void
    {
        $name = $acc->name;
        if ($name === 'count') {
            if ($star || ($args[0] ?? null) !== null) {
                $acc->count++;
            }
            return;
        }

        $v = $args[0] ?? null;
        if ($v === null && !\in_array($name, ['group_concat', 'string_agg'], true)) {
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
        }
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
            default => null,
        };
    }
}
