<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql;

/**
 * A single lexical token. `type` is one of the TYPE_* constants; `value`
 * carries the decoded text (string contents, identifier name, etc.).
 */
final class Token
{
    public const KEYWORD = 'keyword';
    public const IDENT = 'ident';
    public const STRING = 'string';
    public const NUMBER = 'number';
    public const BLOB = 'blob';
    public const PARAM = 'param';
    public const OP = 'op';
    public const PUNCT = 'punct';
    public const EOF = 'eof';

    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly int $pos = 0,
        /** Upper-cased keyword text, for keyword tokens. */
        public readonly string $keyword = '',
    ) {
    }

    public function is(string $type, ?string $value = null): bool
    {
        return $this->type === $type && ($value === null || $this->value === $value);
    }

    public function isKeyword(string $kw): bool
    {
        return $this->type === self::KEYWORD && $this->keyword === $kw;
    }
}
