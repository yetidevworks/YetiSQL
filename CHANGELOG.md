# Changelog

All notable changes to YetiSQL are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/); this project has not yet cut a
tagged release, so everything lives under *Unreleased*.

## [Unreleased]

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
