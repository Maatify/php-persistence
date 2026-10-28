<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Exception;

use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface;

/**
 * Thrown when a transaction runner is constructed with an invalid configuration.
 *
 * Example:
 * - A deadlock retry limit outside the supported range.
 *
 * This exception is intended for configuration/programming mistakes, not for
 * user-input validation failures.
 */
final class InvalidTransactionConfigurationException extends SystemMaatifyException implements PhpPersistenceExceptionInterface
{
    protected function defaultErrorCode(): ErrorCodeInterface
    {
        return ErrorCodeEnum::MAATIFY_ERROR;
    }
}
