<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Engine;

use YetiDevWorks\YetiSQL\Executor\Executor;
use YetiDevWorks\YetiSQL\Executor\Result;
use YetiDevWorks\YetiSQL\Sql\Ast\Statement;
use YetiDevWorks\YetiSQL\Sql\Ast\TransactionStatement;
use YetiDevWorks\YetiSQL\Sql\Parser;

/**
 * Top-level database handle: owns the pager and schema catalog, manages
 * autocommit vs explicit transactions, and routes statements to the executor.
 *
 * Autocommit: when no explicit transaction is open, a writing statement is
 * wrapped in its own pager transaction so it commits (and fsyncs) atomically.
 */
final class Database
{
    private readonly Pager $pager;
    private Schema $schema;
    private readonly Executor $executor;

    private bool $autocommit = true;
    /** True when the open transaction was started implicitly by a SAVEPOINT (not BEGIN). */
    private bool $savepointInitiated = false;
    private int $lastInsertId = 0;
    private int $changes = 0;
    private int $totalChanges = 0;

    /** @var array<string,list<Statement>> bounded SQL-text -> parsed program cache */
    private array $parseCache = [];
    private const PARSE_CACHE_MAX = 512;

    public function __construct(string $path)
    {
        $this->pager = new Pager($path);
        $this->schema = new Schema($this->pager);
        $this->executor = new Executor($this);
    }

    /** When true, single-table SELECTs that compile are run through the VDBE VM. */
    private bool $vdbeEnabled = false;

    /** When true, FOREIGN KEY constraints are enforced. SQLite defaults this OFF. */
    private bool $foreignKeysEnabled = false;

    public function pager(): Pager
    {
        return $this->pager;
    }

    public function vdbeEnabled(): bool
    {
        return $this->vdbeEnabled;
    }

    public function setVdbeEnabled(bool $on): void
    {
        $this->vdbeEnabled = $on;
    }

    public function foreignKeysEnabled(): bool
    {
        return $this->foreignKeysEnabled;
    }

    public function setForeignKeysEnabled(bool $on): void
    {
        $this->foreignKeysEnabled = $on;
    }

    public function schema(): Schema
    {
        return $this->schema;
    }

    public function lastInsertId(): int
    {
        return $this->lastInsertId;
    }

    public function setLastInsertId(int $id): void
    {
        $this->lastInsertId = $id;
    }

    public function changes(): int
    {
        return $this->changes;
    }

    public function totalChanges(): int
    {
        return $this->totalChanges;
    }

    public function inTransaction(): bool
    {
        return !$this->autocommit;
    }

    /**
     * Parse SQL into one or more statements, memoizing by exact text. Repeated
     * query()/exec() of the same statement (a very common pattern) then skips the
     * lexer and parser entirely. The parsed AST is immutable across executions —
     * all per-run state lives on the evaluator — so sharing it is safe.
     *
     * @return list<Statement>
     */
    public function parse(string $sql): array
    {
        if (isset($this->parseCache[$sql])) {
            return $this->parseCache[$sql];
        }
        $stmts = (new Parser($sql))->parseProgram();
        if (\count($this->parseCache) >= self::PARSE_CACHE_MAX) {
            $this->parseCache = [];
        }
        return $this->parseCache[$sql] = $stmts;
    }

    public function parseOne(string $sql): Statement
    {
        return (new Parser($sql))->parseStatement();
    }

    /**
     * Execute a single already-parsed statement.
     *
     * @param array<string,null|int|float|string|\YetiDevWorks\YetiSQL\Engine\Blob> $params
     */
    public function execute(Statement $stmt, array $params = []): Result
    {
        if ($stmt instanceof TransactionStatement) {
            return $this->handleTransaction($stmt);
        }

        $writes = $this->executor->isWrite($stmt);

        if ($writes && $this->autocommit && !$this->pager->inTransaction()) {
            $this->pager->beginTransaction();
            // beginTransaction may have re-synced to another process's commit; if
            // that commit changed the schema, rebuild the catalog before running.
            if ($this->pager->takeSchemaChangedFlag()) {
                $this->schema->reload();
            }
            try {
                $result = $this->executor->execute($stmt, $params);
                $this->pager->commit();
            } catch (\Throwable $e) {
                $this->pager->rollback();
                $this->schema->reload();
                throw $e;
            }
        } else {
            // A read outside any transaction: pick up other processes' commits on
            // a reused connection before serving stale cached pages.
            if (!$writes && !$this->pager->inTransaction()) {
                $this->pager->syncForRead();
                if ($this->pager->takeSchemaChangedFlag()) {
                    $this->schema->reload();
                }
            }
            $result = $this->executor->execute($stmt, $params);
        }

        $this->changes = $result->isQuery() ? $this->changes : $result->rowCount;
        if (!$result->isQuery()) {
            $this->totalChanges += $result->rowCount;
        }
        if ($result->lastInsertId !== 0) {
            $this->lastInsertId = $result->lastInsertId;
        }
        return $result;
    }

    private function handleTransaction(TransactionStatement $stmt): Result
    {
        switch ($stmt->action) {
            case TransactionStatement::BEGIN:
                if ($this->autocommit) {
                    $this->pager->beginTransaction();
                    $this->autocommit = false;
                    $this->savepointInitiated = false;
                }
                break;
            case TransactionStatement::COMMIT:
                if (!$this->autocommit) {
                    $this->pager->commit();
                    $this->autocommit = true;
                    $this->savepointInitiated = false;
                }
                break;
            case TransactionStatement::ROLLBACK:
                if ($stmt->savepoint !== null) {
                    // ROLLBACK TO SAVEPOINT: undo to the savepoint, keep the txn open.
                    $this->pager->rollbackToSavepoint($stmt->savepoint);
                    $this->schema->reload();
                } elseif (!$this->autocommit) {
                    $this->pager->rollback();
                    $this->schema->reload();
                    $this->autocommit = true;
                    $this->savepointInitiated = false;
                }
                break;
            case TransactionStatement::SAVEPOINT:
                // A SAVEPOINT outside a transaction starts one implicitly.
                if ($this->autocommit) {
                    $this->pager->beginTransaction();
                    $this->autocommit = false;
                    $this->savepointInitiated = true;
                }
                $this->pager->savepoint((string) $stmt->savepoint);
                break;
            case TransactionStatement::RELEASE:
                $this->pager->releaseSavepoint((string) $stmt->savepoint);
                // Releasing the outermost savepoint of an implicitly-started
                // transaction commits it (SQLite semantics).
                if ($this->savepointInitiated && !$this->pager->hasSavepoints()) {
                    $this->pager->commit();
                    $this->autocommit = true;
                    $this->savepointInitiated = false;
                }
                break;
        }
        return Result::affected(0);
    }

    public function beginTransaction(): void
    {
        if ($this->autocommit) {
            $this->pager->beginTransaction();
            $this->autocommit = false;
            $this->savepointInitiated = false;
        }
    }

    public function commit(): void
    {
        if (!$this->autocommit) {
            $this->pager->commit();
            $this->autocommit = true;
            $this->savepointInitiated = false;
        }
    }

    public function rollback(): void
    {
        if (!$this->autocommit) {
            $this->pager->rollback();
            $this->schema->reload();
            $this->autocommit = true;
            $this->savepointInitiated = false;
        }
    }

    public function close(): void
    {
        $this->pager->close();
    }
}
