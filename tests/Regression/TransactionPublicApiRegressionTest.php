<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Regression;

use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface;
use Maatify\PhpPersistence\Transaction\Exception\InvalidTransactionConfigurationException;
use Maatify\PhpPersistence\Transaction\Exception\TransactionExecutionException;
use Maatify\PhpPersistence\Transaction\Repository\OwnedTransactionExecutor;
use Maatify\PhpPersistence\Transaction\Repository\PdoSavepointTransactionRunner;
use Maatify\PhpPersistence\Transaction\Repository\PdoTransactionRunner;
use Maatify\PhpPersistence\Transaction\Repository\SavepointScope;
use Maatify\PhpPersistence\Transaction\Repository\SavepointTransactionRunnerInterface;
use Maatify\PhpPersistence\Transaction\Repository\TransactionRunnerInterface;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Protects the Transaction public API inventory decided in
 * CONTRACT_MATRIX.md §6.5-6.6 and §6.9 (WU-6): exact FQCNs, signatures,
 * parameter names/defaults, final/readonly, interface inheritance, and the
 * exception/marker hierarchy. No `PdoDeadlockRetry*` public name and no Gen1
 * runner are carried (DEC-007); nothing beyond the members below is public.
 */
final class TransactionPublicApiRegressionTest extends TestCase
{
    public function testTransactionRunnerInterfaceShape(): void
    {
        $reflection = new ReflectionClass(TransactionRunnerInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertSame([], $reflection->getInterfaceNames());
        self::assertSame(['run'], array_map(static fn($method): string => $method->getName(), $reflection->getMethods()));
        self::assertStringContainsString('@template TResult', (string) $reflection->getMethod('run')->getDocComment());

        $run = $reflection->getMethod('run');
        self::assertSame(['callback'], array_map(static fn($parameter): string => $parameter->getName(), $run->getParameters()));
        self::assertSame('callable', (string) $run->getParameters()[0]->getType());
        self::assertSame('mixed', (string) $run->getReturnType());
    }

    public function testSavepointTransactionRunnerInterfaceExtendsWithNoExtraMembers(): void
    {
        $reflection = new ReflectionClass(SavepointTransactionRunnerInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertSame([TransactionRunnerInterface::class], $reflection->getInterfaceNames());
        self::assertSame([], array_filter(
            array_map(static fn($method): string => $method->getName(), $reflection->getMethods()),
            static fn(string $name): bool => $name !== 'run',
        ));
    }

    public function testPdoTransactionRunnerShape(): void
    {
        $this->assertRunnerShape(PdoTransactionRunner::class, [TransactionRunnerInterface::class]);
    }

    public function testPdoSavepointTransactionRunnerShape(): void
    {
        $this->assertRunnerShape(PdoSavepointTransactionRunner::class, [SavepointTransactionRunnerInterface::class, TransactionRunnerInterface::class]);
    }

    /**
     * @param class-string<TransactionRunnerInterface> $class
     * @param list<class-string>                        $expectedInterfaces
     */
    private function assertRunnerShape(string $class, array $expectedInterfaces): void
    {
        $reflection = new ReflectionClass($class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertSame($expectedInterfaces, $reflection->getInterfaceNames());

        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        $parameters = $constructor->getParameters();
        self::assertSame(['pdo', 'maxDeadlockRetries'], array_map(static fn($parameter): string => $parameter->getName(), $parameters));
        self::assertSame(PDO::class, (string) $parameters[0]->getType());
        self::assertFalse($parameters[0]->isDefaultValueAvailable());
        self::assertSame('int', (string) $parameters[1]->getType());
        self::assertTrue($parameters[1]->isDefaultValueAvailable());
        self::assertSame(0, $parameters[1]->getDefaultValue());

        $declaredMethods = array_values(array_map(
            static fn($method): string => $method->getName(),
            array_filter(
                $reflection->getMethods(),
                static fn($method): bool => $method->getDeclaringClass()->getName() === $reflection->getName() && !$method->isConstructor(),
            ),
        ));
        self::assertSame(['run'], $declaredMethods);

        $run = $reflection->getMethod('run');
        self::assertSame(['callback'], array_map(static fn($parameter): string => $parameter->getName(), $run->getParameters()));
        self::assertSame('mixed', (string) $run->getReturnType());
    }

    public function testInternalTypesAreMarkedAndCarryNoOwnPublicConstructor(): void
    {
        foreach ([OwnedTransactionExecutor::class, SavepointScope::class] as $internal) {
            $reflection = new ReflectionClass($internal);

            self::assertTrue($reflection->isFinal(), $internal);
            self::assertTrue($reflection->isReadOnly(), $internal);
            self::assertStringContainsString('@internal', (string) $reflection->getDocComment(), $internal);
        }

        self::assertFalse((new ReflectionClass(SavepointScope::class))->getConstructor()?->isPublic() ?? false);
    }

    public function testNoPdoDeadlockRetryPublicNameExistsInTheTransactionNamespace(): void
    {
        foreach ([
            PdoTransactionRunner::class,
            PdoSavepointTransactionRunner::class,
            TransactionRunnerInterface::class,
            SavepointTransactionRunnerInterface::class,
        ] as $type) {
            self::assertStringNotContainsStringIgnoringCase('DeadlockRetry', $type);
        }
        self::assertFalse(class_exists('Maatify\PhpPersistence\Transaction\Repository\PdoDeadlockRetryTransactionRunner'));
        self::assertFalse(class_exists('Maatify\PhpPersistence\Transaction\Repository\PdoDeadlockRetrySavepointTransactionRunner'));
    }

    public function testTransactionExceptionsExtendSystemMaatifyExceptionAndImplementTheMarker(): void
    {
        foreach ([InvalidTransactionConfigurationException::class, TransactionExecutionException::class] as $exceptionClass) {
            $reflection = new ReflectionClass($exceptionClass);

            self::assertTrue($reflection->isFinal(), $exceptionClass);
            self::assertTrue($reflection->isSubclassOf(SystemMaatifyException::class), $exceptionClass);
            self::assertTrue($reflection->implementsInterface(PhpPersistenceExceptionInterface::class), $exceptionClass);
        }
    }

    public function testReflectedCountsMatchContractMatrixSection68(): void
    {
        $constructedTypes = [PdoTransactionRunner::class, PdoSavepointTransactionRunner::class];
        foreach ($constructedTypes as $type) {
            self::assertNotNull((new ReflectionClass($type))->getConstructor(), $type);
        }

        $declaredPublicMethodsOutsideConstructor = 0;
        foreach ([
            TransactionRunnerInterface::class,
            PdoTransactionRunner::class,
            PdoSavepointTransactionRunner::class,
        ] as $type) {
            $reflection = new ReflectionClass($type);
            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() === $reflection->getName() && !$method->isConstructor()) {
                    $declaredPublicMethodsOutsideConstructor++;
                }
            }
        }
        // TransactionRunnerInterface::run (the interface's own declaration), and run()
        // declared by each of the two runner classes: 3 declarations of the same contract.
        self::assertSame(3, $declaredPublicMethodsOutsideConstructor);
    }
}
