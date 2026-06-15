<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Sql;

use YetiDevWorks\YetiSQL\Engine\Blob;
use YetiDevWorks\YetiSQL\Exception\SqlException;
use YetiDevWorks\YetiSQL\Sql\Ast\CheckConstraint;
use YetiDevWorks\YetiSQL\Sql\Ast\ColumnDef;
use YetiDevWorks\YetiSQL\Sql\Ast\ForeignKey;
use YetiDevWorks\YetiSQL\Sql\Ast\AlterTableStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\CreateIndexStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\CreateTableStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\CreateTriggerStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\CreateViewStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\ExplainStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\DeleteStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\DropStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\Expr;
use YetiDevWorks\YetiSQL\Sql\Ast\InsertStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\JoinClause;
use YetiDevWorks\YetiSQL\Sql\Ast\OrderTerm;
use YetiDevWorks\YetiSQL\Sql\Ast\PragmaStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\ResultColumn;
use YetiDevWorks\YetiSQL\Sql\Ast\SelectStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\Statement;
use YetiDevWorks\YetiSQL\Sql\Ast\TableRef;
use YetiDevWorks\YetiSQL\Sql\Ast\TransactionStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\UpdateStatement;
use YetiDevWorks\YetiSQL\Sql\Ast\UpsertClause;

/**
 * Recursive-descent parser for the SQLite SQL dialect, producing the AST in
 * Sql\Ast. Expression parsing follows SQLite's operator precedence.
 */
final class Parser
{
    /** @var list<Token> */
    private array $tokens;
    private int $i = 0;
    private int $paramCounter = 0;

    public function __construct(private readonly string $sql)
    {
        $this->tokens = (new Lexer($sql))->tokenize();
    }

    /** Parse a single statement (ignoring a trailing semicolon). */
    public function parseStatement(): Statement
    {
        $stmt = $this->statement();
        $this->accept(Token::PUNCT, ';');
        if (!$this->peek()->is(Token::EOF)) {
            throw SqlException::parse('unexpected trailing input: ' . $this->peek()->value);
        }
        return $stmt;
    }

    /**
     * Parse one or more semicolon-separated statements.
     *
     * @return list<Statement>
     */
    public function parseProgram(): array
    {
        $stmts = [];
        while (!$this->peek()->is(Token::EOF)) {
            if ($this->accept(Token::PUNCT, ';')) {
                continue;
            }
            $stmts[] = $this->statement();
            $this->accept(Token::PUNCT, ';');
        }
        return $stmts;
    }

    // --- statement dispatch ----------------------------------------------

    private function statement(): Statement
    {
        if ($this->peek()->isKeyword('EXPLAIN')) {
            $this->advance();
            $queryPlan = false;
            if ($this->accept(Token::KEYWORD, 'QUERY')) {
                $this->accept(Token::KEYWORD, 'PLAN');
                $queryPlan = true;
            }
            return new ExplainStatement($this->statement(), $queryPlan);
        }

        $t = $this->peek();
        return match (true) {
            $t->isKeyword('SELECT'), $t->isKeyword('VALUES'), $t->isKeyword('WITH') => $this->selectStatement(),
            $t->isKeyword('CREATE') => $this->createStatement(),
            $t->isKeyword('INSERT'), $t->isKeyword('REPLACE') => $this->insertStatement(),
            $t->isKeyword('UPDATE') => $this->updateStatement(),
            $t->isKeyword('DELETE') => $this->deleteStatement(),
            $t->isKeyword('DROP') => $this->dropStatement(),
            $t->isKeyword('ALTER') => $this->alterStatement(),
            $t->isKeyword('PRAGMA') => $this->pragmaStatement(),
            $t->isKeyword('BEGIN') => $this->beginStatement(),
            $t->isKeyword('COMMIT'), $t->isKeyword('END') => $this->endTransaction(TransactionStatement::COMMIT),
            $t->isKeyword('ROLLBACK') => $this->rollbackStatement(),
            $t->isKeyword('SAVEPOINT') => $this->savepointStatement(),
            $t->isKeyword('RELEASE') => $this->releaseStatement(),
            default => throw SqlException::parse('unrecognized statement: ' . $t->value),
        };
    }

    // --- SELECT ----------------------------------------------------------

    private function selectStatement(): SelectStatement
    {
        $with = [];
        $recursive = false;
        if ($this->accept(Token::KEYWORD, 'WITH')) {
            $recursive = $this->accept(Token::KEYWORD, 'RECURSIVE')
                || $this->accept(Token::IDENT, 'RECURSIVE');
            do {
                $name = $this->name();
                $columns = null;
                if ($this->accept(Token::PUNCT, '(')) {
                    $columns = [];
                    do {
                        $columns[] = $this->name();
                    } while ($this->accept(Token::PUNCT, ','));
                    $this->expect(Token::PUNCT, ')');
                }
                $this->expect(Token::KEYWORD, 'AS');
                $this->expect(Token::PUNCT, '(');
                $cteSelect = $this->selectStatement();
                $this->expect(Token::PUNCT, ')');
                $with[] = ['name' => $name, 'columns' => $columns, 'select' => $cteSelect];
            } while ($this->accept(Token::PUNCT, ','));
        }

        $select = $this->selectCore();

        // Compound operators.
        while (true) {
            $op = null;
            if ($this->peek()->isKeyword('UNION')) {
                $this->advance();
                $op = $this->accept(Token::KEYWORD, 'ALL') ? 'UNION ALL' : 'UNION';
            } elseif ($this->peek()->isKeyword('INTERSECT')) {
                $this->advance();
                $op = 'INTERSECT';
            } elseif ($this->peek()->isKeyword('EXCEPT')) {
                $this->advance();
                $op = 'EXCEPT';
            }
            if ($op === null) {
                break;
            }
            $select->compoundOp = $op;
            $select->compound = $this->selectCore();
            $select = $this->wrapCompound($select);
            break;
        }

        $this->parseOrderLimit($select);
        $select->with = $with;
        $select->recursive = $recursive;
        return $select;
    }

    private function wrapCompound(SelectStatement $left): SelectStatement
    {
        // Keep the linked structure; ORDER BY/LIMIT bind to the whole compound.
        return $left;
    }

    private function selectCore(): SelectStatement
    {
        if ($this->accept(Token::KEYWORD, 'VALUES')) {
            $select = new SelectStatement();
            do {
                $this->expect(Token::PUNCT, '(');
                $row = [];
                if (!$this->peek()->is(Token::PUNCT, ')')) {
                    do {
                        $row[] = $this->expression();
                    } while ($this->accept(Token::PUNCT, ','));
                }
                $this->expect(Token::PUNCT, ')');
                $select->valuesRows[] = $row;
            } while ($this->accept(Token::PUNCT, ','));
            return $select;
        }

        $this->expect(Token::KEYWORD, 'SELECT');
        $select = new SelectStatement();
        if ($this->accept(Token::KEYWORD, 'DISTINCT')) {
            $select->distinct = true;
        } else {
            $this->accept(Token::KEYWORD, 'ALL');
        }

        $select->columns = $this->resultColumns();

        if ($this->accept(Token::KEYWORD, 'FROM')) {
            $select->from = $this->tableRef();
            $select->joins = $this->joins();
        }
        if ($this->accept(Token::KEYWORD, 'WHERE')) {
            $select->where = $this->expression();
        }
        if ($this->accept(Token::KEYWORD, 'GROUP')) {
            $this->expect(Token::KEYWORD, 'BY');
            do {
                $select->groupBy[] = $this->expression();
            } while ($this->accept(Token::PUNCT, ','));
            if ($this->accept(Token::KEYWORD, 'HAVING')) {
                $select->having = $this->expression();
            }
        }
        return $select;
    }

