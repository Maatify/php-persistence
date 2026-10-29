<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use PDO;
use stdClass;

final class PdoPaginatorQuerySemanticsTest extends PaginationIntegrationTestCase
{
    public function testParameterTypes(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $ids = $this->insertMultiTenantDataset($pdo, $table);

        $descriptor = new PdoPaginationQueryDescriptor(
            "SELECT COUNT(*) AS total_count FROM `$table` WHERE tenant_id = :tenant_id AND category = :category AND is_active = :is_active AND nullable_code <=> :nullable_code",
            ['tenant_id' => 1, 'category' => 'book', 'is_active' => true, 'nullable_code' => null],
            "SELECT COUNT(*) AS filtered_count FROM `$table` WHERE tenant_id = :tenant_id AND category = :category AND is_active = :is_active AND nullable_code <=> :nullable_code",
            ['tenant_id' => 1, 'category' => 'book', 'is_active' => true, 'nullable_code' => null],
            "SELECT id FROM `$table` WHERE tenant_id = :tenant_id AND category = :category AND is_active = :is_active AND nullable_code <=> :nullable_code",
            ['tenant_id' => 1, 'category' => 'book', 'is_active' => true, 'nullable_code' => null],
        );

        $result = (new PdoPaginator())->paginate(
            $pdo,
            $descriptor,
            new PageRequest(),
            $this->config(),
            static fn(array $row): array => $row,
        );

        // Alpha and Deleted are both tenant 1, category book, is_active true, and nullable_code null.
        // The query does not filter deleted_at IS NULL, so both match.
        // Default sort is created_at DESC: Deleted (row 5) was created after Alpha (row 1).
        self::assertSame(2, $result->total);
        self::assertSame(2, $result->filtered);
        self::assertSame([$ids[4], $ids[0]], self::idsFromRows($result->data));
    }

    public function testBaseTotalFilteredSortingTieBreakerAndConsecutivePages(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $ids = $this->insertMultiTenantDataset($pdo, $table);
        $paginator = new PdoPaginator();

        $emulatePrepares = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        self::assertTrue($emulatePrepares === false || $emulatePrepares === 0);

        $firstPage = $paginator->paginate(
            $pdo,
            $this->descriptor($table),
            new PageRequest(1, 2, 'score', ' asc '),
            $this->config(),
            static fn(array $row): array => $row,
        );
        self::assertSame(4, $firstPage->total);
        self::assertSame(3, $firstPage->filtered);
        self::assertSame(2, $firstPage->totalPages);
        self::assertSame([$ids[0], $ids[1]], self::idsFromRows($firstPage->data));

        $firstPageSingle = $paginator->paginate($pdo, $this->descriptor($table), new PageRequest(1, 1, 'score', 'ASC'), $this->config(), static fn($r) => $r);
        $middlePage = $paginator->paginate($pdo, $this->descriptor($table), new PageRequest(2, 1, 'score', 'ASC'), $this->config(), static fn($r) => $r);
        $finalPage = $paginator->paginate($pdo, $this->descriptor($table), new PageRequest(3, 1, 'score', 'ASC'), $this->config(), static fn($r) => $r);

        self::assertSame([$ids[0]], self::idsFromRows($firstPageSingle->data));
        self::assertSame([$ids[1]], self::idsFromRows($middlePage->data));
        self::assertSame([$ids[2]], self::idsFromRows($finalPage->data));

        $descPage = $paginator->paginate(
            $pdo,
            $this->descriptor($table),
            new PageRequest(1, 2, 'score', 'desc'),
            $this->config(),
            static fn(array $row): array => $row,
        );
        // Active tenant-1 rows: Alpha/Beta (score 10), Gamma (score 20).
        // score DESC, id ASC tie-breaker -> Gamma, then Alpha.
        self::assertSame([$ids[2], $ids[0]], self::idsFromRows($descPage->data));

        // `same_id` resolves to `id` in the whitelist, same as the configured tie-breaker;
        // the paginator must not duplicate the ORDER BY term.
        $tieBreakerPage = $paginator->paginate(
            $pdo,
            $this->descriptor($table),
            new PageRequest(1, 2, 'same_id', 'desc'),
            $this->config(),
            static fn(array $row): array => $row,
        );
        self::assertSame([$ids[2], $ids[1]], self::idsFromRows($tieBreakerPage->data));

        $overflow = $paginator->paginate(
            $pdo,
            $this->descriptor($table),
            new PageRequest(99, 2, 'score', ' asc '),
            $this->config(),
            static fn(array $row): array => $row,
        );
        self::assertSame(1, $overflow->page);
        self::assertSame([$ids[0], $ids[1]], self::idsFromRows($overflow->data));

        $emulatePreparesAfter = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        self::assertTrue($emulatePreparesAfter === false || $emulatePreparesAfter === 0);
    }

