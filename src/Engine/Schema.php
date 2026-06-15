<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Engine;

use YetiDevWorks\YetiSQL\Exception\SqlException;
use YetiDevWorks\YetiSQL\Sql\Ast\CreateIndexStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\CreateTableStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\CreateTriggerStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\CreateViewStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\SelectStatement;
use YetiDevWorks\YetiSQL\Sql\Parser;
use YetiDevWorks\YetiSQL\Types\Affinity;

/**
 * The schema catalog, persisted in the master table (root page 2, analogous to
 * sqlite_master). Each master row is [type, name, tbl_name, rootpage, sql].
 * On open the catalog is reconstructed by scanning that table and re-parsing
 * the stored CREATE statements into TableInfo metadata.
 */
final class Schema
{
    /** @var array<string,TableInfo> lower-cased table name => info */
    private array $tables = [];
    /** @var array<string,array{name:string,table:string,rootPage:int,sql:string,ast:CreateIndexStatement}> */
    private array $indexes = [];
    /** @var array<string,array{name:string,columns:list<string>,select:SelectStatement,sql:string}> */
    private array $views = [];
    /** @var array<string,CreateTriggerStatement> lower-cased trigger name => AST */
    private array $triggers = [];

    private TableBTree $master;

    public function __construct(private readonly Pager $pager)
    {
        $this->master = new TableBTree($pager, Pager::MASTER_ROOT);
        $this->load();
    }

    private function load(): void
    {
        $this->tables = [];
        $this->indexes = [];
        $this->views = [];
        $this->triggers = [];
        foreach ($this->master->scan() as [$rowid, $payload]) {
            $r = RecordCodec::decode($payload);
            [$type, $name, $tblName, $rootPage, $sql] = [$r[0], $r[1], $r[2], $r[3], $r[4]];
            if ($type === 'table') {
                $this->tables[\strtolower((string) $name)] = $this->buildTableInfo(
                    (string) $sql,
                    (int) $rootPage,
                );
            } elseif ($type === 'trigger') {
                $parser = new Parser((string) $sql);
                /** @var CreateTriggerStatement $ast */
                $ast = $parser->parseStatement();
                $this->triggers[\strtolower((string) $name)] = $ast;
            } elseif ($type === 'view') {
                $parser = new Parser((string) $sql);
                /** @var CreateViewStatement $ast */
                $ast = $parser->parseStatement();
                $this->views[\strtolower((string) $name)] = [
                    'name' => (string) $name,
                    'columns' => $ast->columns,
                    'select' => $ast->select,
                    'sql' => (string) $sql,
                ];
            } elseif ($type === 'index') {
                $parser = new Parser((string) $sql);
                /** @var CreateIndexStatement $ast */
                $ast = $parser->parseStatement();
                $this->indexes[\strtolower((string) $name)] = [
                    'name' => (string) $name,
                    'table' => (string) $tblName,
                    'rootPage' => (int) $rootPage,
                    'sql' => (string) $sql,
                    'ast' => $ast,
                ];
            }
        }
    }

    public function reload(): void
    {
        $this->load();
    }

    public function getTable(string $name): ?TableInfo
    {
        $lc = \strtolower($name);
        if (\in_array($lc, ['sqlite_master', 'sqlite_schema', 'sqlite_temp_master', 'sqlite_temp_schema'], true)) {
            return $this->masterTableInfo();
        }
        return $this->tables[$lc] ?? null;
    }

    /** sqlite_temp_master is always empty (we have no temp tables). */
    public static function isTempMaster(string $name): bool
    {
        $lc = \strtolower($name);
        return $lc === 'sqlite_temp_master' || $lc === 'sqlite_temp_schema';
    }

    private ?TableInfo $masterInfo = null;

    /**
     * Synthetic, read-only view over the master catalog exposed as
     * sqlite_master / sqlite_schema, so SQLite-style introspection works.
     */
    private function masterTableInfo(): TableInfo
    {
        if ($this->masterInfo === null) {
            $cols = [];
            foreach ([['type', 'TEXT'], ['name', 'TEXT'], ['tbl_name', 'TEXT'], ['rootpage', 'INTEGER'], ['sql', 'TEXT']] as [$n, $t]) {
                $cols[] = new ColumnInfo($n, $t, Affinity::fromDeclaredType($t));
            }
            $this->masterInfo = new TableInfo('sqlite_master', Pager::MASTER_ROOT, $cols);
        }
        return $this->masterInfo;
    }

