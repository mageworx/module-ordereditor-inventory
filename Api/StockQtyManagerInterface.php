<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace MageWorx\OrderEditorInventory\Api;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Model\Order\Item as OrderItem;

interface StockQtyManagerInterface
{
    /**
     * @param OrderItem $orderItem
     * @param float|null $qty
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function deductQtyFromStock(OrderItem $orderItem, ?float $qty = null): void;

    /**
     * @param OrderItem $orderItem
     * @param float|null $qty
     */
    public function returnQtyToStock(OrderItem $orderItem, ?float $qty = null): void;

    /**
     * Return qty to stock by product ID (without order item context).
     * Used when replacing a configurable child — returns stock for the old child product.
     *
     * @param int $productId
     * @param float $qty
     * @param int $websiteId
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function returnQtyToStockByProductId(int $productId, float $qty, int $websiteId): void;

    /**
     * Return all shipment items to stock (cancel\delete shipment)
     *
     * @param ShipmentInterface $shipment
     */
    public function cancelShipment(ShipmentInterface $shipment): void;
}
