# YetiSQL

A **pure-PHP, SQLite-compatible embedded SQL database**. Zero extensions, a single
committable file, and a PDO-shaped API. Runs anywhere PHP 8.3+ runs — including
restricted shared hosting and locked-down containers where `pdo_sqlite` isn't available —
and is a natural fit for flat-file projects where the whole database is one file you can
commit to git.

> **Scope & honesty.** YetiSQL speaks SQLite's *SQL dialect* and stores data in its own
> single-file binary format (`*.ysql`). It is **not** byte-compatible with the `sqlite3`
> file format, and it is **not** a literal `\PDO` subclass (real PDO drivers are C
> extensions). Pure PHP will never match the C engine on raw speed; the goal is
> portability and correctness, using every PHP-level trick (page cache, lazy column
> decoding, compiled plan cache) to be as fast as pure PHP allows. Compatibility is
> validated by **differential testing against the real `pdo_sqlite` extension**.

## Install

```bash
composer require yetidevworks/yetisql
```

## Quick start

```php
use YetiDevWorks\YetiSQL\PDO;

$db = new PDO('yetisql:app.ysql');          // or 'yetisql::memory:'
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec('CREATE TABLE users (
    id    INTEGER PRIMARY KEY AUTOINCREMENT,
    name  TEXT NOT NULL,
    email TEXT UNIQUE COLLATE NOCASE,
    age   INTEGER
)');

$stmt = $db->prepare('INSERT INTO users (name, email, age) VALUES (?, ?, ?)');
$stmt->execute(['Alice', 'alice@example.com', 30]);
$stmt->execute(['Bob',   'bob@example.com',   25]);

$rows = $db->query('SELECT name, age FROM users WHERE age >= 18 ORDER BY age DESC')
           ->fetchAll(PDO::FETCH_ASSOC);

echo $db->lastInsertId();
```

The constants use the same numeric values as `\PDO`, so `\PDO::FETCH_ASSOC` and
`YetiSQL\PDO::FETCH_ASSOC` are interchangeable.

## Doctrine DBAL

YetiSQL ships a Doctrine DBAL driver, so it plugs into the DBAL stack
(`DriverManager`, `QueryBuilder`, schema manager, transactions). The driver extends
`AbstractSQLiteDriver`, so DBAL reuses its SQLite platform and grammar.

```php
use Doctrine\DBAL\DriverManager;
use YetiDevWorks\YetiSQL\Doctrine\Driver;

$conn = DriverManager::getConnection([
    'driverClass' => Driver::class,
    'path'        => 'app.ysql',   // or 'memory' => true
]);

$conn->insert('users', ['name' => 'Alice', 'age' => 30]);

$rows = $conn->createQueryBuilder()
    ->select('name', 'age')->from('users')
    ->where('age >= :min')->setParameter('min', 18)
    ->executeQuery()->fetchAllAssociative();

$columns = $conn->createSchemaManager()->listTableColumns('users');
```

DBAL's QueryBuilder CRUD, prepared statements, joins, transactions, and the schema
manager (`listTableNames`, `listTableColumns`) are validated against the real DBAL stack
in the test suite — this is the project's v1 compatibility gate.

## CLI

A small `sqlite3`-style shell ships in `bin/yetisql`:

```bash
vendor/bin/yetisql app.ysql                       # interactive REPL
vendor/bin/yetisql app.ysql "SELECT * FROM users" # one-shot
```

## What works today

- **DDL** — `CREATE TABLE` (type affinity, `PRIMARY KEY`, `AUTOINCREMENT`, `NOT NULL`,
  `DEFAULT`, `UNIQUE`, `COLLATE`), `DROP TABLE/INDEX`, and `CREATE INDEX` with real
  index B-trees the planner uses for equality/range/`IN` lookups.
- **DML** — `INSERT` (multi-row, `OR REPLACE`/`OR IGNORE`, `INSERT … SELECT`, `DEFAULT
  VALUES`), `UPDATE`, `DELETE`, with positional (`?`, `?N`) and named (`:n`/`@n`/`$n`)
  parameters.
- **Queries** — `SELECT` with `WHERE`, `INNER`/`LEFT`/`CROSS`/comma joins, `GROUP BY` /
  `HAVING`, aggregates (`count`/`sum`/`avg`/`min`/`max`/`total`/`group_concat`), `ORDER
  BY` (incl. positional and alias), `LIMIT`/`OFFSET`, `DISTINCT`, scalar subqueries,
  `EXISTS`, `IN (…)`, compound `UNION`/`UNION ALL`/`INTERSECT`/`EXCEPT`, `CASE`, `CAST`,
  `LIKE`/`GLOB`, subqueries in `FROM` (derived tables), table-valued PRAGMA functions
  (`pragma_table_info(…)` etc.), and SQLite's affinity-aware comparison and three-valued logic.
