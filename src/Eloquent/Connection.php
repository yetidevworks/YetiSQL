<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Eloquent;

use Illuminate\Database\SQLiteConnection;

/**
 * Eloquent / Illuminate database connection backed by a YetiSQL engine.
 *
 * Because YetiSQL speaks the SQLite dialect, we extend {@see SQLiteConnection}
 * so Laravel reuses its SQLite query grammar, schema grammar, and processor —
 * the SQL it generates is exactly what the YetiSQL parser understands.
 *
 * The one impedance mismatch: Illuminate's Connection::prepared() type-hints the
 * native \PDOStatement, and YetiSQL\PDOStatement is only PDO-*shaped* (it cannot
 * extend the C-level class). PHP contravariance lets us widen that parameter
 * away in the override, so our statement object flows through unchanged.
 *
 * @see YetiSql::register() for wiring this into a Capsule / DatabaseManager.
 */
final class Connection extends SQLiteConnection
{
    /**
     * Configure the prepared statement. Identical to the parent, but with the
     * \PDOStatement type hint removed so a YetiSQL\PDOStatement is accepted.
     *
     * @param  \YetiDevWorks\YetiSQL\PDOStatement  $statement
     * @return \YetiDevWorks\YetiSQL\PDOStatement
     */
    protected function prepared($statement)
    {
        $statement->setFetchMode($this->fetchMode);

        // StatementPrepared carries the statement untyped, so this is safe even
        // though $statement is not a native \PDOStatement.
        $this->event(new \Illuminate\Database\Events\StatementPrepared($this, $statement));

        return $statement;
    }

    /**
     * Report a SQLite server version so the SQLite grammar/schema builder pick
     * the dialect features we implement, without probing PDO::ATTR_SERVER_VERSION
     * (which the shaped driver does not expose).
     */
    public function getServerVersion(): string
    {
        return '3.45.0';
    }

    /**
     * Identify as the SQLite driver. The connection is registered under the
     * `yetisql` resolver key, but at runtime it *is* the SQLite dialect (same
     * grammar, schema builder, and processor), so application and framework
     * code that branches on `DB::getDriverName() === 'sqlite'` — e.g. migrations
     * guarding SQLite-only schema limitations — must treat YetiSQL as SQLite.
     */
    public function getDriverName()
    {
        return 'sqlite';
    }
}
