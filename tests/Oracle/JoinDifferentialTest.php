<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Oracle;

use PDO as RealPDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\PDO as YetiPDO;

/**
 * Differential coverage for joins, exercising the index nested-loop path: the
 * inner table is reached by a per-outer-row rowid/index seek rather than a full
 * scan. Each query must return exactly what pdo_sqlite returns — including LEFT
 * joins, NULL foreign keys, extra ON conjuncts, and multi-table chains.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class JoinDifferentialTest extends TestCase
{
    private const SETUP = [
        'CREATE TABLE authors (id INTEGER PRIMARY KEY, name TEXT, country TEXT)',
        'CREATE TABLE books (id INTEGER PRIMARY KEY, author_id INTEGER, title TEXT, year INTEGER)',
        'CREATE INDEX idx_books_author ON books(author_id)',
        'CREATE TABLE sales (book_id INTEGER, qty INTEGER)',
        'CREATE INDEX idx_sales_book ON sales(book_id)',
        "INSERT INTO authors VALUES (1,'Tolkien','UK'),(2,'Le Guin','US'),(3,'Borges','AR'),(4,'NoBooks','XX')",
        "INSERT INTO books VALUES (10,1,'Hobbit',1937),(11,1,'LOTR',1954),(12,2,'Earthsea',1968),"
            . "(13,2,'Dispossessed',1974),(14,3,'Ficciones',1944),(15,NULL,'Orphan',2000)",
        'INSERT INTO sales VALUES (10,100),(10,50),(11,200),(12,75),(14,30)',
    ];

    /** @return iterable<string,array{0:string}> */
    public static function queries(): iterable
    {
        $cases = [
            // inner join via indexed FK (seek) and via rowid PK (seek)
            'SELECT b.title, a.name FROM books b JOIN authors a ON b.author_id = a.id ORDER BY b.id',
            'SELECT a.name, b.title FROM authors a JOIN books b ON b.author_id = a.id ORDER BY a.id, b.id',
            'SELECT b.title FROM books b JOIN authors a ON a.id = b.author_id ORDER BY b.id',
            // left joins: unmatched outer (NoBooks) and NULL inner key (Orphan)
            'SELECT a.name, b.title FROM authors a LEFT JOIN books b ON b.author_id = a.id ORDER BY a.id, b.title',
            'SELECT b.title, a.name FROM books b LEFT JOIN authors a ON b.author_id = a.id ORDER BY b.id',
            // extra ON conjunct beyond the equi-key
            'SELECT a.name, b.title FROM authors a JOIN books b ON b.author_id = a.id AND b.year > 1950 ORDER BY a.id, b.id',
            // join + WHERE
            "SELECT b.title FROM books b JOIN authors a ON b.author_id = a.id WHERE a.country = 'US' ORDER BY b.id",
            // three-table chain
            'SELECT a.name, b.title, s.qty FROM authors a JOIN books b ON b.author_id = a.id '
                . 'JOIN sales s ON s.book_id = b.id ORDER BY a.id, b.id, s.qty',
            // join feeding an aggregate
            'SELECT a.name, COUNT(*) c FROM authors a JOIN books b ON b.author_id = a.id GROUP BY a.name ORDER BY a.name',
            'SELECT b.title, SUM(s.qty) t FROM books b LEFT JOIN sales s ON s.book_id = b.id GROUP BY b.id ORDER BY b.id',
            // expression join key (rowid seek with computed key)
            'SELECT a.id, b.id FROM authors a JOIN authors b ON b.id = a.id + 1 ORDER BY a.id',
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

        foreach (self::SETUP as $ddl) {
            $yeti->exec($ddl);
            $real->exec($ddl);
        }

        self::assertSame(
            $real->query($sql)->fetchAll(RealPDO::FETCH_NUM),
            $yeti->query($sql)->fetchAll(YetiPDO::FETCH_NUM),
            $sql,
        );
    }

    public function testUnindexedEquiJoinMatchesSqlite(): void
    {
        $setup = \array_values(\array_filter(
            self::SETUP,
            static fn (string $sql): bool => !\str_starts_with($sql, 'CREATE INDEX'),
        ));
        $queries = [
            'SELECT a.name, b.title FROM authors a JOIN books b ON b.author_id = a.id ORDER BY a.id, b.id',
            'SELECT a.name, b.title FROM authors a JOIN books b ON b.author_id = a.id AND b.year > 1950 ORDER BY a.id, b.id',
            'SELECT a.name, b.title FROM authors a LEFT JOIN books b ON b.author_id = a.id ORDER BY a.id, b.title',
            'SELECT a.name, b.title, s.qty FROM authors a JOIN books b ON b.author_id = a.id '
                . 'JOIN sales s ON s.book_id = b.id ORDER BY a.id, b.id, s.qty',
        ];

        foreach ($queries as $sql) {
            $yeti = new YetiPDO('yetisql::memory:');
            $real = new RealPDO('sqlite::memory:');
            $real->setAttribute(RealPDO::ATTR_ERRMODE, RealPDO::ERRMODE_EXCEPTION);
            foreach ($setup as $ddl) {
                $yeti->exec($ddl);
                $real->exec($ddl);
            }

            self::assertSame(
                $real->query($sql)->fetchAll(RealPDO::FETCH_NUM),
                $yeti->query($sql)->fetchAll(YetiPDO::FETCH_NUM),
                $sql,
            );
        }
    }
}