    public function testNoFilterIdenticalCountsNullBindingInvalidSortFallbackAndObjectMapper(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $ids = $this->insertMultiTenantDataset($pdo, $table);
        $descriptor = new PdoPaginationQueryDescriptor(
            "SELECT COUNT(*) AS total_count FROM `$table` WHERE tenant_id = :tenant AND deleted_at IS NULL AND nullable_code <=> :code",
            ['tenant' => 1, 'code' => null],
            "SELECT COUNT(*) AS filtered_count FROM `$table` WHERE tenant_id = :tenant_filtered AND deleted_at IS NULL AND nullable_code <=> :code_filtered",
            ['tenant_filtered' => 1, 'code_filtered' => null],
            "SELECT id, name FROM `$table` WHERE tenant_id = :tenant_data AND deleted_at IS NULL AND nullable_code <=> :code_data",
            ['tenant_data' => 1, 'code_data' => null],
        );
        $objects = [];

        $result = (new PdoPaginator())->paginate(
            $pdo,
            $descriptor,
            new PageRequest(1, 10, 'unknown', 'bad'),
            $this->config(),
            static function (array $row) use (&$objects): stdClass {
                $object = (object) $row;
                $objects[] = $object;

                return $object;
            },
        );

        self::assertSame(2, $result->total);
        self::assertSame($result->total, $result->filtered);
        self::assertSame('created_at', $result->sortBy);
        self::assertSame('DESC', $result->sortDirection->value);
        self::assertSame([$ids[3], $ids[0]], self::idsFromObjects($result->data));
        self::assertSame($objects[0], $result->data[0]);
    }

    public function testOverflowZeroFilteredEmptyPositiveFilteredAndFilteredGreaterThanTotal(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($pdo, $table);
        $paginator = new PdoPaginator();

        $overflow = $paginator->paginate(
            $pdo,
            $this->descriptor($table),
            new PageRequest(99, 2),
            $this->config(),
            static fn(array $row): array => $row,
        );
        self::assertSame(1, $overflow->page);
        self::assertCount(2, $overflow->data);

        $zero = $paginator->paginate(
            $pdo,
            new PdoPaginationQueryDescriptor(
                "SELECT COUNT(*) AS total_count FROM `$table` WHERE tenant_id = 1",
                [],
                "SELECT COUNT(*) AS filtered_count FROM `$table` WHERE tenant_id = 999",
                [],
                'THIS INVALID DATA SQL MUST NOT BE PREPARED',
                [],
            ),
            new PageRequest(7, 2),
            $this->config(),
            static fn(array $row): array => $row,
        );
        self::assertSame(1, $zero->page);
        self::assertSame([], $zero->data);
        self::assertSame(0, $zero->filtered);

        $emptyPositive = $paginator->paginate(
            $pdo,
            new PdoPaginationQueryDescriptor(
                "SELECT COUNT(*) AS total_count FROM `$table` WHERE tenant_id = 1",
                [],
                'SELECT 1 AS filtered_count',
                [],
                "SELECT id FROM `$table` WHERE tenant_id = 999",
                [],
            ),
            new PageRequest(1, 2),
            $this->config(),
            static fn(array $row): array => $row,
        );
        self::assertSame(1, $emptyPositive->filtered);
        self::assertSame([], $emptyPositive->data);
        self::assertSame(1, $emptyPositive->totalPages);

        $filteredGreaterThanTotal = $paginator->paginate(
            $pdo,
            new PdoPaginationQueryDescriptor(
                'SELECT 1 AS total_count',
                [],
                'SELECT 3 AS filtered_count',
                [],
                "SELECT id FROM `$table` WHERE tenant_id = 1 AND deleted_at IS NULL AND is_active = 1",
                [],
            ),
            new PageRequest(1, 3),
            $this->config(),
            static fn(array $row): array => $row,
        );
        self::assertSame(1, $filteredGreaterThanTotal->total);
        self::assertSame(3, $filteredGreaterThanTotal->filtered);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<int>
     */
    private static function idsFromRows(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            self::assertArrayHasKey('id', $row);
            self::assertTrue(is_int($row['id']) || is_string($row['id']));
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * @param list<object> $rows
     *
     * @return list<int>
     */
    private static function idsFromObjects(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            self::assertTrue(property_exists($row, 'id'));
            $id = $row->id;
            self::assertTrue(is_int($id) || is_string($id));
            $ids[] = (int) $id;
        }

        return $ids;
    }
}
