<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types = 1);

namespace MageWorx\OrderEditorInventory\Model;

use Magento\Bundle\Model\Product\Type;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryCatalogApi\Model\GetProductTypesBySkusInterface;
use Magento\InventoryCatalogApi\Model\GetSkusByProductIdsInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\InventorySalesApi\Api\Data\ItemToSellInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory;
use Magento\InventorySalesApi\Api\PlaceReservationsForSalesEventInterface;
use Magento\InventorySalesApi\Model\GetSkuFromOrderItemInterface;
use Magento\InventorySalesApi\Model\ReturnProcessor\ProcessRefundItemsInterface;
use Magento\InventorySalesApi\Model\ReturnProcessor\Request\ItemsToRefundInterfaceFactory;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Store\Api\WebsiteRepositoryInterface;
use MageWorx\OrderEditor\Model\StockDebugLogger;
use MageWorx\OrderEditorInventory\Api\CancelShipmentProcessorInterface;
use MageWorx\OrderEditorInventory\Api\StockQtyManagerInterface;

/**
 * Class StockQtyManager
 *
 * Change stock and source qty on order items operations during edit
 */
class StockQtyManager implements StockQtyManagerInterface
{
    /**
     * @var PlaceReservationsForSalesEventInterface
     */
    private $placeReservationsForSalesEvent;

    /**
     * @var GetSkusByProductIdsInterface
     */
    private $getSkusByProductIds;

    /**
     * @var WebsiteRepositoryInterface
     */
    private $websiteRepository;

    /**
     * @var SalesChannelInterfaceFactory
     */
    private $salesChannelFactory;

    /**
     * @var SalesEventInterfaceFactory
     */
    private $salesEventFactory;

    /**
     * @var ItemToSellInterfaceFactory
     */
    private $itemsToSellFactory;

    /**
     * @var CheckItemsQuantity
     */
    private $checkItemsQuantity;

    /**
     * @var StockByWebsiteIdResolverInterface
     */
    private $stockByWebsiteIdResolver;

    /**
     * @var GetProductTypesBySkusInterface
     */
    private $getProductTypesBySkus;

    /**
     * @var IsSourceItemManagementAllowedForProductTypeInterface
     */
    private $isSourceItemManagementAllowedForProductType;

    /**
     * @var SalesEventExtensionFactory;
     */
    private $salesEventExtensionFactory;

    /**
     * @var GetSkuFromOrderItemInterface
     */
    private $getSkuFromOrderItem;

    /**
     * @var ItemsToRefundInterfaceFactory
     */
    private $itemsToRefundFactory;

    /**
     * @var ProcessRefundItemsInterface
     */
    private $processRefundItems;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var CancelShipmentProcessorInterface
     */
    private $processCancelledShipmentItems;

    private StockDebugLogger $stockDebugLogger;

