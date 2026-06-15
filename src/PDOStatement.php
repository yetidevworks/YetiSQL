<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL;

use ArrayIterator;
use IteratorAggregate;
use Traversable;
use YetiDevWorks\YetiSQL\Engine\Blob;
use YetiDevWorks\YetiSQL\Engine\Database;
use YetiDevWorks\YetiSQL\Exception\YetiSQLException;
use YetiDevWorks\YetiSQL\Executor\Result;
use YetiDevWorks\YetiSQL\Sql\Ast\Statement;

/**
 * PDOStatement-shaped result/parameter holder. Execution runs the prepared
 * statement(s); fetch* walk the materialised row set with the configured
 * fetch mode.
 *
 * @implements IteratorAggregate<int,mixed>
 */
class PDOStatement implements IteratorAggregate
{
    /** @var array<string,null|int|float|string|Blob> */
    private array $bound = [];
    /**
     * By-reference bindings from bindParam(): the variable is read at execute()
     * time (PDO semantics), not snapshotted at bind time. Each entry holds a
     * live reference plus the bind type.
     *
     * @var array<string,array{ref:mixed,type:int}>
     */
    private array $boundRefs = [];
    private ?Result $result = null;
    private int $cursor = 0;
    private int $fetchMode;

    public string $queryString;

