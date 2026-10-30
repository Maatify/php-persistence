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
 *
 * PR #17 review F17-01: the two "ON" cases also make a caller-owned write before the hazard and
 * prove its fate on an independent connection (the whole transaction is rolled back, that earlier
 * write included, not merely the one failing statement), then issue an identified, named
 * statement on the SAME connection afterward and observe — never assume — whether the driver
 * rejects it or accepts it as a fresh autocommitted unit (the "later statements may autocommit"
 * hazard), confirming via the independent connection whether it actually persisted either way.
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

        // A caller-owned write before the hazard, to prove its fate (Issue #16 §4; PR #17 review F17-01).
        $priorId = $onMariaDb ? $this->fixture->insertScoped('marker-scope', 1, 'caller-prior-write') : null;

        $this->otherFixture->insertScoped('scope-a', 1);

        if ($onMariaDb) {
            try {
                $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a');
                self::fail('Expected the documented innodb_snapshot_isolation=ON hazard (driver code 1020).');
            } catch (PDOException $exception) {
                self::assertSame('HY000', $exception->errorInfo[0] ?? null, $exception->getMessage());
                self::assertSame(1020, $exception->errorInfo[1] ?? null, $exception->getMessage());
            }

            self::assertIsInt($priorId);
            $this->assertServerRolledBackThePriorCallerWriteAndObservesTheNextStatement($priorId);

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

        $priorId = $onMariaDb ? $this->fixture->insertScoped('marker-scope', 1, 'caller-prior-write') : null;

        $this->otherFixture->insertScoped('scope-a', 2);

        if ($onMariaDb) {
            try {
                $this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $id, 2);
                self::fail('Expected the documented innodb_snapshot_isolation=ON hazard (driver code 1020).');
            } catch (PDOException $exception) {
                self::assertSame('HY000', $exception->errorInfo[0] ?? null, $exception->getMessage());
                self::assertSame(1020, $exception->errorInfo[1] ?? null, $exception->getMessage());
            }

            self::assertIsInt($priorId);
            $this->assertServerRolledBackThePriorCallerWriteAndObservesTheNextStatement($priorId);

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
     * propagated above, unchanged. This proves the two things PR #17 review F17-01 found missing
     * (Issue #16 §4; DATABASE_SUPPORT_AND_TEST_POLICY.md §7):
     *
     * - the prior caller-owned write made before the hazard does not survive it: an independent
     *   connection must not see $priorId, confirming the server really rolled back the WHOLE
     *   transaction (earlier writes included), not merely the one failing statement;
     * - a named, identified statement issued afterward on the SAME connection is observed
     *   directly rather than assumed. PDO::inTransaction() is documented to possibly still
     *   report true right after 1020 (the same staleness B-12's OwnedTransactionExecutor probes
     *   with `DO 0`); this issues a real INSERT and records whichever actually happens — the
     *   connection rejects it (still reporting a transaction the server already ended), or it
     *   autocommits as a fresh single-statement unit (the "later statements may autocommit"
     *   hazard the Package Reference warns about) — then confirms via the independent
     *   connection whether that statement's effect actually persisted.
     */
    private function assertServerRolledBackThePriorCallerWriteAndObservesTheNextStatement(int $priorId): void
    {
        self::assertFalse(
            $this->rowVisibleTo($this->otherPdo, $priorId),
            'The prior caller-owned write must not survive the documented 1020 (the whole transaction was rolled back).',
        );

        try {
            $subsequentId = $this->fixture->insertScoped('marker-scope', 2, 'caller-subsequent-write');
        } catch (PDOException) {
            // Observed, not assumed — the connection rejected the identified subsequent statement
            // outright. Nothing persisted to check; fall through to the final state assertion.
            $subsequentId = null;
        }

        if ($subsequentId !== null) {
            // Observed, not assumed: the connection accepted it. Confirm the effect actually
            // persisted (the autocommit hazard), not merely that no exception was thrown.
            self::assertTrue($this->rowVisibleTo($this->otherPdo, $subsequentId));
        }

        $this->pdo()->exec('DO 0');
        self::assertFalse($this->pdo()->inTransaction());
    }

    private function rowVisibleTo(PDO $pdo, int $id): bool
    {
        $statement = $pdo->prepare('SELECT 1 FROM `' . $this->scopedTable . '` WHERE `id` = :id');
        self::assertNotFalse($statement);
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn() !== false;
    }
}
