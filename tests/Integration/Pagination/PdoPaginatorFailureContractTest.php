<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use PDOException;
use ReflectionMethod;
use RuntimeException;
use Throwable;

final class PdoPaginatorFailureContractTest extends PaginationIntegrationTestCase
{
    public function testCountShapeFailures(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($pdo, $table);

        $descriptors = [
            new PdoPaginationQueryDescriptor("SELECT id AS total_count FROM `$table` WHERE tenant_id = 999", [], 'SELECT 1 AS filtered_count', [], "SELECT id FROM `$table`", []),
            new PdoPaginationQueryDescriptor("SELECT id AS total_count FROM `$table` WHERE tenant_id = 1", [], 'SELECT 1 AS filtered_count', [], "SELECT id FROM `$table`", []),
            new PdoPaginationQueryDescriptor("SELECT id, name FROM `$table` WHERE tenant_id = 1 LIMIT 1", [], 'SELECT 1 AS filtered_count', [], "SELECT id FROM `$table`", []),
        ];

        foreach ($descriptors as $descriptor) {
            try {
                (new PdoPaginator())->paginate($pdo, $descriptor, new PageRequest(), $this->config(), static fn(array $row): array => $row);
                self::fail('Expected count shape failure.');
            } catch (PaginationExecutionException $exception) {
                self::assertInstanceOf(PaginationExecutionException::class, $exception);
            }
        }
    }

    public function testRepeatedNamedPlaceholder(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $descriptor = new PdoPaginationQueryDescriptor('SELECT 1 AS total_count', [], 'SELECT 1 AS filtered_count', [], "SELECT id FROM `$table` WHERE name = :p OR category = :p", ['p' => 'test']);
        self::assertInstanceOf(PdoPaginationQueryDescriptor::class, $descriptor);

        try {
            (new PdoPaginator())->paginate($pdo, $descriptor, new PageRequest(), $this->config(), static fn(array $row): array => $row);
            self::fail('Expected execution to fail.');
        } catch (Throwable $exception) {
            self::assertTrue($exception instanceof PDOException || $exception instanceof PaginationExecutionException);
        }
    }

    public function testMissingParameter(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $descriptor = new PdoPaginationQueryDescriptor('SELECT 1 AS total_count', [], 'SELECT 1 AS filtered_count', [], "SELECT id FROM `$table` WHERE name = :p", []);
        self::assertInstanceOf(PdoPaginationQueryDescriptor::class, $descriptor);

        try {
            (new PdoPaginator())->paginate($pdo, $descriptor, new PageRequest(), $this->config(), static fn(array $row): array => $row);
            self::fail('Expected execution to fail.');
        } catch (Throwable $exception) {
            self::assertTrue($exception instanceof PDOException || $exception instanceof PaginationExecutionException);
        }
    }

    public function testUnusedParameter(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $descriptor = new PdoPaginationQueryDescriptor('SELECT 1 AS total_count', [], 'SELECT 1 AS filtered_count', [], "SELECT id FROM `$table`", ['p' => 'unused']);
        self::assertInstanceOf(PdoPaginationQueryDescriptor::class, $descriptor);

        try {
            (new PdoPaginator())->paginate($pdo, $descriptor, new PageRequest(), $this->config(), static fn(array $row): array => $row);
            self::fail('Expected execution to fail.');
        } catch (Throwable $exception) {
            self::assertTrue($exception instanceof PDOException || $exception instanceof PaginationExecutionException);
        }
    }

    public function testPositionalPlaceholder(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $descriptor = new PdoPaginationQueryDescriptor('SELECT 1 AS total_count', [], 'SELECT 1 AS filtered_count', [], "SELECT id FROM `$table` WHERE name = ?", []);
        self::assertInstanceOf(PdoPaginationQueryDescriptor::class, $descriptor);

        try {
            (new PdoPaginator())->paginate($pdo, $descriptor, new PageRequest(), $this->config(), static fn(array $row): array => $row);
            self::fail('Expected execution to fail.');
        } catch (Throwable $exception) {
            self::assertTrue($exception instanceof PDOException || $exception instanceof PaginationExecutionException);
        }
    }

    public function testMixedPositionalAndNamedPlaceholders(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $descriptor = new PdoPaginationQueryDescriptor('SELECT 1 AS total_count', [], 'SELECT 1 AS filtered_count', [], "SELECT id FROM `$table` WHERE name = ? AND category = :cat", ['cat' => 'test']);
        self::assertInstanceOf(PdoPaginationQueryDescriptor::class, $descriptor);

        try {
            (new PdoPaginator())->paginate($pdo, $descriptor, new PageRequest(), $this->config(), static fn(array $row): array => $row);
            self::fail('Expected execution to fail.');
        } catch (Throwable $exception) {
            self::assertTrue($exception instanceof PDOException || $exception instanceof PaginationExecutionException);
        }
    }

    public function testInvalidTable(): void
    {
        [$pdo] = $this->connectWithPaginationTable();
        $descriptor = new PdoPaginationQueryDescriptor('SELECT COUNT(*) AS total_count FROM missing_pagination_table', [], 'SELECT 1 AS filtered_count', [], 'SELECT id FROM missing_pagination_table', []);

        try {
            (new PdoPaginator())->paginate($pdo, $descriptor, new PageRequest(), $this->config(), static fn(array $row): array => $row);
            self::fail('Expected PDOException.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(PDOException::class, $exception);
        }
    }

    public function testInvalidColumn(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $descriptor = new PdoPaginationQueryDescriptor("SELECT COUNT(missing_col) AS total_count FROM `$table`", [], 'SELECT 1 AS filtered_count', [], "SELECT missing_col FROM `$table`", []);

        try {
            (new PdoPaginator())->paginate($pdo, $descriptor, new PageRequest(), $this->config(), static fn(array $row): array => $row);
            self::fail('Expected PDOException.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(PDOException::class, $exception);
        }
    }

    public function testMapperThrowableIdentityAndInvalidMapperResults(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($pdo, $table);
        $throwable = new RuntimeException('mapper');

        try {
            (new PdoPaginator())->paginate($pdo, $this->descriptor($table), new PageRequest(), $this->config(), static fn(array $row) => throw $throwable);
            self::fail('Expected mapper throwable.');
        } catch (RuntimeException $caught) {
            self::assertSame($throwable, $caught);
        }

        $this->expectException(PaginationExecutionException::class);
        $this->invokePaginatorWithMapper($pdo, $table, static fn(array $row): int => 1);
    }

    public function testResourceMapperFailureClosesResource(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($pdo, $table);
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        try {
            $this->expectException(PaginationExecutionException::class);
            $this->invokePaginatorWithMapper($pdo, $table, static fn(array $row) => $resource);
        } finally {
            fclose($resource);
        }
    }

    private function invokePaginatorWithMapper(\PDO $pdo, string $table, callable $mapper): void
    {
        $method = new ReflectionMethod(PdoPaginator::class, 'paginate');
        $method->invokeArgs(new PdoPaginator(), [
            $pdo,
            $this->descriptor($table),
            new PageRequest(),
            $this->config(),
            $mapper,
        ]);
    }
}
