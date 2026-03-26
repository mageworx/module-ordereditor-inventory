<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types = 1);

namespace MageWorx\OrderEditorInventory\Test\Unit\Model\Stock;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Sales\Model\Order\Item as OrderItem;
use MageWorx\OrderEditorInventory\Api\StockQtyManagerInterface;
use MageWorx\OrderEditorInventory\Model\Stock\MultiSourceInventoryManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MultiSourceInventoryManager — the MSI adapter for StockManagerInterface.
 *
 * Bug: registerReturnByProductId() is not implemented (TODO stub).
 * This method is called from Item::updateInventoryAfterUpdateOptions() when
 * a configurable child product is replaced during order editing.
 * Without this implementation, the old child's stock is never returned.
 *
 * @see https://repos.mageworx.com/mageworx_extensions_m2/orders/MageWorx_OrderEditor/-/issues/232
 */
class MultiSourceInventoryManagerTest extends TestCase
{
    private ObjectManagerHelper $objectManagerHelper;

    private MultiSourceInventoryManager $manager;

    private StockQtyManagerInterface|MockObject $stockQtyManagerMock;

    protected function setUp(): void
    {
        $this->objectManagerHelper = new ObjectManagerHelper($this);

        $this->stockQtyManagerMock = $this->createMock(StockQtyManagerInterface::class);

        $this->manager = $this->objectManagerHelper->getObject(
            MultiSourceInventoryManager::class,
            [
                'stockQtyManager' => $this->stockQtyManagerMock,
            ]
        );
    }

    /**
     * registerReturn() should delegate to StockQtyManager::returnQtyToStock().
     */
    public function testRegisterReturnDelegatesToStockQtyManager(): void
    {
        $orderItemMock = $this->getMockBuilder(OrderItem::class)
            ->disableOriginalConstructor()
            ->getMock();
        $qty = 5.0;

        $this->stockQtyManagerMock
            ->expects($this->once())
            ->method('returnQtyToStock')
            ->with($orderItemMock, $qty);

        $this->manager->registerReturn($orderItemMock, $qty);
    }

    /**
     * registerSale() should delegate to StockQtyManager::deductQtyFromStock().
     */
    public function testRegisterSaleDelegatesToStockQtyManager(): void
    {
        $orderItemMock = $this->getMockBuilder(OrderItem::class)
            ->disableOriginalConstructor()
            ->getMock();
        $qty = 3.0;

        $this->stockQtyManagerMock
            ->expects($this->once())
            ->method('deductQtyFromStock')
            ->with($orderItemMock, $qty);

        $this->manager->registerSale($orderItemMock, $qty);
    }

    /**
     * registerReturnByProductId() MUST perform actual stock return for the given product.
     *
     * Currently FAILS: method body is empty (TODO stub).
     * This is called from Item::updateInventoryAfterUpdateOptions() when replacing
     * a configurable child product. Without it, stock is never returned for the old child.
     */
    public function testRegisterReturnByProductIdIsNotEmptyStub(): void
    {
        $productId = 100;
        $qty = 2.0;
        $websiteId = 1;

        // Spy on all StockQtyManager methods
        $anyMethodCalled = false;
        $spyManager = $this->getMockBuilder(StockQtyManagerInterface::class)->getMock();

        $spyManager->method('returnQtyToStock')
            ->willReturnCallback(function () use (&$anyMethodCalled) {
                $anyMethodCalled = true;
            });
        $spyManager->method('deductQtyFromStock')
            ->willReturnCallback(function () use (&$anyMethodCalled) {
                $anyMethodCalled = true;
            });
        $spyManager->method('returnQtyToStockByProductId')
            ->willReturnCallback(function () use (&$anyMethodCalled) {
                $anyMethodCalled = true;
            });

        $manager = $this->objectManagerHelper->getObject(
            MultiSourceInventoryManager::class,
            ['stockQtyManager' => $spyManager]
        );

        $manager->registerReturnByProductId($productId, $qty, $websiteId);

        $this->assertTrue(
            $anyMethodCalled,
            'registerReturnByProductId() is a no-op stub (// TODO). '
            . 'It must perform actual stock return via MSI reservations. '
            . 'Without this, replacing a configurable child never returns the old child stock.'
        );
    }
}