    private function parseOrderLimit(SelectStatement $select): void
    {
        if ($this->accept(Token::KEYWORD, 'ORDER')) {
            $this->expect(Token::KEYWORD, 'BY');
            do {
                $select->orderBy[] = $this->orderTerm();
            } while ($this->accept(Token::PUNCT, ','));
        }
        if ($this->accept(Token::KEYWORD, 'LIMIT')) {
            $select->limit = $this->expression();
            if ($this->accept(Token::KEYWORD, 'OFFSET')) {
                $select->offset = $this->expression();
            } elseif ($this->accept(Token::PUNCT, ',')) {
                // LIMIT offset, count
                $select->offset = $select->limit;
                $select->limit = $this->expression();
            }
        }
    }

    private function orderTerm(): OrderTerm
    {
        $expr = $this->expression();
        $collation = null;
        $desc = false;
        if ($this->accept(Token::KEYWORD, 'ASC')) {
            $desc = false;
        } elseif ($this->accept(Token::KEYWORD, 'DESC')) {
            $desc = true;
        }
        $nullsFirst = null;
        if ($this->accept(Token::KEYWORD, 'NULLS')) {
            $nullsFirst = $this->accept(Token::KEYWORD, 'FIRST');
            if (!$nullsFirst) {
                $this->expect(Token::KEYWORD, 'LAST');
            }
        }
        return new OrderTerm($expr, $desc, $collation, $nullsFirst);
    }

    /** @return list<ResultColumn> */
    private function resultColumns(): array
    {
        $cols = [];
        do {
            if ($this->accept(Token::OP, '*')) {
                $cols[] = new ResultColumn(star: true);
                continue;
            }
            // table.* form
            if ($this->peek()->is(Token::IDENT) && $this->peekAt(1)->is(Token::PUNCT, '.') && $this->peekAt(2)->is(Token::OP, '*')) {
                $table = $this->advance()->value;
                $this->advance(); // .
                $this->advance(); // *
                $cols[] = new ResultColumn(tableStar: $table);
                continue;
            }
            $expr = $this->expression();
            $alias = $this->optionalAlias();
            $cols[] = new ResultColumn(expr: $expr, alias: $alias);
        } while ($this->accept(Token::PUNCT, ','));
        return $cols;
    }

    private function optionalAlias(): ?string
    {
        if ($this->accept(Token::KEYWORD, 'AS')) {
            return $this->name();
        }
        // Bare alias: an identifier or string that isn't a clause keyword.
        $t = $this->peek();
        if ($t->is(Token::IDENT) || $t->is(Token::STRING)) {
            return $this->advance()->value;
        }
        if ($t->type === Token::KEYWORD && !$this->isClauseKeyword($t->keyword)) {
            return $this->advance()->value;
        }
        return null;
    }

    private function isClauseKeyword(string $kw): bool
    {
        return \in_array($kw, [
            'FROM', 'WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT', 'OFFSET',
            'UNION', 'INTERSECT', 'EXCEPT', 'JOIN', 'INNER', 'LEFT', 'RIGHT',
            'FULL', 'CROSS', 'NATURAL', 'ON', 'USING', 'AS', 'AND', 'OR',
            'WHEN', 'THEN', 'ELSE', 'END', 'COLLATE', 'ASC', 'DESC', 'RETURNING',
        ], true);
    }

    /** Parse a trailing `RETURNING <result-columns>` clause, or null if absent. */
    private function returningClause(): ?array
    {
        if (!$this->accept(Token::KEYWORD, 'RETURNING')) {
            return null;
        }
        return $this->resultColumns();
    }

    private function tableRef(): TableRef
    {
        if ($this->accept(Token::PUNCT, '(')) {
            $sub = $this->selectStatement();
            $this->expect(Token::PUNCT, ')');
            $alias = $this->optionalAlias();
            return new TableRef(alias: $alias, subquery: $sub);
        }
        $name = $this->name();
        // schema-qualified: db.table
        if ($this->accept(Token::PUNCT, '.')) {
            $name = $this->name();
        }
        // Table-valued function, e.g. pragma_table_info('users').
        if ($this->peek()->is(Token::PUNCT, '(')) {
            $this->advance();
            $args = [];
            if (!$this->peek()->is(Token::PUNCT, ')')) {
                do {
                    $args[] = $this->expression();
                } while ($this->accept(Token::PUNCT, ','));
            }
            $this->expect(Token::PUNCT, ')');
            $alias = $this->optionalAlias();
            return new TableRef(alias: $alias, func: $name, funcArgs: $args);
        }
        $alias = $this->optionalAlias();
        return new TableRef(name: $name, alias: $alias);
    }

    /** @return list<JoinClause> */
    private function joins(): array
    {
        $joins = [];
        while (true) {
            // Comma join: implicit CROSS JOIN, no JOIN keyword or ON/USING.
            if ($this->accept(Token::PUNCT, ',')) {
                $joins[] = new JoinClause(JoinClause::CROSS, $this->tableRef());
                continue;
            }

            $natural = $this->accept(Token::KEYWORD, 'NATURAL');
            $type = JoinClause::INNER;
            if ($this->accept(Token::KEYWORD, 'CROSS')) {
                $type = JoinClause::CROSS;
            } elseif ($this->accept(Token::KEYWORD, 'LEFT')) {
                $this->accept(Token::KEYWORD, 'OUTER');
                $type = JoinClause::LEFT;
            } elseif ($this->accept(Token::KEYWORD, 'INNER')) {
                $type = JoinClause::INNER;
            } elseif (!$this->peek()->isKeyword('JOIN')) {
                if ($natural) {
                    throw SqlException::parse('expected JOIN after NATURAL');
                }
                break;
            }
            $this->expect(Token::KEYWORD, 'JOIN');
            $table = $this->tableRef();
            $on = null;
            $using = [];
            if ($this->accept(Token::KEYWORD, 'ON')) {
                $on = $this->expression();
            } elseif ($this->accept(Token::KEYWORD, 'USING')) {
                $this->expect(Token::PUNCT, '(');
                do {
                    $using[] = $this->name();
                } while ($this->accept(Token::PUNCT, ','));
                $this->expect(Token::PUNCT, ')');
            }
            $joins[] = new JoinClause($type, $table, $on, $using, $natural);
        }
        return $joins;
    }

    // --- CREATE ----------------------------------------------------------

    private function createStatement(): Statement
    {
        $start = $this->peek()->pos;
        $this->expect(Token::KEYWORD, 'CREATE');
        $temporary = $this->accept(Token::KEYWORD, 'TEMP')
            || $this->accept(Token::KEYWORD, 'TEMPORARY');

        if ($this->accept(Token::KEYWORD, 'TRIGGER')) {
            return $this->createTriggerBody($start);
        }
        $unique = $this->accept(Token::KEYWORD, 'UNIQUE');
        if ($this->accept(Token::KEYWORD, 'INDEX')) {
            return $this->createIndexBody($unique, $start);
        }
        if ($this->accept(Token::KEYWORD, 'VIEW')) {
            return $this->createViewBody($start, $temporary);
        }
        $this->expect(Token::KEYWORD, 'TABLE');
        return $this->createTableBody($start);
    }

