<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Repository;

interface TransactionRunnerInterface
{
    /**
     * @template TResult
     *
     * @param callable(): TResult $callback
     *
     * @return TResult
     */
    public function run(callable $callback): mixed;
}
