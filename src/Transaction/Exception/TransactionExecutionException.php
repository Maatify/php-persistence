<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Exception;

use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface;

/**
 * Thrown when a PDO transaction-control statement (BEGIN, COMMIT, SAVEPOINT,
 * RELEASE SAVEPOINT, ROLLBACK TO SAVEPOINT) fails without throwing, or when a
 * caller-owned transaction is no longer active where this package requires one.
 */
final class TransactionExecutionException extends SystemMaatifyException implements PhpPersistenceExceptionInterface
{
    protected function defaultErrorCode(): ErrorCodeInterface
    {
        return ErrorCodeEnum::MAATIFY_ERROR;
    }
}
