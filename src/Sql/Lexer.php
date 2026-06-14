<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql;

use YetiDevWorks\YetiSQL\Exception\SqlException;

/**
 * Hand-written tokenizer for the SQLite SQL dialect.
 *
 * Handles 'text' literals (with '' escapes), "identifier"/[identifier]/`ident`
 * quoting, x'..' blob literals, decimal/hex numbers, ?/:name/@name/$name
 * parameters, comments, and the operator set.
 */
final class Lexer
{
    /** SQLite keyword set (the ones we recognise; unknown words become identifiers). */
    private const KEYWORDS = [
        'ABORT', 'ACTION', 'ADD', 'AFTER', 'ALL', 'ALTER', 'ALWAYS', 'AND', 'AS', 'ASC',
        'ATTACH', 'AUTOINCREMENT', 'BEFORE', 'BEGIN', 'BETWEEN', 'BY', 'CASCADE', 'CASE',
        'CAST', 'CHECK', 'COLLATE', 'COLUMN', 'COMMIT', 'CONFLICT', 'CONSTRAINT', 'CREATE',
        'CROSS', 'CURRENT', 'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'DATABASE',
        'DEFAULT', 'DEFERRABLE', 'DEFERRED', 'DELETE', 'DESC', 'DETACH', 'DISTINCT', 'DROP',
        'EACH', 'ELSE', 'END', 'ESCAPE', 'EXCEPT', 'EXCLUSIVE', 'EXISTS', 'EXPLAIN', 'FAIL',
        'FILTER', 'FOR', 'FOREIGN', 'FROM', 'FULL', 'GENERATED', 'GLOB', 'GROUP', 'HAVING',
        'IF', 'IGNORE', 'IMMEDIATE', 'IN', 'INDEX', 'INDEXED', 'INITIALLY', 'INNER', 'INSERT',
        'INSTEAD', 'INTERSECT', 'INTO', 'IS', 'ISNULL', 'JOIN', 'KEY', 'LEFT', 'LIKE', 'LIMIT',
        'MATCH', 'NATURAL', 'NO', 'NOT', 'NOTNULL', 'NULL', 'NULLS', 'OF', 'OFFSET', 'ON',
        'OR', 'ORDER', 'OUTER', 'OVER', 'PARTITION', 'PLAN', 'PRAGMA', 'PRIMARY', 'QUERY',
        'RAISE', 'REFERENCES', 'REGEXP', 'REINDEX', 'RELEASE', 'RENAME', 'REPLACE', 'RESTRICT',
        'RETURNING', 'RIGHT', 'ROLLBACK', 'ROW', 'ROWID', 'ROWS', 'SAVEPOINT', 'SELECT', 'SET', 'TABLE',
        'TEMP', 'TEMPORARY', 'THEN', 'TO', 'TRANSACTION', 'TRIGGER', 'UNION', 'UNIQUE',
        'UPDATE', 'USING', 'VALUES', 'VIEW', 'VIRTUAL', 'WHEN', 'WHERE', 'WITH', 'WITHOUT',
    ];

    private int $pos = 0;
    private int $len;

    public function __construct(private readonly string $sql)
    {
        $this->len = \strlen($sql);
    }

    /** @return list<Token> */
    public function tokenize(): array
    {
        $tokens = [];
        while (($t = $this->next()) !== null) {
            $tokens[] = $t;
        }
        $tokens[] = new Token(Token::EOF, '', $this->pos);
        return $tokens;
    }

    private function next(): ?Token
    {
        $this->skipWhitespaceAndComments();
        if ($this->pos >= $this->len) {
            return null;
        }

        $start = $this->pos;
        $c = $this->sql[$this->pos];

        // Identifiers and keywords.
        if (\ctype_alpha($c) || $c === '_') {
            while ($this->pos < $this->len
                && (\ctype_alnum($this->sql[$this->pos]) || $this->sql[$this->pos] === '_' || $this->sql[$this->pos] === '$')) {
                $this->pos++;
            }
            $word = \substr($this->sql, $start, $this->pos - $start);
            $upper = \strtoupper($word);
            // x'..' / X'..' blob literal.
            if (($word === 'x' || $word === 'X') && $this->pos < $this->len && $this->sql[$this->pos] === "'") {
                return $this->blobLiteral($start);
            }
            if (\in_array($upper, self::KEYWORDS, true)) {
                return new Token(Token::KEYWORD, $word, $start, $upper);
            }
            return new Token(Token::IDENT, $word, $start);
        }

        // Numbers.
        if (\ctype_digit($c) || ($c === '.' && $this->pos + 1 < $this->len && \ctype_digit($this->sql[$this->pos + 1]))) {
            return $this->number($start);
        }

        // String literal.
        if ($c === "'") {
            return $this->stringLiteral($start);
        }

        // Quoted identifiers.
        if ($c === '"') {
            return new Token(Token::IDENT, $this->quoted('"'), $start);
        }
        if ($c === '`') {
            return new Token(Token::IDENT, $this->quoted('`'), $start);
        }
        if ($c === '[') {
            return new Token(Token::IDENT, $this->quoted(']'), $start);
        }

        // Bind parameters.
        if ($c === '?') {
            $this->pos++;
            while ($this->pos < $this->len && \ctype_digit($this->sql[$this->pos])) {
                $this->pos++;
            }
            return new Token(Token::PARAM, \substr($this->sql, $start, $this->pos - $start), $start);
        }
        if ($c === ':' || $c === '@' || $c === '$') {
            $this->pos++;
            while ($this->pos < $this->len
                && (\ctype_alnum($this->sql[$this->pos]) || $this->sql[$this->pos] === '_')) {
                $this->pos++;
            }
            return new Token(Token::PARAM, \substr($this->sql, $start, $this->pos - $start), $start);
        }

        // Operators and punctuation.
        return $this->operator($start);
    }

