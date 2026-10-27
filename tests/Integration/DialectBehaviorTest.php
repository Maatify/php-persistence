<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration;

use PDO;

/**
 * SQL dialect assumptions of the legacy code that the new package inherits.
 */
final class DialectBehaviorTest extends IntegrationTestCase
{
    public function testBacktickQuotingAllowsReservedWordsAndEscapedBackticks(): void
    {
        $pdo = $this->connect();
        $name = 'it_' . bin2hex(random_bytes(6)) . '_order';
        $pdo->exec('CREATE TABLE `' . $name . '` (`id` INT NOT NULL PRIMARY KEY, `order` INT NOT NULL, `group``x` INT NOT NULL) ENGINE=InnoDB');

        try {
            $pdo->exec('INSERT INTO `' . $name . '` (`id`, `order`, `group``x`) VALUES (1, 2, 3)');
            $statement = $pdo->query('SELECT `order`, `group``x` AS g FROM `' . $name . '`');
            self::assertNotFalse($statement);
            $row = $statement->fetch();

            self::assertEquals(['order' => 2, 'g' => 3], $row);
        } finally {
            $pdo->exec('DROP TABLE `' . $name . '`');
        }
    }

    public function testUnquotedReservedWordIsASyntaxError(): void
    {
        $pdo = $this->connect();

        self::assertSame(1064, self::driverCode(self::failureOf(static fn() => $pdo->query('SELECT 1 AS order'))));
    }

    public function testLockingReadAndNoOpStatementAreAccepted(): void
    {
        $pdo = $this->connect();
        $table = $this->createTable($pdo);
        $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)');

        $pdo->beginTransaction();
        $locked = $this->scalar($pdo, 'SELECT v FROM `' . $table . '` WHERE id = 1 FOR UPDATE');
        self::assertSame(0, $pdo->exec('DO 0'));
        self::assertTrue($pdo->inTransaction());
        $pdo->rollBack();

        self::assertSame(10, (int) $locked);
    }

    public function testLimitAndOffsetBoundAsIntegersWorkWithNativeAndEmulatedPreparedStatements(): void
    {
        foreach ([false, true] as $emulate) {
            $pdo = $this->connect();
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
            $table = $this->createTable($pdo);
            $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10), (2, 20), (3, 30)');

            $statement = $pdo->prepare('SELECT v FROM `' . $table . '` ORDER BY id LIMIT :limit OFFSET :offset');
            $statement->bindValue(':limit', 2, PDO::PARAM_INT);
            $statement->bindValue(':offset', 1, PDO::PARAM_INT);
            $statement->execute();

            self::assertEquals([20, 30], $statement->fetchAll(PDO::FETCH_COLUMN), 'emulate=' . var_export($emulate, true));
        }
    }

    public function testLimitBoundAsAStringIsASyntaxErrorWithEmulatedPreparedStatements(): void
    {
        $pdo = $this->connect();
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        $table = $this->createTable($pdo);

        $statement = $pdo->prepare('SELECT v FROM `' . $table . '` ORDER BY id LIMIT :limit');
        $statement->bindValue(':limit', '2', PDO::PARAM_STR);

        self::assertSame(1064, self::driverCode(self::failureOf(static fn() => $statement->execute())));
    }

    public function testNativePreparedStatementsRejectAReusedNamedPlaceholder(): void
    {
        $pdo = $this->connect();
        $table = $this->createTable($pdo);

        $statement = $pdo->prepare('SELECT v FROM `' . $table . '` WHERE id = :id OR v = :id');
        $exception = self::failureOf(static fn() => $statement->execute(['id' => 1]));

        self::assertSame('HY093', self::sqlState($exception));
    }
}
