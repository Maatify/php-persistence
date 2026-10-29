<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Ordering\Repository;

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Tests\Support\Ordering\OrderingScriptedPdo;
use Maatify\PhpPersistence\Tests\Support\Ordering\OrderingScriptedStatement;
use PHPUnit\Framework\TestCase;

/**
 * Proves, with a scripted PDO, which {@see \Maatify\PhpPersistence\Transaction\Repository\SavepointScope}
 * method moveWithinScope() calls for a given target-write outcome inside a caller-owned (participating)
 * transaction: releaseOrUndo() on success, undoOrFail() when the target write reports zero affected rows
 * after a real range shift (B-04). The helper's own control-statement failure precedence
 * (SAVEPOINT/RELEASE/ROLLBACK TO false-or-throwing) is already characterized in isolation by
 * tests/Unit/Transaction/Repository/SavepointScopeTest.php (WU-6, DEC-011 item 2); this test exists only
 * because the "false after a real shift" outcome cannot be reproduced on a real connection without a
 * database privilege this project's Integration environment does not grant a CREATE TRIGGER (confirmed
 * against mysql:8.4.13: error 1419/1458) — see ScopedOrderingManagerLazySavepointTest (Integration) for
 * the real-server scenarios (C06, C13, C14, S1-S7, the nested-runner case and a genuine deadlock) that
 * remain provable there.
 */
final class ScopedOrderingManagerSavepointWiringTest extends TestCase
{
    public function testSuccessfulTargetWriteCallsReleaseOrUndo(): void
    {
        $pdo = new OrderingScriptedPdo([
            new OrderingScriptedStatement(fetchColumnQueue: [3, false]), // scope lock: one row at order 3, then end of cursor
            new OrderingScriptedStatement(fetchColumnQueue: [5]),        // current order of the target row
            new OrderingScriptedStatement(),                             // shift UPDATE
            new OrderingScriptedStatement(rowCount: 1),                  // target UPDATE: 1 row changed
        ]);

        $result = (new ScopedOrderingManager())->moveWithinScope($pdo, new ScopedOrderingConfig(table: 'items'), null, 5, 2);

        self::assertTrue($result);
        self::assertCount(2, $pdo->executedControlStatements);
        self::assertMatchesRegularExpression(
            '/^SAVEPOINT (maatify_persistence_sp_[0-9a-f]{32})$/',
            $pdo->executedControlStatements[0],
        );
        $name = substr($pdo->executedControlStatements[0], strlen('SAVEPOINT '));
        self::assertSame('RELEASE SAVEPOINT ' . $name, $pdo->executedControlStatements[1]);
    }

    public function testTargetWriteReportingNoAffectedRowsAfterARealShiftCallsUndoOrFailAndReturnsFalse(): void
    {
        $pdo = new OrderingScriptedPdo([
            new OrderingScriptedStatement(fetchColumnQueue: [3, false]), // scope lock: one row at order 3, then end of cursor
            new OrderingScriptedStatement(fetchColumnQueue: [5]),        // current order of the target row
            new OrderingScriptedStatement(),                             // shift UPDATE
            new OrderingScriptedStatement(rowCount: 0),                  // target UPDATE: zero rows changed (the hazard)
        ]);

        $result = (new ScopedOrderingManager())->moveWithinScope($pdo, new ScopedOrderingConfig(table: 'items'), null, 5, 2);

        self::assertFalse($result);
        self::assertCount(3, $pdo->executedControlStatements);
        self::assertMatchesRegularExpression(
            '/^SAVEPOINT (maatify_persistence_sp_[0-9a-f]{32})$/',
            $pdo->executedControlStatements[0],
        );
        $name = substr($pdo->executedControlStatements[0], strlen('SAVEPOINT '));
        self::assertSame('ROLLBACK TO SAVEPOINT ' . $name, $pdo->executedControlStatements[1]);
        self::assertSame('RELEASE SAVEPOINT ' . $name, $pdo->executedControlStatements[2]);
    }

    public function testOwnedPathTakesNoSavepointAtAllRegardlessOfTheTargetWriteOutcome(): void
    {
        $pdo = new OrderingScriptedPdo([
            new OrderingScriptedStatement(fetchColumnQueue: [3, false]),
            new OrderingScriptedStatement(fetchColumnQueue: [5]),
            new OrderingScriptedStatement(),
            new OrderingScriptedStatement(rowCount: 0),
        ], participating: false);

        $result = (new ScopedOrderingManager())->moveWithinScope($pdo, new ScopedOrderingConfig(table: 'items'), null, 5, 2);

        self::assertFalse($result);
        self::assertSame([], $pdo->executedControlStatements, 'The owned path never issues a savepoint statement.');
        self::assertFalse($pdo->inTransaction(), 'The owned transaction is rolled back and left closed.');
    }
}
