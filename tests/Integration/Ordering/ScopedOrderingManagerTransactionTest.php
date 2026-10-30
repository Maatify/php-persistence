<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Ordering;

use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Tests\Support\Ordering\OrderingFailureInjector;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * B-04/B-05 caller-owned vs. package-owned transaction ownership, ported from legacy
 * tests/Integration/Pdo/Ordering/ScopedOrderingManagerTransactionTest.php (1f6a7aa).
 */
final class ScopedOrderingManagerTransactionTest extends OrderingIntegrationTestCase
{
    private ScopedOrderingManager $manager;

    private PDO $controlPdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new ScopedOrderingManager();
        $this->controlPdo = $this->connect();
    }

    public function testParticipatesInCallerOwnedTransactionAndLeavesItActive(): void
    {
        $first = $this->fixture->insertGlobal(1);
        $second = $this->fixture->insertGlobal(2);
        $before = $this->reader()->globalOrdersById();

        $this->pdo()->beginTransaction();

        try {
            self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $first, 2));
            self::assertTrue($this->pdo()->inTransaction());
            self::assertSame([$first => 2, $second => 1], $this->reader()->globalOrdersById());
        } finally {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
        }

        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame($before, $this->reader()->globalOrdersById());
    }

    public function testOuterRollbackReversesOrderingMutation(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3);
        $before = $this->reader()->globalOrdersById();

        $this->pdo()->beginTransaction();
        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $ids[0], 3));
        self::assertTrue($this->pdo()->inTransaction());
        self::assertNotSame($before, $this->reader()->globalOrdersById());

        $this->pdo()->rollBack();

        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame($before, $this->reader()->globalOrdersById());
    }

    public function testOuterCommitPersistsOrderingMutation(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3);

        $this->pdo()->beginTransaction();
        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $ids[0], 3));
        self::assertTrue($this->pdo()->inTransaction());
        $this->pdo()->commit();

        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame([$ids[0] => 3, $ids[1] => 1, $ids[2] => 2], $this->reader($this->controlPdo)->globalOrdersById());
    }

    public function testMultipleOrderingOperationsParticipateInOneCallerTransaction(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3, 4);

        $this->pdo()->beginTransaction();
        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $ids[3], 1));
        self::assertTrue($this->pdo()->inTransaction());
        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $ids[3], 3));
        self::assertTrue($this->pdo()->inTransaction());
        $this->pdo()->commit();

        self::assertSame([$ids[0] => 1, $ids[1] => 2, $ids[2] => 4, $ids[3] => 3], $this->reader($this->controlPdo)->globalOrdersById());
    }

    public function testParticipantFailureUndoesItsOwnMutationWindowAndLeavesOuterTransactionActive(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3, 4);
        $before = $this->reader()->globalOrdersById();
        $failureInjector = new OrderingFailureInjector($this->controlPdo);
        $constraint = $failureInjector->createTargetUpdateFailure($this->globalTable, $ids[3], 2);

        try {
            $this->pdo()->beginTransaction();

            try {
                $this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $ids[3], 2);
                self::fail('Expected the ordering PDO exception.');
            } catch (PDOException $throwable) {
                // The CHECK constraint violation's SQLSTATE/driver code differs by engine
                // (MySQL: HY000/3819; MariaDB: 23000/4025); only that a real PDOException
                // propagated is asserted.
                self::assertTrue($this->pdo()->inTransaction());
                self::assertSame($before, $this->reader()->globalOrdersById());
            }

            $this->pdo()->rollBack();
            self::assertSame($before, $this->reader($this->controlPdo)->globalOrdersById());
        } finally {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
            $failureInjector->dropConstraint($this->globalTable, $constraint);
        }
    }

    public function testOrdinaryPdoMutationAndOrderingMutationRollbackAtomically(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3);
        $before = $this->reader()->globalOrdersById();
        $failure = new RuntimeException('outer transaction failure.');

        try {
            $this->pdo()->beginTransaction();
            $statement = $this->pdo()->prepare(
                'UPDATE `' . $this->globalTable . '` SET `label` = :label WHERE `id` = :id',
            );
            self::assertNotFalse($statement);
            $statement->execute(['label' => 'changed in outer transaction', 'id' => $ids[0]]);
            self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $ids[0], 3));

            throw $failure;
        } catch (Throwable $throwable) {
            self::assertSame($failure, $throwable);
            self::assertTrue($this->pdo()->inTransaction());
            $this->pdo()->rollBack();
        }

        self::assertSame($before, $this->reader()->globalOrdersById());
        $labelStatement = $this->pdo()->prepare(
            'SELECT `label` FROM `' . $this->globalTable . '` WHERE `id` = :id',
        );
        self::assertNotFalse($labelStatement);
        $labelStatement->execute(['id' => $ids[0]]);
        self::assertSame('global row', $labelStatement->fetchColumn());
    }

    public function testSuccessfulMovementClosesOwnedTransaction(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3);

        $result = $this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $ids[0], 3);

        self::assertTrue($result);
        self::assertSame([$ids[0] => 3, $ids[1] => 1, $ids[2] => 2], $this->reader()->globalOrdersById());
        self::assertFalse($this->pdo()->inTransaction());
    }

    public function testMissingTargetClosesOwnedTransaction(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3);
        $before = $this->reader()->globalOrdersById();
        $missingId = $ids[2] + 100;

        $result = $this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $missingId, 2);

        self::assertFalse($result);
        self::assertSame($before, $this->reader()->globalOrdersById());
        self::assertFalse($this->pdo()->inTransaction());
    }

    public function testNoOpClosesOwnedTransaction(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3);
        $before = $this->reader()->globalOrdersById();

        $result = $this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $ids[2], 999);

        self::assertTrue($result);
        self::assertSame($before, $this->reader()->globalOrdersById());
        self::assertFalse($this->pdo()->inTransaction());
    }

    /**
     * @return list<int>
     */
    private function insertGlobalOrders(int ...$orders): array
    {
        $ids = [];
        foreach ($orders as $order) {
            $ids[] = $this->fixture->insertGlobal($order);
        }

        return $ids;
    }
}
