<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Support\Ordering;

use PDO;
use PDOStatement;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Golden-captured from legacy tests/Support/MySql/OrderingFixture.php (1f6a7aa, provenance gate
 * recorded in the WU-8 pull request), adapted to run-unique table names instead of legacy's fixed
 * shared-table constants (DATABASE_SUPPORT_AND_TEST_POLICY.md §5).
 */
final readonly class OrderingFixture
{
    public function __construct(
        private PDO $pdo,
        private string $globalTable,
        private string $scopedTable,
    ) {}

    public function insertGlobal(int $displayOrder, string $label = 'global row', ?string $deletedAt = null): int
    {
        $stmt = $this->prepare(
            'INSERT INTO `' . $this->globalTable . '` (`display_order`, `deleted_at`, `label`)
             VALUES (:display_order, :deleted_at, :label)',
        );
        $stmt->execute([
            'display_order' => $displayOrder,
            'deleted_at'    => $deletedAt,
            'label'         => $label,
        ]);

        return $this->lastInsertId();
    }

    public function insertScoped(
        int|string|null $scopeValue,
        int $displayOrder,
        string $label = 'scoped row',
        ?string $deletedAt = null,
        ?string $updatedAt = null,
    ): int {
        $stmt = $this->prepare(
            'INSERT INTO `' . $this->scopedTable . '` (`scope_key`, `display_order`, `deleted_at`, `updated_at`, `label`)
             VALUES (:scope_key, :display_order, :deleted_at, :updated_at, :label)',
        );
        $stmt->execute([
            'scope_key'     => $scopeValue,
            'display_order' => $displayOrder,
            'deleted_at'    => $deletedAt,
            'updated_at'    => $updatedAt,
            'label'         => $label,
        ]);

        return $this->lastInsertId();
    }

    /**
     * @param non-empty-string $sql
     */
    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);

        if (!$statement instanceof PDOStatement) {
            throw new AssertionFailedError('Unable to prepare ordering fixture statement.');
        }

        return $statement;
    }

    private function lastInsertId(): int
    {
        $id = $this->pdo->lastInsertId();

        if ($id === false || !ctype_digit($id)) {
            throw new AssertionFailedError('Unable to read inserted ordering fixture id.');
        }

        return (int) $id;
    }
}
