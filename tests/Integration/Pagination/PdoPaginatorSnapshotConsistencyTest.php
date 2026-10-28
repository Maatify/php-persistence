<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Tests\Support\Pagination\PaginationFixture;

/**
 * B-08a evidence: `paginate()` issues three consecutive statements (total, filtered,
 * data) and owns no transaction. Outside a caller transaction, each statement is its
 * own implicit, independently snapshotted unit of work on InnoDB, so a second
 * connection's committed write between two of the three statements can be visible to
 * one and not the other. Deterministic, not timing-based: a second real connection
 * writes and commits from inside the paginator's own `prepare()` call, numbered by a
 * counting wrapper, never from a sleep. A caller-owned REPEATABLE READ transaction
 * fixes the snapshot for all three statements and is the documented mitigation.
 */
final class PdoPaginatorSnapshotConsistencyTest extends PaginationIntegrationTestCase
{
    public function testWithoutACallerTransactionTheThreeReadsDoNotShareOneSnapshot(): void
    {
        [$seed, $table] = $this->connectWithPaginationTable();
        $fixture = new PaginationFixture($seed, $table);
        $fixture->insertItem(1, 'book', 'Alpha', 10, true, null, '2026-01-01 00:00:00.000000');
        $fixture->insertItem(1, 'book', 'Beta', 10, true, null, '2026-01-02 00:00:00.000000');

        $writer = $this->connect();
        $paginating = $this->connectInterleaving(function (int $callNumber, string $sql) use ($writer, $table): void {
            unset($sql);
            if ($callNumber === 2) {
                // Lands strictly between the already-executed total-count statement and
                // the about-to-run filtered-count statement; autocommits immediately
                // because $writer has no transaction of its own.
                (new PaginationFixture($writer, $table))->insertItem(1, 'book', 'Gamma', 20, true, null, '2026-01-03 00:00:00.000000');
            }
        });

        $result = (new PdoPaginator())->paginate(
            $paginating,
            $this->descriptor($table),
            new PageRequest(),
            $this->config(),
            static fn(array $row): array => $row,
        );

        self::assertFalse($paginating->inTransaction(), 'the paginator must not open a transaction of its own');
        self::assertSame(2, $result->total, 'the total count ran before the concurrent insert');
        self::assertSame(
            3,
            $result->filtered,
            'the filtered count ran after the concurrent insert, so it is not the same snapshot as the total count',
        );
        self::assertGreaterThan(
            $result->total,
            $result->filtered,
            'a stricter, later-evaluated filter outnumbering an earlier, looser total is only possible without a single shared snapshot',
        );
    }

    public function testWithACallerOwnedTransactionTheThreeReadsShareOneRepeatableReadSnapshot(): void
    {
        [$seed, $table] = $this->connectWithPaginationTable();
        $fixture = new PaginationFixture($seed, $table);
        $fixture->insertItem(1, 'book', 'Alpha', 10, true, null, '2026-01-01 00:00:00.000000');
        $fixture->insertItem(1, 'book', 'Beta', 10, true, null, '2026-01-02 00:00:00.000000');

        $writer = $this->connect();
        $paginating = $this->connectInterleaving(function (int $callNumber, string $sql) use ($writer, $table): void {
            unset($sql);
            if ($callNumber === 2) {
                (new PaginationFixture($writer, $table))->insertItem(1, 'book', 'Gamma', 20, true, null, '2026-01-03 00:00:00.000000');
            }
        });

        $paginating->beginTransaction();
        try {
            $result = (new PdoPaginator())->paginate(
                $paginating,
                $this->descriptor($table),
                new PageRequest(),
                $this->config(),
                static fn(array $row): array => $row,
            );

            self::assertTrue($paginating->inTransaction(), 'the caller-owned transaction must remain under caller ownership');
            self::assertSame(2, $result->total);
            self::assertSame(
                2,
                $result->filtered,
                'REPEATABLE READ keeps every statement of the caller transaction on the snapshot taken at its first statement',
            );
        } finally {
            $paginating->rollBack();
        }
    }
}
