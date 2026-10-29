<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Support\Ordering;

use LogicException;
use PDO;
use PDOStatement;

/**
 * Minimal scripted PDO for ScopedOrderingManagerSavepointWiringTest: a queue of prepared
 * statements consumed in the exact order moveWithinScope() issues them (scope-lock SELECT,
 * current-order SELECT, shift UPDATE, target UPDATE), and a transaction-active flag that starts
 * at $participating and flips with beginTransaction()/commit()/rollBack() so the double can
 * script both the participating and the owned path. Control statements (SAVEPOINT/RELEASE/
 * ROLLBACK TO) go through exec(), tracked and always reported successful: the shared
 * SavepointScope's own control-statement failure precedence is already characterized in
 * isolation by tests/Unit/Transaction/Repository/SavepointScopeTest.php (WU-6); this double
 * exists only to prove which SavepointScope method moveWithinScope() calls for a given
 * target-write outcome.
 */
final class OrderingScriptedPdo extends PDO
{
    /** @var list<string> */
    public array $executedControlStatements = [];

    /** @var list<OrderingScriptedStatement> */
    private array $prepareQueue;

    private bool $active;

    /** @param list<OrderingScriptedStatement> $prepareQueue */
    public function __construct(array $prepareQueue, bool $participating = true)
    {
        $this->prepareQueue = $prepareQueue;
        $this->active = $participating;
    }

    public function inTransaction(): bool
    {
        return $this->active;
    }

    public function beginTransaction(): bool
    {
        $this->active = true;

        return true;
    }

    public function commit(): bool
    {
        $this->active = false;

        return true;
    }

    public function rollBack(): bool
    {
        $this->active = false;

        return true;
    }

    /** @param array<mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement
    {
        unset($query, $options);

        if ($this->prepareQueue === []) {
            throw new LogicException('Ordering scripted PDO prepare queue exhausted.');
        }

        return array_shift($this->prepareQueue);
    }

    public function exec(string $statement): int
    {
        $this->executedControlStatements[] = $statement;

        return 0;
    }
}