    private function createTriggerBody(int $start): CreateTriggerStatement
    {
        $ifNotExists = false;
        if ($this->accept(Token::KEYWORD, 'IF')) {
            $this->expect(Token::KEYWORD, 'NOT');
            $this->expect(Token::KEYWORD, 'EXISTS');
            $ifNotExists = true;
        }
        $name = $this->qualifiedName();

        // Timing: BEFORE | AFTER | INSTEAD OF (defaults to BEFORE when omitted).
        $timing = CreateTriggerStatement::BEFORE;
        if ($this->accept(Token::KEYWORD, 'BEFORE')) {
            $timing = CreateTriggerStatement::BEFORE;
        } elseif ($this->accept(Token::KEYWORD, 'AFTER')) {
            $timing = CreateTriggerStatement::AFTER;
        } elseif ($this->accept(Token::KEYWORD, 'INSTEAD')) {
            $this->expect(Token::KEYWORD, 'OF');
            $timing = CreateTriggerStatement::INSTEAD_OF;
        }

        // Event: DELETE | INSERT | UPDATE [OF col, ...].
        $updateOf = [];
        if ($this->accept(Token::KEYWORD, 'DELETE')) {
            $event = CreateTriggerStatement::DELETE;
        } elseif ($this->accept(Token::KEYWORD, 'INSERT')) {
            $event = CreateTriggerStatement::INSERT;
        } elseif ($this->accept(Token::KEYWORD, 'UPDATE')) {
            $event = CreateTriggerStatement::UPDATE;
            if ($this->accept(Token::KEYWORD, 'OF')) {
                do {
                    $updateOf[] = $this->name();
                } while ($this->accept(Token::PUNCT, ','));
            }
        } else {
            throw SqlException::parse('expected DELETE, INSERT or UPDATE in CREATE TRIGGER');
        }

        $this->expect(Token::KEYWORD, 'ON');
        $table = $this->qualifiedName();

        if ($this->accept(Token::KEYWORD, 'FOR')) {
            $this->expect(Token::KEYWORD, 'EACH');
            $this->expect(Token::KEYWORD, 'ROW');
        }

        $when = null;
        if ($this->accept(Token::KEYWORD, 'WHEN')) {
            $when = $this->expression();
        }

        $this->expect(Token::KEYWORD, 'BEGIN');
        $body = [];
        while (!$this->peek()->isKeyword('END') && !$this->peek()->is(Token::EOF)) {
            $body[] = $this->statement();
            $this->accept(Token::PUNCT, ';');
        }
        $this->expect(Token::KEYWORD, 'END');

        $sql = \rtrim(\substr($this->sql, $start, $this->peek()->pos - $start));
        return new CreateTriggerStatement(
            $name,
            $timing,
            $event,
            $table,
            $updateOf,
            $when,
            $body,
            $ifNotExists,
            $sql,
        );
    }

    private function createViewBody(int $start, bool $temporary): CreateViewStatement
    {
        $ifNotExists = false;
        if ($this->accept(Token::KEYWORD, 'IF')) {
            $this->expect(Token::KEYWORD, 'NOT');
            $this->expect(Token::KEYWORD, 'EXISTS');
            $ifNotExists = true;
        }
        $name = $this->qualifiedName();

        $columns = [];
        if ($this->accept(Token::PUNCT, '(')) {
            do {
                $columns[] = $this->name();
            } while ($this->accept(Token::PUNCT, ','));
            $this->expect(Token::PUNCT, ')');
        }

        $this->expect(Token::KEYWORD, 'AS');
        $select = $this->selectStatement();

        $sql = \rtrim(\substr($this->sql, $start, $this->peek()->pos - $start));
        return new CreateViewStatement($name, $select, $columns, $ifNotExists, $temporary, $sql);
    }

    private function createTableBody(int $start): CreateTableStatement
    {
        $ifNotExists = false;
        if ($this->accept(Token::KEYWORD, 'IF')) {
            $this->expect(Token::KEYWORD, 'NOT');
            $this->expect(Token::KEYWORD, 'EXISTS');
            $ifNotExists = true;
        }
        $name = $this->qualifiedName();
        $stmt = new CreateTableStatement($name, ifNotExists: $ifNotExists);

        $this->expect(Token::PUNCT, '(');
        do {
            if ($this->isTableConstraintStart()) {
                $this->tableConstraint($stmt);
            } else {
                $stmt->columns[] = $this->columnDef();
            }
        } while ($this->accept(Token::PUNCT, ','));
        $this->expect(Token::PUNCT, ')');

        foreach ($stmt->columns as $cd) {
            if ($cd->reference !== null) {
                $stmt->foreignKeys[] = $cd->reference;
            }
            foreach ($cd->checks as $check) {
                $stmt->checks[] = $check;
            }
        }

        if ($this->accept(Token::KEYWORD, 'WITHOUT')) {
            $this->expect(Token::KEYWORD, 'ROWID');
            $stmt->withoutRowid = true;
        }
        $this->accept(Token::IDENT, 'STRICT'); // accept & ignore STRICT for now

        $stmt->sql = \rtrim(\substr($this->sql, $start, $this->peek()->pos - $start));
        return $stmt;
    }

    private function isTableConstraintStart(): bool
    {
        $kw = $this->peek()->keyword;
        return \in_array($kw, ['PRIMARY', 'UNIQUE', 'CHECK', 'FOREIGN', 'CONSTRAINT'], true);
    }

    private function tableConstraint(CreateTableStatement $stmt): void
    {
        $constraintName = null;
        if ($this->accept(Token::KEYWORD, 'CONSTRAINT')) {
            $constraintName = $this->name();
        }
        if ($this->accept(Token::KEYWORD, 'PRIMARY')) {
            $this->expect(Token::KEYWORD, 'KEY');
            $stmt->primaryKeyColumns = $this->parenColumnList();
            $this->consumeConflictClause();
        } elseif ($this->accept(Token::KEYWORD, 'UNIQUE')) {
            $stmt->uniqueConstraints[] = $this->parenColumnList();
            $this->consumeConflictClause();
        } elseif ($this->accept(Token::KEYWORD, 'CHECK')) {
            $stmt->checks[] = $this->checkConstraint($constraintName);
        } elseif ($this->accept(Token::KEYWORD, 'FOREIGN')) {
            $this->expect(Token::KEYWORD, 'KEY');
            $childCols = $this->parenColumnList();
            $this->expect(Token::KEYWORD, 'REFERENCES');
            $stmt->foreignKeys[] = $this->referencesClause($childCols);
        }
    }

    /** Parse `( <expr> )` after a CHECK keyword, capturing the expr's SQL text. */
    private function checkConstraint(?string $name): CheckConstraint
    {
        $this->expect(Token::PUNCT, '(');
        $start = $this->peek()->pos;
        $expr = $this->expression();
        $sql = \rtrim(\substr($this->sql, $start, $this->peek()->pos - $start));
        $this->expect(Token::PUNCT, ')');
        return new CheckConstraint($expr, $sql, $name);
    }

    /** @return list<string> */
    private function parenColumnList(): array
    {
        $this->expect(Token::PUNCT, '(');
        $cols = [];
        do {
            $cols[] = $this->name();
            $this->accept(Token::KEYWORD, 'ASC');
            $this->accept(Token::KEYWORD, 'DESC');
        } while ($this->accept(Token::PUNCT, ','));
        $this->expect(Token::PUNCT, ')');
        return $cols;
    }

