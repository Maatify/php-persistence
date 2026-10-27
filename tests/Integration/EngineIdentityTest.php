<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration;

use PDO;

final class EngineIdentityTest extends IntegrationTestCase
{
    public function testDriverIsPdoMysql(): void
    {
        $pdo = $this->connect();

        self::assertSame('mysql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::assertContains('mysql', PDO::getAvailableDrivers());
    }

    public function testServerIdentityMatchesTheRequestedCell(): void
    {
        $pdo = $this->connect();
        $version = (string) $this->scalar($pdo, 'SELECT VERSION()');
        $comment = (string) $this->scalar($pdo, 'SELECT @@version_comment');

        self::assertStringStartsWith(self::setting('EXPECTED_VERSION'), $version);
        if (self::engine() === 'mariadb') {
            self::assertStringContainsString('MariaDB', $version . ' ' . $comment);
        } else {
            self::assertSame('mysql', self::engine());
            self::assertStringNotContainsString('MariaDB', $version . ' ' . $comment);
        }
    }

    public function testConnectionUsesTheTemporaryNonRootUserOnLoopback(): void
    {
        $pdo = $this->connect();
        $user = (string) $this->scalar($pdo, 'SELECT CURRENT_USER()');
        $database = (string) $this->scalar($pdo, 'SELECT DATABASE()');

        self::assertSame('127.0.0.1', self::setting('HOST'));
        self::assertStringStartsNotWith('root@', $user);
        self::assertSame(self::setting('DATABASE'), $database);
    }

    public function testWrongPasswordFailsClosed(): void
    {
        $exception = self::failureOf(fn() => $this->connect('definitely-not-the-password'));

        self::assertSame(1045, self::driverCode($exception));
        self::assertStringContainsString('Access denied', $exception->getMessage());
    }

    public function testFreshServiceHasNoLeftoverTablesFromEarlierRuns(): void
    {
        $pdo = $this->connect();
        $count = (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');

        self::assertSame(0, $count);
    }
}
