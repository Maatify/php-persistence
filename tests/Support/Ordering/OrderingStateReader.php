<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Support\Ordering;

use PDO;
use PDOStatement;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Golden-captured from legacy tests/Support/MySql/OrderingStateReader.php (1f6a7aa, provenance gate
 * recorded in the WU-8 pull request), adapted to run-unique table names.
 */
final readonly class OrderingStateReader
{
    public function __construct(
        private PDO $pdo,
        private string $globalTable,
        private string $scopedTable,
    ) {}

    /**
     * @return array<int, int>
     */
    public function globalOrdersById(): array
    {
        $stmt = $this->prepare(
            'SELECT `id`, `display_order`
               FROM `' . $this->globalTable . '`
              ORDER BY `id` ASC',
        );
        $stmt->execute();

        return $this->ordersByIdFromStatement($stmt);
    }

    /**
     * @return array<int, int>
     */
    public function scopedOrdersById(int|string|null $scopeValue): array
    {
        if ($scopeValue === null) {
            $stmt = $this->prepare(
                'SELECT `id`, `display_order`
                   FROM `' . $this->scopedTable . '`
                  WHERE `scope_key` IS NULL
                  ORDER BY `id` ASC',
            );
            $stmt->execute();
        } else {
            $stmt = $this->prepare(
                'SELECT `id`, `display_order`
                   FROM `' . $this->scopedTable . '`
                  WHERE `scope_key` = :scope_key
                  ORDER BY `id` ASC',
            );
            $stmt->execute(['scope_key' => $scopeValue]);
        }

        return $this->ordersByIdFromStatement($stmt);
    }

    /**
     * @param non-empty-string $sql
     */
    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);

        if (!$statement instanceof PDOStatement) {
            throw new AssertionFailedError('Unable to prepare ordering state reader statement.');
        }

        return $statement;
    }

    /**
     * @return array<int, int>
     */
    private function ordersByIdFromStatement(PDOStatement $stmt): array
    {
        $ordersById = [];

        while (true) {
            /** @var mixed $row The PDO stub is narrower than what the driver may return. */
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                break;
            }

            if (!is_array($row)) {
                throw new AssertionFailedError('Ordering state row must be an array.');
            }

            $id = $row['id'] ?? null;
            $displayOrder = $row['display_order'] ?? null;

            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                throw new AssertionFailedError('Ordering state row id must be a positive integer.');
            }

            if (!is_int($displayOrder) && !(is_string($displayOrder) && ctype_digit($displayOrder))) {
                throw new AssertionFailedError('Ordering state display order must be a positive integer.');
            }

            $ordersById[(int) $id] = (int) $displayOrder;
        }

        return $ordersById;
    }
}