    public function hasTable(string $name): bool
    {
        return isset($this->tables[\strtolower($name)]);
    }

    /** @return list<string> */
    public function tableNames(): array
    {
        return \array_map(static fn (TableInfo $t): string => $t->name, \array_values($this->tables));
    }

    /** @return list<array{name:string,table:string,rootPage:int,sql:string,ast:CreateIndexStatement}> */
    public function indexesForTable(string $table): array
    {
        $out = [];
        foreach ($this->indexes as $idx) {
            if (\strtolower($idx['table']) === \strtolower($table)) {
                $out[] = $idx;
            }
        }
        return $out;
    }

    public function getIndex(string $name): ?array
    {
        return $this->indexes[\strtolower($name)] ?? null;
    }

    /**
     * Resolve every index on a table to IndexInfo (column positions +
     * collations), skipping any whose columns are expressions rather than
     * plain column references (expression indexes are not yet planned).
     *
     * @return list<IndexInfo>
     */
    public function resolvedIndexes(string $table): array
    {
        $info = $this->getTable($table);
        if ($info === null) {
            return [];
        }
        $out = [];
        foreach ($this->indexesForTable($table) as $idx) {
            $positions = [];
            $collations = [];
            $ok = true;
            foreach ($idx['ast']->columns as $term) {
                if ($term->expr->kind !== \YetiDevWorks\YetiSQL\Sql\Ast\Expr::COL) {
                    $ok = false;
                    break;
                }
                $pos = $info->columnPos((string) $term->expr->name);
                if ($pos === null) {
                    $ok = false;
                    break;
                }
                $positions[] = $pos;
                $collations[] = $term->collation ?? $info->columns[$pos]->collation;
            }
            if ($ok && $positions !== []) {
                $out[] = new IndexInfo(
                    $idx['name'],
                    $table,
                    $idx['rootPage'],
                    $positions,
                    $collations,
                    $idx['ast']->unique,
                    $idx['ast']->where,
                );
            }
        }
        return $out;
    }

    public function createTable(CreateTableStatement $stmt): TableInfo
    {
        if ($this->hasTable($stmt->name) || $this->hasView($stmt->name)) {
            if ($stmt->ifNotExists && !$this->hasView($stmt->name)) {
                return $this->getTable($stmt->name);
            }
            throw SqlException::constraint("table {$stmt->name} already exists");
        }
        $rootPage = TableBTree::create($this->pager);
        $info = $this->buildTableInfoFromAst($stmt, $rootPage);

        $rowid = $this->master->maxRowid() + 1;
        $this->master->put($rowid, RecordCodec::encode([
            'table', $stmt->name, $stmt->name, $rootPage, $stmt->sql,
        ]));
        $this->pager->bumpSchemaCookie();
        $this->tables[\strtolower($stmt->name)] = $info;
        return $info;
    }

    public function hasView(string $name): bool
    {
        return isset($this->views[\strtolower($name)]);
    }

    /** @return array{name:string,columns:list<string>,select:SelectStatement,sql:string}|null */
    public function getView(string $name): ?array
    {
        return $this->views[\strtolower($name)] ?? null;
    }

    /** @return list<string> */
    public function viewNames(): array
    {
        return \array_map(static fn (array $v): string => $v['name'], \array_values($this->views));
    }

    public function createView(CreateViewStatement $stmt): void
    {
        if ($this->hasView($stmt->name) || $this->hasTable($stmt->name)) {
            if ($stmt->ifNotExists) {
                return;
            }
            $kind = $this->hasView($stmt->name) ? 'view' : 'table';
            throw SqlException::constraint("{$kind} {$stmt->name} already exists");
        }
        $rowid = $this->master->maxRowid() + 1;
        $this->master->put($rowid, RecordCodec::encode([
            'view', $stmt->name, $stmt->name, 0, $stmt->sql,
        ]));
        $this->pager->bumpSchemaCookie();
        $this->views[\strtolower($stmt->name)] = [
            'name' => $stmt->name,
            'columns' => $stmt->columns,
            'select' => $stmt->select,
            'sql' => $stmt->sql,
        ];
    }

