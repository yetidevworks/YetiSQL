<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Oracle;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Differential coverage for the JSON1 surface: the json_* scalar functions, the
 * `->` / `->>` operators, the json_group_array/json_group_object aggregates, and
 * the json_each/json_tree table-valued functions. Each query must return exactly
 * what pdo_sqlite returns (skipped if the system SQLite lacks JSON1).
 *
 * json_each/json_tree `id` and `parent` are deliberately excluded: SQLite's are
 * internal byte offsets, not a portable numbering.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class Json1DifferentialTest extends TestCase
{
    private const SETUP = [
        'CREATE TABLE docs (id INTEGER PRIMARY KEY, body TEXT)',
        "INSERT INTO docs VALUES (1, '{\"name\":\"ann\",\"age\":30,\"tags\":[\"x\",\"y\"],\"addr\":{\"city\":\"NYC\"}}')",
        "INSERT INTO docs VALUES (2, '{\"name\":\"bob\",\"age\":25,\"tags\":[],\"addr\":{\"city\":\"LA\"}}')",
        "INSERT INTO docs VALUES (3, '{\"name\":\"cy\",\"age\":40,\"tags\":[\"z\"],\"addr\":null}')",
    ];

    protected function setUp(): void
    {
        $probe = new RealPDO('sqlite::memory:');
        try {
            $probe->query("SELECT json('{}')");
        } catch (\Throwable) {
            self::markTestSkipped('system SQLite was built without JSON1');
        }
    }

    /** @return iterable<string,array{0:string}> */
    public static function queries(): iterable
    {
        $J = "'{\"a\":1,\"b\":\"x\",\"c\":[10,20],\"d\":null,\"e\":true}'";
        $cases = [
            // --- validation / type / minify ---
            "SELECT json(' {\"a\" : 1 ,\"b\":[2 ,3]} ')",
            "SELECT json_valid('{}'), json_valid('{bad'), json_valid('[1,2]'), json_valid(NULL)",
            "SELECT json_type($J), json_type($J,'\$.c'), json_type($J,'\$.e'), json_type($J,'\$.z')",
            "SELECT json_type('1.5'), json_type('\"s\"'), json_type('null'), json_type('false')",

            // --- extract: single path (SQL value) vs multi (JSON array) ---
            "SELECT json_extract($J,'\$.a'), json_extract($J,'\$.b'), json_extract($J,'\$.c')",
            "SELECT json_extract($J,'\$.d'), json_extract($J,'\$.e'), json_extract($J,'\$.z')",
            "SELECT json_extract($J,'\$.a','\$.b','\$.z')",
            "SELECT json_extract('[10,20,30]','\$[1]'), json_extract('[10,20,30]','\$[#-1]')",
            "SELECT json_extract($J,'\$')",

            // --- constructors ---
            "SELECT json_array(1, 2.5, 'x', NULL, -7)",
            "SELECT json_object('k', 1, 'm', 'v', 'n', NULL)",
            "SELECT json_array(), json_object()",
            "SELECT json_quote('a\"b'), json_quote(5), json_quote(2.5), json_quote(NULL)",
            "SELECT json_array_length('[1,2,3]'), json_array_length($J,'\$.c'), json_array_length($J,'\$.a'), json_array_length($J,'\$.z')",

            // --- mutators ---
            "SELECT json_set($J,'\$.a',99,'\$.f',7)",
            "SELECT json_insert($J,'\$.a',99,'\$.f',7)",
            "SELECT json_replace($J,'\$.a',99,'\$.f',7)",
            "SELECT json_set('{\"a\":{\"b\":1}}','\$.a.b',5,'\$.a.c',6)",
            "SELECT json_set('[1,2,3]','\$[#]',99), json_replace('[1,2,3]','\$[1]',20)",
            "SELECT json_remove($J,'\$.b','\$.c')",
            "SELECT json_remove('[1,2,3,4]','\$[1]','\$[1]')",
            "SELECT json_patch('{\"a\":1,\"b\":2,\"c\":{\"x\":1}}','{\"b\":null,\"c\":{\"y\":2},\"d\":3}')",

            // --- error parity (malformed JSON, bad path, bad arity) ---
            "SELECT json('not json')",
            "SELECT json_extract('{}','nopath')",
            "SELECT json_object('a')",

            // --- arrow operators ---
            "SELECT $J->'\$.b', $J->>'\$.b', $J->'b', $J->>'b'",
            "SELECT $J->'\$.c', $J->>'\$.c', $J->'\$.z', $J->>'\$.z'",
            "SELECT '[10,20,30]'->1, '[10,20,30]'->>1, '[10,20,30]'->>-1",
            "SELECT $J->>'\$.a' + 1, '[5]'->>0 || 'z'",
            "SELECT '{\"a\":[{\"b\":7}]}'->>'\$.a[0].b'",

            // --- json_each / json_tree (deterministic columns only) ---
            "SELECT key, value, type, atom, fullkey, path FROM json_each($J)",
            "SELECT key, value, type, atom, fullkey, path FROM json_each('[5,6,7]')",
            "SELECT value FROM json_each($J,'\$.c')",
            "SELECT key, value, type, fullkey, path FROM json_tree('{\"a\":[10,{\"b\":2}]}') ORDER BY fullkey",
            "SELECT count(*) FROM json_each($J,'\$.x')",
        ];
        foreach ($cases as $sql) {
            yield $sql => [$sql];
        }
    }

    #[DataProvider('queries')]
    public function testMatchesSqlite(string $sql): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        $real = new RealPDO('sqlite::memory:');
        $real->setAttribute(RealPDO::ATTR_ERRMODE, RealPDO::ERRMODE_EXCEPTION);

        $yErr = $rErr = null;
        try {
            $actual = $yeti->query($sql)->fetchAll(YetiPDO::FETCH_NUM);
        } catch (\Throwable $e) {
            $yErr = $e;
        }
        try {
            $expected = $real->query($sql)->fetchAll(RealPDO::FETCH_NUM);
        } catch (\Throwable $e) {
            $rErr = $e;
        }

        if ($rErr !== null || $yErr !== null) {
            self::assertNotNull($rErr, "YetiSQL errored but SQLite did not for: $sql");
            self::assertNotNull($yErr, "SQLite errored but YetiSQL did not for: $sql");
            return;
        }

        self::assertSame($expected, $actual, $sql);
    }

    /** Queries against a TEXT column, including the arrow operators and a lateral json_each. */
    public function testJsonOverTableColumnMatchesSqlite(): void
    {
        $yeti = new YetiPDO('yetisql::memory:');
        $real = new RealPDO('sqlite::memory:');
        $real->setAttribute(RealPDO::ATTR_ERRMODE, RealPDO::ERRMODE_EXCEPTION);
        foreach (self::SETUP as $ddl) {
            $yeti->exec($ddl);
            $real->exec($ddl);
        }

        $queries = [
            "SELECT id, body->>'\$.name', body->>'\$.age' FROM docs ORDER BY id",
            "SELECT id FROM docs WHERE body->>'\$.age' > 28 ORDER BY id",
            "SELECT id, json_extract(body,'\$.addr.city') FROM docs ORDER BY id",
            "SELECT id, json_array_length(body,'\$.tags') FROM docs ORDER BY id",
            "SELECT d.id, t.value FROM docs d, json_each(d.body, '\$.tags') t ORDER BY d.id, t.value",
            "SELECT json_group_array(body->>'\$.name') FROM docs",
            "SELECT json_group_object(body->>'\$.name', json_extract(body,'\$.age')) FROM docs",
        ];
        foreach ($queries as $sql) {
            self::assertSame(
                $real->query($sql)->fetchAll(RealPDO::FETCH_NUM),
                $yeti->query($sql)->fetchAll(YetiPDO::FETCH_NUM),
                $sql,
            );
        }
    }
}