    private function skipWhitespaceAndComments(): void
    {
        while ($this->pos < $this->len) {
            $c = $this->sql[$this->pos];
            if (\ctype_space($c)) {
                $this->pos++;
                continue;
            }
            if ($c === '-' && $this->peek(1) === '-') {
                while ($this->pos < $this->len && $this->sql[$this->pos] !== "\n") {
                    $this->pos++;
                }
                continue;
            }
            if ($c === '/' && $this->peek(1) === '*') {
                $this->pos += 2;
                while ($this->pos < $this->len && !($this->sql[$this->pos] === '*' && $this->peek(1) === '/')) {
                    $this->pos++;
                }
                $this->pos += 2;
                continue;
            }
            break;
        }
    }

    private function number(int $start): Token
    {
        if ($this->sql[$this->pos] === '0' && \in_array($this->peek(1), ['x', 'X'], true)) {
            $this->pos += 2;
            while ($this->pos < $this->len && \ctype_xdigit($this->sql[$this->pos])) {
                $this->pos++;
            }
            return new Token(Token::NUMBER, \substr($this->sql, $start, $this->pos - $start), $start);
        }
        while ($this->pos < $this->len && \ctype_digit($this->sql[$this->pos])) {
            $this->pos++;
        }
        if ($this->pos < $this->len && $this->sql[$this->pos] === '.') {
            $this->pos++;
            while ($this->pos < $this->len && \ctype_digit($this->sql[$this->pos])) {
                $this->pos++;
            }
        }
        if ($this->pos < $this->len && \in_array($this->sql[$this->pos], ['e', 'E'], true)) {
            $this->pos++;
            if ($this->pos < $this->len && \in_array($this->sql[$this->pos], ['+', '-'], true)) {
                $this->pos++;
            }
            while ($this->pos < $this->len && \ctype_digit($this->sql[$this->pos])) {
                $this->pos++;
            }
        }
        return new Token(Token::NUMBER, \substr($this->sql, $start, $this->pos - $start), $start);
    }

    private function stringLiteral(int $start): Token
    {
        $this->pos++; // opening quote
        $out = '';
        while ($this->pos < $this->len) {
            $ch = $this->sql[$this->pos];
            if ($ch === "'") {
                if ($this->peek(1) === "'") {
                    $out .= "'";
                    $this->pos += 2;
                    continue;
                }
                $this->pos++;
                return new Token(Token::STRING, $out, $start);
            }
            $out .= $ch;
            $this->pos++;
        }
        throw SqlException::parse('unterminated string literal');
    }

    private function blobLiteral(int $start): Token
    {
        $this->pos++; // opening quote (the x was already consumed)
        $hexStart = $this->pos;
        while ($this->pos < $this->len && $this->sql[$this->pos] !== "'") {
            $this->pos++;
        }
        $hex = \substr($this->sql, $hexStart, $this->pos - $hexStart);
        $this->pos++; // closing quote
        if (\strlen($hex) % 2 !== 0 || \preg_match('/^[0-9a-fA-F]*$/', $hex) !== 1) {
            throw SqlException::parse('malformed blob literal');
        }
        return new Token(Token::BLOB, (string) \hex2bin($hex), $start);
    }

    private function quoted(string $close): string
    {
        $this->pos++; // opening
        $out = '';
        while ($this->pos < $this->len) {
            $ch = $this->sql[$this->pos];
            if ($ch === $close) {
                if ($close !== ']' && $this->peek(1) === $close) {
                    $out .= $close;
                    $this->pos += 2;
                    continue;
                }
                $this->pos++;
                return $out;
            }
            $out .= $ch;
            $this->pos++;
        }
        throw SqlException::parse('unterminated quoted identifier');
    }

    private function operator(int $start): Token
    {
        if (\substr($this->sql, $this->pos, 3) === '->>') {
            $this->pos += 3;
            return new Token(Token::OP, '->>', $start);
        }
        $two = \substr($this->sql, $this->pos, 2);
        if (\in_array($two, ['==', '!=', '<>', '<=', '>=', '||', '<<', '>>', '->'], true)) {
            $this->pos += 2;
            return new Token(Token::OP, $two, $start);
        }
        $c = $this->sql[$this->pos];
        $this->pos++;
        if (\in_array($c, ['(', ')', ',', '.', ';'], true)) {
            return new Token(Token::PUNCT, $c, $start);
        }
        if (\in_array($c, ['=', '<', '>', '+', '-', '*', '/', '%', '&', '|', '~'], true)) {
            return new Token(Token::OP, $c, $start);
        }
        throw SqlException::parse("unexpected character '$c'");
    }

    private function peek(int $ahead): string
    {
        return $this->sql[$this->pos + $ahead] ?? '';
    }
}
