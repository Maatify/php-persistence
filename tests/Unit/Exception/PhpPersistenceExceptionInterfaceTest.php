<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Exception;

use Exception;
use Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

final class PhpPersistenceExceptionInterfaceTest extends TestCase
{
    public function testMarkerIsAnAutoloadableInterfaceInTheDecidedNamespace(): void
    {
        self::assertTrue(interface_exists(PhpPersistenceExceptionInterface::class));
        self::assertSame(
            'Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface',
            PhpPersistenceExceptionInterface::class,
        );
    }

    public function testMarkerExtendsOnlyThrowableAndDeclaresNoMembers(): void
    {
        $reflection = new ReflectionClass(PhpPersistenceExceptionInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertSame(
            [Throwable::class],
            array_values(array_diff($reflection->getInterfaceNames(), (new ReflectionClass(Throwable::class))->getInterfaceNames())),
        );
        self::assertSame([], $reflection->getConstants());
        self::assertSame([], array_map(
            static fn($method): string => $method->getName(),
            array_filter(
                $reflection->getMethods(),
                static fn($method): bool => $method->getDeclaringClass()->getName() === $reflection->getName(),
            ),
        ));
    }

    public function testMarkerFileFollowsPsr4LocationAndFileName(): void
    {
        $reflection = new ReflectionClass(PhpPersistenceExceptionInterface::class);
        $expected = dirname(__DIR__, 3) . '/src/Exception/PhpPersistenceExceptionInterface.php';

        self::assertSame($expected, $reflection->getFileName());
    }

    public function testPackageExceptionCanBeCaughtThroughTheMarker(): void
    {
        $exception = new class ('package failure') extends Exception implements PhpPersistenceExceptionInterface {};

        try {
            throw $exception;
        } catch (PhpPersistenceExceptionInterface $caught) {
            self::assertSame($exception, $caught);
        }
    }

    public function testExternalPdoExceptionDoesNotImplementTheMarker(): void
    {
        self::assertFalse((new ReflectionClass(PDOException::class))->implementsInterface(PhpPersistenceExceptionInterface::class));
    }
}
