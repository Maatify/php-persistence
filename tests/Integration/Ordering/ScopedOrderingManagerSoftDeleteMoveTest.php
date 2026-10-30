<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Ordering;

use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;

/**
 * Soft-delete interaction with B-01/B-03/B-06, ported from legacy
 * tests/Integration/Pdo/Ordering/ScopedOrderingManagerSoftDeleteMoveTest.php (1f6a7aa).
 */
final class ScopedOrderingManagerSoftDeleteMoveTest extends OrderingIntegrationTestCase
{
    private const DELETED_AT = '2026-01-01 00:00:00';

    private ScopedOrderingManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new ScopedOrderingManager();
    }

    public function testGlobalDeletedTargetWithDefaultFilteringReturnsFalse(): void
    {
        $active1 = $this->fixture->insertGlobal(1);
        $deleted = $this->fixture->insertGlobal(2, deletedAt: self::DELETED_AT);
        $active3 = $this->fixture->insertGlobal(3);
        $before = $this->reader()->globalOrdersById();

        $result = $this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $deleted, 1);

        self::assertFalse($result);
        self::assertSame($before, $this->reader()->globalOrdersById());
        self::assertSame([$active1 => 1, $deleted => 2, $active3 => 3], $before);
        self::assertFalse($this->pdo()->inTransaction());
    }

    public function testGlobalDeletedNonTargetIsNotShifted(): void
    {
        $target = $this->fixture->insertGlobal(1);
        $deleted = $this->fixture->insertGlobal(2, deletedAt: self::DELETED_AT);
        $active = $this->fixture->insertGlobal(3);

        $result = $this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $target, 3);

        self::assertTrue($result);
        self::assertSame([$target => 3, $deleted => 2, $active => 2], $this->reader()->globalOrdersById());
        self::assertFalse($this->pdo()->inTransaction());
    }

    public function testGlobalFilteringDisabledIncludesDeletedRowsInMove(): void
    {
        $active1 = $this->fixture->insertGlobal(1);
        $deletedTarget = $this->fixture->insertGlobal(2, deletedAt: self::DELETED_AT);
        $active3 = $this->fixture->insertGlobal(3);

        $result = $this->manager->moveWithinScope($this->pdo(), $this->globalConfigWithoutSoftDeletes(), null, $deletedTarget, 1);

        self::assertTrue($result);
        self::assertSame([$active1 => 2, $deletedTarget => 1, $active3 => 3], $this->reader()->globalOrdersById());
        self::assertFalse($this->pdo()->inTransaction());
    }

    public function testScopedDeletedTargetWithDefaultFilteringReturnsFalse(): void
    {
        $a1 = $this->fixture->insertScoped('scope-a', 1);
        $deleted = $this->fixture->insertScoped('scope-a', 2, deletedAt: self::DELETED_AT);
        $b1 = $this->fixture->insertScoped('scope-b', 1);
        $beforeA = $this->reader()->scopedOrdersById('scope-a');
        $beforeB = $this->reader()->scopedOrdersById('scope-b');

        $result = $this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $deleted, 1);

        self::assertFalse($result);
        self::assertSame($beforeA, $this->reader()->scopedOrdersById('scope-a'));
        self::assertSame($beforeB, $this->reader()->scopedOrdersById('scope-b'));
        self::assertSame([$a1 => 1, $deleted => 2], $beforeA);
        self::assertSame([$b1 => 1], $beforeB);
        self::assertFalse($this->pdo()->inTransaction());
    }

    public function testScopedDeletedNonTargetIsNotShifted(): void
    {
        $target = $this->fixture->insertScoped('scope-a', 1);
        $deleted = $this->fixture->insertScoped('scope-a', 2, deletedAt: self::DELETED_AT);
        $active = $this->fixture->insertScoped('scope-a', 3);
        $b = $this->fixture->insertScoped('scope-b', 3);

        $result = $this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $target, 3);

        self::assertTrue($result);
        self::assertSame([$target => 3, $deleted => 2, $active => 2], $this->reader()->scopedOrdersById('scope-a'));
        self::assertSame([$b => 3], $this->reader()->scopedOrdersById('scope-b'));
        self::assertFalse($this->pdo()->inTransaction());
    }

    public function testScopedFilteringDisabledIncludesDeletedRowsInRequestedScopeOnly(): void
    {
        $active1 = $this->fixture->insertScoped('scope-a', 1);
        $deletedTarget = $this->fixture->insertScoped('scope-a', 2, deletedAt: self::DELETED_AT);
        $active3 = $this->fixture->insertScoped('scope-a', 3);
        $bDeleted = $this->fixture->insertScoped('scope-b', 2, deletedAt: self::DELETED_AT);
        $bActive = $this->fixture->insertScoped('scope-b', 3);

        $result = $this->manager->moveWithinScope($this->pdo(), $this->scopedConfigWithoutSoftDeletes(), 'scope-a', $deletedTarget, 1);

        self::assertTrue($result);
        self::assertSame([$active1 => 2, $deletedTarget => 1, $active3 => 3], $this->reader()->scopedOrdersById('scope-a'));
        self::assertSame([$bDeleted => 2, $bActive => 3], $this->reader()->scopedOrdersById('scope-b'));
        self::assertFalse($this->pdo()->inTransaction());
    }
}