- **Functions** — a broad scalar set (`abs`, `length`, `lower`/`upper`, `substr`, `trim`
  family, `replace`, `instr`, `round`, `coalesce`, `ifnull`, `nullif`, `typeof`, `hex`,
  `quote`, `printf`/`format`, `char`/`unicode`, `iif`, …).
- **Transactions** — `BEGIN`/`COMMIT`/`ROLLBACK` with a crash-safe rollback journal and
  `flock()` (many readers / one writer). A crash mid-write rolls back cleanly.
- **PRAGMA** — `table_info`, `table_list`, `database_list`, and common no-op settings.

## Storage & durability

A YetiSQL database is one page-based binary file (`app.ysql`, default 4 KB pages) holding
B+-trees for each table keyed by rowid, with overflow pages for large values. Writes are
made crash-safe by a rollback journal (`app.ysql-journal`): a crash during commit restores
the pre-commit state on next open. Concurrent access across processes is serialised with
advisory `flock()` (note: advisory locking has known caveats on some network filesystems).

## Performance

SQLite's C engine is the gold standard; pure-PHP YetiSQL is, unavoidably, far
slower. `benchmarks/bench.php` measures *how much*, and shows what index-based
planning buys. Representative run (in-memory, 5000 rows, PHP 8.3):

```
workload                           SQLite  YetiSQL+idx  YetiSQL scan  vs SQLite
------------------------------------------------------------------------------
bulk insert                        2.9 ms      1.05 s       1.07 s        360x
PK lookups (×2000)                 1.3 ms     94.4 ms      96.9 ms         73x
city COUNT (×200, ~20% rows)       2.9 ms      7.08 s       9.39 s       2408x
range query (×200)                 1.8 ms      4.57 s       7.08 s       2482x
group-by aggregate (×50)          32.2 ms      2.20 s       2.22 s         68x
PK updates (×1000)                 0.6 ms    153.0 ms     148.1 ms        278x
```

```bash
php benchmarks/bench.php [rows]    # default 5000
```

Takeaways:
- **Index planning works.** Point lookups by primary key and by indexed column
  use rowid/index seeks; the `YetiSQL+idx` column beats `YetiSQL scan` on
  selective and range queries, and `UPDATE` only re-indexes columns that change.
- **Expect ~70-360x** for point operations, inserts, and aggregates — the cost
  of a tree-walking interpreter and per-row work in pure PHP.
- **Large result sets are the weak spot** (the 2400x cases): a query touching
  ~20% of rows still materialises and re-checks each one. Covering-index counts
  (answering `COUNT(*)` from the index without fetching rows) and the planned
  VDBE compiler are the next levers.

Hot-path optimisations already in place: an LRU page cache, a parsed-page cache
(so repeated reads skip re-decoding), single-pass page encoding, binary-search
rowid lookups, lazy/early-stop index scans, and a compiled-statement path.

## Architecture

```
YetiSQL\PDO / PDOStatement         PDO-shaped API
  └─ Engine\Database               autocommit / transactions / catalog
       ├─ Sql\Lexer → Parser       SQL text → AST
       ├─ Executor                 nested-loop join → filter → group → order → limit
       │    └─ Evaluator           affinity-aware expression evaluation
       └─ Engine\Pager             paged file I/O, page cache, journal, flock
            └─ TableBTree          rowid B+-tree + overflow
```

The execution model is a tree-walking interpreter; a VDBE-style bytecode compiler is the
planned performance upgrade (see roadmap).

## Roadmap

Working: secondary-index and rowid query planning (equality, range, `IN`, `BETWEEN`)
with automatic index maintenance on writes.

Not yet implemented (planned): multi-column index planning beyond the leading column,
covering-index counts, index-driven joins (the inner table of a join still scans),
correlated subqueries, `WITH` (CTEs), window functions, triggers, views, `ALTER TABLE`,
JSON1, FTS5, true WAL, and the Eloquent adapter. The execution model is a tree-walking
interpreter; a VDBE-style bytecode compiler is the planned performance upgrade. Byte-level
`sqlite3` *file* interop is out of scope by design.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The suite includes unit tests (storage engine, codecs), a `sqllogictest`-style
conformance corpus, a **differential oracle** that runs identical SQL against the real
`pdo_sqlite` extension and asserts the results match, and a **Doctrine DBAL integration
suite** that drives YetiSQL through the real DBAL stack (QueryBuilder, schema manager,
transactions).

## License

MIT © YetiDevWorks / Andy Miller