    /**
     * StockQtyManager constructor.
     *
     * @param PlaceReservationsForSalesEventInterface $placeReservationsForSalesEvent
     * @param GetSkusByProductIdsInterface $getSkusByProductIds
     * @param WebsiteRepositoryInterface $websiteRepository
     * @param SalesChannelInterfaceFactory $salesChannelFactory
     * @param SalesEventInterfaceFactory $salesEventFactory
     * @param ItemToSellInterfaceFactory $itemsToSellFactory
     * @param CheckItemsQuantity $checkItemsQuantity
     * @param StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver
     * @param GetProductTypesBySkusInterface $getProductTypesBySkus
     * @param IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowedForProductType
     * @param SalesEventExtensionFactory $salesEventExtensionFactory
     * @param GetSkuFromOrderItemInterface $getSkuFromOrderItem
     * @param ItemsToRefundInterfaceFactory $itemsToRefundFactory
     * @param ProcessRefundItemsInterface $processRefundItems
     * @param OrderRepositoryInterface $orderRepository
     * @param CancelShipmentProcessorInterface $processCancelledShipmentItems
     * @param StockDebugLogger|null $stockDebugLogger
     */
    public function __construct(
        PlaceReservationsForSalesEventInterface              $placeReservationsForSalesEvent,
        GetSkusByProductIdsInterface                         $getSkusByProductIds,
        WebsiteRepositoryInterface                           $websiteRepository,
        SalesChannelInterfaceFactory                         $salesChannelFactory,
        SalesEventInterfaceFactory                           $salesEventFactory,
        ItemToSellInterfaceFactory                           $itemsToSellFactory,
        CheckItemsQuantity                                   $checkItemsQuantity,
        StockByWebsiteIdResolverInterface                    $stockByWebsiteIdResolver,
        GetProductTypesBySkusInterface                       $getProductTypesBySkus,
        IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowedForProductType,
        SalesEventExtensionFactory                           $salesEventExtensionFactory,
        GetSkuFromOrderItemInterface                         $getSkuFromOrderItem,
        ItemsToRefundInterfaceFactory                        $itemsToRefundFactory,
        ProcessRefundItemsInterface                          $processRefundItems,
        OrderRepositoryInterface                             $orderRepository,
        CancelShipmentProcessorInterface                     $processCancelledShipmentItems,
        ?StockDebugLogger                                    $stockDebugLogger = null
    ) {
        $this->placeReservationsForSalesEvent              = $placeReservationsForSalesEvent;
        $this->getSkusByProductIds                         = $getSkusByProductIds;
        $this->websiteRepository                           = $websiteRepository;
        $this->salesChannelFactory                         = $salesChannelFactory;
        $this->salesEventFactory                           = $salesEventFactory;
        $this->itemsToSellFactory                          = $itemsToSellFactory;
        $this->checkItemsQuantity                          = $checkItemsQuantity;
        $this->stockByWebsiteIdResolver                    = $stockByWebsiteIdResolver;
        $this->getProductTypesBySkus                       = $getProductTypesBySkus;
        $this->isSourceItemManagementAllowedForProductType = $isSourceItemManagementAllowedForProductType;
        $this->salesEventExtensionFactory                  = $salesEventExtensionFactory;
        $this->getSkuFromOrderItem                         = $getSkuFromOrderItem;
        $this->itemsToRefundFactory                        = $itemsToRefundFactory;
        $this->processRefundItems                          = $processRefundItems;
        $this->orderRepository                             = $orderRepository;
        $this->processCancelledShipmentItems               = $processCancelledShipmentItems;
        $this->stockDebugLogger = $stockDebugLogger ?? \Magento\Framework\App\ObjectManager::getInstance()->get(StockDebugLogger::class);
    }

    /**
     * @param OrderItem $orderItem
     * @param float|null $qty
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function deductQtyFromStock(OrderItem $orderItem, ?float $qty = null): void
    {
        $this->stockDebugLogger->open('deductQtyFromStock', [
            'sku'  => $orderItem->getSku(),
            'type' => $orderItem->getProductType(),
            'qty'  => $qty,
        ]);

        if ($orderItem->getProductType() === Configurable::TYPE_CODE) {
            $this->stockDebugLogger->log('branch: configurable — using children items');
            $orderItems = $orderItem->getChildrenItems();
        } elseif ($orderItem->getProductType() === Type::TYPE_CODE) {
            $this->stockDebugLogger->log('branch: bundle — using children items');
            $orderItems = $orderItem->getChildrenItems();
        } elseif ($orderItem->getParentItemId()) {
            $this->stockDebugLogger->log('branch: child item — skipping (parent handles deduction)');
            $this->stockDebugLogger->close('deductQtyFromStock');
            return; // Do not deduct qty of child item manually
        } else {
            $this->stockDebugLogger->log('branch: simple/other — deducting directly');
            $orderItems = [$orderItem];
        }

        foreach ($orderItems as $item) {
            $this->stockDebugLogger->log('deducting child', ['sku' => $item->getSku(), 'item_id' => $item->getItemId()]);
            $this->deduct($item, $qty);
        }

        $this->stockDebugLogger->close('deductQtyFromStock');
    }

    /**
     * @param OrderItem $orderItem
     * @param float|null $qty
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    private function deduct(OrderItem $orderItem, ?float $qty = null): void
    {
        $this->stockDebugLogger->open('deduct', [
            'sku' => $orderItem->getSku(),
            'qty' => $qty ?? $orderItem->getQtyOrdered(),
        ]);

        $order = $orderItem->getOrder();
        if (!$order || !$order->getId()) {
            throw new InputException(__('Order Id must be set before processing order item'));
        }

        $itemsById = $itemsBySku = $itemsToSell = [];

        $itemsById[$orderItem->getProductId()] = $qty ?? $orderItem->getQtyOrdered();
        $productSkus                           = $this->getSkusByProductIds->execute(array_keys($itemsById));

        foreach ($productSkus as $productId => $sku) {
            if (!$this->isValidItem($sku, $orderItem)) {
                continue;
            }

            $itemsBySku[$sku] = (float)$itemsById[$productId];
            $itemsToSell[]    = $this->itemsToSellFactory->create(
                [
                    'sku' => $sku,
                    'qty' => -(float)$itemsById[$productId]
                ]
            );
        }

        $websiteId   = (int)$order->getStore()->getWebsiteId();
        $websiteCode = $this->websiteRepository->getById($websiteId)->getCode();
        $stockId     = (int)$this->stockByWebsiteIdResolver->execute((int)$websiteId)->getStockId();

        $this->checkItemsQuantity->execute($itemsBySku, $stockId);

        /** @var SalesEventExtensionInterface */
        $salesEventExtension = $this->salesEventExtensionFactory->create(
            [
                'data' => [
                    'objectIncrementId' => (string)$order->getIncrementId()
                ]
            ]
        );