    /** @param list<Statement> $statements */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Database $db,
        string $queryString,
        private readonly array $statements,
        int $defaultFetchMode,
    ) {
        $this->queryString = $queryString;
        $this->fetchMode = $defaultFetchMode;
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $key = $this->normalizeKey($param);
        unset($this->boundRefs[$key]);
        $this->bound[$key] = $this->coerce($value, $type);
        return true;
    }

    public function bindParam(string|int $param, mixed &$variable, int $type = PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool
    {
        // PDO binds by reference: the variable is read at execute() time, not
        // snapshotted here. Hold a live reference and resolve it in execute().
        $key = $this->normalizeKey($param);
        unset($this->bound[$key]);
        $this->boundRefs[$key] = ['type' => $type, 'ref' => null];
        $this->boundRefs[$key]['ref'] = &$variable;
        return true;
    }

    /** @param array<int|string,mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        $bindings = $this->bound;
        // Resolve by-reference bindings now, reading each variable's current value.
        foreach ($this->boundRefs as $key => $r) {
            $bindings[$key] = $this->coerce($r['ref'], $r['type']);
        }
        if ($params !== null) {
            if (\array_is_list($params)) {
                foreach ($params as $i => $v) {
                    $bindings[(string) ($i + 1)] = $this->coerce($v, PDO::PARAM_STR);
                }
            } else {
                foreach ($params as $k => $v) {
                    $bindings[$this->normalizeKey($k)] = $this->coerce($v, PDO::PARAM_STR);
                }
            }
        }

        try {
            $this->result = null;
            foreach ($this->statements as $stmt) {
                $r = $this->db->execute($stmt, $bindings);
                $this->result = $r;
            }
            $this->cursor = 0;
            return true;
        } catch (YetiSQLException $e) {
            $this->pdo->recordError($e);
            if ($this->pdo->errorMode() === PDO::ERRMODE_EXCEPTION) {
                throw $this->pdo->makeException($e);
            }
            return false;
        }
    }

    public function fetch(?int $mode = null, int $cursorOrientation = 0, int $cursorOffset = 0): mixed
    {
        $mode ??= $this->fetchMode;
        if ($this->result === null || !$this->result->isQuery()) {
            return false;
        }
        $row = $this->pullRow();
        if ($row === null) {
            return false;
        }
        $this->cursor++;
        return $this->shape($row, $mode);
    }

    /** @return list<mixed> */
    public function fetchAll(?int $mode = null, mixed ...$args): array
    {
        $mode ??= $this->fetchMode;
        if ($this->result === null || !$this->result->isQuery()) {
            return [];
        }
        $out = [];
        $rows = $this->pullAllRows();
        if ($mode === PDO::FETCH_COLUMN) {
            $col = (int) ($args[0] ?? 0);
            foreach ($rows as $row) {
                $out[] = $this->normalizeOut($row[$col] ?? null);
            }
            return $out;
        }
        if ($mode === PDO::FETCH_KEY_PAIR) {
            $pairs = [];
            foreach ($rows as $row) {
                $pairs[$this->normalizeOut($row[0] ?? null)] = $this->normalizeOut($row[1] ?? null);
            }
            return $pairs;
        }
        foreach ($rows as $row) {
            $out[] = $this->shape($row, $mode);
        }
        return $out;
    }

    /**
     * Pull the next row from the result cursor, routing any engine error raised
     * by lazy per-row evaluation (e.g. an unsupported MATCH) through PDO's error
     * mode — so it surfaces as a PDOException under ERRMODE_EXCEPTION rather than
     * a raw engine exception, matching pdo_sqlite's runtime-error behaviour.
     *
     * @return list<null|int|float|string|Blob>|null
     */
    private function pullRow(): ?array
    {
        try {
            return $this->result?->fetchRow($this->cursor);
        } catch (YetiSQLException $e) {
            $this->failFetch($e);
            return null;
        }
    }

    /** @return list<list<null|int|float|string|Blob>> */
    private function pullAllRows(): array
    {
        try {
            return $this->result?->materializeRows() ?? [];
        } catch (YetiSQLException $e) {
            $this->failFetch($e);
            return [];
        }
    }

    private function failFetch(YetiSQLException $e): void
    {
        $this->pdo->recordError($e);
        if ($this->pdo->errorMode() === PDO::ERRMODE_EXCEPTION) {
            throw $this->pdo->makeException($e);
        }
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if ($this->result === null || !$this->result->isQuery()) {
            return false;
        }
        $row = $this->pullRow();
        if ($row === null) {
            return false;
        }
        $this->cursor++;
        return $this->normalizeOut($row[$column] ?? null);
    }

    public function fetchObject(string $class = 'stdClass', array $constructorArgs = []): object|false
    {
        $assoc = $this->fetch(PDO::FETCH_ASSOC);
        if ($assoc === false) {
            return false;
        }
        if ($class === 'stdClass') {
            return (object) $assoc;
        }
        /** @var object $obj */
        $obj = new $class(...$constructorArgs);
        foreach ($assoc as $k => $v) {
            $obj->$k = $v;
        }
        return $obj;
    }

    public function rowCount(): int
    {
        return $this->result?->rowCount ?? 0;
    }

    public function columnCount(): int
    {
        return $this->result?->columns !== null ? \count($this->result->columns) : 0;
    }

    public function setFetchMode(int $mode, mixed ...$args): bool
    {
        $this->fetchMode = $mode;
        return true;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->fetchAll());
    }

    public function errorCode(): ?string
    {
        return $this->pdo->errorCode();
    }

    /** @return array{0:string,1:?int,2:?string} */
    public function errorInfo(): array
    {
        return $this->pdo->errorInfo();
    }

    // --- shaping ----------------------------------------------------------

    /** @param list<null|int|float|string|Blob> $row */
    private function shape(array $row, int $mode): mixed
    {
        $cols = $this->result->columns ?? [];
        return match ($mode) {
            PDO::FETCH_NUM => \array_map([$this, 'normalizeOut'], $row),
            PDO::FETCH_ASSOC => $this->assoc($cols, $row),
            PDO::FETCH_OBJ, PDO::FETCH_LAZY => (object) $this->assoc($cols, $row),
            PDO::FETCH_COLUMN => $this->normalizeOut($row[0] ?? null),
            default => $this->both($cols, $row), // FETCH_BOTH
        };
    }

    /**
     * @param list<string> $cols
     * @param list<null|int|float|string|Blob> $row
     * @return array<string,mixed>
     */
    private function assoc(array $cols, array $row): array
    {
        $out = [];
        foreach ($cols as $i => $name) {
            $out[$name] = $this->normalizeOut($row[$i] ?? null);
        }
        return $out;
    }

    /**
     * @param list<string> $cols
     * @param list<null|int|float|string|Blob> $row
     * @return array<int|string,mixed>
     */
    private function both(array $cols, array $row): array
    {
        $out = [];
        foreach ($cols as $i => $name) {
            $val = $this->normalizeOut($row[$i] ?? null);
            $out[$i] = $val;
            $out[$name] = $val;
        }
        return $out;
    }

    private function normalizeOut(null|int|float|string|Blob $v): null|int|float|string
    {
        return $v instanceof Blob ? $v->bytes : $v;
    }

    private function normalizeKey(string|int $param): string
    {
        if (\is_int($param)) {
            return (string) $param;
        }
        return \ltrim($param, ':@$');
    }

    private function coerce(mixed $value, int $type): null|int|float|string|Blob
    {
        if ($value === null) {
            return null;
        }
        return match (true) {
            \is_bool($value) => $value ? 1 : 0,
            \is_int($value) => $value,
            \is_float($value) => $value,
            $value instanceof Blob => $value,
            $type === PDO::PARAM_LOB && \is_string($value) => new Blob($value),
            default => (string) $value,
        };
    }
}
