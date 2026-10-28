<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Transaction;

use Maatify\PhpPersistence\Transaction\Exception\TransactionExecutionException;
use Maatify\PhpPersistence\Transaction\Repository\PdoSavepointTransactionRunner;
use Maatify\PhpPersistence\Transaction\Repository\PdoTransactionRunner;
use Maatify\PhpPersistence\Transaction\Repository\TransactionRunnerInterface;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * Real database (B-12): genuine InnoDB/InnoDB-equivalent deadlocks (1213 /
 * 40001) against a second connection in another process, run on the one
 * existing compose.integration.yaml service. Owned transactions are replayed
 * from a fresh BEGIN when retries are configured; caller-owned transactions
 * and their savepoints are never replayed. Adapted, with the same scenarios
 * and assertions, from the legacy repository's
 * tests/Integration/Pdo/Transaction/PdoDeadlockRetryRunnerIntegrationTest.php
 * at commit 1f6a7aafe601090b7e55f60d83794364afdefe67 (provenance gate recorded
 * in the pull request); the legacy "deprecated runner" case does not apply
 * here (DEC-007: no Gen1 runner is carried).
 */
final class DeadlockRetryIntegrationTest extends DeadlockIntegrationTestCase
{
    /** @return array<string, array{callable(PDO): TransactionRunnerInterface}> */
    public static function retryingRunners(): array
    {
        return [
            'PdoTransactionRunner' => [static fn(PDO $pdo): TransactionRunnerInterface => new PdoTransactionRunner($pdo, 3)],
            'PdoSavepointTransactionRunner' => [static fn(PDO $pdo): TransactionRunnerInterface => new PdoSavepointTransactionRunner($pdo, 3)],
        ];
    }

    public function testDefaultConfigurationDoesNotReplayARealOwnedDeadlock(): void
    {
        $pdo = $this->connect();
        $calls = 0;
        $raised = null;

        $thrown = $this->runCatching(
            new PdoTransactionRunner($pdo),
            function () use ($pdo, &$calls, &$raised): never {
                $calls++;
                $this->insert($pdo, 'default-no-retry');

                throw $raised = $this->provokeDeadlockAsVictim($pdo);
            },
        );

        self::assertSame($raised, $thrown);
        self::assertSame(1, $calls);
        self::assertFalse($pdo->inTransaction());
        self::assertSame([], $this->committedPayloads());
        self::assertSame(['ACQUIRED'], $this->partnerReports());
    }

    /** @param callable(PDO): TransactionRunnerInterface $runnerFactory */
    #[DataProvider('retryingRunners')]
    public function testRealOwnedDeadlockIsReplayedFromAFreshTransactionAndCommittedOnce(callable $runnerFactory): void
    {
        $pdo = $this->connect();
        $calls = 0;
        /** @var list<bool> $inTransactionAtStart */
        $inTransactionAtStart = [];
        $raised = null;

        $result = $runnerFactory($pdo)->run(function () use ($pdo, &$calls, &$inTransactionAtStart, &$raised): string {
            $calls++;
            $inTransactionAtStart[] = $pdo->inTransaction();
            $this->insert($pdo, 'attempt-' . $calls);

            if ($calls === 1) {
                throw $raised = $this->provokeDeadlockAsVictim($pdo);
            }

            return 'committed after replay';
        });

        self::assertSame('committed after replay', $result);
        self::assertSame(2, $calls);
        self::assertSame([true, true], $inTransactionAtStart);
        self::assertInstanceOf(PDOException::class, $raised);
        self::assertFalse($pdo->inTransaction());
        self::assertSame(['attempt-2'], $this->committedPayloads(), 'The rolled-back first attempt leaves nothing behind.');
        self::assertSame(['ACQUIRED'], $this->partnerReports());
    }

    /** @param callable(PDO): TransactionRunnerInterface $runnerFactory */
    #[DataProvider('retryingRunners')]
    public function testExhaustionAfterFourRealDeadlocksPropagatesTheExactFinalPdoException(callable $runnerFactory): void
    {
        $pdo = $this->connect();
        /** @var list<PDOException> $raised */
        $raised = [];
        $attempts = 0;

        $thrown = $this->runCatching(
            $runnerFactory($pdo),
            function () use ($pdo, &$raised, &$attempts): never {
                $attempts++;
                $this->insert($pdo, 'attempt-' . (count($raised) + 1));

                throw $raised[] = $this->provokeDeadlockAsVictim($pdo);
            },
        );

        self::assertCount(4, $raised, sprintf('Initial attempt plus three retries. Callback attempts: %d.', $attempts));
        $finalDeadlock = array_pop($raised);
        self::assertNotNull($finalDeadlock);
        self::assertSame($finalDeadlock, $thrown);
        self::assertSame(self::DEADLOCK_SQLSTATE, $finalDeadlock->errorInfo[0] ?? null);
        self::assertSame(self::DEADLOCK_DRIVER_CODE, $finalDeadlock->errorInfo[1] ?? null);
        self::assertFalse($pdo->inTransaction());
        self::assertSame([], $this->committedPayloads());
        self::assertSame(['ACQUIRED', 'ACQUIRED', 'ACQUIRED', 'ACQUIRED'], $this->partnerReports());
    }

