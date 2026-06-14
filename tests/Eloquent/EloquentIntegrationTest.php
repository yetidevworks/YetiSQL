<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Eloquent;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\Eloquent\YetiSql;

/**
 * Drives YetiSQL through the real Eloquent / Illuminate database stack: the
 * Capsule manager, schema builder (migrations), query builder, Eloquent models
 * with relationships, and transactions. Proves the SQLite-dialect grammar
 * Laravel generates runs unmodified on the YetiSQL engine.
 */
final class EloquentIntegrationTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        $this->capsule = new Capsule();
        $this->capsule->addConnection([
            'driver' => 'yetisql',
            'database' => ':memory:',
            'prefix' => '',
        ], 'default');
        YetiSql::register($this->capsule);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $schema = $this->capsule->schema();
        $schema->create('authors', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('name');
            $t->integer('age')->nullable();
        });
        $schema->create('books', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('author_id');
            $t->string('title');
            $t->integer('votes')->default(0);
            $t->index('author_id');
        });
    }

    public function testSchemaBuilderAndIntrospection(): void
    {
        $schema = $this->capsule->schema();
        self::assertTrue($schema->hasTable('authors'));
        self::assertTrue($schema->hasColumn('books', 'title'));
        self::assertFalse($schema->hasColumn('books', 'nope'));
        self::assertSame(
            ['id', 'author_id', 'title', 'votes'],
            $schema->getColumnListing('books'),
        );
    }

    public function testQueryBuilderCrud(): void
    {
        Capsule::table('authors')->insert([
            ['name' => 'Tolkien', 'age' => 81],
            ['name' => 'Le Guin', 'age' => 88],
        ]);

        self::assertSame(2, Capsule::table('authors')->count());
        self::assertSame('Le Guin', Capsule::table('authors')->where('age', '>', 85)->value('name'));
        self::assertEqualsWithDelta(84.5, (float) Capsule::table('authors')->avg('age'), 1e-9);

        Capsule::table('authors')->where('name', 'Tolkien')->update(['age' => 82]);
        self::assertSame(82, (int) Capsule::table('authors')->where('name', 'Tolkien')->value('age'));

        self::assertSame(1, Capsule::table('authors')->where('name', 'Le Guin')->delete());
        self::assertSame(1, Capsule::table('authors')->count());
    }

    public function testEloquentModelCrud(): void
    {
        $a = Author::create(['name' => 'Pratchett', 'age' => 66]);
        self::assertIsInt($a->id);
        self::assertSame('Pratchett', Author::find($a->id)->name);

        $a->age = 67;
        $a->save();
        self::assertSame(67, Author::find($a->id)->age);

        self::assertSame(1, Author::where('age', '>', 60)->count());
        $a->delete();
        self::assertSame(0, Author::count());
    }

    public function testRelationshipsAndEagerLoading(): void
    {
        $tolkien = Author::create(['name' => 'Tolkien']);
        $leguin = Author::create(['name' => 'Le Guin']);
        Book::insert([
            ['author_id' => $tolkien->id, 'title' => 'The Hobbit', 'votes' => 5],
            ['author_id' => $tolkien->id, 'title' => 'LOTR', 'votes' => 9],
            ['author_id' => $leguin->id, 'title' => 'Earthsea', 'votes' => 7],
        ]);

        self::assertSame(['The Hobbit', 'LOTR'], $tolkien->books->pluck('title')->all());
        self::assertSame('Le Guin', Book::where('title', 'Earthsea')->first()->author->name);

        $eager = Author::with('books')->orderBy('id')->get()
            ->map(static fn (Author $a): array => [$a->name, $a->books->count()])
            ->all();
        self::assertSame([['Tolkien', 2], ['Le Guin', 1]], $eager);
    }

    public function testJoinAndGroupBy(): void
    {
        $t = Author::create(['name' => 'Tolkien']);
        $l = Author::create(['name' => 'Le Guin']);
        Book::insert([
            ['author_id' => $t->id, 'title' => 'The Hobbit', 'votes' => 5],
            ['author_id' => $t->id, 'title' => 'LOTR', 'votes' => 9],
            ['author_id' => $l->id, 'title' => 'Earthsea', 'votes' => 7],
        ]);

        $rows = Capsule::table('books')
            ->join('authors', 'books.author_id', '=', 'authors.id')
            ->where('books.votes', '>', 6)
            ->orderBy('books.id')
            ->pluck('authors.name', 'books.title')
            ->all();
        self::assertSame(['LOTR' => 'Tolkien', 'Earthsea' => 'Le Guin'], $rows);

        $counts = Capsule::table('books')
            ->selectRaw('author_id, COUNT(*) as c')
            ->groupBy('author_id')
            ->orderBy('author_id')
            ->get()
            ->map(static fn ($r): array => [(int) $r->author_id, (int) $r->c])
            ->all();
        self::assertSame([[1, 2], [2, 1]], $counts);
    }

    public function testTransactionCommitAndRollback(): void
    {
        $conn = $this->capsule->getConnection('default');

        $conn->transaction(static function () {
            Author::create(['name' => 'Committed']);
        });
        self::assertSame(1, Author::count());

        try {
            $conn->transaction(static function () {
                Author::create(['name' => 'RolledBack']);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }
        self::assertSame(1, Author::count());
        self::assertNull(Author::where('name', 'RolledBack')->first());
    }
}

class Author extends Model
{
    public $timestamps = false;
    protected $table = 'authors';
    protected $guarded = [];

    public function books()
    {
        return $this->hasMany(Book::class);
    }
}

class Book extends Model
{
    public $timestamps = false;
    protected $table = 'books';
    protected $guarded = [];

    public function author()
    {
        return $this->belongsTo(Author::class);
    }
}
