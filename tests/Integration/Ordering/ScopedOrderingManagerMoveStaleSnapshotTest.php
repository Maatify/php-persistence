<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Ordering;

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Tests\Support\Ordering\OrderingFixture;
use Maatify\PhpPersistence\Tests\Support\Ordering\OrderingStateReader;
use PDO;
use PDOException;

/**
 * B-03 regression protection for moveWithinScope() inside a caller-owned transaction whose
 * REPEATABLE READ snapshot is older than another connection's commit: the movement clamp must
 * follow the latest committed state that the scope lock reads, not the caller's older
 * consistent-read snapshot. Ported from legacy
 * tests/Integration/Pdo/Ordering/ScopedOrderingManagerMoveStaleSnapshotTest.php (1f6a7aa).
 */
final class ScopedOrderingManagerMoveStaleSnapshotTest extends OrderingIntegrationTestCase
{
    private const LOCK_WAIT_TIMEOUT_ERROR = 1205;

    private ScopedOrderingManager $manager;
    private PDO $otherPdo;
    private OrderingFixture $otherFixture;
    private OrderingStateReader $otherReader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->arrangeLatestCommittedStateGuarantee($this->pdo());

        $this->manager = new ScopedOrderingManager();
        $this->otherPdo = $this->connect();
        $this->otherPdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->otherPdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $this->arrangeLatestCommittedStateGuarantee($this->otherPdo);
        $this->otherFixture = new OrderingFixture($this->otherPdo, $this->globalTable, $this->scopedTable);
        $this->otherReader = new OrderingStateReader($this->otherPdo, $this->globalTable, $this->scopedTable);
    }

    protected function tearDown(): void
    {
        if ($this->otherPdo->inTransaction()) {
            $this->otherPdo->rollBack();
        }

        parent::tearDown();
    }

    public function testScopedMoveToTheLatestPositionAfterAnOlderSnapshot(): void
    {
        $one = $this->fixture->insertScoped('scope-a', 1);
        $two = $this->fixture->insertScoped('scope-a', 2);
        $three = $this->fixture->insertScoped('scope-a', 3);
        $this->beginTransactionWithSnapshot();
        $four = $this->otherFixture->insertScoped('scope-a', 4);
        $five = $this->otherFixture->insertScoped('scope-a', 5);

        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $one, 5));
        $this->pdo()->commit();

        self::assertSame(
            [$one => 5, $two => 1, $three => 2, $four => 3, $five => 4],
            $this->otherReader->scopedOrdersById('scope-a'),
        );
    }

    public function testScopedMoveUpwardWithinRowsCommittedAfterTheSnapshot(): void
    {
        $one = $this->fixture->insertScoped('scope-a', 1);
        $two = $this->fixture->insertScoped('scope-a', 2);
        $this->beginTransactionWithSnapshot();
        $three = $this->otherFixture->insertScoped('scope-a', 3);
        $four = $this->otherFixture->insertScoped('scope-a', 4);

        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $four, 1));
        $this->pdo()->commit();

        self::assertSame(
            [$one => 2, $two => 3, $three => 4, $four => 1],
            $this->otherReader->scopedOrdersById('scope-a'),
        );
    }

    public function testRowCommittedAfterTheSnapshotCanMoveBelowTheStaleMaximum(): void
    {
        $one = $this->fixture->insertScoped('scope-a', 1);
        $two = $this->fixture->insertScoped('scope-a', 2);
        $this->beginTransactionWithSnapshot();
        $three = $this->otherFixture->insertScoped('scope-a', 3);

        // The stale snapshot maximum is 2; the clamp must use 3.
        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $one, 99));
        $this->pdo()->commit();

        self::assertSame(
            [$one => 3, $two => 1, $three => 2],
            $this->otherReader->scopedOrdersById('scope-a'),
        );
    }

    public function testGlobalMoveAfterAnOlderSnapshot(): void
    {
        $one = $this->fixture->insertGlobal(1);
        $two = $this->fixture->insertGlobal(2);
        $this->beginTransactionWithSnapshot();
        $three = $this->otherFixture->insertGlobal(3);
        $four = $this->otherFixture->insertGlobal(4);

        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $one, 4));
        $this->pdo()->commit();

        self::assertSame(
            [$one => 4, $two => 1, $three => 2, $four => 3],
            $this->otherReader->globalOrdersById(),
        );
    }

    public function testNullableScopeMoveAfterAnOlderSnapshot(): void
    {
        $one = $this->fixture->insertScoped(null, 1);
        $two = $this->fixture->insertScoped(null, 2);
        $this->beginTransactionWithSnapshot();
        $three = $this->otherFixture->insertScoped(null, 3);
        $this->otherFixture->insertScoped('scope-a', 9);

        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->nullableScopedConfig(), null, $one, 3));
        $this->pdo()->commit();

        self::assertSame(
            [$one => 3, $two => 1, $three => 2],
            $this->otherReader->scopedOrdersById(null),
        );
    }

    public function testSoftDeletedRowsCommittedAfterTheSnapshotDoNotRaiseTheClamp(): void
    {
        $one = $this->fixture->insertScoped('scope-a', 1);
        $two = $this->fixture->insertScoped('scope-a', 2);
        $this->beginTransactionWithSnapshot();
        $three = $this->otherFixture->insertScoped('scope-a', 3);
        $deleted = $this->otherFixture->insertScoped('scope-a', 9, deletedAt: '2026-01-01 00:00:00');

        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $one, 99));
        $this->pdo()->commit();

        self::assertSame(
            [$one => 3, $two => 1, $three => 2, $deleted => 9],
            $this->otherReader->scopedOrdersById('scope-a'),
        );
    }

    public function testSoftDeleteCommittedAfterTheSnapshotLowersTheClamp(): void
    {
        $one = $this->fixture->insertScoped('scope-a', 1);
        $two = $this->fixture->insertScoped('scope-a', 2);
        $top = $this->fixture->insertScoped('scope-a', 3);
        $this->beginTransactionWithSnapshot();

        $statement = $this->otherPdo->prepare(
            'UPDATE `' . $this->scopedTable . '` SET `deleted_at` = :deleted_at WHERE `id` = :id',
        );
        self::assertNotFalse($statement);
        $statement->execute(['deleted_at' => '2026-01-01 00:00:00', 'id' => $top]);

        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $one, 99));
        $this->pdo()->commit();

        self::assertSame(
            [$one => 2, $two => 1, $top => 3],
            $this->otherReader->scopedOrdersById('scope-a'),
        );
    }

    public function testRowsOfOtherScopesAreNeitherMovedNorUsedForTheClamp(): void
    {
        $one = $this->fixture->insertScoped('scope-a', 1);
        $two = $this->fixture->insertScoped('scope-a', 2);
        $otherOne = $this->fixture->insertScoped('scope-b', 1);
        $this->beginTransactionWithSnapshot();
        $three = $this->otherFixture->insertScoped('scope-a', 3);
        $otherNine = $this->otherFixture->insertScoped('scope-b', 9);

        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $one, 99));
        $this->pdo()->commit();

        self::assertSame([$one => 3, $two => 1, $three => 2], $this->otherReader->scopedOrdersById('scope-a'));
        self::assertSame([$otherOne => 1, $otherNine => 9], $this->otherReader->scopedOrdersById('scope-b'));
    }

    public function testGapsAreKeptAndTheClampUsesTheHighestActivePosition(): void
    {
        $one = $this->fixture->insertScoped('scope-a', 1);
        $two = $this->fixture->insertScoped('scope-a', 2);
        $this->beginTransactionWithSnapshot();
        $five = $this->otherFixture->insertScoped('scope-a', 5);

        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $one, 99));
        $this->pdo()->commit();

        // Same affected-range semantics as before: positions in (1, 5] shift down by one.
        self::assertSame(
            [$one => 5, $two => 1, $five => 4],
            $this->otherReader->scopedOrdersById('scope-a'),
        );
    }

    public function testRowAlreadyAtTheLatestMaximumIsANoOp(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $this->beginTransactionWithSnapshot();
        $top = $this->otherFixture->insertScoped('scope-a', 2);
        $before = $this->otherReader->scopedOrdersById('scope-a');

        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $top, 99));
        self::assertTrue($this->pdo()->inTransaction());
        $this->pdo()->commit();

        self::assertSame($before, $this->otherReader->scopedOrdersById('scope-a'));
    }

    public function testUpdatedAtIsWrittenOnlyForTheTargetRow(): void
    {
        $one = $this->fixture->insertScoped('scope-a', 1);
        $two = $this->fixture->insertScoped('scope-a', 2);
        $this->beginTransactionWithSnapshot();
        $three = $this->otherFixture->insertScoped('scope-a', 3);
        $config = new ScopedOrderingConfig(
            table: $this->scopedTable,
            scopeColumn: 'scope_key',
            updatedAtColumn: 'updated_at',
        );

        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $config, 'scope-a', $one, 3, '2026-02-03 04:05:06'));
        $this->pdo()->commit();

        $statement = $this->otherPdo->prepare(
            'SELECT `updated_at` FROM `' . $this->scopedTable . '` WHERE `id` = :id',
        );
        self::assertNotFalse($statement);

        foreach ([[$one, '2026-02-03 04:05:06'], [$two, null], [$three, null]] as [$id, $expected]) {
            $statement->execute(['id' => $id]);
            self::assertSame($expected, $statement->fetchColumn(), 'updated_at of row ' . $id);
        }
    }

    public function testMissingRowReturnsFalseAndLeavesTheCallerTransactionActive(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped('scope-a', 2);
        $before = $this->otherReader->scopedOrdersById('scope-a');

        self::assertFalse($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', 999_999, 2));
        self::assertTrue($this->pdo()->inTransaction());
        $this->pdo()->commit();

        self::assertSame($before, $this->otherReader->scopedOrdersById('scope-a'));
    }

    public function testCallerTransactionStaysActiveAfterTheMoveAndRollbackRestoresTheState(): void
    {
        $one = $this->fixture->insertScoped('scope-a', 1);
        $two = $this->fixture->insertScoped('scope-a', 2);
        $this->beginTransactionWithSnapshot();
        $three = $this->otherFixture->insertScoped('scope-a', 3);

        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $one, 3));
        self::assertTrue($this->pdo()->inTransaction(), 'The manager must not end a caller-owned transaction.');

        $this->pdo()->rollBack();

        self::assertSame(
            [$one => 1, $two => 2, $three => 3],
            $this->otherReader->scopedOrdersById('scope-a'),
        );
    }

    public function testOwnedTransactionIsCommittedAndNothingStaysOpen(): void
    {
        $one = $this->fixture->insertScoped('scope-a', 1);
        $two = $this->fixture->insertScoped('scope-a', 2);
        $three = $this->otherFixture->insertScoped('scope-a', 3);

        self::assertFalse($this->pdo()->inTransaction());
        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $one, 3));
        self::assertFalse($this->pdo()->inTransaction());

        self::assertSame(
            [$one => 3, $two => 1, $three => 2],
            $this->otherReader->scopedOrdersById('scope-a'),
        );
    }

    public function testOwnedTransactionRollsBackWhenTheRowIsMissing(): void
    {
        $one = $this->fixture->insertScoped('scope-a', 1);

        self::assertFalse($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', 999_999, 1));
        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame([$one => 1], $this->otherReader->scopedOrdersById('scope-a'));
    }

    public function testMoveInEmptyScopeReturnsFalse(): void
    {
        $this->beginTransactionWithSnapshot();

        self::assertFalse($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'empty-scope', 1, 1));
        self::assertTrue($this->pdo()->inTransaction());
        $this->pdo()->rollBack();
    }

    public function testMoveKeepsHoldingTheScopeLockUntilTheCallerEnds(): void
    {
        $one = $this->fixture->insertScoped('scope-a', 1);
        $two = $this->fixture->insertScoped('scope-a', 2);
        $this->beginTransactionWithSnapshot();

        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $one, 2));

        try {
            $this->manager->moveWithinScope($this->otherPdo, $this->scopedConfig(), 'scope-a', $two, 2);
            self::fail('A concurrent move in the same scope must wait for the caller transaction.');
        } catch (PDOException $exception) {
            self::assertSame(self::LOCK_WAIT_TIMEOUT_ERROR, $exception->errorInfo[1] ?? null);
        }

        self::assertFalse($this->otherPdo->inTransaction());
        $this->pdo()->commit();

        self::assertTrue($this->manager->moveWithinScope($this->otherPdo, $this->scopedConfig(), 'scope-a', $one, 1));
        self::assertSame([$one => 1, $two => 2], $this->otherReader->scopedOrdersById('scope-a'));
    }

    public function testMoveDoesNotLockRowsOfOtherScopes(): void
    {
        $one = $this->fixture->insertScoped('scope-a', 1);
        $this->fixture->insertScoped('scope-a', 2);
        // Sorts before scope-a, so the scan of scope-a does not reach it (a
        // REPEATABLE READ scan also locks the gap up to the next index record).
        $otherId = $this->fixture->insertScoped('scope-0', 1);
        $this->beginTransactionWithSnapshot();

        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $one, 2));

        $statement = $this->otherPdo->prepare(
            'UPDATE `' . $this->scopedTable . '` SET `label` = :label WHERE `id` = :id',
        );
        self::assertNotFalse($statement);
        $statement->execute(['label' => 'updated outside the locked scope', 'id' => $otherId]);

        self::assertSame(1, $statement->rowCount());
        $this->pdo()->rollBack();
    }

    public function testMoveCompletesOnAnUnbufferedConnectionWithoutLeavingAnOpenCursor(): void
    {
        $one = $this->fixture->insertScoped('scope-a', 1);
        $two = $this->fixture->insertScoped('scope-a', 2);
        $three = $this->fixture->insertScoped('scope-a', 3);

        // PHP 8.4+ moves the driver constant to Pdo\Mysql; the package still supports 8.4.
        $bufferedQueryAttribute = defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY')
            ? constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY')
            : constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
        $this->otherPdo->setAttribute($bufferedQueryAttribute, false);

        self::assertTrue($this->manager->moveWithinScope($this->otherPdo, $this->scopedConfig(), 'scope-a', $one, 3));
        self::assertFalse($this->otherPdo->inTransaction());

        // A further statement on the same connection fails if a cursor were left open.
        self::assertSame(
            [$one => 3, $two => 1, $three => 2],
            $this->otherReader->scopedOrdersById('scope-a'),
        );
    }

    private function beginTransactionWithSnapshot(): void
    {
        $this->pdo()->beginTransaction();

        // Establish the REPEATABLE READ consistent-read snapshot before any
        // other connection commits.
        foreach ([$this->scopedTable, $this->globalTable] as $table) {
            $statement = $this->pdo()->query('SELECT COUNT(*) FROM `' . $table . '`');
            self::assertNotFalse($statement);
            $statement->fetchColumn();
        }
    }
}