    private function columnDef(): ColumnDef
    {
        $name = $this->name();
        $type = $this->typeName();
        $col = new ColumnDef($name, $type);

        while (true) {
            if ($this->accept(Token::KEYWORD, 'CONSTRAINT')) {
                $this->name();
            }
            if ($this->accept(Token::KEYWORD, 'PRIMARY')) {
                $this->expect(Token::KEYWORD, 'KEY');
                $col->primaryKey = true;
                if ($this->accept(Token::KEYWORD, 'DESC')) {
                    $col->primaryKeyDesc = true;
                } else {
                    $this->accept(Token::KEYWORD, 'ASC');
                }
                $col->autoincrement = $this->accept(Token::KEYWORD, 'AUTOINCREMENT');
                $this->consumeConflictClause();
            } elseif ($this->accept(Token::KEYWORD, 'NOT')) {
                $this->expect(Token::KEYWORD, 'NULL');
                $col->notNull = true;
                $this->consumeConflictClause();
            } elseif ($this->accept(Token::KEYWORD, 'NULL')) {
                // explicit NULL (no-op)
                $this->consumeConflictClause();
            } elseif ($this->accept(Token::KEYWORD, 'UNIQUE')) {
                $col->unique = true;
                $this->consumeConflictClause();
            } elseif ($this->accept(Token::KEYWORD, 'DEFAULT')) {
                $dstart = $this->peek()->pos;
                $col->default = $this->defaultValue();
                $col->defaultSql = \rtrim(\substr($this->sql, $dstart, $this->peek()->pos - $dstart));
            } elseif ($this->accept(Token::KEYWORD, 'COLLATE')) {
                $col->collation = $this->name();
            } elseif ($this->accept(Token::KEYWORD, 'CHECK')) {
                $col->checks[] = $this->checkConstraint(null);
            } elseif ($this->accept(Token::KEYWORD, 'REFERENCES')) {
                $col->reference = $this->referencesClause([$name]);
            } elseif ($this->accept(Token::KEYWORD, 'GENERATED')) {
                $this->expect(Token::KEYWORD, 'ALWAYS');
                $this->expect(Token::KEYWORD, 'AS');
                $gstart = $this->peek()->pos;
                $this->expect(Token::PUNCT, '(');
                $col->generated = $this->expression();
                $this->expect(Token::PUNCT, ')');
                $col->generatedSql = \rtrim(\substr($this->sql, $gstart, $this->peek()->pos - $gstart));
                $col->generatedStored = $this->accept(Token::IDENT, 'STORED');
                $this->accept(Token::IDENT, 'VIRTUAL');
            } elseif ($this->accept(Token::KEYWORD, 'AS')) {
                $gstart = $this->peek()->pos;
                $this->expect(Token::PUNCT, '(');
                $col->generated = $this->expression();
                $this->expect(Token::PUNCT, ')');
                $col->generatedSql = \rtrim(\substr($this->sql, $gstart, $this->peek()->pos - $gstart));
                $col->generatedStored = $this->accept(Token::IDENT, 'STORED');
                $this->accept(Token::IDENT, 'VIRTUAL');
            } else {
                break;
            }
        }
        return $col;
    }

    private function defaultValue(): Expr
    {
        if ($this->accept(Token::PUNCT, '(')) {
            $e = $this->expression();
            $this->expect(Token::PUNCT, ')');
            return $e;
        }
        // signed numeric or literal or keyword constants
        if ($this->peek()->is(Token::OP, '-') || $this->peek()->is(Token::OP, '+')) {
            $op = $this->advance()->value;
            $lit = $this->primary();
            return Expr::unary($op, $lit);
        }
        return $this->primary();
    }

    private function consumeConflictClause(): void
    {
        if ($this->accept(Token::KEYWORD, 'ON')) {
            $this->expect(Token::KEYWORD, 'CONFLICT');
            $this->advance(); // ROLLBACK/ABORT/FAIL/IGNORE/REPLACE
        }
    }

    /**
     * Parse a REFERENCES clause (the REFERENCES keyword has already been consumed for
     * table-level FOREIGN KEY, and is consumed by the caller for column-level too).
     *
     * @param list<string> $childCols
     */
    private function referencesClause(array $childCols): ForeignKey
    {
        $refTable = $this->name();
        $refCols = [];
        if ($this->peek()->is(Token::PUNCT, '(')) {
            $refCols = $this->parenColumnList();
        }

        $onDelete = ForeignKey::NO_ACTION;
        $onUpdate = ForeignKey::NO_ACTION;
        while (true) {
            $v = \strtoupper($this->peek()->value);
            if ($v === 'ON') {
                $this->advance();
                $which = \strtoupper($this->advance()->value); // DELETE or UPDATE
                $action = $this->foreignKeyAction();
                if ($which === 'UPDATE') {
                    $onUpdate = $action;
                } else {
                    $onDelete = $action;
                }
            } elseif ($v === 'MATCH') {
                $this->advance();
                $this->advance(); // match-type name, ignored (treated as MATCH SIMPLE)
            } else {
                break;
            }
        }
        // Trailing [NOT] DEFERRABLE [INITIALLY DEFERRED|IMMEDIATE] — accepted, enforced immediately.
        $this->skipForeignKeyClause();

        return new ForeignKey($childCols, $refTable, $refCols, $onDelete, $onUpdate);
    }

    private function foreignKeyAction(): string
    {
        $v = \strtoupper($this->advance()->value);
        if ($v === 'SET') {
            $n = \strtoupper($this->advance()->value); // NULL or DEFAULT
            return $n === 'DEFAULT' ? ForeignKey::SET_DEFAULT : ForeignKey::SET_NULL;
        }
        if ($v === 'NO') {
            $this->advance(); // ACTION
            return ForeignKey::NO_ACTION;
        }
        if ($v === 'CASCADE') {
            return ForeignKey::CASCADE;
        }
        return ForeignKey::RESTRICT;
    }

    private function skipForeignKeyClause(): void
    {
        // Consume ON DELETE/UPDATE ..., MATCH ..., DEFERRABLE ... until a comma or ).
        while (true) {
            $t = $this->peek();
            if ($t->is(Token::PUNCT, ',') || $t->is(Token::PUNCT, ')') || $t->is(Token::EOF)) {
                return;
            }
            $this->advance();
        }
    }

    private function typeName(): ?string
    {
        $parts = [];
        while (true) {
            $t = $this->peek();
            if ($t->is(Token::IDENT)) {
                $parts[] = $this->advance()->value;
            } elseif ($t->type === Token::KEYWORD && $this->isTypeWord($t->keyword)) {
                $parts[] = $this->advance()->value;
            } else {
                break;
            }
            if ($this->peek()->is(Token::PUNCT, '(')) {
                $this->advance();
                // numeric precision args, ignored for affinity
                while (!$this->peek()->is(Token::PUNCT, ')') && !$this->peek()->is(Token::EOF)) {
                    $this->advance();
                }
                $this->expect(Token::PUNCT, ')');
                break;
            }
        }
        return $parts === [] ? null : \implode(' ', $parts);
    }

    private function isTypeWord(string $kw): bool
    {
        // Words that legitimately appear inside a column type and would otherwise
        // be lexed as keywords.
        return \in_array($kw, ['NULL', 'DEFAULT'], true) === false
            && !\in_array($kw, [
                'PRIMARY', 'NOT', 'UNIQUE', 'CHECK', 'DEFAULT', 'COLLATE',
                'REFERENCES', 'GENERATED', 'AS', 'CONSTRAINT', 'KEY',
            ], true);
    }

    private function createIndexBody(bool $unique, int $start): CreateIndexStatement
    {
        $ifNotExists = false;
        if ($this->accept(Token::KEYWORD, 'IF')) {
            $this->expect(Token::KEYWORD, 'NOT');
            $this->expect(Token::KEYWORD, 'EXISTS');
            $ifNotExists = true;
        }
        $name = $this->qualifiedName();
        $this->expect(Token::KEYWORD, 'ON');
        $table = $this->name();
        $this->expect(Token::PUNCT, '(');
        $columns = [];
        do {
            $expr = $this->expression();
            $desc = false;
            if ($this->accept(Token::KEYWORD, 'ASC')) {
                $desc = false;
            } elseif ($this->accept(Token::KEYWORD, 'DESC')) {
                $desc = true;
            }
            $columns[] = new OrderTerm($expr, $desc);
        } while ($this->accept(Token::PUNCT, ','));
        $this->expect(Token::PUNCT, ')');
        $where = null;
        if ($this->accept(Token::KEYWORD, 'WHERE')) {
            $where = $this->expression();
        }
        $stmt = new CreateIndexStatement($name, $table, $columns, $unique, $ifNotExists, $where);
        $stmt->sql = \rtrim(\substr($this->sql, $start, $this->peek()->pos - $start));
        return $stmt;
    }

