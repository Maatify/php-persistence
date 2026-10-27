<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration;

use PDO;

/**
 * Raw SAVEPOINT statements on the real server, inside a caller-owned transaction.
 * No package runner is involved; these tests prove the server behavior it will build on.
 */
final class SavepointBehaviorTest extends IntegrationTestCase
{
    private static function savepointName(): string
    {
        return 'maatify_persistence_sp_' . bin2hex(random_bytes(16));
    }

    private function insert(PDO $pdo, string $table, int $id, int $value): void
    {
        $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (' . $id . ', ' . $value . ')');
    }

    public function testRollbackToSavepointUndoesOnlyLaterWork(): void
    {
        $pdo = $this->connect();
        $table = $this->createTable($pdo);
        $name = self::savepointName();

        $pdo->beginTransaction();
        $this->insert($pdo, $table, 1, 10);
        $pdo->exec('SAVEPOINT ' . $name);
        $this->insert($pdo, $table, 2, 20);
        $pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
        $this->insert($pdo, $table, 3, 30);
        $pdo->exec('RELEASE SAVEPOINT ' . $name);
        self::assertTrue($pdo->inTransaction());
        $pdo->commit();

        self::assertSame([10, 30], $this->values($pdo, $table));
    }

    public function testSavepointStaysUsableAfterRollbackTo(): void
    {
        $pdo = $this->connect();
        $table = $this->createTable($pdo);
        $name = self::savepointName();

        $pdo->beginTransaction();
        $pdo->exec('SAVEPOINT ' . $name);
        $this->insert($pdo, $table, 1, 10);
        $pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
        $this->insert($pdo, $table, 2, 20);
        $pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
        $pdo->commit();

        self::assertSame([], $this->values($pdo, $table));
    }

    public function testRollbackToAnOuterSavepointDiscardsInnerSavepoints(): void
    {
        $pdo = $this->connect();
        $table = $this->createTable($pdo);
        $outer = self::savepointName();
        $inner = self::savepointName();

        $pdo->beginTransaction();
        $pdo->exec('SAVEPOINT ' . $outer);
        $this->insert($pdo, $table, 1, 10);
        $pdo->exec('SAVEPOINT ' . $inner);
        $this->insert($pdo, $table, 2, 20);
        $pdo->exec('ROLLBACK TO SAVEPOINT ' . $outer);

        $exception = self::failureOf(static fn() => $pdo->exec('ROLLBACK TO SAVEPOINT ' . $inner));
        self::assertSame(1305, self::driverCode($exception));
        self::assertSame('42000', self::sqlState($exception));
        self::assertTrue($pdo->inTransaction());
        $pdo->rollBack();
        self::assertSame([], $this->values($pdo, $table));
    }

    public function testReleasedOrUnknownSavepointIsAnError(): void
    {
        $pdo = $this->connect();
        $name = self::savepointName();

        $pdo->beginTransaction();
        $pdo->exec('SAVEPOINT ' . $name);
        $pdo->exec('RELEASE SAVEPOINT ' . $name);

        self::assertSame(1305, self::driverCode(self::failureOf(static fn() => $pdo->exec('ROLLBACK TO SAVEPOINT ' . $name))));
        self::assertSame(1305, self::driverCode(self::failureOf(static fn() => $pdo->exec('RELEASE SAVEPOINT ' . $name))));
        $pdo->rollBack();
    }

    public function testRollbackToSavepointOutsideATransactionFails(): void
    {
        $pdo = $this->connect();
        $name = self::savepointName();

        $pdo->exec('SAVEPOINT ' . $name);

        self::assertFalse($pdo->inTransaction());
        self::assertSame(1305, self::driverCode(self::failureOf(static fn() => $pdo->exec('ROLLBACK TO SAVEPOINT ' . $name))));
    }

    public function testTheGeneratedSavepointNameFormatIsAccepted(): void
    {
        $pdo = $this->connect();
        $accepted = self::savepointName();

        self::assertSame(55, strlen($accepted));
        $pdo->beginTransaction();
        $pdo->exec('SAVEPOINT ' . $accepted);
        $pdo->exec('ROLLBACK TO SAVEPOINT ' . $accepted);
        $pdo->exec('RELEASE SAVEPOINT ' . $accepted);
        $pdo->rollBack();
    }

    public function testFailureAfterASavepointCanBeUndoneWithoutEndingTheTransaction(): void
    {
        $pdo = $this->connect();
        $table = $this->createTable($pdo);
        $name = self::savepointName();

        $pdo->beginTransaction();
        $this->insert($pdo, $table, 1, 10);
        $pdo->exec('SAVEPOINT ' . $name);
        $this->insert($pdo, $table, 2, 20);
        self::assertSame('23000', self::sqlState(self::failureOf(fn() => $this->insert($pdo, $table, 2, 21))));
        $pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
        $pdo->commit();

        self::assertSame([10], $this->values($pdo, $table));
    }
}
