<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Ordering;

use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Tests\Support\Ordering\OrderingFixture;
use PDO;
use PDOException;

/**
 * B-02/B-03 evidence gathering for the MariaDB `innodb_snapshot_isolation` hazard documented in
 * DATABASE_SUPPORT_AND_TEST_POLICY.md §7 (legacy Issue #47 study): with the setting ON (the
 * 11.8+ default), a locking read inside an older `REPEATABLE READ` snapshot that touches a row
 * changed after that snapshot fails with the engine's PDOException, driver code 1020. This is
 * test-only configuration arranging the documented precondition, never a package-side setting
 * mutation — the production code issues no `SET` of this setting and never retries, swallows or
 * overrides it (Issue #16 §4). `DEC-009`, which would turn this evidence into an accepted safety
 * contract, is still `PROPOSED`; this file collects the evidence it is gated on without resolving
 * it.
 *
 * `innodb_snapshot_isolation` does not exist on MySQL, so there is nothing to set there; `phpunit.xml`
 * sets `failOnSkipped="true"` project-wide (no silent skips), so these tests are not MariaDB-only —
 * on an engine without the setting, the "ON" tests assert the ordinary success path instead (the
 * same guarantee the `getNextPosition*()`/`moveWithinScope()` contract already gives on MySQL),
 * and the "OFF" tests simply never set anything there.
 */
final class ScopedOrderingManagerMariaDbSnapshotIsolationHazardTest extends OrderingIntegrationTestCase
{
    private ScopedOrderingManager $manager;

    private PDO $otherPdo;

    private OrderingFixture $otherFixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->manager = new ScopedOrderingManager();
        $this->otherPdo = $this->connect();
        $this->otherPdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->otherFixture = new OrderingFixture($this->otherPdo, $this->globalTable, $this->scopedTable);
    }

    protected function tearDown(): void
    {
        if (isset($this->otherPdo) && $this->otherPdo->inTransaction()) {
            $this->otherPdo->rollBack();
        }

        parent::tearDown();
    }

    public function testGetNextPositionForUpdateHonorsMariaDbSnapshotIsolationOnWhereItExists(): void
    {
        $onMariaDb = self::engine() === 'mariadb';

        if ($onMariaDb) {
            $this->pdo()->exec('SET SESSION innodb_snapshot_isolation = ON');
        }

        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped('scope-a', 1);

        if ($onMariaDb) {
            try {
                $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a');
                self::fail('Expected the documented innodb_snapshot_isolation=ON hazard (driver code 1020).');
            } catch (PDOException $exception) {
                self::assertSame('HY000', $exception->errorInfo[0] ?? null, $exception->getMessage());
                self::assertSame(1020, $exception->errorInfo[1] ?? null, $exception->getMessage());
            }

            $this->assertServerEndedTheWholeTransactionAndNoRetryHappened();

            return;
        }

        // No such setting on this engine: the ordinary latest-committed-state guarantee applies.
        self::assertSame(2, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a'));
        $this->pdo()->rollBack();
    }

    public function testMoveWithinScopeHonorsMariaDbSnapshotIsolationOnWhereItExists(): void
    {
        $onMariaDb = self::engine() === 'mariadb';

        if ($onMariaDb) {
            $this->pdo()->exec('SET SESSION innodb_snapshot_isolation = ON');
        }

        $id = $this->fixture->insertScoped('scope-a', 1);
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped('scope-a', 2);

        if ($onMariaDb) {
            try {
                $this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $id, 2);
                self::fail('Expected the documented innodb_snapshot_isolation=ON hazard (driver code 1020).');
            } catch (PDOException $exception) {
                self::assertSame('HY000', $exception->errorInfo[0] ?? null, $exception->getMessage());
                self::assertSame(1020, $exception->errorInfo[1] ?? null, $exception->getMessage());
            }

            $this->assertServerEndedTheWholeTransactionAndNoRetryHappened();

            return;
        }

        // No such setting on this engine: the clamp still follows the latest committed state.
        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $id, 99));
        $this->pdo()->commit();
        self::assertSame(2, $this->reader($this->otherPdo)->scopedOrdersById('scope-a')[$id]);
    }

    public function testGetNextPositionForUpdateSeesTheLatestCommittedStateWhenSnapshotIsolationIsOff(): void
    {
        if (self::engine() === 'mariadb') {
            $this->pdo()->exec('SET SESSION innodb_snapshot_isolation = OFF');
        }

        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped('scope-a', 1);

        self::assertSame(2, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a'));

        $this->pdo()->rollBack();
    }

    public function testMoveWithinScopeUsesTheLatestCommittedClampWhenSnapshotIsolationIsOff(): void
    {
        if (self::engine() === 'mariadb') {
            $this->pdo()->exec('SET SESSION innodb_snapshot_isolation = OFF');
        }

        $id = $this->fixture->insertScoped('scope-a', 1);
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped('scope-a', 2);

        // The stale snapshot's maximum is 1; the clamp must use the latest committed maximum (2),
        // which the scope lock itself reads, exactly as B-03 requires on every engine.
        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $id, 99));
        $this->pdo()->commit();

        self::assertSame(2, $this->reader($this->otherPdo)->scopedOrdersById('scope-a')[$id]);
    }

    private function beginTransactionWithSnapshot(): void
    {
        $this->pdo()->beginTransaction();
        $statement = $this->pdo()->query('SELECT COUNT(*) FROM `' . $this->scopedTable . '`');
        self::assertNotFalse($statement);
        $statement->fetchColumn();
    }

    /**
     * No package retry, no setting override, no false success: the exact original 1020 already
     * propagated above, unchanged. This only confirms the server genuinely ended the whole
     * transaction (not merely the one statement), using the same successful-no-op probe
     * {@see \Maatify\PhpPersistence\Transaction\Repository\OwnedTransactionExecutor} uses for B-12,
     * since PDO::inTransaction() is documented to possibly still report true right after 1020.
     */
    private function assertServerEndedTheWholeTransactionAndNoRetryHappened(): void
    {
        $this->pdo()->exec('DO 0');
        self::assertFalse($this->pdo()->inTransaction());
    }
}