    // --- INSERT / UPDATE / DELETE ----------------------------------------

    private function insertStatement(): InsertStatement
    {
        $orReplace = false;
        $orIgnore = false;
        if ($this->accept(Token::KEYWORD, 'REPLACE')) {
            $orReplace = true;
        } else {
            $this->expect(Token::KEYWORD, 'INSERT');
            if ($this->accept(Token::KEYWORD, 'OR')) {
                if ($this->accept(Token::KEYWORD, 'REPLACE')) {
                    $orReplace = true;
                } elseif ($this->accept(Token::KEYWORD, 'IGNORE')) {
                    $orIgnore = true;
                } else {
                    $this->advance(); // ABORT/FAIL/ROLLBACK
                }
            }
        }
        $this->expect(Token::KEYWORD, 'INTO');
        $table = $this->qualifiedName();
        $this->accept(Token::KEYWORD, 'AS') && $this->name();

        $columns = null;
        if ($this->peek()->is(Token::PUNCT, '(')) {
            $columns = $this->parenColumnList();
        }

        $stmt = new InsertStatement($table, $columns, orReplace: $orReplace, orIgnore: $orIgnore);

        if ($this->accept(Token::KEYWORD, 'DEFAULT')) {
            $this->expect(Token::KEYWORD, 'VALUES');
            $stmt->defaultValues = true;
            $stmt->returning = $this->returningClause();
            return $stmt;
        }
        if ($this->accept(Token::KEYWORD, 'VALUES')) {
            do {
                $this->expect(Token::PUNCT, '(');
                $row = [];
                do {
                    $row[] = $this->expression();
                } while ($this->accept(Token::PUNCT, ','));
                $this->expect(Token::PUNCT, ')');
                $stmt->rows[] = $row;
            } while ($this->accept(Token::PUNCT, ','));
        } else {
            $stmt->select = $this->selectStatement();
        }
        if ($this->accept(Token::KEYWORD, 'ON')) {
            $stmt->upsert = $this->upsertClause();
        }
        $stmt->returning = $this->returningClause();
        return $stmt;
    }

    /**
     * Parse the ON CONFLICT upsert tail (the leading ON keyword is already
     * consumed): CONFLICT [(cols) [WHERE expr]] DO (NOTHING | UPDATE SET ... [WHERE expr]).
     * DO, NOTHING and EXCLUDED are non-reserved words, matched by value.
     */
    private function upsertClause(): UpsertClause
    {
        $this->expect(Token::KEYWORD, 'CONFLICT');

        $target = null;
        $targetWhere = null;
        if ($this->peek()->is(Token::PUNCT, '(')) {
            $target = $this->parenColumnList();
            if ($this->accept(Token::KEYWORD, 'WHERE')) {
                $targetWhere = $this->expression();
            }
        }

        $this->expectIdent('DO');
        if ($this->acceptIdent('NOTHING')) {
            return new UpsertClause(target: $target, targetWhere: $targetWhere, doNothing: true);
        }

        $this->expect(Token::KEYWORD, 'UPDATE');
        $this->expect(Token::KEYWORD, 'SET');
        $set = [];
        do {
            $col = $this->name();
            $this->expectOp('=');
            $set[] = [$col, $this->expression()];
        } while ($this->accept(Token::PUNCT, ','));
        $updateWhere = $this->accept(Token::KEYWORD, 'WHERE') ? $this->expression() : null;

        return new UpsertClause(target: $target, targetWhere: $targetWhere, set: $set, updateWhere: $updateWhere);
    }

    private function updateStatement(): UpdateStatement
    {
        $this->expect(Token::KEYWORD, 'UPDATE');
        $orReplace = false;
        $orIgnore = false;
        if ($this->accept(Token::KEYWORD, 'OR')) {
            if ($this->accept(Token::KEYWORD, 'REPLACE')) {
                $orReplace = true;
            } elseif ($this->accept(Token::KEYWORD, 'IGNORE')) {
                $orIgnore = true;
            } else {
                $this->advance();
            }
        }
        $table = $this->qualifiedName();
        $this->expect(Token::KEYWORD, 'SET');
        $set = [];
        do {
            $col = $this->name();
            $this->expectOp('=');
            $set[] = [$col, $this->expression()];
        } while ($this->accept(Token::PUNCT, ','));
        $where = null;
        if ($this->accept(Token::KEYWORD, 'WHERE')) {
            $where = $this->expression();
        }
        $returning = $this->returningClause();
        return new UpdateStatement($table, $set, $where, $orReplace, $orIgnore, $returning);
    }

    private function deleteStatement(): DeleteStatement
    {
        $this->expect(Token::KEYWORD, 'DELETE');
        $this->expect(Token::KEYWORD, 'FROM');
        $table = $this->qualifiedName();
        $where = null;
        if ($this->accept(Token::KEYWORD, 'WHERE')) {
            $where = $this->expression();
        }
        $returning = $this->returningClause();
        return new DeleteStatement($table, $where, $returning);
    }

    private function dropStatement(): DropStatement
    {
        $this->expect(Token::KEYWORD, 'DROP');
        $kind = match (true) {
            $this->accept(Token::KEYWORD, 'TABLE') => DropStatement::TABLE,
            $this->accept(Token::KEYWORD, 'INDEX') => DropStatement::INDEX,
            $this->accept(Token::KEYWORD, 'VIEW') => DropStatement::VIEW,
            $this->accept(Token::KEYWORD, 'TRIGGER') => DropStatement::TRIGGER,
            default => throw SqlException::parse('expected TABLE, INDEX, VIEW or TRIGGER after DROP'),
        };
        $ifExists = false;
        if ($this->accept(Token::KEYWORD, 'IF')) {
            $this->expect(Token::KEYWORD, 'EXISTS');
            $ifExists = true;
        }
        $name = $this->qualifiedName();
        return new DropStatement($kind, $name, $ifExists);
    }

    private function alterStatement(): AlterTableStatement
    {
        $this->expect(Token::KEYWORD, 'ALTER');
        $this->expect(Token::KEYWORD, 'TABLE');
        $table = $this->qualifiedName();

        if ($this->accept(Token::KEYWORD, 'RENAME')) {
            if ($this->accept(Token::KEYWORD, 'TO')) {
                $newName = $this->name();
                return new AlterTableStatement($table, AlterTableStatement::RENAME_TABLE, newName: $newName);
            }
            $this->accept(Token::KEYWORD, 'COLUMN'); // optional
            $old = $this->name();
            $this->expect(Token::KEYWORD, 'TO');
            $new = $this->name();
            return new AlterTableStatement(
                $table,
                AlterTableStatement::RENAME_COLUMN,
                newName: $new,
                columnName: $old,
            );
        }

        if ($this->accept(Token::KEYWORD, 'ADD')) {
            $this->accept(Token::KEYWORD, 'COLUMN'); // optional
            $col = $this->columnDef();
            return new AlterTableStatement($table, AlterTableStatement::ADD_COLUMN, column: $col);
        }

        if ($this->accept(Token::KEYWORD, 'DROP')) {
            $this->accept(Token::KEYWORD, 'COLUMN'); // optional
            $col = $this->name();
            return new AlterTableStatement($table, AlterTableStatement::DROP_COLUMN, columnName: $col);
        }

        throw SqlException::parse('expected RENAME, ADD or DROP after ALTER TABLE');
    }

