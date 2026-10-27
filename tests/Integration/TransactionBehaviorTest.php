<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration;

/**
 * Server and pdo_mysql transaction behavior that later work units rely on.
 */
final class TransactionBehaviorTest extends IntegrationTestCase
{
    public function testCommitPersistsAndIsVisibleToAnotherConnection(): void
    {
        $writer = $this->connect();
        $reader = $this->connect();
        $table = $this->createTable($writer);

        self::assertFalse($writer->inTransaction());
        $writer->beginTransaction();
        self::assertTrue($writer->inTransaction());
        $writer->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)');
        self::assertSame([], $this->values($reader, $table), 'uncommitted data must stay invisible');
        $writer->commit();

        self::assertFalse($writer->inTransaction());
        self::assertSame([10], $this->values($reader, $table));
    }

    public function testRollbackDiscardsChanges(): void
    {
        $pdo = $this->connect();
        $table = $this->createTable($pdo);

        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)');
        $pdo->rollBack();

        self::assertFalse($pdo->inTransaction());
        self::assertSame([], $this->values($pdo, $table));
    }

    public function testControlCallsOutsideTheExpectedStateFail(): void
    {
        $pdo = $this->connect();

        self::assertStringContainsString('no active transaction', self::failureOf(static fn() => $pdo->commit())->getMessage());
        self::assertStringContainsString('no active transaction', self::failureOf(static fn() => $pdo->rollBack())->getMessage());

        $pdo->beginTransaction();
        self::assertStringContainsString('already an active transaction', self::failureOf(static fn() => $pdo->beginTransaction())->getMessage());
        $pdo->rollBack();
    }

    public function testFailedStatementKeepsTheTransactionActiveAndEarlierWork(): void
    {
        $pdo = $this->connect();
        $table = $this->createTable($pdo);

        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)');
        $exception = self::failureOf(static fn() => $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 99)'));

        self::assertSame('23000', self::sqlState($exception));
        self::assertSame(1062, self::driverCode($exception));
        self::assertTrue($pdo->inTransaction());
        $pdo->commit();
        self::assertSame([10], $this->values($pdo, $table));
    }

    public function testRepeatableReadKeepsTheSnapshotAcrossAnotherConnectionsCommit(): void
    {
        $first = $this->connect();
        $second = $this->connect();
        $table = $this->createTable($first);
        $first->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)');

        $first->beginTransaction();
        self::assertSame([10], $this->values($first, $table));
        $second->exec('UPDATE `' . $table . '` SET v = 20 WHERE id = 1');

        self::assertSame([10], $this->values($first, $table), 'the plain read keeps the old snapshot');
        $first->rollBack();
        self::assertSame([20], $this->values($first, $table));
    }

    public function testLockWaitTimeoutIsReportedAsErrorOneTwoZeroFive(): void
    {
        $holder = $this->connect();
        $waiter = $this->connect();
        $table = $this->createTable($holder);
        $holder->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)');

        $holder->beginTransaction();
        self::assertNotFalse($holder->query('SELECT v FROM `' . $table . '` WHERE id = 1 FOR UPDATE'));
        $waiter->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $waiter->beginTransaction();

        $exception = self::failureOf(static fn() => $waiter->query('SELECT v FROM `' . $table . '` WHERE id = 1 FOR UPDATE'));

        self::assertSame(1205, self::driverCode($exception));
        self::assertSame('HY000', self::sqlState($exception));
        $waiter->rollBack();
        $holder->rollBack();
    }

    public function testDdlInsideATransactionCommitsEarlierWork(): void
    {
        $pdo = $this->connect();
        $table = $this->createTable($pdo);

        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)');
        $other = $this->createTable($pdo, 'ddl');
        self::assertNotSame($table, $other);

        self::assertFalse($pdo->inTransaction(), 'the implicit commit of DDL ends the transaction on the server');
        self::assertSame([10], $this->values($pdo, $table));
    }
}
