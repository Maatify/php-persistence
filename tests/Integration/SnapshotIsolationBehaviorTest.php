<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration;

use PDO;

/**
 * Behavior of a locking read or update after an older REPEATABLE READ snapshot.
 *
 * MariaDB can raise error 1020 when innodb_snapshot_isolation is ON. The settings are changed
 * only on the test connections of this disposable service; the package never changes them.
 * Behavior after the error (what the next statement does) is not asserted because it differs
 * between versions; the transaction state is only recorded in the failure message.
 */
final class SnapshotIsolationBehaviorTest extends IntegrationTestCase
{
    private function variableExists(PDO $pdo): bool
    {
        $statement = $pdo->query("SHOW VARIABLES LIKE 'innodb_snapshot_isolation'");
        self::assertNotFalse($statement);

        return $statement->fetch() !== false;
    }

    /**
     * Opens a snapshot on $reader, changes the row on another connection, then returns the table.
     */
    private function staleSnapshot(PDO $reader, PDO $writer): string
    {
        $table = $this->createTable($writer);
        $writer->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)');
        $reader->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $reader->beginTransaction();
        self::assertSame([10], $this->values($reader, $table));
        $writer->exec('UPDATE `' . $table . '` SET v = 20 WHERE id = 1');

        return $table;
    }

    public function testVariableAvailabilityMatchesTheEngine(): void
    {
        $pdo = $this->connect();

        if (self::engine() === 'mysql') {
            self::assertFalse($this->variableExists($pdo), 'MySQL has no innodb_snapshot_isolation variable');

            return;
        }
        self::assertTrue($this->variableExists($pdo), 'this MariaDB version exposes innodb_snapshot_isolation');
    }

    public function testLockingReadAfterAnOlderSnapshotSeesTheLatestRowWhenTheSettingIsOffOrAbsent(): void
    {
        $reader = $this->connect();
        $writer = $this->connect();
        if ($this->variableExists($reader)) {
            $reader->exec('SET SESSION innodb_snapshot_isolation = OFF');
        }
        $table = $this->staleSnapshot($reader, $writer);

        self::assertSame([10], $this->values($reader, $table), 'the plain read keeps the snapshot');
        self::assertSame(20, (int) $this->scalar($reader, 'SELECT v FROM `' . $table . '` WHERE id = 1 FOR UPDATE'));
        $reader->rollBack();
    }

    public function testLockingReadAfterAnOlderSnapshotFailsWithErrorOneZeroTwoZeroWhenTheSettingIsOn(): void
    {
        $reader = $this->connect();
        $writer = $this->connect();
        if (!$this->variableExists($reader)) {
            self::assertSame('mysql', self::engine(), 'only MySQL may lack the variable');

            return;
        }
        $reader->exec('SET SESSION innodb_snapshot_isolation = ON');
        $table = $this->staleSnapshot($reader, $writer);

        $exception = self::failureOf(static fn() => $reader->query('SELECT v FROM `' . $table . '` WHERE id = 1 FOR UPDATE'));

        self::assertSame(1020, self::driverCode($exception));
        $this->recordTransactionState($reader);
    }

    public function testUpdateAfterAnOlderSnapshotFailsWithErrorOneZeroTwoZeroWhenTheSettingIsOn(): void
    {
        $reader = $this->connect();
        $writer = $this->connect();
        if (!$this->variableExists($reader)) {
            self::assertSame('mysql', self::engine(), 'only MySQL may lack the variable');

            return;
        }
        $reader->exec('SET SESSION innodb_snapshot_isolation = ON');
        $table = $this->staleSnapshot($reader, $writer);

        $exception = self::failureOf(static fn() => $reader->exec('UPDATE `' . $table . '` SET v = v + 1 WHERE id = 1'));

        self::assertSame(1020, self::driverCode($exception));
        $this->recordTransactionState($reader);
    }

    public function testUpdateAfterAnOlderSnapshotSucceedsOnTheLatestRowWhenTheSettingIsOffOrAbsent(): void
    {
        $reader = $this->connect();
        $writer = $this->connect();
        if ($this->variableExists($reader)) {
            $reader->exec('SET SESSION innodb_snapshot_isolation = OFF');
        }
        $table = $this->staleSnapshot($reader, $writer);

        self::assertSame(1, $reader->exec('UPDATE `' . $table . '` SET v = v + 1 WHERE id = 1'));
        $reader->commit();
        self::assertSame([21], $this->values($reader, $table));
    }

    /**
     * After error 1020 the server may already have rolled the transaction back while PDO still
     * reports it as active. The state differs between versions, so it is only made visible
     * and the connection is returned to a clean state.
     */
    private function recordTransactionState(PDO $reader): void
    {
        if ($reader->inTransaction()) {
            $reader->rollBack();
        }
        self::assertFalse($reader->inTransaction());
    }
}
