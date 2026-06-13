<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YetiDevWorks\YetiSQL\Engine\Blob;
use YetiDevWorks\YetiSQL\Engine\Pager;
use YetiDevWorks\YetiSQL\Engine\RecordCodec;
use YetiDevWorks\YetiSQL\Engine\TableBTree;
use YetiDevWorks\YetiSQL\Engine\Varint;

final class StorageEngineTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = \sys_get_temp_dir() . '/yetisql_test_' . \bin2hex(\random_bytes(6)) . '.ysql';
    }

    protected function tearDown(): void
    {
        @\unlink($this->path);
        @\unlink($this->path . '-journal');
    }

    /** @return list<int> */
    public static function varintCases(): array
    {
        return [0, 1, 127, 128, 16383, 16384, 2097151, 2097152,
            2147483647, -1, -128, -129, PHP_INT_MAX, PHP_INT_MIN,
            140737488355327, 140737488355328];
    }

    public function testVarintRoundTrips(): void
    {
        foreach (self::varintCases() as $v) {
            [$decoded, $n] = Varint::decode(Varint::encode($v));
            self::assertSame($v, $decoded, "varint round-trip for $v");
            self::assertSame(\strlen(Varint::encode($v)), $n);
        }
    }

    public function testRecordCodecRoundTrips(): void
    {
        $rows = [
            [null, 1, -42, 3.14, 'hello', new Blob("\x00\x01\xff")],
            [0, 1, PHP_INT_MAX, PHP_INT_MIN, '', 'café ☕'],
            [12345678901234, -9999999999, 2.5e300],
        ];
        foreach ($rows as $row) {
            $decoded = RecordCodec::decode(RecordCodec::encode($row));
            foreach ($row as $i => $v) {
                $a = $v instanceof Blob ? $v->bytes : $v;
                $b = $decoded[$i] instanceof Blob ? $decoded[$i]->bytes : $decoded[$i];
                self::assertSame($a, $b);
                $c = RecordCodec::decodeColumn(RecordCodec::encode($row), $i);
                $cc = $c instanceof Blob ? $c->bytes : $c;
                self::assertSame($a, $cc);
            }
        }
    }

    public function testBTreeInsertScanPersistAcrossReopen(): void
    {
        $pager = new Pager($this->path);
        $pager->beginTransaction();
        $root = TableBTree::create($pager);
        $tree = new TableBTree($pager, $root);

        $ids = \range(1, 3000);
        \shuffle($ids);
        foreach ($ids as $id) {
            $tree->put($id, RecordCodec::encode([$id, "row-$id"]));
        }
        $tree->put(99999, RecordCodec::encode([99999, \str_repeat('X', 20000)])); // overflow
        $pager->commit();
        $pager->close();

        $pager = new Pager($this->path);
        $tree = new TableBTree($pager, $root);
        $count = 0;
        $prev = 0;
        foreach ($tree->scan() as [$rid, $payload]) {
            self::assertGreaterThan($prev, $rid, 'rows must be in ascending rowid order');
            $prev = $rid;
            $count++;
        }
        self::assertSame(3001, $count);
        self::assertSame(3001, $tree->countRange());
        self::assertSame(1001, $tree->countRange(1000, true, 2000, true));
        self::assertSame(20000, \strlen(RecordCodec::decode($tree->get(99999))[1]));
        self::assertNull($tree->get(123456));
        $pager->close();
    }

    public function testTransactionRollbackRestoresState(): void
    {
        $pager = new Pager($this->path);
        $pager->beginTransaction();
        $root = TableBTree::create($pager);
        $tree = new TableBTree($pager, $root);
        $tree->put(1, RecordCodec::encode(['original']));
        $pager->commit();

        $pager->beginTransaction();
        $tree->put(1, RecordCodec::encode(['mutated']));
        $tree->put(2, RecordCodec::encode(['new']));
        $pager->rollback();

        self::assertSame('original', RecordCodec::decode($tree->get(1))[0]);
        self::assertNull($tree->get(2));
        $pager->close();
    }
}
