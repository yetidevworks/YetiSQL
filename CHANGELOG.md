# Changelog

All notable changes to YetiSQL are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/), and this project adheres to
[Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-06-18

First tagged release.


### Added

- **CHECK constraint enforcement.** Column- and table-level `CHECK (...)` (named
  or anonymous) are now evaluated on `INSERT`/`UPDATE` and reject violating rows,
  matching SQLite (NULL and true pass; only an explicit false fails; `OR IGNORE`
  skips). Constraints survive reload by re-parsing the stored `CREATE TABLE` SQL.
- **Savepoints.** `SAVEPOINT`, `RELEASE`, and `ROLLBACK TO` are now real
  sub-transactions: `ROLLBACK TO` undoes only the changes since the savepoint and
  keeps the transaction open, nesting works, and a `SAVEPOINT` outside an explicit
  transaction starts one implicitly (releasing the outermost commits). Previously
  these were no-ops and `ROLLBACK TO` discarded the entire transaction.
- **Partial indexes.** `CREATE INDEX ... WHERE <predicate>` now indexes only the
  matching rows, maintains membership as rows are updated in and out of the
  predicate, enforces a unique partial index only within its predicate, and
  reports `partial=1` from `PRAGMA index_list`.
- **MySQL/MariaDB benchmark** (`benchmarks/bench_mysql.php`) comparing file-backed
  durable YetiSQL against a local MySQL/MariaDB server; see *Performance* in the
  README.
- **UPSERT.** `INSERT ... ON CONFLICT [(target)] DO NOTHING` and
  `... DO UPDATE SET ... [WHERE ...]` are now supported, including the `excluded`
  pseudo-table holding the would-be-inserted row and a conflict target that
  selects a specific unique constraint. This is what Laravel's `upsert()` /
  `updateOrCreate` emit on SQLite.
- **Boolean literals.** Bare `TRUE` and `FALSE` evaluate to `1` and `0`, as in
  SQLite (used by query builders in generated `CASE` expressions).

### Changed

- `PDOStatement::bindParam()` now binds **by reference** and reads the variable at
  `execute()` time, matching PDO semantics (it previously snapshotted the value at
  bind time, like `bindValue()`).
- `MATCH` now raises "unable to use function MATCH in the requested context"
  instead of silently behaving like `LIKE` (FTS5 is not yet implemented). As in
  SQLite this is a runtime error raised when a row is evaluated, so a query over
  an empty table still returns no rows.
- Engine errors raised during lazy row evaluation (`fetch()` / `fetchAll()`) now
  follow the connection's error mode, surfacing as a `PDOException` under
  `ERRMODE_EXCEPTION` rather than a raw engine exception.
- Expression indexes (`CREATE INDEX ... ON t(expr)`) are now rejected explicitly
  rather than silently created and never used.
- The Eloquent connection now reports `getDriverName()` as `sqlite` (it extends
  `SQLiteConnection` and speaks the SQLite dialect), so application code and
  migrations that branch on the driver name treat YetiSQL as SQLite. The
  `illuminate/database` dev dependency now also allows `^13.0`.

### Performance

- **`CREATE INDEX` builds the index bottom-up** from sorted keys in one linear
  pass instead of an insert-with-split per row, removing superlinear scaling
  (~2.4× faster building three indexes over 20k rows).
- **O(1) page space accounting:** a B-tree page tracks its used bytes incrementally
  instead of re-summing every cell on each insert, so `fits()` no longer makes leaf
  fills quadratic.
- **Covered `COUNT(*)` over indexed joins and correlated subqueries** can answer
  from a cached per-index count map, with an inner-table-size threshold so small
  inners build the shared map eagerly while large inners with few driving rows fall
  back to cheap per-row index seeks (keeps both small- and large-table cases fast).
- Reused the inner-table access plan and `IndexBTree` instance across a join's
  outer rows instead of rebuilding them per row.

### Fixed

- Lost-update / whole-transaction-loss hazard for ORM nested transactions that
  relied on savepoints (see *Added*).
- Several covered-count and hash/count-map keying correctness issues surfaced while
  extending the join and correlated-subquery fast paths, each verified against the
  `pdo_sqlite` differential oracle.
- **`ALTER TABLE ADD/DROP COLUMN` no longer drops constraints.** The table's
  CREATE statement was re-serialized without its FOREIGN KEY, CHECK, or
  generated-column definitions, silently losing them (and any cascade behaviour)
  whenever a column was added or dropped. The serializer now reproduces them.
- **`PRAGMA table_info` reports the real `dflt_value` and `pk`.** `dflt_value` is
  now the verbatim DEFAULT source text (e.g. `''`, `CURRENT_TIMESTAMP`) rather
  than the evaluated value, and a column named in a table-level `PRIMARY KEY(...)`
  now reports `pk=1`. Both are read back by ORMs when rebuilding a table; getting
  them wrong dropped primary keys and corrupted regenerated defaults.
- A multi-column index equality plan (`WHERE a = ? AND b = ?`) raised
  "Undefined array key values" when reached through the `DELETE` / `UPDATE` /
  uniqueness-probe path; the composite prefix seek is now used there too.
- The above were all found and fixed by running the Koel application's full test
  suite (unit, integration, feature) against YetiSQL as its Eloquent database.
