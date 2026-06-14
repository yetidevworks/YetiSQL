<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Engine;

use YetiDevWorks\YetiSQL\Exception\SqlException;
use YetiDevWorks\YetiSQL\Sql\Ast\CreateIndexStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\CreateTableStatement;
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
        foreach ($this->master->scan() as [$rowid, $payload]) {
            $r = RecordCodec::decode($payload);
            [$type, $name, $tblName, $rootPage, $sql] = [$r[0], $r[1], $r[2], $r[3], $r[4]];
            if ($type === 'table') {
                $this->tables[\strtolower((string) $name)] = $this->buildTableInfo(
                    (string) $sql,
                    (int) $rootPage,
                );
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
            || ($r[0] === 'index' && \strtolower((string) $r[2]) === \strtolower($name)));
        $this->pager->bumpSchemaCookie();
        unset($this->tables[\strtolower($name)]);
        foreach (\array_keys($this->indexes) as $k) {
            if (\strtolower($this->indexes[$k]['table']) === \strtolower($name)) {
                unset($this->indexes[$k]);
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
            $columns[] = new ColumnInfo(
                name: $cd->name,
                declaredType: $cd->typeName,
                affinity: Affinity::fromDeclaredType($cd->typeName),
                notNull: $cd->notNull,
                primaryKey: $cd->primaryKey,
                default: $cd->default,
                collation: $cd->collation ?? 'BINARY',
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

        return new TableInfo(
            name: $ast->name,
            rootPage: $rootPage,
            columns: $columns,
            rowidAlias: $rowidAlias,
            autoincrement: $autoincrement,
            withoutRowid: $ast->withoutRowid,
            sql: $ast->sql,
        );
    }

    private static function isIntegerType(?string $type): bool
    {
        return $type !== null && \strtoupper(\trim($type)) === 'INTEGER';
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