    private function pragmaStatement(): PragmaStatement
    {
        $this->expect(Token::KEYWORD, 'PRAGMA');
        $name = $this->qualifiedName();
        $value = null;
        if ($this->accept(Token::OP, '=')) {
            $value = $this->signedLiteralOrName();
        } elseif ($this->accept(Token::PUNCT, '(')) {
            $value = $this->signedLiteralOrName();
            $this->expect(Token::PUNCT, ')');
        }
        return new PragmaStatement($name, $value);
    }

    private function signedLiteralOrName(): Expr
    {
        if ($this->peek()->is(Token::OP, '-')) {
            $this->advance();
            return Expr::unary('-', $this->primary());
        }
        $t = $this->peek();
        if ($t->is(Token::IDENT) || $t->type === Token::KEYWORD) {
            return Expr::lit($this->advance()->value);
        }
        return $this->primary();
    }

    private function beginStatement(): TransactionStatement
    {
        $this->expect(Token::KEYWORD, 'BEGIN');
        $this->accept(Token::KEYWORD, 'DEFERRED');
        $this->accept(Token::KEYWORD, 'IMMEDIATE');
        $this->accept(Token::KEYWORD, 'EXCLUSIVE');
        $this->accept(Token::KEYWORD, 'TRANSACTION');
        return new TransactionStatement(TransactionStatement::BEGIN);
    }

    private function endTransaction(string $action): TransactionStatement
    {
        $this->advance(); // COMMIT/END
        $this->accept(Token::KEYWORD, 'TRANSACTION');
        return new TransactionStatement($action);
    }

    private function rollbackStatement(): TransactionStatement
    {
        $this->expect(Token::KEYWORD, 'ROLLBACK');
        $this->accept(Token::KEYWORD, 'TRANSACTION');
        if ($this->accept(Token::KEYWORD, 'TO')) {
            $this->accept(Token::KEYWORD, 'SAVEPOINT');
            return new TransactionStatement(TransactionStatement::ROLLBACK, $this->name());
        }
        return new TransactionStatement(TransactionStatement::ROLLBACK);
    }

    private function savepointStatement(): TransactionStatement
    {
        $this->expect(Token::KEYWORD, 'SAVEPOINT');
        return new TransactionStatement(TransactionStatement::SAVEPOINT, $this->name());
    }

    private function releaseStatement(): TransactionStatement
    {
        $this->expect(Token::KEYWORD, 'RELEASE');
        $this->accept(Token::KEYWORD, 'SAVEPOINT');
        return new TransactionStatement(TransactionStatement::RELEASE, $this->name());
    }

    // --- expressions (precedence climbing by layered descent) ------------

    private function expression(): Expr
    {
        return $this->orExpr();
    }

    private function orExpr(): Expr
    {
        $left = $this->andExpr();
        while ($this->accept(Token::KEYWORD, 'OR')) {
            $left = Expr::bin('OR', $left, $this->andExpr());
        }
        return $left;
    }

    private function andExpr(): Expr
    {
        $left = $this->notExpr();
        while ($this->accept(Token::KEYWORD, 'AND')) {
            $left = Expr::bin('AND', $left, $this->notExpr());
        }
        return $left;
    }

    private function notExpr(): Expr
    {
        if ($this->accept(Token::KEYWORD, 'NOT')) {
            return Expr::unary('NOT', $this->notExpr());
        }
        return $this->equalityExpr();
    }

    private function equalityExpr(): Expr
    {
        $left = $this->comparisonExpr();
        while (true) {
            $t = $this->peek();
            if ($t->is(Token::OP, '=') || $t->is(Token::OP, '==')) {
                $this->advance();
                $left = Expr::bin('=', $left, $this->comparisonExpr());
            } elseif ($t->is(Token::OP, '!=') || $t->is(Token::OP, '<>')) {
                $this->advance();
                $left = Expr::bin('<>', $left, $this->comparisonExpr());
            } elseif ($t->isKeyword('IS')) {
                $this->advance();
                $not = $this->accept(Token::KEYWORD, 'NOT');
                $right = $this->comparisonExpr();
                $left = Expr::bin($not ? 'IS NOT' : 'IS', $left, $right);
            } elseif ($t->isKeyword('ISNULL')) {
                $this->advance();
                $left = new Expr(Expr::ISNULL, operand: $left, not: false);
            } elseif ($t->isKeyword('NOTNULL')) {
                $this->advance();
                $left = new Expr(Expr::ISNULL, operand: $left, not: true);
            } else {
                $not = false;
                if ($t->isKeyword('NOT') && $this->isNegatableFollow($this->peekAt(1))) {
                    $this->advance();
                    $not = true;
                    $t = $this->peek();
                }
                if ($t->isKeyword('IN')) {
                    $left = $this->inExpr($left, $not);
                } elseif ($t->isKeyword('LIKE') || $t->isKeyword('GLOB') || $t->isKeyword('REGEXP') || $t->isKeyword('MATCH')) {
                    $left = $this->likeExpr($left, $not);
                } elseif ($t->isKeyword('BETWEEN')) {
                    $left = $this->betweenExpr($left, $not);
                } elseif ($not) {
                    throw SqlException::parse('expected IN/LIKE/BETWEEN after NOT');
                } else {
                    break;
                }
            }
        }
        return $left;
    }

    private function isNegatableFollow(Token $t): bool
    {
        return $t->isKeyword('IN') || $t->isKeyword('LIKE') || $t->isKeyword('GLOB')
            || $t->isKeyword('BETWEEN') || $t->isKeyword('REGEXP') || $t->isKeyword('MATCH');
    }

    private function inExpr(Expr $left, bool $not): Expr
    {
        $this->expect(Token::KEYWORD, 'IN');
        $e = new Expr(Expr::IN, operand: $left, not: $not);
        if ($this->accept(Token::PUNCT, '(')) {
            if ($this->peek()->isKeyword('SELECT') || $this->peek()->isKeyword('VALUES')) {
                $e->select = $this->selectStatement();
            } else {
                if (!$this->peek()->is(Token::PUNCT, ')')) {
                    do {
                        $e->list[] = $this->expression();
                    } while ($this->accept(Token::PUNCT, ','));
                }
            }
            $this->expect(Token::PUNCT, ')');
        } else {
            // IN table-name : treat as a single-column membership (rare); store as name
            $name = $this->qualifiedName();
            $e->list[] = Expr::col(null, $name);
        }
        return $e;
    }

    private function likeExpr(Expr $left, bool $not): Expr
    {
        $op = $this->advance()->keyword; // LIKE/GLOB/REGEXP/MATCH
        $pattern = $this->comparisonExpr();
        $escape = null;
        if ($this->accept(Token::KEYWORD, 'ESCAPE')) {
            $escape = $this->comparisonExpr();
        }
        return new Expr(Expr::LIKE, left: $left, right: $pattern, escape: $escape, op: $op, not: $not);
    }

    private function betweenExpr(Expr $left, bool $not): Expr
    {
        $this->expect(Token::KEYWORD, 'BETWEEN');
        $low = $this->comparisonExpr();
        $this->expect(Token::KEYWORD, 'AND');
        $high = $this->comparisonExpr();
        return new Expr(Expr::BETWEEN, operand: $left, not: $not, low: $low, high: $high);
    }

    private function comparisonExpr(): Expr
    {
        $left = $this->bitExpr();
        while (true) {
            $t = $this->peek();
            if ($t->is(Token::OP, '<') || $t->is(Token::OP, '<=') || $t->is(Token::OP, '>') || $t->is(Token::OP, '>=')) {
                $op = $this->advance()->value;
                $left = Expr::bin($op, $left, $this->bitExpr());
            } else {
                break;
            }
        }
        return $left;
    }

