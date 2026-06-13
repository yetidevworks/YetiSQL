<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Engine;

use YetiDevWorks\YetiSQL\Exception\YetiSQLException;

/**
 * Low-level reader/writer for a single b-tree page buffer.
 *
 * A page is decoded into a flat list of raw cell byte-strings (in key order)
 * plus its type and right-child pointer, then re-encoded from scratch on every
 * mutation. Rebuilding the page each write keeps the layout compact with zero
 * fragmentation bookkeeping — simpler and less bug-prone than in-place edits,
 * and the page cache keeps it off the disk hot path.
 *
 * Header (12 bytes):
 *   [0]      page type (TABLE_LEAF=13, TABLE_INTERIOR=5, INDEX_LEAF=10, INDEX_INTERIOR=2)
 *   [1..2]   cell count            (uint16)
 *   [3..4]   cell content start    (uint16)
 *   [5..8]   right-most child page (uint32, interior only)
 *   [9..11]  reserved
 * Followed by the cell-pointer array (uint16 each) at offset 12.
 */
final class BTreePage
{
    public const TABLE_LEAF = 13;
    public const TABLE_INTERIOR = 5;
    public const INDEX_LEAF = 10;
    public const INDEX_INTERIOR = 2;

    public const HEADER = 12;

    public int $type;
    public int $rightChild = 0;
    /** @var list<string> raw cell byte-strings, in key order */
    public array $cells = [];

    public function __construct(int $type)
    {
        $this->type = $type;
    }

    public function isLeaf(): bool
    {
        return $this->type === self::TABLE_LEAF || $this->type === self::INDEX_LEAF;
    }

    public function isTable(): bool
    {
        return $this->type === self::TABLE_LEAF || $this->type === self::TABLE_INTERIOR;
    }

    public static function newLeaf(int $pageSize, bool $isTable): string
    {
        $p = new self($isTable ? self::TABLE_LEAF : self::INDEX_LEAF);
        return $p->encode($pageSize);
    }

    public static function decode(string $buf): self
    {
        $type = \ord($buf[0]);
        /** @var array{count:int,content:int,right:int} $h */
        $h = \unpack('ncount/ncontent/Nright', \substr($buf, 1, 8));
        $page = new self($type);
        $page->rightChild = $h['right'];

        $count = $h['count'];
        $pageSize = \strlen($buf);
        for ($i = 0; $i < $count; $i++) {
            /** @var array{1:int} $ptr */
            $ptr = \unpack('n', \substr($buf, self::HEADER + $i * 2, 2));
            $off = $ptr[1];
            $len = self::cellLength($buf, $off, $type, $pageSize);
            $page->cells[] = \substr($buf, $off, $len);
        }
        return $page;
    }

    /** Compute the byte length of the cell stored at $off. */
    public static function cellLength(string $buf, int $off, int $type, int $pageSize): int
    {
        $maxLocal = self::maxLocal($pageSize);
        switch ($type) {
            case self::TABLE_LEAF:
                [$plen, $n1] = Varint::decode($buf, $off);
                [, $n2] = Varint::decode($buf, $off + $n1);
                $local = \min($plen, $maxLocal);
                return $n1 + $n2 + $local + ($plen > $maxLocal ? 4 : 0);
            case self::TABLE_INTERIOR:
                [, $n] = Varint::decode($buf, $off + 4);
                return 4 + $n;
            case self::INDEX_LEAF:
                [$plen, $n1] = Varint::decode($buf, $off);
                $local = \min($plen, $maxLocal);
                return $n1 + $local + ($plen > $maxLocal ? 4 : 0);
            case self::INDEX_INTERIOR:
                [$plen, $n1] = Varint::decode($buf, $off + 4);
                $local = \min($plen, $maxLocal);
                return 4 + $n1 + $local + ($plen > $maxLocal ? 4 : 0);
            default:
                throw new YetiSQLException("bad page type $type");
        }
    }

    public static function maxLocal(int $pageSize): int
    {
        // Largest payload kept inline before spilling to overflow pages.
        return $pageSize - 35;
    }

    /** Bytes available for cells + pointer array. */
    public static function usable(int $pageSize): int
    {
        return $pageSize - self::HEADER;
    }

    /** Total space this page's contents would occupy (pointer array + cells). */
    public function spaceUsed(): int
    {
        $total = \count($this->cells) * 2;
        foreach ($this->cells as $c) {
            $total += \strlen($c);
        }
        return $total;
    }

    public function fits(int $pageSize): bool
    {
        return $this->spaceUsed() <= self::usable($pageSize);
    }

    public function encode(int $pageSize): string
    {
        $count = \count($this->cells);

        // Place cells from the end of the page downward (cell 0 at the highest
        // offset), recording each cell's start offset for the pointer array.
        $offset = $pageSize;
        $pointers = '';
        foreach ($this->cells as $cell) {
            $offset -= \strlen($cell);
            $pointers .= \pack('n', $offset);
        }
        $contentStart = $offset;

        $pointerArrayEnd = self::HEADER + $count * 2;
        if ($contentStart < $pointerArrayEnd) {
            throw new YetiSQLException('b-tree page overflow (caller must split)');
        }

        // Cells are laid out at decreasing offsets in key order, so the physical
        // content region (from $contentStart upward) is the cells in reverse.
        $content = '';
        for ($i = $count - 1; $i >= 0; $i--) {
            $content .= $this->cells[$i];
        }

        $header = \chr($this->type)
            . \pack('n', $count)
            . \pack('n', $contentStart)
            . \pack('N', $this->rightChild)
            . "\x00\x00\x00";

        $prefix = $header . $pointers;
        return $prefix . \str_repeat("\x00", $contentStart - \strlen($prefix)) . $content;
    }
}
