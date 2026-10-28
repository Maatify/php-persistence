<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Transaction\Repository;

use Maatify\PhpPersistence\Tests\Support\Transaction\PdoErrors;
use Maatify\PhpPersistence\Tests\Support\Transaction\RetryTransactionPdo;
use Maatify\PhpPersistence\Transaction\Exception\TransactionExecutionException;
use Maatify\PhpPersistence\Transaction\Repository\PdoSavepointTransactionRunner;
use Maatify\PhpPersistence\Transaction\Repository\PdoTransactionRunner;
use Maatify\PhpPersistence\Transaction\Repository\TransactionRunnerInterface;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Ported, with the same assertions, from the legacy repository's
 * tests/Unit/Pdo/Transaction/DeadlockRetryRunnerFailClosedTest.php at commit
 * 1f6a7aafe601090b7e55f60d83794364afdefe67 (review findings F44-01 and F44-02;
 * provenance gate recorded in the pull request): a transaction-control call
 * that fails without throwing is never reported as success, and a deadlock is
 * replayed only while the driver still reports the runner's own transaction.
 * The legacy "deprecated runners keep their historical behavior" case does not
 * apply here: this package carries no Gen1 runner (DEC-007).
 */
final class DeadlockRetryFailClosedTest extends TestCase
{
    /** @return array<string, array{callable(PDO): TransactionRunnerInterface}> */
    public static function runners(): array
    {
        $runners = [];
        foreach ([0, 3] as $retries) {
            $runners['PdoTransactionRunner, ' . $retries . ' retries'] = [
                static fn(PDO $pdo): TransactionRunnerInterface => new PdoTransactionRunner($pdo, $retries),
            ];
            $runners['PdoSavepointTransactionRunner, ' . $retries . ' retries'] = [
                static fn(PDO $pdo): TransactionRunnerInterface => new PdoSavepointTransactionRunner($pdo, $retries),
            ];
        }

        return $runners;
    }

    /** @return array<string, array{callable(PDO): TransactionRunnerInterface}> */
    public static function retryingRunners(): array
    {
        return array_filter(
            self::runners(),
            static fn(string $name): bool => str_ends_with($name, '3 retries'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    // ---- F44-01: non-throwing BEGIN / COMMIT failures ----

    /** @param callable(PDO): TransactionRunnerInterface $runnerFactory */
    #[DataProvider('runners')]
    public function testBeginReturningFalseFailsBeforeTheCallback(callable $runnerFactory): void
    {
        $pdo = new RetryTransactionPdo(beginFailures: [false]);
        $calls = 0;

        $thrown = $this->runCatching($runnerFactory($pdo), static function () use (&$calls): string {
            $calls++;

            return 'must not run outside a transaction';
        });

        self::assertInstanceOf(TransactionExecutionException::class, $thrown);
        self::assertSame(0, $calls);
        self::assertSame(1, $pdo->beginTransactionCalls);
        self::assertSame(0, $pdo->commitCalls);
        self::assertFalse($pdo->inTransaction());
    }

    /** @param callable(PDO): TransactionRunnerInterface $runnerFactory */
    #[DataProvider('runners')]
    public function testCommitReturningFalseIsNeverReportedAsSuccessOrReplayed(callable $runnerFactory): void
    {
        $pdo = new RetryTransactionPdo(commitFailures: [false]);
        $calls = 0;

        $thrown = $this->runCatching($runnerFactory($pdo), static function () use (&$calls): string {
            $calls++;

            return 'callback completed, transaction not committed';
        });

        self::assertInstanceOf(TransactionExecutionException::class, $thrown);
        self::assertSame(1, $calls, 'A failed COMMIT is never replayed.');
        self::assertSame(1, $pdo->beginTransactionCalls);
        self::assertSame(1, $pdo->commitCalls);
        self::assertSame(1, $pdo->rollBackCalls, 'Best-effort rollback of the transaction that could not be committed.');
        self::assertFalse($pdo->inTransaction());
    }

    /** @param callable(PDO): TransactionRunnerInterface $runnerFactory */
    #[DataProvider('runners')]
    public function testRollbackFailureAfterCommitReturningFalseDoesNotHideTheCommitFailure(callable $runnerFactory): void
    {
        $pdo = new RetryTransactionPdo(commitFailures: [false], rollbackFailure: PdoErrors::driverError('HY000', 2006, 'MySQL server has gone away'));

        $thrown = $this->runCatching($runnerFactory($pdo), static fn(): string => 'not committed');

        self::assertInstanceOf(TransactionExecutionException::class, $thrown);
        self::assertSame(1, $pdo->rollBackCalls);
    }

    // ---- F44-02: a transaction the callback already ended is never replayed ----

    /** @param callable(PDO): TransactionRunnerInterface $runnerFactory */
    #[DataProvider('retryingRunners')]
    public function testManuallyCommittedTransactionIsNotReplayedAfterADeadlockFromAnotherConnection(callable $runnerFactory): void
    {
        $pdo = new RetryTransactionPdo();
        $deadlock = PdoErrors::deadlock('raised by another connection');
        $calls = 0;

        $thrown = $this->runCatching($runnerFactory($pdo), static function () use ($pdo, $deadlock, &$calls): never {
            $calls++;
            $pdo->commit(); // the runner-owned transaction is now committed

            throw $deadlock;
        });

        self::assertSame($deadlock, $thrown);
        self::assertSame(1, $calls, 'The committed work must not run again.');
        self::assertSame(1, $pdo->beginTransactionCalls);
        self::assertSame([], $pdo->executedStatements, 'No probe: the driver already reports no transaction, so the runner cannot attribute the end to the deadlock.');
    }

    /** @param callable(PDO): TransactionRunnerInterface $runnerFactory */
    #[DataProvider('retryingRunners')]
    public function testManuallyRolledBackTransactionIsNotReplayedAfterADeadlock(callable $runnerFactory): void
    {
        $pdo = new RetryTransactionPdo();
        $deadlock = PdoErrors::deadlock();
        $calls = 0;

        $thrown = $this->runCatching($runnerFactory($pdo), static function () use ($pdo, $deadlock, &$calls): never {
            $calls++;
            $pdo->rollBack();

            throw $deadlock;
        });

        self::assertSame($deadlock, $thrown);
        self::assertSame(1, $calls);
        self::assertSame(1, $pdo->beginTransactionCalls);
        self::assertSame([], $pdo->executedStatements);
    }

    /** @param callable(PDO): TransactionRunnerInterface $runnerFactory */
    #[DataProvider('retryingRunners')]
    public function testPositiveControlADeadlockVictimStillReportedAsActiveIsReplayed(callable $runnerFactory): void
    {
        $pdo = new RetryTransactionPdo();
        $calls = 0;

        $result = $runnerFactory($pdo)->run(static function () use ($pdo, &$calls): string {
            $calls++;
            if ($calls === 1) {
                $pdo->endTransactionOnServer(); // InnoDB rolled it back; the driver still reports it

                throw PdoErrors::deadlock();
            }

            return 'replayed';
        });

        self::assertSame('replayed', $result);
        self::assertSame(2, $calls);
        self::assertSame(['DO 0'], $pdo->executedStatements);
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
