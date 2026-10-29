<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use PDO;

final class PdoPaginatorTransactionTest extends PaginationIntegrationTestCase
{
    public function testAttributesUnchangedOutsideTransactionAndCallerTransactionPreserved(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($pdo, $table);

        $attributes = [
            PDO::ATTR_ERRMODE,
            PDO::ATTR_DEFAULT_FETCH_MODE,
            PDO::ATTR_EMULATE_PREPARES,
            PDO::ATTR_STRINGIFY_FETCHES,
        ];
        $before = [];
        foreach ($attributes as $attribute) {
            $before[$attribute] = $pdo->getAttribute($attribute);
        }

        self::assertFalse($pdo->inTransaction());
        (new PdoPaginator())->paginate(
            $pdo,
            $this->descriptor($table),
            new PageRequest(),
            $this->config(),
            static fn(array $row): array => $row,
        );
        self::assertFalse($pdo->inTransaction());

        foreach ($before as $attribute => $value) {
            self::assertSame($value, $pdo->getAttribute($attribute));
            if (is_bool($value) || $value === 0 || $value === 1) {
                self::assertSame((bool) $value, (bool) $pdo->getAttribute($attribute));
            }
        }

        $pdo->beginTransaction();
        try {
            $result = (new PdoPaginator())->paginate(
                $pdo,
                $this->descriptor($table),
                new PageRequest(),
                $this->config(),
                static fn(array $row): array => $row,
            );
            self::assertSame(4, $result->total);
            self::assertSame(3, $result->filtered);
            self::assertCount(2, $result->data);
            self::assertTrue($pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }
    }
}