    private function bitExpr(): Expr
    {
        $left = $this->addExpr();
        while (true) {
            $t = $this->peek();
            if ($t->is(Token::OP, '&') || $t->is(Token::OP, '|') || $t->is(Token::OP, '<<') || $t->is(Token::OP, '>>')) {
                $op = $this->advance()->value;
                $left = Expr::bin($op, $left, $this->addExpr());
            } else {
                break;
            }
        }
        return $left;
    }

    private function addExpr(): Expr
    {
        $left = $this->mulExpr();
        while (true) {
            $t = $this->peek();
            if ($t->is(Token::OP, '+') || $t->is(Token::OP, '-')) {
                $op = $this->advance()->value;
                $left = Expr::bin($op, $left, $this->mulExpr());
            } else {
                break;
            }
        }
        return $left;
    }

    private function mulExpr(): Expr
    {
        $left = $this->concatExpr();
        while (true) {
            $t = $this->peek();
            if ($t->is(Token::OP, '*') || $t->is(Token::OP, '/') || $t->is(Token::OP, '%')) {
                $op = $this->advance()->value;
                $left = Expr::bin($op, $left, $this->concatExpr());
            } else {
                break;
            }
        }
        return $left;
    }

    private function concatExpr(): Expr
    {
        // `||`, `->`, and `->>` share one precedence level (left-associative),
        // just above the multiplicative operators, matching SQLite.
        $left = $this->unaryExpr();
        while (true) {
            $t = $this->peek();
            if ($t->is(Token::OP, '||') || $t->is(Token::OP, '->') || $t->is(Token::OP, '->>')) {
                $op = $this->advance()->value;
                $left = Expr::bin($op, $left, $this->unaryExpr());
            } else {
                break;
            }
        }
        return $left;
    }

    private function unaryExpr(): Expr
    {
        $t = $this->peek();
        if ($t->is(Token::OP, '-') || $t->is(Token::OP, '+') || $t->is(Token::OP, '~')) {
            $op = $this->advance()->value;
            return Expr::unary($op, $this->unaryExpr());
        }
        return $this->collateExpr();
    }

    private function collateExpr(): Expr
    {
        $e = $this->primary();
        while ($this->accept(Token::KEYWORD, 'COLLATE')) {
            $e = new Expr(Expr::COLLATE, operand: $e, collation: $this->name());
        }
        return $e;
    }

    private function primary(): Expr
    {
        $t = $this->peek();

        if ($t->is(Token::NUMBER)) {
            $this->advance();
            return Expr::lit($this->numberValue($t->value));
        }
        if ($t->is(Token::STRING)) {
            $this->advance();
            return Expr::lit($t->value);
        }
        if ($t->is(Token::BLOB)) {
            $this->advance();
            return Expr::lit(new Blob($t->value));
        }
        if ($t->is(Token::PARAM)) {
            $this->advance();
            return Expr::param($this->numberParam($t->value));
        }
        if ($t->isKeyword('NULL')) {
            $this->advance();
            return Expr::lit(null);
        }
        if ($t->isKeyword('CURRENT_DATE') || $t->isKeyword('CURRENT_TIME') || $t->isKeyword('CURRENT_TIMESTAMP')) {
            $this->advance();
            return new Expr(Expr::FUNC, name: \strtolower($t->keyword), args: []);
        }
        if ($t->isKeyword('CASE')) {
            return $this->caseExpr();
        }
        if ($t->isKeyword('CAST')) {
            return $this->castExpr();
        }
        if ($t->isKeyword('EXISTS')) {
            $this->advance();
            $this->expect(Token::PUNCT, '(');
            $sel = $this->selectStatement();
            $this->expect(Token::PUNCT, ')');
            return new Expr(Expr::EXISTS, select: $sel);
        }
        if ($t->isKeyword('NOT')) {
            $this->advance();
            return Expr::unary('NOT', $this->unaryExpr());
        }
        if ($t->is(Token::PUNCT, '(')) {
            $this->advance();
            if ($this->peek()->isKeyword('SELECT') || $this->peek()->isKeyword('VALUES')) {
                $sel = $this->selectStatement();
                $this->expect(Token::PUNCT, ')');
                return new Expr(Expr::SUBQUERY, select: $sel);
            }
            $e = $this->expression();
            // row-value / parenthesised list: keep first for scalar use
            while ($this->accept(Token::PUNCT, ',')) {
                $this->expression();
            }
            $this->expect(Token::PUNCT, ')');
            return $e;
        }

        // Identifier: column reference or function call.
        if ($t->is(Token::IDENT) || ($t->type === Token::KEYWORD && $this->canBeName($t->keyword))) {
            $name = $this->advance()->value;

            // function call
            if ($this->peek()->is(Token::PUNCT, '(')) {
                return $this->functionCall($name);
            }
            // qualified column: a.b or a.b.c
            if ($this->accept(Token::PUNCT, '.')) {
                if ($this->accept(Token::OP, '*')) {
                    // table.* used as expression (rare) — represent as column '*'
                    return Expr::col($name, '*');
                }
                $col = $this->name();
                if ($this->accept(Token::PUNCT, '.')) {
                    $col = $this->name(); // db.table.col -> drop db
                    return Expr::col($name, $col);
                }
                return Expr::col($name, $col);
            }
            // Bare TRUE / FALSE are the integer literals 1 / 0, as in SQLite. (A
            // real column of that name would take precedence in SQLite, but such
            // a schema is pathological and not worth a runtime resolution.)
            $lower = \strtolower($name);
            if ($lower === 'true') {
                return Expr::lit(1);
            }
            if ($lower === 'false') {
                return Expr::lit(0);
            }
            return Expr::col(null, $name);
        }

        throw SqlException::parse('unexpected token in expression: ' . ($t->value === '' ? $t->type : $t->value));
    }

    private function functionCall(string $name): Expr
    {
        $this->expect(Token::PUNCT, '(');
        $fn = new Expr(Expr::FUNC, name: $name);
        if ($this->accept(Token::OP, '*')) {
            $fn->star = true;
            $this->expect(Token::PUNCT, ')');
        } else {
            if ($this->accept(Token::KEYWORD, 'DISTINCT')) {
                $fn->distinct = true;
            }
            if (!$this->peek()->is(Token::PUNCT, ')')) {
                do {
                    $fn->args[] = $this->expression();
                } while ($this->accept(Token::PUNCT, ','));
            }
            $this->expect(Token::PUNCT, ')');
        }
        if ($this->peek()->isKeyword('FILTER')) {
            throw SqlException::parse('aggregate FILTER clause is not supported in this version');
        }
        if ($this->accept(Token::KEYWORD, 'OVER') || $this->accept(Token::IDENT, 'OVER')) {
            $fn->window = $this->windowSpec();
        }
        return $fn;
    }

    private function windowSpec(): \YetiDevWorks\YetiSQL\Sql\Ast\WindowSpec
    {
        $this->expect(Token::PUNCT, '(');
        $partition = [];
        $order = [];
        if ($this->accept(Token::KEYWORD, 'PARTITION') || $this->accept(Token::IDENT, 'PARTITION')) {
            $this->expect(Token::KEYWORD, 'BY');
            do {
                $partition[] = $this->expression();
            } while ($this->accept(Token::PUNCT, ','));
        }
        if ($this->accept(Token::KEYWORD, 'ORDER')) {
            $this->expect(Token::KEYWORD, 'BY');
            do {
                $order[] = $this->orderTerm();
            } while ($this->accept(Token::PUNCT, ','));
        }
        $frame = $this->maybeWindowFrame();
        $this->expect(Token::PUNCT, ')');
        return new \YetiDevWorks\YetiSQL\Sql\Ast\WindowSpec($partition, $order, $frame);
    }