    /** @param callable(PDO): TransactionRunnerInterface $runnerFactory */
    #[DataProvider('retryingRunners')]
    public function testRealDeadlockInsideCallerOwnedTransactionIsNeverReplayed(callable $runnerFactory): void
    {
        $pdo = $this->connect();
        $calls = 0;
        $raised = null;
        $pdo->beginTransaction();
        $this->insert($pdo, 'caller-before');

        $thrown = $this->runCatching(
            $runnerFactory($pdo),
            function () use ($pdo, &$calls, &$raised): never {
                $calls++;
                $this->insert($pdo, 'operation');

                throw $raised = $this->provokeDeadlockAsVictim($pdo);
            },
        );

        self::assertSame($raised, $thrown);
        self::assertSame(1, $calls);
        // The server rolled back the whole caller-owned transaction, including the row written before the
        // runner was called, and the runner neither replayed nor committed anything.
        self::assertSame([], $this->payloadsSeenBy($pdo));
        self::assertFalse($pdo->inTransaction(), 'After a successful statement no transaction is reported.');
        self::assertSame([], $this->committedPayloads());
        self::assertSame(['ACQUIRED'], $this->partnerReports());
    }

    /**
     * The callback commits the runner-owned transaction itself, then a GENUINE
     * deadlock is raised by a different connection. The committed work must
     * never be replayed.
     *
     * @param callable(PDO): TransactionRunnerInterface $runnerFactory
     */
    #[DataProvider('retryingRunners')]
    public function testManuallyCommittedTransactionFollowedByAnotherConnectionsDeadlockIsNeverReplayed(callable $runnerFactory): void
    {
        $pdo = $this->connect();
        $other = $this->unmonitoredConnection();
        $calls = 0;
        $raised = null;

        $thrown = $this->runCatching(
            $runnerFactory($pdo),
            function () use ($pdo, $other, &$calls, &$raised): never {
                $calls++;
                $this->insert($pdo, 'committed-by-callback');
                $pdo->commit(); // contract violation: the runner-owned transaction is now committed

                $other->beginTransaction();

                throw $raised = $this->provokeDeadlockAsVictim($other); // genuine 1213 / 40001, other connection
            },
        );

        self::assertInstanceOf(PDOException::class, $thrown);
        self::assertSame($raised, $thrown);
        self::assertSame(1, $calls);
        self::assertSame(['committed-by-callback'], $this->committedPayloads(), 'Committed once, never replayed.');
        self::assertSame(['ACQUIRED'], $this->partnerReports());
    }

    /**
     * In ERRMODE_SILENT, COMMIT on a session that the server has ended
     * returns false instead of throwing. The runner must not report the
     * callback result as committed, and must not replay it.
     *
     * @param callable(PDO): TransactionRunnerInterface $runnerFactory
     */
    #[DataProvider('retryingRunners')]
    public function testCommitThatFailsWithoutThrowingIsNotReportedAsSuccess(callable $runnerFactory): void
    {
        $silent = $this->unmonitoredConnection();
        $silent->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $calls = 0;

        $thrown = $this->runCatching(
            $runnerFactory($silent),
            function () use ($silent, &$calls): string {
                $calls++;
                $this->insert($silent, 'must-not-be-reported-as-committed');
                $this->killConnection($silent); // the server session ends before COMMIT

                return 'reported as committed';
            },
        );

        self::assertInstanceOf(TransactionExecutionException::class, $thrown);
        self::assertSame(1, $calls);
        self::assertSame([], $this->committedPayloads());
    }

    public function testNestedSavepointDeadlockReplaysTheWholeOwnedTransaction(): void
    {
        $pdo = $this->connect();
        $outerCalls = 0;
        $innerCalls = 0;
        $savepoints = new PdoSavepointTransactionRunner($pdo, 3);

        $result = (new PdoTransactionRunner($pdo, 3))->run(
            function () use ($pdo, $savepoints, &$outerCalls, &$innerCalls): string {
                $outerCalls++;
                $this->insert($pdo, 'outer-' . $outerCalls);

                $savepoints->run(function () use ($pdo, &$innerCalls): void {
                    $innerCalls++;
                    $this->insert($pdo, 'inner-' . $innerCalls);

                    if ($innerCalls === 1) {
                        throw $this->provokeDeadlockAsVictim($pdo);
                    }
                });

                return 'outer committed';
            },
        );

        self::assertSame('outer committed', $result);
        self::assertSame(2, $outerCalls, 'The owning runner replays its whole callback.');
        self::assertSame(2, $innerCalls, 'The savepoint itself is never replayed; it runs again only as part of the outer replay.');
        self::assertFalse($pdo->inTransaction());
        self::assertSame(['outer-2', 'inner-2'], $this->committedPayloads());
        self::assertSame(['ACQUIRED'], $this->partnerReports());
    }

    /** @param callable(): mixed $callback */
    private function runCatching(TransactionRunnerInterface $runner, callable $callback): Throwable
    {
        try {
            $runner->run($callback);
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('Expected a Throwable.');
    }
}