        /** @var SalesEventInterface $salesEvent */
        $salesEvent = $this->salesEventFactory->create(
            [
                'type'       => SalesEventInterface::EVENT_ORDER_PLACED,
                'objectType' => SalesEventInterface::OBJECT_TYPE_ORDER,
                'objectId'   => (string)$order->getEntityId()
            ]
        );

        $salesEvent->setExtensionAttributes($salesEventExtension);
        $salesChannel = $this->salesChannelFactory->create(
            [
                'data' => [
                    'type' => SalesChannelInterface::TYPE_WEBSITE,
                    'code' => $websiteCode
                ]
            ]
        );

        $this->stockDebugLogger->log('placing reservation', ['items_count' => count($itemsToSell), 'stock_id' => $stockId]);
        $this->placeReservationsForSalesEvent->execute($itemsToSell, $salesChannel, $salesEvent);
        $this->stockDebugLogger->close('deduct');
    }

    /**
     * @param string $sku
     * @param OrderItem $orderItem
     * @return bool
     */
    private function isValidItem(string $sku, OrderItem $orderItem): bool
    {
        // Since simple products which are the part of a grouped product are saved in the database
        // (table sales_order_item) with product type grouped, we manually change the type of
        // product from grouped to simple which support source management.
        $typeId = $orderItem->getProductType() === 'grouped' ? 'simple' : $orderItem->getProductType();

        $productType = $typeId ?: $this->getProductTypesBySkus->execute(
            [$sku]
        )[$sku];

        return $this->isSourceItemManagementAllowedForProductType->execute($productType);
    }

    /**
     * @inheritDoc
     */
    public function returnQtyToStock(OrderItem $orderItem, ?float $qty = null): void
    {
        $this->stockDebugLogger->open('returnQtyToStock', [
            'sku'  => $orderItem->getSku(),
            'type' => $orderItem->getProductType(),
            'qty'  => $qty,
        ]);

        // Similar with return items on creditmemo
        // @see \Magento\InventorySales\Plugin\SalesInventory\ProcessReturnQtyOnCreditMemoPlugin::aroundExecute()
        $order = $orderItem->getOrder(); //@TODO: $order must be OrderEditorOrder
        if (!$order || !$order->getId()) {
            throw new InputException(__('Order Id must be set before processing order item'));
        }

        if ($orderItem->getProductType() === Configurable::TYPE_CODE) {
            $items = $orderItem->getChildrenItems();
            $this->stockDebugLogger->log('resolved children for configurable', ['count' => count($items)]);
        } elseif ($orderItem->getProductType() === Type::TYPE_CODE) {
            $items = $orderItem->getChildrenItems();
            $this->stockDebugLogger->log('resolved children for bundle', ['count' => count($items)]);
        } else {
            $items = [$orderItem];
            $this->stockDebugLogger->log('simple/other — returning directly');
        }

        if (!empty($items)) {
            $this->returnItems($items, $order, $qty);
        }

        $this->stockDebugLogger->close('returnQtyToStock');
    }

    /**
     * @param OrderItem[] $items
     * @param OrderInterface $order
     * @param float|null $qty
     */
    private function returnItems(array $items, OrderInterface $order, ?float $qty = null): void
    {
        $itemsToRefund = $refundedOrderItemIds = [];

        /** @var OrderItem|OrderItemInterface $orderItem */
        foreach ($items as $orderItem) {
            $sku = $this->getSkuFromOrderItem->execute($orderItem);
            $this->stockDebugLogger->log('returnItems: processing item', ['sku' => $sku, 'qty' => $qty, 'item_id' => $orderItem->getItemId()]);

            if ($this->isValidItem($sku, $orderItem)) {
                $refundedOrderItemIds[] = $orderItem->getItemId();

                $qtyToReturn = abs($qty); // Qty to return
                /**
                 * Total items with currently returning qty
                 * Overall qty without refunded before items
                 */
                $processedQty = /* @TODO: Qty ordered should be here? */
                    $orderItem->getQtyOrdered() - $orderItem->getQtyCanceled() - $orderItem->getQtyRefunded(); // All qty before return

                $itemsToRefund[] = $this->itemsToRefundFactory->create(
                    [
                        'sku'          => $sku,
                        'qty'          => $qtyToReturn,
                        'processedQty' => (float)$processedQty
                    ]
                );
            }
        }

        if (!empty($itemsToRefund)) {
            $this->processRefundItems->execute($order, $itemsToRefund, $refundedOrderItemIds);
        }
    }

    /**
     * @inheritDoc
     */
    public function returnQtyToStockByProductId(int $productId, float $qty, int $websiteId): void
    {
        $this->stockDebugLogger->open('returnQtyToStockByProductId', [
            'product_id' => $productId,
            'qty'        => $qty,
            'website_id' => $websiteId,
        ]);

        $productSkus = $this->getSkusByProductIds->execute([$productId]);
        if (empty($productSkus[$productId])) {
            $this->stockDebugLogger->log('sku not found for product_id, skipping');
            $this->stockDebugLogger->close('returnQtyToStockByProductId');
            return;
        }

        $sku         = $productSkus[$productId];
        $productType = $this->getProductTypesBySkus->execute([$sku])[$sku] ?? null;

        if (!$productType || !$this->isSourceItemManagementAllowedForProductType->execute($productType)) {
            $this->stockDebugLogger->log('source item management not allowed, skipping', ['sku' => $sku, 'type' => $productType]);
            $this->stockDebugLogger->close('returnQtyToStockByProductId');
            return;
        }

        $websiteCode = $this->websiteRepository->getById($websiteId)->getCode();

        $itemsToSell = [
            $this->itemsToSellFactory->create(
                [
                    'sku' => $sku,
                    'qty' => (float)$qty // positive qty = compensation (return to stock)
                ]
            )
        ];

        /** @var SalesEventExtensionInterface */
        $salesEventExtension = $this->salesEventExtensionFactory->create(
            [
                'data' => [
                    'objectIncrementId' => 'order_edit_return_' . $productId
                ]
            ]
        );

        /** @var SalesEventInterface $salesEvent */
        $salesEvent = $this->salesEventFactory->create(
            [
                'type'       => SalesEventInterface::EVENT_ORDER_PLACED,
                'objectType' => SalesEventInterface::OBJECT_TYPE_ORDER,
                'objectId'   => 'order_edit_return_' . $productId
            ]
        );

        $salesEvent->setExtensionAttributes($salesEventExtension);
        $salesChannel = $this->salesChannelFactory->create(
            [
                'data' => [
                    'type' => SalesChannelInterface::TYPE_WEBSITE,
                    'code' => $websiteCode
                ]
            ]
        );

        $this->stockDebugLogger->log('placing return reservation', ['sku' => $sku, 'qty' => $qty]);
        $this->placeReservationsForSalesEvent->execute($itemsToSell, $salesChannel, $salesEvent);
        $this->stockDebugLogger->close('returnQtyToStockByProductId');
    }

    /**
     * @param ShipmentInterface $shipment
     */
    public function cancelShipment(ShipmentInterface $shipment): void
    {
        $this->processCancelledShipmentItems->execute($shipment);
    }
}
