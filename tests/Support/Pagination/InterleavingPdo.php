<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Support\Pagination;

use Closure;
use PDO;
use PDOStatement;

/**
 * A real PDO connection that runs a caller-supplied callback immediately before each
 * `prepare()` call, numbered from 1. Used to deterministically interleave a second
 * connection's write between the Pagination total/filtered/data statements, instead of
 * a timing-based sleep, to prove or disprove a single-snapshot guarantee (B-08a).
 */
final class InterleavingPdo extends PDO
{
    private int $prepareCount = 0;

    /**
     * @param array<int, mixed> $options
     * @param Closure(int, string): void $beforePrepare
     */
    public function __construct(
        string $dsn,
        ?string $username,
        ?string $password,
        array $options,
        private readonly Closure $beforePrepare,
    ) {
        parent::__construct($dsn, $username, $password, $options);
    }

    /** @param array<mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepareCount++;
        ($this->beforePrepare)($this->prepareCount, $query);

        return parent::prepare($query, $options);
    }
}
