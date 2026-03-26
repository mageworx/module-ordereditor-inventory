<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types = 1);

namespace MageWorx\OrderEditorInventory\Model\Stock;

use Magento\Sales\Api\Data\OrderItemInterface;
use MageWorx\OrderEditor\Api\StockManagerInterface;
use MageWorx\OrderEditorInventory\Api\StockQtyManagerInterface;

class MultiSourceInventoryManager implements StockManagerInterface
{
    /**
     * @var StockQtyManagerInterface
     */
    private $stockQtyManager;

    /**
     * MultiSourceInventoryManager constructor.
     *
     * @param StockQtyManagerInterface $stockQtyManager
     */
    public function __construct(
        StockQtyManagerInterface $stockQtyManager
    ) {
        $this->stockQtyManager = $stockQtyManager;
    }

    /**
     * @inheritDoc
     */
    public function registerReturn(OrderItemInterface $item, float $qty): void
    {
        $this->stockQtyManager->returnQtyToStock($item, $qty);
    }

    /**
     * @inheritDoc
     */
    public function registerReturnByProductId(int $productId, float $qty, int $websiteId): void
    {
        $this->stockQtyManager->returnQtyToStockByProductId($productId, $qty, $websiteId);
    }

    /**
     * @inheritDoc
     */
    public function registerSale(OrderItemInterface $item, float $qty): void
    {
        $this->stockQtyManager->deductQtyFromStock($item, $qty);
    }
}
