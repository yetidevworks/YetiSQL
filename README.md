# YetiSQL

A **pure-PHP, SQLite-compatible embedded SQL database**. Zero extensions, a single
committable file, and a PDO-shaped API. Runs anywhere PHP 8.3+ runs — including
restricted shared hosting and locked-down containers where `pdo_sqlite` isn't available —
and is a natural fit for flat-file projects where the whole database is one file you can
commit to git.

> **Scope & honesty.** YetiSQL speaks SQLite's *SQL dialect* and stores data in its own
> single-file binary format (`*.ysql`). It is **not** byte-compatible with the `sqlite3`
> file format, and it is **not** a literal `\PDO` subclass (real PDO drivers are C
> extensions). Pure PHP will never match the C engine on raw per-row speed; the goal is
> portability and correctness, using every PHP-level trick (page cache, lazy column
> decoding, compiled plan cache, index planning, WAL) to be as fast as pure PHP allows —
> and for indexed, set-oriented work it gets surprisingly close. Compatibility is validated
> by **differential testing against the real `pdo_sqlite` extension**.

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

## Eloquent / Laravel

YetiSQL ships an Eloquent (`illuminate/database`) driver. Because YetiSQL speaks the
SQLite dialect, the connection extends Laravel's `SQLiteConnection`, reusing its SQLite
query grammar, schema grammar, and processor. `YetiSql::register()` wires a `yetisql`
driver into a Capsule (or a Laravel app's `DatabaseManager`).

```php
use Illuminate\Database\Capsule\Manager as Capsule;
use YetiDevWorks\YetiSQL\Eloquent\YetiSql;

$capsule = new Capsule();
$capsule->addConnection([
    'driver'   => 'yetisql',
    'database' => 'app.ysql',   // or ':memory:'
    'prefix'   => '',
]);
YetiSql::register($capsule);     // register the yetisql driver
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Migrations
$capsule->schema()->create('users', function ($t) {
    $t->increments('id');
    $t->string('name');
    $t->integer('age')->nullable();
});

// Query builder + Eloquent models, relationships, and transactions all work.
Capsule::table('users')->insert(['name' => 'Alice', 'age' => 30]);
$adults = Capsule::table('users')->where('age', '>=', 18)->get();
```

In a full Laravel app, call `YetiSql::register(app('db'))` from a service provider's
`boot()` and set a connection with `'driver' => 'yetisql'`. The schema builder
(migrations + introspection), query builder, Eloquent models with relationships and eager
loading, and transactions are validated against the real Eloquent stack in the test suite.

## CLI

A small `sqlite3`-style shell ships in `bin/yetisql`:

```bash
vendor/bin/yetisql app.ysql                       # interactive REPL
vendor/bin/yetisql app.ysql "SELECT * FROM users" # one-shot
```

## What works today

- **DDL** — `CREATE TABLE` (type affinity, `PRIMARY KEY`, `AUTOINCREMENT`, `NOT NULL`,
  `DEFAULT`, `UNIQUE`, `COLLATE`, `FOREIGN KEY` … `REFERENCES`, and **generated columns**
  `GENERATED ALWAYS AS (…)` STORED/VIRTUAL), `CREATE INDEX` with real index B-trees the
  planner uses for equality/range/`IN` lookups, `CREATE VIEW`, `CREATE TRIGGER`, `DROP
  TABLE/INDEX/VIEW/TRIGGER`, and **`ALTER TABLE`** (`RENAME TO`, `RENAME COLUMN`, `ADD
  COLUMN`, `DROP COLUMN`).
- **DML** — `INSERT` (multi-row, `OR REPLACE`/`OR IGNORE`, `INSERT … SELECT`, `DEFAULT
  VALUES`), `UPDATE`, `DELETE`, all with an optional **`RETURNING`** clause, and positional
  (`?`, `?N`) and named (`:n`/`@n`/`$n`) parameters.
- **Constraints & integrity** — `UNIQUE` / `PRIMARY KEY` enforcement backed by auto-created
  unique indexes (SQLite's `sqlite_autoindex_*` model) with full `REPLACE` / `OR IGNORE`
  conflict resolution, `NOT NULL`, and **`FOREIGN KEY` enforcement** under
  `PRAGMA foreign_keys=ON` (child existence checks; `ON DELETE`/`ON UPDATE`
  `NO ACTION`/`RESTRICT`/`CASCADE`/`SET NULL`/`SET DEFAULT`; composite and self-referential
  keys).
- **Queries** — `SELECT` with `WHERE`, `INNER`/`LEFT`/`CROSS`/comma joins, `GROUP BY` /
  `HAVING`, aggregates (`count`/`sum`/`avg`/`min`/`max`/`total`/`group_concat`),
  **window functions** (`ROW_NUMBER`/`RANK`/`DENSE_RANK`/`NTILE`/`PERCENT_RANK`/`CUME_DIST`,
  `LAG`/`LEAD`/`FIRST_VALUE`/`LAST_VALUE`/`NTH_VALUE`, running/`PARTITION BY` aggregates,
  and explicit `ROWS`/`RANGE`/`GROUPS` frames), **CTEs** (`WITH`, incl. `WITH RECURSIVE`),
  correlated and scalar subqueries, `EXISTS`, `IN (…)`, `ORDER BY` (incl. positional and
  alias), `LIMIT`/`OFFSET`, `DISTINCT`, compound `UNION`/`UNION ALL`/`INTERSECT`/`EXCEPT`,
  `CASE`, `CAST`, `LIKE`/`GLOB`, subqueries in `FROM` (derived tables) and views,
  table-valued PRAGMA functions (`pragma_table_info(…)` etc.), and SQLite's affinity-aware
  comparison and three-valued logic.
- **Views & triggers** — `CREATE VIEW` (incl. explicit column lists and views over views);
  `BEFORE`/`AFTER` row triggers on `INSERT`/`UPDATE`/`DELETE` with `NEW`/`OLD`, `WHEN`
  gating, `UPDATE OF`, multi-statement bodies, and cascades, plus `INSTEAD OF` triggers
  that make views writable (non-recursive, matching `recursive_triggers=OFF`).
- **Functions** — a broad scalar set (`abs`, `length`, `lower`/`upper`, `substr`, `trim`
  family, `replace`, `instr`, `round`, `coalesce`, `ifnull`, `nullif`, `typeof`, `hex`,
  `quote`, `printf`/`format`, `char`/`unicode`, `iif`, …), plus the **JSON1** surface
  (`json`, `json_extract`, `json_type`, `json_valid`, `json_quote`, `json_array`,
  `json_object`, `json_array_length`, `json_set`/`insert`/`replace`/`remove`/`patch`, the
  `->`/`->>` operators, the `json_group_array`/`json_group_object` aggregates, and the
  `json_each`/`json_tree` table-valued functions).
- **Transactions & durability** — `BEGIN`/`COMMIT`/`ROLLBACK` with a crash-safe rollback
  journal *or* **true WAL mode** (`PRAGMA journal_mode=WAL`), and `flock()` (many readers /
  one writer). A crash mid-write rolls back cleanly under either mode.
- **EXPLAIN** — `EXPLAIN <select>` returns the compiled **VDBE bytecode** program and
  `EXPLAIN QUERY PLAN` returns the scan summary (see *VDBE & EXPLAIN* below).
- **PRAGMA** — `table_info`/`table_xinfo`, `index_list`, `foreign_key_list`, `table_list`,
  `database_list`, `journal_mode` (incl. `wal`), `wal_checkpoint`, `foreign_keys`,
  `busy_timeout`, and common settings.

## Storage & durability

A YetiSQL database is one page-based binary file (`app.ysql`, default 4 KB pages) holding
B+-trees for each table keyed by rowid, with overflow pages for large values. Multiple
processes can safely share one file with single-writer / multiple-reader semantics — see
*Concurrency* below (note: advisory `flock()` has known caveats on some network filesystems).

Two durability modes are available:

- **Rollback journal** (default). On commit the original page images are written to
  `app.ysql-journal` and fsync'd before the main file is updated; a crash during commit
  restores the pre-commit state on next open.
- **WAL** (`PRAGMA journal_mode=WAL`). Commits append the *new* page images to
  `app.ysql-wal` as frames terminated by a commit marker, leaving the main file untouched;
  reads merge the log over the main file. Recovery on open replays the WAL's committed
  prefix and discards any half-written trailing transaction. `PRAGMA wal_checkpoint` (and
  closing the database) folds the log back into the main file. WAL turns each commit's two
  fsyncs + journal churn into a single sequential append + fsync, so many small commits get
  **~2.4× faster** (see *Performance*).

## Performance

SQLite's C engine is the gold standard; pure-PHP YetiSQL is, unavoidably, slower
on raw per-row work. `benchmarks/bench.php` measures *how much*, and shows what
index-based planning buys. Representative run (in-memory, 5000 rows, PHP 8.3):

```
workload                           SQLite  YetiSQL+idx  YetiSQL scan  vs SQLite
------------------------------------------------------------------------------
bulk insert                        3.1 ms    127.9 ms     129.7 ms         42x
PK lookups (×2000)                 1.4 ms     49.4 ms      45.3 ms         34x
city COUNT (×200, ~20% rows)       2.9 ms      8.9 ms      85.6 ms          3x
range query (×200)                 1.8 ms      4.2 ms      19.8 ms          2x
group-by aggregate (×50)          31.3 ms     25.1 ms      26.4 ms          1x
PK updates (×1000)                 0.6 ms     41.5 ms      36.9 ms         68x
join users⋈posts (×3, ≤100)        0.0 ms     50.5 ms      42.1 ms      ~1300x
correlated subquery (≤100)         0.0 ms     12.7 ms      14.7 ms       ~320x
```

(The join and correlated-subquery ratios are dominated by SQLite rounding to ~0 ms at
this scale; treat them as "fast enough to be noise on the C engine," not a literal multiple.)

```bash
php benchmarks/bench.php [rows]    # default 5000
```

Takeaways:
- **Index planning is the headline.** The `YetiSQL+idx` vs `YetiSQL scan` gap is
  what persistent indexes buy: selective `COUNT` is ~10×, range queries ~5×,
  and index-accelerated correlated subqueries stay a little faster than the
  no-index count-map path. Equality joins without a persistent index can still
  use a transient hash table, and unindexed equality `COUNT(*)` correlated
  subqueries can build a transient count map, so both avoid the old
  full-inner-scan-per-outer-row cost. Covered `COUNT(*)` over full tables,
  rowid ranges, and leading-column index predicates count B-tree cells directly
  without fetching rows, and `UPDATE` only re-indexes columns that change.
- **For indexed, set-oriented work YetiSQL is competitive with SQLite** in this
  in-memory harness — the group-by aggregate and indexed COUNT/range rows are
  within single-digit multiples (sometimes faster, since there's no driver/IPC
  boundary). Point operations, inserts, and updates stay tens of ×'s behind: the
  irreducible cost of a tree-walking interpreter in pure PHP.

**Durability — WAL vs rollback journal** (file-backed, 2000 single-statement commits):

```
workload                                 rollback            WAL   speedup
------------------------------------------------------------------------------
INSERTs, one commit each                 501.6 ms       208.2 ms      2.4x
UPDATEs, one commit each                 488.0 ms       203.5 ms      2.4x
```

WAL replaces two fsyncs + journal create/delete per commit with one fsync and a
sequential append, so the win scales with the number of commits (a single big
transaction commits once and sees no difference; in-memory never touches disk).

**vs MySQL / MariaDB** (`benchmarks/bench_mysql.php`, both durable: YetiSQL
file-backed with a rollback journal, MariaDB 12.2 over the local socket with
InnoDB's default per-commit fsync; 5000 rows, PHP 8.3):

```
workload                          MariaDB  YetiSQL(file)  Yeti/MySQL
------------------------------------------------------------------------------
bulk insert (1 txn)               94.2 ms       124.8 ms        1.3x
create 3 indexes                 149.6 ms       232.8 ms        1.6x
PK lookups (×2000)                38.8 ms        52.0 ms        1.3x
city COUNT (×200, ~20% rows)      18.1 ms        12.3 ms        0.7x
range query (×200)                13.7 ms         5.2 ms        0.4x
group-by aggregate (×50)          52.9 ms        24.9 ms        0.5x
PK updates (×1000, 1 txn)         16.7 ms        43.3 ms        2.6x
join users⋈posts (×3, ≤100)       0.3 ms        12.7 ms       38.5x
correlated subquery (≤100)         0.1 ms         2.3 ms       18.1x
```

```bash
php benchmarks/bench_mysql.php [rows]    # default 5000
```

This is apples-to-oranges by design, and that's the point: YetiSQL runs
**in-process** while MariaDB is a **client-server** engine, so every statement
crosses a socket to a separate process. That round-trip is exactly what an
embedded database exists to avoid, so for repeated scans and aggregates YetiSQL
is *faster* here (`Yeti/MySQL < 1`) despite being pure PHP — those columns fire
hundreds of separate queries and MariaDB pays IPC on each, while YetiSQL's
covered counts answer them without leaving the process. Bulk insert and point
lookups land close (~1.3×). The real gaps are per-row `UPDATE` (~2.6×) and tiny
joins / correlated subqueries — but those are 0.1–0.3 ms on MariaDB, so the
large *ratio* is sub-millisecond *absolute* time. The takeaway isn't "faster
than MySQL"; it's that a zero-dependency pure-PHP engine stays in the same league
on real workloads where avoiding the client-server boundary counts.

Hot-path optimisations already in place: an LRU page cache, a parsed-page cache
(so repeated reads skip re-decoding), single-pass page encoding, binary-search
rowid lookups, lazy/early-stop index scans, multi-column index prefix seeks,
index-driven joins and correlated subqueries, covered table/index counts, and a
transient hash table for unindexed equality joins, transient count maps for
unindexed equality `COUNT(*)` correlated subqueries, plus a compiled-closure
plan cache for the per-row hot loop.

### VDBE & EXPLAIN

YetiSQL includes a small **register virtual machine** in the SQLite VDBE tradition.
`EXPLAIN` compiles a single-table `SELECT` to a bytecode program and returns the
disassembly:

```
sqlite> EXPLAIN SELECT id, name FROM users WHERE age > 30 LIMIT 2;
 0  OpenRead   3  0  0        root=3 (users)
 1  Rewind     0 10  0        if empty goto 10
 2  Column     2  1  0        r1 = column 2
 3  Load       0  2  0   30   r2 = 30
 4  BinOp      1  2  0   >    r0 = r1 > r2
 5  IfNot      0  9  0        if !r0 goto 9
 6  Rowid      0  3  0        r3 = rowid
 7  Column     1  4  0        r4 = column 1
 8  ResultRow  3  2  0        output r3..4
 9  Next       0  2  0        goto 2 if more rows
10  Halt       0  0  0
```

`PRAGMA vdbe=on` routes compilable single-table scans through the VM instead of the
tree-walker. It is **off by default**: in PHP a register-interpreter loop runs a bit
slower than the existing composed-closure compilation, so the closure path stays the
default hot loop. The VM's value is the VDBE architecture and `EXPLAIN` introspection;
its results are verified against `pdo_sqlite`, so the bytecode and the interpreter agree
row-for-row.

## Architecture

```
YetiSQL\PDO / PDOStatement         PDO-shaped API
  └─ Engine\Database               autocommit / transactions / catalog
       ├─ Sql\Lexer → Parser       SQL text → AST
       ├─ Executor                 nested-loop join → filter → group → window → order → limit
       │    ├─ Evaluator           affinity-aware expression evaluation (+ NEW/OLD for triggers)
       │    └─ Vdbe\Compiler/Vm    bytecode compiler + register VM (EXPLAIN; opt-in execution)
       └─ Engine\Pager             paged file I/O, page cache, rollback journal | WAL, flock
            └─ TableBTree / IndexBTree   rowid + secondary B+-trees, overflow
```

The default execution model is a tree-walking interpreter with a compiled-closure fast
path for the per-row hot loop; the VDBE bytecode compiler + register VM (above) is an
alternative engine used for `EXPLAIN` and available opt-in via `PRAGMA vdbe=on`.

## Roadmap

**Working:** secondary-index, multi-column-index, and rowid query planning (equality,
range, `IN`, `BETWEEN`) with automatic index maintenance on writes; `UNIQUE` and
`PRIMARY KEY` constraint enforcement (backed by auto-created unique indexes, like SQLite's
`sqlite_autoindex_*`) with `REPLACE` / `INSERT OR REPLACE` / `INSERT OR IGNORE` /
`UPDATE OR REPLACE`/`IGNORE` conflict resolution; index-driven joins and
index-accelerated/count-map correlated subqueries; covering-index counts (persisted subtree
row counts); CTEs (incl. recursive), window functions, views, triggers (incl. `INSTEAD OF`),
`ALTER TABLE`, true WAL mode, a VDBE bytecode compiler with `EXPLAIN`; the **JSON1** functions
(`json`, `json_extract`, `json_type`, `json_valid`, `json_quote`, `json_array`, `json_object`,
`json_array_length`, `json_set`/`insert`/`replace`/`remove`/`patch`), the `->` and `->>`
operators, the `json_group_array`/`json_group_object` aggregates, and the `json_each`/`json_tree`
table-valued functions; `RETURNING` on INSERT/UPDATE/DELETE; generated columns
(`GENERATED ALWAYS AS`, STORED and VIRTUAL); `FOREIGN KEY` enforcement under
`PRAGMA foreign_keys=ON` (child existence checks plus `ON DELETE`/`ON UPDATE`
`NO ACTION`/`RESTRICT`/`CASCADE`/`SET NULL`/`SET DEFAULT`, composite and
self-referential keys, `pragma_foreign_key_list`); and Doctrine DBAL and Eloquent adapters.

**Not yet implemented (planned):** FTS5 extension and full
VDBE execution of every query (today the VM covers single-table scans and the
tree-walker handles the rest). Byte-level `sqlite3` *file* interop is out of scope by
design. Foreign keys are enforced immediately: `DEFERRABLE` / `INITIALLY DEFERRED`
clauses parse and run but behave as immediate constraints.

Generated columns are computed and stored on every write for both STORED and VIRTUAL
kinds — value-identical to SQLite for the deterministic expressions they're allowed to
use, so a VIRTUAL column occupies storage it wouldn't under SQLite (no query-result
difference).

JSON1 has two documented divergences from SQLite, both stemming from YetiSQL's value
model having no JSON "subtype": (1) feeding one JSON function's text output into another
embeds it as a string rather than as JSON (e.g. `json_array(json('[1]'))` yields `["[1]"]`,
not `[[1]]`); plain-column inputs match. (2) `json()` normalizes numeric literals while
re-minifying, so a redundant trailing zero is dropped (`json('[2.50]')` &rarr; `[2.5]`);
extracted and constructed values are unaffected.

## Concurrency

YetiSQL is safe for multiple processes (e.g. concurrent PHP-FPM requests) sharing one
`.ysql` file, with **single-writer / multiple-reader** semantics like SQLite's rollback-journal
mode:

- **Writers are serialized** by an advisory `flock(LOCK_EX)` held only for the duration of a
  write transaction (a single statement in autocommit). On entering a write transaction the
  pager re-reads the committed header *under the lock* and drops any stale page cache, so a
  writer always operates on the latest committed state — no lost updates or B-tree corruption
  when two processes write at once.
- **Opening a connection never blocks** on another connection's write. A crashed writer's
  "hot" journal is recovered under a non-blocking lock (a live writer's in-flight commit is
  left alone).
- **Reused connections stay coherent**: a read picks up other processes' commits (including
  schema changes) rather than serving stale cached pages, validated by a change-counter /
  WAL-size check.
- **`PRAGMA busy_timeout = <ms>`** controls how long a contended write waits for the lock
  before raising `database is locked` (default 5000 ms) — it fails fast instead of hanging.

Because writes take a whole-file exclusive lock, a write-heavy workload (e.g. tracking a
counter on every page view) serializes all writers through one lock — correct, but a
throughput ceiling, the same as SQLite's default journal mode. Cross-process coherence
relies on local-filesystem `flock` semantics (not guaranteed over some network filesystems).
This is verified by a forked multi-process test suite (`tests/Concurrency`) that asserts no
lost updates or corruption under heavy contention in both journal and WAL modes.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The suite (385 tests) includes unit tests (storage engine, codecs, WAL recovery), a
`sqllogictest`-style conformance corpus, a **differential oracle** that runs identical SQL
against the real `pdo_sqlite` extension and asserts the results match (covering joins,
CTEs, window functions, views, triggers, `ALTER TABLE`, JSON1, `RETURNING`, generated
columns, foreign keys, and the VDBE VM), a **forked multi-process concurrency suite**
(`tests/Concurrency`, ext-pcntl) that asserts no lost updates or corruption under heavy
contention in both journal and WAL modes, and **Doctrine DBAL and Eloquent integration
suites** that drive YetiSQL through the real ORM stacks (QueryBuilder, schema
manager/migrations, relationships, transactions). The differential oracle is the project's
correctness gate: new features ship only once they match `pdo_sqlite` row-for-row.

## License

MIT © YetiDevWorks / Andy Miller
