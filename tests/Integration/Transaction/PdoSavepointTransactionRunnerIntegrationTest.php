<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Transaction;

use Maatify\PhpPersistence\Tests\Integration\IntegrationTestCase;
use Maatify\PhpPersistence\Transaction\Repository\PdoSavepointTransactionRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * Real database (B-11): package Transaction public API against an actual PDO
 * connection. Adapted, with the same scenarios, from the legacy repository's
 * tests/Integration/Pdo/Transaction/PdoSavepointTransactionRunnerIntegrationTest.php
 * at commit 1f6a7aafe601090b7e55f60d83794364afdefe67 (provenance gate recorded
 * in the pull request); run at both ends of the retry range (0 and 3) as the
 * real-database half of the parity characterization in DEC-011 item 3, since
 * none of these scenarios triggers a deadlock.
 */
final class PdoSavepointTransactionRunnerIntegrationTest extends IntegrationTestCase
{
    /** @return array<string, array{int}> */
    public static function retryConfigurations(): array
    {
        return ['no retries configured' => [0], 'maximum retries configured' => [3]];
    }

    #[DataProvider('retryConfigurations')]
    public function testNoOuterTransactionSuccessCommitsMutation(int $retries): void
    {
        $pdo = $this->connect();
        $control = $this->connect();
        $table = $this->createTable($pdo);

        (new PdoSavepointTransactionRunner($pdo, $retries))->run(
            static fn(): int|false => $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)'),
        );

        self::assertFalse($pdo->inTransaction());
        self::assertSame([10], $this->values($control, $table));
    }

    #[DataProvider('retryConfigurations')]
    public function testNoOuterTransactionFailureRollsBackMutation(int $retries): void
    {
        $pdo = $this->connect();
        $control = $this->connect();
        $table = $this->createTable($pdo);
        $failure = new RuntimeException('owned transaction failure.');

        $thrown = $this->catchThrowable(
            fn() => (new PdoSavepointTransactionRunner($pdo, $retries))->run(
                function () use ($pdo, $table, $failure): never {
                    $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)');

                    throw $failure;
                },
            ),
        );

        self::assertSame($failure, $thrown);
        self::assertFalse($pdo->inTransaction());
        self::assertSame([], $this->values($pdo, $table));
        self::assertSame([], $this->values($control, $table));
    }

    #[DataProvider('retryConfigurations')]
    public function testSuccessfulActiveOperationRemainsPendingUntilCallerCommits(int $retries): void
    {
        $pdo = $this->connect();
        $control = $this->connect();
        $table = $this->createTable($pdo);
        $pdo->beginTransaction();

        try {
            $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)');
            (new PdoSavepointTransactionRunner($pdo, $retries))->run(
                static fn(): int|false => $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (2, 20)'),
            );

            self::assertTrue($pdo->inTransaction());
            self::assertSame([10, 20], $this->values($pdo, $table));
            self::assertSame([], $this->values($control, $table));

            $pdo->commit();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        self::assertSame([10, 20], $this->values($control, $table));
    }

    #[DataProvider('retryConfigurations')]
    public function testFailedActiveOperationRollsBackOnlyItsMutationAndCallerCanContinueAndCommit(int $retries): void
    {
        $pdo = $this->connect();
        $control = $this->connect();
        $table = $this->createTable($pdo);
        $failure = new RuntimeException('isolated operation failure.');
        $pdo->beginTransaction();

        try {
            $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)');
            $thrown = $this->catchThrowable(
                fn() => (new PdoSavepointTransactionRunner($pdo, $retries))->run(
                    function () use ($pdo, $table, $failure): never {
                        $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (2, 20)');

                        throw $failure;
                    },
                ),
            );

            self::assertSame($failure, $thrown);
            self::assertTrue($pdo->inTransaction());
            self::assertSame([10], $this->values($pdo, $table));

            $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (3, 30)');
            $pdo->commit();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        self::assertSame([10, 30], $this->values($control, $table));
    }

    #[DataProvider('retryConfigurations')]
    public function testCallerCanRollBackAfterSuccessfulIsolatedOperation(int $retries): void
    {
        $pdo = $this->connect();
        $control = $this->connect();
        $table = $this->createTable($pdo);
        $pdo->beginTransaction();

        try {
            (new PdoSavepointTransactionRunner($pdo, $retries))->run(
                static fn(): int|false => $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)'),
            );

            self::assertTrue($pdo->inTransaction());
            self::assertSame([10], $this->values($pdo, $table));
            $pdo->rollBack();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        self::assertFalse($pdo->inTransaction());
        self::assertSame([], $this->values($control, $table));
    }

    #[DataProvider('retryConfigurations')]
    public function testNestedSuccessfulOperationsRemainInOuterTransactionUntilCallerCommits(int $retries): void
    {
        $pdo = $this->connect();
        $control = $this->connect();
        $table = $this->createTable($pdo);
        $runner = new PdoSavepointTransactionRunner($pdo, $retries);
        $pdo->beginTransaction();

        try {
            $runner->run(function () use ($pdo, $table, $runner): void {
                $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)');
                $runner->run(static fn(): int|false => $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (2, 20)'));
            });

            self::assertTrue($pdo->inTransaction());
            self::assertSame([10, 20], $this->values($pdo, $table));
            self::assertSame([], $this->values($control, $table));
            $pdo->commit();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        self::assertSame([10, 20], $this->values($control, $table));
    }

    #[DataProvider('retryConfigurations')]
    public function testNestedFailureCaughtByOuterOperationRollsBackOnlyInnerMutation(int $retries): void
    {
        $pdo = $this->connect();
        $control = $this->connect();
        $table = $this->createTable($pdo);
        $runner = new PdoSavepointTransactionRunner($pdo, $retries);
        $innerFailure = new RuntimeException('inner operation failure.');
        $pdo->beginTransaction();

        try {
            $runner->run(function () use ($pdo, $table, $runner, $innerFailure): void {
                $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)');

                $thrown = $this->catchThrowable(
                    fn() => $runner->run(function () use ($pdo, $table, $innerFailure): never {
                        $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (2, 20)');

                        throw $innerFailure;
                    }),
                );

                self::assertSame($innerFailure, $thrown);
                self::assertSame([10], $this->values($pdo, $table));
                $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (3, 30)');
            });

            self::assertTrue($pdo->inTransaction());
            $pdo->commit();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        self::assertSame([10, 30], $this->values($control, $table));
    }

    #[DataProvider('retryConfigurations')]
    public function testPropagatedNestedFailureRollsBackInnerAndOuterSavepointsAndCallerCanCommit(int $retries): void
    {
        $pdo = $this->connect();
        $control = $this->connect();
        $table = $this->createTable($pdo);
        $runner = new PdoSavepointTransactionRunner($pdo, $retries);
        $innerFailure = new RuntimeException('propagated inner operation failure.');
        $pdo->beginTransaction();

        try {
            $thrown = $this->catchThrowable(
                fn() => $runner->run(function () use ($pdo, $table, $runner, $innerFailure): void {
                    $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)');
                    $runner->run(function () use ($pdo, $table, $innerFailure): never {
                        $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (2, 20)');

                        throw $innerFailure;
                    });
                }),
            );

            self::assertSame($innerFailure, $thrown);
            self::assertTrue($pdo->inTransaction());
            self::assertSame([], $this->values($pdo, $table));

            $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (3, 30)');
            $pdo->commit();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        self::assertSame([30], $this->values($control, $table));
    }

    /** @param callable(): mixed $callback */
    private function catchThrowable(callable $callback): Throwable
    {
        try {
            $callback();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('Expected a Throwable.');
    }
}