    public function dropView(string $name): void
    {
        $this->deleteMasterRows(static fn (array $r): bool =>
            $r[0] === 'view' && \strtolower((string) $r[1]) === \strtolower($name));
        $this->pager->bumpSchemaCookie();
        unset($this->views[\strtolower($name)]);
    }

    public function hasTrigger(string $name): bool
    {
        return isset($this->triggers[\strtolower($name)]);
    }

    public function hasTriggersOn(string $table): bool
    {
        foreach ($this->triggers as $trg) {
            if (\strcasecmp($trg->table, $table) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Triggers on a table for a given event/timing, in creation order.
     *
     * @return list<CreateTriggerStatement>
     */
    public function triggersFor(string $table, string $event, string $timing): array
    {
        $out = [];
        foreach ($this->triggers as $trg) {
            if (\strcasecmp($trg->table, $table) === 0 && $trg->event === $event && $trg->timing === $timing) {
                $out[] = $trg;
            }
        }
        return $out;
    }

    public function createTrigger(CreateTriggerStatement $stmt): void
    {
        if ($this->hasTrigger($stmt->name)) {
            if ($stmt->ifNotExists) {
                return;
            }
            throw SqlException::constraint("trigger {$stmt->name} already exists");
        }
        if ($this->hasTable($stmt->name) || $this->hasView($stmt->name)) {
            throw SqlException::constraint("there is already an object named {$stmt->name}");
        }
        $rowid = $this->master->maxRowid() + 1;
        $this->master->put($rowid, RecordCodec::encode([
            'trigger', $stmt->name, $stmt->table, 0, $stmt->sql,
        ]));
        $this->pager->bumpSchemaCookie();
        $this->triggers[\strtolower($stmt->name)] = $stmt;
    }

    public function dropTrigger(string $name): void
    {
        $this->deleteMasterRows(static fn (array $r): bool =>
            $r[0] === 'trigger' && \strtolower((string) $r[1]) === \strtolower($name));
        $this->pager->bumpSchemaCookie();
        unset($this->triggers[\strtolower($name)]);
    }

    public function recordIndex(CreateIndexStatement $stmt, int $rootPage): void
    {
        $rowid = $this->master->maxRowid() + 1;
        $this->master->put($rowid, RecordCodec::encode([
            'index', $stmt->name, $stmt->table, $rootPage, $stmt->sql,
        ]));
        $this->pager->bumpSchemaCookie();
        $this->indexes[\strtolower($stmt->name)] = [
            'name' => $stmt->name,
            'table' => $stmt->table,
            'rootPage' => $rootPage,
            'sql' => $stmt->sql,
            'ast' => $stmt,
        ];
    }

    public function dropTable(string $name): void
    {
        $this->deleteMasterRows(static fn (array $r): bool =>
            ($r[0] === 'table' && \strtolower((string) $r[1]) === \strtolower($name))
            || ($r[0] === 'index' && \strtolower((string) $r[2]) === \strtolower($name))
            || ($r[0] === 'trigger' && \strtolower((string) $r[2]) === \strtolower($name)));
        $this->pager->bumpSchemaCookie();
        unset($this->tables[\strtolower($name)]);
        foreach (\array_keys($this->indexes) as $k) {
            if (\strtolower($this->indexes[$k]['table']) === \strtolower($name)) {
                unset($this->indexes[$k]);
            }
        }
        foreach (\array_keys($this->triggers) as $k) {
            if (\strcasecmp($this->triggers[$k]->table, $name) === 0) {
                unset($this->triggers[$k]);
            }
        }
    }

    public function dropIndex(string $name): void
    {
        $this->deleteMasterRows(static fn (array $r): bool =>
            $r[0] === 'index' && \strtolower((string) $r[1]) === \strtolower($name));
        $this->pager->bumpSchemaCookie();
        unset($this->indexes[\strtolower($name)]);
    }

    // --- ALTER TABLE ------------------------------------------------------

    public function renameTable(string $old, string $new): void
    {
        $info = $this->getTable($old);
        if ($info === null) {
            throw new SqlException("no such table: $old");
        }
        if ($this->hasTable($new) || $this->hasView($new)) {
            throw SqlException::constraint("there is already another table or index with this name: $new");
        }
        $ast = $this->parseTableSql($info->sql);
        $ast->name = $new;
        $newSql = $this->serializeCreateTable($ast);
        $ast->sql = $newSql;
        $newInfo = $this->buildTableInfoFromAst($ast, $info->rootPage);

        $this->rewriteMasterRow('table', $old, ['table', $new, $new, $info->rootPage, $newSql]);

        foreach ($this->indexes as $k => $idx) {
            if (\strtolower($idx['table']) !== \strtolower($old)) {
                continue;
            }
            $idx['ast']->table = $new;
            $idx['table'] = $new;
            $idx['sql'] = $this->serializeCreateIndex($idx['ast']);
            $this->indexes[$k] = $idx;
            $this->rewriteMasterRow('index', $idx['name'], ['index', $idx['name'], $new, $idx['rootPage'], $idx['sql']]);
        }

        unset($this->tables[\strtolower($old)]);
        $this->tables[\strtolower($new)] = $newInfo;
        $this->pager->bumpSchemaCookie();
    }

    public function renameColumn(string $table, string $old, string $new): void
    {
        $info = $this->getTable($table);
        if ($info === null) {
            throw new SqlException("no such table: $table");
        }
        if ($info->columnPos($old) === null) {
            throw new SqlException("no such column: \"$old\"");
        }
        if ($info->columnPos($new) !== null) {
            throw SqlException::constraint("duplicate column name: $new");
        }
        $ast = $this->parseTableSql($info->sql);
        foreach ($ast->columns as $cd) {
            if (\strcasecmp($cd->name, $old) === 0) {
                $cd->name = $new;
            }
        }
        $rename = static fn (string $c): string => \strcasecmp($c, $old) === 0 ? $new : $c;
        $ast->primaryKeyColumns = \array_map($rename, $ast->primaryKeyColumns);
        $ast->uniqueConstraints = \array_map(
            static fn (array $grp): array => \array_map($rename, $grp),
            $ast->uniqueConstraints,
        );
        $newSql = $this->serializeCreateTable($ast);
        $ast->sql = $newSql;
        $newInfo = $this->buildTableInfoFromAst($ast, $info->rootPage);

        $this->rewriteMasterRow('table', $table, ['table', $table, $table, $info->rootPage, $newSql]);
        $this->tables[\strtolower($table)] = $newInfo;

        foreach ($this->indexes as $k => $idx) {
            if (\strtolower($idx['table']) !== \strtolower($table)) {
                continue;
            }
            $changed = false;
            foreach ($idx['ast']->columns as $term) {
                if ($term->expr->kind === \YetiDevWorks\YetiSQL\Sql\Ast\Expr::COL
                    && \strcasecmp((string) $term->expr->name, $old) === 0) {
                    $term->expr->name = $new;
                    $changed = true;
                }
            }
            if ($changed) {
                $idx['sql'] = $this->serializeCreateIndex($idx['ast']);
                $this->indexes[$k] = $idx;
                $this->rewriteMasterRow('index', $idx['name'], ['index', $idx['name'], $idx['table'], $idx['rootPage'], $idx['sql']]);
            }
        }
        $this->pager->bumpSchemaCookie();
    }

    public function addColumn(string $table, \YetiDevWorks\YetiSQL\Sql\Ast\ColumnDef $col, bool $tableHasRows = true): void
    {
        $info = $this->getTable($table);
        if ($info === null) {
            throw new SqlException("no such table: $table");
        }
        if ($info->columnPos($col->name) !== null) {
            throw SqlException::constraint("duplicate column name: {$col->name}");
        }
        if ($col->primaryKey) {
            throw new SqlException('Cannot add a PRIMARY KEY column');
        }
        if ($col->unique) {
            throw new SqlException('Cannot add a UNIQUE column');
        }
        // SQLite permits a NOT NULL column without a non-NULL default only when
        // there are no existing rows that the constraint could violate.
        if ($col->notNull && $tableHasRows && $this->defaultIsNull($col)) {
            throw new SqlException('Cannot add a NOT NULL column with default value NULL');
        }
        $ast = $this->parseTableSql($info->sql);
        $ast->columns[] = $col;
        $newSql = $this->serializeCreateTable($ast);
        $ast->sql = $newSql;
        $newInfo = $this->buildTableInfoFromAst($ast, $info->rootPage);

        $this->rewriteMasterRow('table', $table, ['table', $table, $table, $info->rootPage, $newSql]);
        $this->tables[\strtolower($table)] = $newInfo;
        $this->pager->bumpSchemaCookie();
    }

    /** Update the catalog after a column's values have been spliced from every row. */
    public function dropColumn(string $table, string $colName): void
    {
        $info = $this->getTable($table);
        if ($info === null) {
            throw new SqlException("no such table: $table");
        }
        $ast = $this->parseTableSql($info->sql);
        $ast->columns = \array_values(\array_filter(
            $ast->columns,
            static fn (\YetiDevWorks\YetiSQL\Sql\Ast\ColumnDef $c): bool => \strcasecmp($c->name, $colName) !== 0,
        ));
        $newSql = $this->serializeCreateTable($ast);
        $ast->sql = $newSql;
        $newInfo = $this->buildTableInfoFromAst($ast, $info->rootPage);

        $this->rewriteMasterRow('table', $table, ['table', $table, $table, $info->rootPage, $newSql]);
        $this->tables[\strtolower($table)] = $newInfo;
        $this->pager->bumpSchemaCookie();
    }

    private function defaultIsNull(\YetiDevWorks\YetiSQL\Sql\Ast\ColumnDef $col): bool
    {
        if ($col->default === null) {
            return true;
        }
        return $col->default->kind === \YetiDevWorks\YetiSQL\Sql\Ast\Expr::LIT && $col->default->value === null;
    }

    private function parseTableSql(string $sql): CreateTableStatement
    {
        $parser = new Parser($sql);
        /** @var CreateTableStatement $ast */
        $ast = $parser->parseStatement();
        return $ast;
    }

    private function rewriteMasterRow(string $type, string $name, array $newRecord): void
    {
        foreach ($this->master->scan() as [$rowid, $payload]) {
            $r = RecordCodec::decode($payload);
            if ($r[0] === $type && \strtolower((string) $r[1]) === \strtolower($name)) {
                $this->master->put($rowid, RecordCodec::encode($newRecord));
                return;
            }
        }
    }

    // --- SQL serialization (for ALTER round-tripping) ---------------------

    private function serializeCreateTable(CreateTableStatement $ast): string
    {
        $parts = [];
        foreach ($ast->columns as $col) {
            $parts[] = $this->serializeColumnDef($col);
        }
        if ($ast->primaryKeyColumns !== []) {
            $parts[] = 'PRIMARY KEY (' . \implode(', ', \array_map([$this, 'quoteIdent'], $ast->primaryKeyColumns)) . ')';
        }
        foreach ($ast->uniqueConstraints as $grp) {
            $parts[] = 'UNIQUE (' . \implode(', ', \array_map([$this, 'quoteIdent'], $grp)) . ')';
        }
        // FOREIGN KEY and CHECK constraints (the parser merges column-level
        // REFERENCES / CHECK into these statement-level lists, so they are
        // emitted once here as table constraints — never inline per column).
        foreach ($ast->foreignKeys as $fk) {
            $parts[] = $this->serializeForeignKey($fk);
        }
        foreach ($ast->checks as $chk) {
            $c = $chk->name !== null ? 'CONSTRAINT ' . $this->quoteIdent($chk->name) . ' ' : '';
            $parts[] = $c . 'CHECK (' . $chk->sql . ')';
        }
        $sql = 'CREATE TABLE ' . $this->quoteIdent($ast->name) . ' (' . \implode(', ', $parts) . ')';
        if ($ast->withoutRowid) {
            $sql .= ' WITHOUT ROWID';
        }
        return $sql;
    }

    private function serializeColumnDef(\YetiDevWorks\YetiSQL\Sql\Ast\ColumnDef $col): string
    {
        $s = $this->quoteIdent($col->name);
        if ($col->typeName !== null && $col->typeName !== '') {
            $s .= ' ' . $col->typeName;
        }
        if ($col->primaryKey) {
            $s .= ' PRIMARY KEY';
            if ($col->primaryKeyDesc) {
                $s .= ' DESC';
            }
            if ($col->autoincrement) {
                $s .= ' AUTOINCREMENT';
            }
        }
        if ($col->notNull) {
            $s .= ' NOT NULL';
        }
        if ($col->unique) {
            $s .= ' UNIQUE';
        }
        if ($col->defaultSql !== null) {
            $s .= ' DEFAULT ' . $col->defaultSql;
        }
        if ($col->collation !== null) {
            $s .= ' COLLATE ' . $col->collation;
        }
        if ($col->generated !== null && $col->generatedSql !== null) {
            $s .= ' GENERATED ALWAYS AS ' . $col->generatedSql . ($col->generatedStored ? ' STORED' : ' VIRTUAL');
        }
        return $s;
    }

    private function serializeForeignKey(\YetiDevWorks\YetiSQL\Sql\Ast\ForeignKey $fk): string
    {
        $s = 'FOREIGN KEY (' . \implode(', ', \array_map([$this, 'quoteIdent'], $fk->columns)) . ')'
            . ' REFERENCES ' . $this->quoteIdent($fk->refTable);
        if ($fk->refColumns !== []) {
            $s .= ' (' . \implode(', ', \array_map([$this, 'quoteIdent'], $fk->refColumns)) . ')';
        }
        if ($fk->onDelete !== \YetiDevWorks\YetiSQL\Sql\Ast\ForeignKey::NO_ACTION) {
            $s .= ' ON DELETE ' . $fk->onDelete;
        }
        if ($fk->onUpdate !== \YetiDevWorks\YetiSQL\Sql\Ast\ForeignKey::NO_ACTION) {
            $s .= ' ON UPDATE ' . $fk->onUpdate;
        }
        return $s;
    }

    private function serializeCreateIndex(CreateIndexStatement $ast): string
    {
        $cols = [];
        foreach ($ast->columns as $term) {
            $c = $this->exprToSql($term->expr);
            if ($term->desc) {
                $c .= ' DESC';
            }
            $cols[] = $c;
        }
        $sql = 'CREATE ' . ($ast->unique ? 'UNIQUE ' : '') . 'INDEX ' . $this->quoteIdent($ast->name)
            . ' ON ' . $this->quoteIdent($ast->table) . ' (' . \implode(', ', $cols) . ')';
        return $sql;
    }

    private function exprToSql(\YetiDevWorks\YetiSQL\Sql\Ast\Expr $e): string
    {
        return match ($e->kind) {
            \YetiDevWorks\YetiSQL\Sql\Ast\Expr::COL => ($e->table !== null ? $this->quoteIdent($e->table) . '.' : '')
                . $this->quoteIdent((string) $e->name),
            default => throw new SqlException('cannot serialize index expression after ALTER'),
        };
    }

    private function quoteIdent(string $name): string
    {
        return '"' . \str_replace('"', '""', $name) . '"';
    }

    /** @param callable(list<mixed>):bool $match */
    private function deleteMasterRows(callable $match): void
    {
        $toDelete = [];
        foreach ($this->master->scan() as [$rowid, $payload]) {
            if ($match(RecordCodec::decode($payload))) {
                $toDelete[] = $rowid;
            }
        }
        foreach ($toDelete as $rowid) {
            $this->master->delete($rowid);
        }
    }

    public function setTableRootPersisted(string $name, int $newRoot): void
    {
        // Update the rootpage value stored in the master row (used if a table's
        // root ever changes; tables here keep a stable root, so rarely needed).
        foreach ($this->master->scan() as [$rowid, $payload]) {
            $r = RecordCodec::decode($payload);
            if ($r[0] === 'table' && \strtolower((string) $r[1]) === \strtolower($name)) {
                $r[3] = $newRoot;
                $this->master->put($rowid, RecordCodec::encode($r));
                return;
            }
        }
    }

    // --- TableInfo construction ------------------------------------------

    private function buildTableInfo(string $sql, int $rootPage): TableInfo
    {
        $parser = new Parser($sql);
        /** @var CreateTableStatement $ast */
        $ast = $parser->parseStatement();
        return $this->buildTableInfoFromAst($ast, $rootPage);
    }

    private function buildTableInfoFromAst(CreateTableStatement $ast, int $rootPage): TableInfo
    {
        $columns = [];
        foreach ($ast->columns as $cd) {
            $affinity = Affinity::fromDeclaredType($cd->typeName);
            $columns[] = new ColumnInfo(
                name: $cd->name,
                declaredType: $cd->typeName,
                affinity: $affinity,
                notNull: $cd->notNull,
                primaryKey: $cd->primaryKey,
                default: $cd->default,
                collation: $cd->collation ?? 'BINARY',
                defaultValue: $affinity->apply(self::constDefault($cd->default)),
                generated: $cd->generated,
                defaultSql: $cd->defaultSql,
            );
        }

        $rowidAlias = -1;
        $autoincrement = false;
        foreach ($ast->columns as $i => $cd) {
            if ($cd->primaryKey && self::isIntegerType($cd->typeName) && !$cd->primaryKeyDesc) {
                $rowidAlias = $i;
                $autoincrement = $cd->autoincrement;
                break;
            }
        }
        if ($rowidAlias === -1 && \count($ast->primaryKeyColumns) === 1) {
            $pos = self::findColumn($ast, $ast->primaryKeyColumns[0]);
            if ($pos !== null && self::isIntegerType($ast->columns[$pos]->typeName)) {
                $rowidAlias = $pos;
            }
        }

        if ($rowidAlias >= 0) {
            $columns[$rowidAlias]->notNull = true; // rowid is never null
        }

        // Flag columns named in a table-level PRIMARY KEY(...) so PRAGMA
        // table_info reports their `pk` ordinal (SQLite marks these too; the
        // inline integer-PK case is already covered via the rowid alias above).
        // Laravel relies on this when rebuilding a table to preserve its key.
        foreach ($ast->primaryKeyColumns as $pkName) {
            $pos = self::findColumn($ast, $pkName);
            if ($pos !== null) {
                $columns[$pos]->primaryKey = true;
            }
        }

        return new TableInfo(
            name: $ast->name,
            rootPage: $rootPage,
            columns: $columns,
            rowidAlias: $rowidAlias,
            autoincrement: $autoincrement,
            withoutRowid: $ast->withoutRowid,
            sql: $ast->sql,
            foreignKeys: $ast->foreignKeys,
            checks: $ast->checks,
        );
    }

    private static function isIntegerType(?string $type): bool
    {
        return $type !== null && \strtoupper(\trim($type)) === 'INTEGER';
    }

    /**
     * Resolve a column DEFAULT to a constant scalar. Handles the literal forms
     * permitted for ALTER TABLE ADD COLUMN (a literal or a signed number);
     * non-constant defaults resolve to null (never read, since such columns are
     * always materialized at insert time).
     */
    private static function constDefault(?\YetiDevWorks\YetiSQL\Sql\Ast\Expr $e): null|int|float|string|Blob
    {
        if ($e === null) {
            return null;
        }
        $kind = $e->kind;
        if ($kind === \YetiDevWorks\YetiSQL\Sql\Ast\Expr::LIT) {
            return $e->value;
        }
        if ($kind === \YetiDevWorks\YetiSQL\Sql\Ast\Expr::UNARY
            && $e->operand !== null
            && $e->operand->kind === \YetiDevWorks\YetiSQL\Sql\Ast\Expr::LIT
            && (\is_int($e->operand->value) || \is_float($e->operand->value))) {
            $v = $e->operand->value;
            return $e->op === '-' ? -$v : $v;
        }
        return null;
    }

    private static function findColumn(CreateTableStatement $ast, string $name): ?int
    {
        foreach ($ast->columns as $i => $cd) {
            if (\strcasecmp($cd->name, $name) === 0) {
                return $i;
            }
        }
        return null;
    }
}