    /** @return array{units:string,startKind:string,startVal:?\YetiDevWorks\YetiSQL\Sql\Ast\Expr,endKind:string,endVal:?\YetiDevWorks\YetiSQL\Sql\Ast\Expr}|null */
    private function maybeWindowFrame(): ?array
    {
        $units = null;
        if ($this->accept(Token::IDENT, 'ROWS') || $this->accept(Token::KEYWORD, 'ROWS')) {
            $units = 'rows';
        } elseif ($this->accept(Token::IDENT, 'RANGE') || $this->accept(Token::KEYWORD, 'RANGE')) {
            $units = 'range';
        } elseif ($this->accept(Token::IDENT, 'GROUPS') || $this->accept(Token::KEYWORD, 'GROUPS')) {
            $units = 'groups';
        }
        if ($units === null) {
            return null;
        }
        if ($this->accept(Token::KEYWORD, 'BETWEEN') || $this->accept(Token::IDENT, 'BETWEEN')) {
            [$startKind, $startVal] = $this->frameBound();
            $this->expect(Token::KEYWORD, 'AND');
            [$endKind, $endVal] = $this->frameBound();
        } else {
            // A single bound is the frame start; the end is implicitly CURRENT ROW.
            [$startKind, $startVal] = $this->frameBound();
            $endKind = 'currentRow';
            $endVal = null;
        }
        return ['units' => $units, 'startKind' => $startKind, 'startVal' => $startVal, 'endKind' => $endKind, 'endVal' => $endVal];
    }

    /** @return array{0:string,1:?\YetiDevWorks\YetiSQL\Sql\Ast\Expr} */
    private function frameBound(): array
    {
        if ($this->accept(Token::KEYWORD, 'UNBOUNDED') || $this->accept(Token::IDENT, 'UNBOUNDED')) {
            if ($this->accept(Token::IDENT, 'PRECEDING') || $this->accept(Token::KEYWORD, 'PRECEDING')) {
                return ['unboundedPreceding', null];
            }
            $this->accept(Token::IDENT, 'FOLLOWING') || $this->accept(Token::KEYWORD, 'FOLLOWING');
            return ['unboundedFollowing', null];
        }
        if ($this->accept(Token::KEYWORD, 'CURRENT') || $this->accept(Token::IDENT, 'CURRENT')) {
            $this->accept(Token::IDENT, 'ROW') || $this->accept(Token::KEYWORD, 'ROW');
            return ['currentRow', null];
        }
        $val = $this->expression();
        if ($this->accept(Token::IDENT, 'PRECEDING') || $this->accept(Token::KEYWORD, 'PRECEDING')) {
            return ['preceding', $val];
        }
        $this->accept(Token::IDENT, 'FOLLOWING') || $this->accept(Token::KEYWORD, 'FOLLOWING');
        return ['following', $val];
    }

    private function caseExpr(): Expr
    {
        $this->expect(Token::KEYWORD, 'CASE');
        $e = new Expr(Expr::CASE_);
        if (!$this->peek()->isKeyword('WHEN')) {
            $e->subject = $this->expression();
        }
        while ($this->accept(Token::KEYWORD, 'WHEN')) {
            $when = $this->expression();
            $this->expect(Token::KEYWORD, 'THEN');
            $then = $this->expression();
            $e->whens[] = [$when, $then];
        }
        if ($this->accept(Token::KEYWORD, 'ELSE')) {
            $e->elseExpr = $this->expression();
        }
        $this->expect(Token::KEYWORD, 'END');
        return $e;
    }

    private function castExpr(): Expr
    {
        $this->expect(Token::KEYWORD, 'CAST');
        $this->expect(Token::PUNCT, '(');
        $operand = $this->expression();
        $this->expect(Token::KEYWORD, 'AS');
        $type = $this->typeName() ?? '';
        $this->expect(Token::PUNCT, ')');
        return new Expr(Expr::CAST, operand: $operand, typeName: $type);
    }

    /**
     * Assign a stable ordinal to a positional parameter so resolution is
     * independent of evaluation order. `?` takes the next number; `?N` uses N
     * and advances the counter; named parameters pass through unchanged.
     */
    private function numberParam(string $marker): string
    {
        if ($marker === '?') {
            return '?' . (++$this->paramCounter);
        }
        if ($marker[0] === '?') {
            $n = (int) \substr($marker, 1);
            $this->paramCounter = \max($this->paramCounter, $n);
            return '?' . $n;
        }
        return $marker; // :name / @name / $name
    }

    private function numberValue(string $text): int|float
    {
        if (\str_starts_with($text, '0x') || \str_starts_with($text, '0X')) {
            return (int) \hexdec(\substr($text, 2));
        }
        if (\str_contains($text, '.') || \str_contains($text, 'e') || \str_contains($text, 'E')) {
            return (float) $text;
        }
        $asInt = (int) $text;
        if ((string) $asInt === $text) {
            return $asInt;
        }
        return (float) $text;
    }

    private function canBeName(string $kw): bool
    {
        // Keywords permitted as identifiers/function names in expression position.
        return \in_array($kw, [
            'ROWID', 'REPLACE', 'KEY', 'MATCH', 'GLOB', 'REGEXP', 'IF',
            'INDEX', 'VALUE', 'CURRENT', 'ROW', 'ROWS',
        ], true);
    }

    // --- name helpers ----------------------------------------------------

    private function name(): string
    {
        $t = $this->peek();
        if ($t->is(Token::IDENT) || $t->is(Token::STRING)) {
            return $this->advance()->value;
        }
        if ($t->type === Token::KEYWORD) {
            return $this->advance()->value;
        }
        throw SqlException::parse('expected a name, got: ' . ($t->value === '' ? $t->type : $t->value));
    }

    private function qualifiedName(): string
    {
        $name = $this->name();
        if ($this->peek()->is(Token::PUNCT, '.')) {
            $this->advance();
            $name = $this->name();
        }
        return $name;
    }

    // --- token cursor ----------------------------------------------------

    private function peek(): Token
    {
        return $this->tokens[$this->i];
    }

    private function peekAt(int $n): Token
    {
        return $this->tokens[$this->i + $n] ?? $this->tokens[\count($this->tokens) - 1];
    }

    private function advance(): Token
    {
        return $this->tokens[$this->i++];
    }

    private function accept(string $type, ?string $value = null): bool
    {
        $t = $this->peek();
        $match = $type === Token::KEYWORD
            ? ($t->type === Token::KEYWORD && ($value === null || $t->keyword === $value))
            : $t->is($type, $value);
        if ($match) {
            $this->i++;
            return true;
        }
        return false;
    }

    private function expect(string $type, ?string $value = null): Token
    {
        if (!$this->accept($type, $value)) {
            $t = $this->peek();
            $want = $value ?? $type;
            throw SqlException::parse("expected '$want', got '" . ($t->value === '' ? $t->type : $t->value) . "'");
        }
        return $this->tokens[$this->i - 1];
    }

    private function expectOp(string $op): void
    {
        if (!$this->accept(Token::OP, $op)) {
            throw SqlException::parse("expected operator '$op'");
        }
    }

    /** Match a non-reserved word (an identifier) by its upper-cased value. */
    private function acceptIdent(string $upper): bool
    {
        $t = $this->peek();
        if ($t->is(Token::IDENT) && \strtoupper($t->value) === $upper) {
            $this->i++;
            return true;
        }
        return false;
    }

    private function expectIdent(string $upper): void
    {
        if (!$this->acceptIdent($upper)) {
            $t = $this->peek();
            throw SqlException::parse("expected '$upper', got '" . ($t->value === '' ? $t->type : $t->value) . "'");
        }
    }
}
