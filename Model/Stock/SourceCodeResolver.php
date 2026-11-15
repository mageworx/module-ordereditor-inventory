<?php
/** Copyright © MageWorx. All rights reserved. See LICENSE.txt */
declare(strict_types = 1);

namespace MageWorx\OrderEditorInventory\Model\Stock;

use Magento\Framework\App\ResourceConnection;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\InventoryApi\Api\GetSourcesAssignedToStockOrderedByPriorityInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Resolves the source_code to deduct/return qty from for a given SKU during
 * a swap physical exchange.
 *
 * Three-tier strategy (per spec):
 *   1. shipment_source — if the original shipment record points at a source
 *      where the SKU has sufficient qty (or backorders are explicitly OK),
 *      use it. Returning old SKU here is straightforward (we add qty), and
 *      deducting new SKU from the same source preserves "where the package
 *      came from" intuition.
 *   2. priority-ordered sources of the website's stock — first source with
 *      sufficient qty / backorder permission.
 *   3. null — caller must throw.
 *
 * Returns null instead of throwing so the caller can compose a precise
 * error message ("New SKU 'cfg-1-XL' is not available on any source...").
 */
class SourceCodeResolver
{
    public function __construct(
        private GetSourceItemsBySkuInterface $getSourceItemsBySku,
        private GetSourcesAssignedToStockOrderedByPriorityInterface $getSourcesByStock,
        private StockResolverInterface $stockResolver,
        private WebsiteRepositoryInterface $websiteRepository,
        private SourceRepositoryInterface $sourceRepository,
        private ResourceConnection $resourceConnection,
        private BackorderPolicy $backorderPolicy
    ) {
    }

    /**
     * Read shipment_source codes for a given order item from
     * `inventory_shipment_source` (set by Magento's shipment service when a
     * shipment is created). Handles split shipments — returns multiple codes
     * with their respective qty contribution.
     *
     * @return array<string, float> [source_code => qty_shipped_from_this_source]
     */
    public function resolveShipmentSources(int $orderItemId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $shipmentItemTable = $this->resourceConnection->getTableName('sales_shipment_item');
        $shipmentSourceTable = $this->resourceConnection->getTableName('inventory_shipment_source');

        // inventory_shipment_source is keyed by shipment entity id, not by item,
        // so all items in the same shipment share the same source.
        $select = $connection->select()
            ->from(['si' => $shipmentItemTable], ['qty'])
            ->joinInner(
                ['iss' => $shipmentSourceTable],
                'iss.shipment_id = si.parent_id',
                ['source_code']
            )
            ->where('si.order_item_id = ?', $orderItemId);

        $sources = [];
        foreach ($connection->fetchAll($select) as $row) {
            $code = (string) $row['source_code'];
            $sources[$code] = ($sources[$code] ?? 0.0) + (float) $row['qty'];
        }

        return $sources;
    }

    /**
     * Find a source code suitable for deducting `qtyNeeded` of `$sku`.
     * Tries `$preferredSourceCode` first if non-null, then iterates the
     * priority list of sources assigned to the website's stock.
     *
     * Returns null if no source qualifies (caller throws with details).
     */
    public function findDeductionSource(
        string $sku,
        int $websiteId,
        float $qtyNeeded,
        ?string $preferredSourceCode = null
    ): ?string {
        $backorderAllowed = $this->backorderPolicy->isBackorderAllowed($sku, $websiteId);
        $availabilityBySource = $this->getAvailabilityBySource($sku);

        // Tier 1: preferred (e.g. shipment) source — if SKU is assigned, active,
        // and has either enough qty or backorders enabled.
        if ($preferredSourceCode !== null
            && $this->isSourceUsable(
                $availabilityBySource,
                $preferredSourceCode,
                $qtyNeeded,
                $backorderAllowed
            )
        ) {
            return $preferredSourceCode;
        }

        // Tier 2: priority-ordered sources of the stock assigned to this website.
        try {
            $websiteCode = $this->websiteRepository->getById($websiteId)->getCode();
            $stock = $this->stockResolver->execute(ScopeInterface::SCOPE_WEBSITE, $websiteCode);
            $stockId = (int) $stock->getStockId();
            $sources = $this->getSourcesByStock->execute($stockId);
            foreach ($sources as $source) {
                if (!$source->isEnabled()) {
                    continue;
                }
                $code = $source->getSourceCode();
                if ($code === $preferredSourceCode) {
                    continue; // already tried
                }
                if ($this->isSourceUsable(
                    $availabilityBySource,
                    $code,
                    $qtyNeeded,
                    $backorderAllowed
                )) {
                    return $code;
                }
            }
        } catch (\Throwable $e) {
            // Stock not resolvable — fall through to null.
        }

        return null;
    }

    /**
     * Return source code for adding qty back of `$sku` (return-to-stock
     * direction). Always succeeds for a source where SKU is assigned and
     * enabled — we never need backorder check on the return side. Falls
     * back to first eligible source, or null if SKU is assigned nowhere.
     */
    public function findReturnSource(string $sku, int $websiteId, ?string $preferredSourceCode = null): ?string
    {
        $availability = $this->getAvailabilityBySource($sku);
        if ($preferredSourceCode !== null && isset($availability[$preferredSourceCode])) {
            return $preferredSourceCode;
        }
        // Any assigned-and-enabled source will accept a +qty.
        foreach ($availability as $code => $info) {
            if ($info['status'] === SourceItemInterface::STATUS_IN_STOCK || $info['source_enabled']) {
                return (string) $code;
            }
        }
        return array_key_first($availability) !== null ? (string) array_key_first($availability) : null;
    }

    /**
     * @return array<string, array{quantity: float, status: int, source_enabled: bool}>
     */
    private function getAvailabilityBySource(string $sku): array
    {
        $result = [];
        foreach ($this->getSourceItemsBySku->execute($sku) as $sourceItem) {
            $code = $sourceItem->getSourceCode();
            $sourceEnabled = false;
            try {
                $sourceEnabled = $this->sourceRepository->get($code)->isEnabled();
            } catch (\Throwable $e) {
                // unknown source — treat as disabled
            }
            $result[$code] = [
                'quantity' => (float) $sourceItem->getQuantity(),
                'status' => (int) $sourceItem->getStatus(),
                'source_enabled' => $sourceEnabled,
            ];
        }
        return $result;
    }

    private function isSourceUsable(
        array $availabilityBySource,
        string $sourceCode,
        float $qtyNeeded,
        bool $backorderAllowed
    ): bool {
        if (!isset($availabilityBySource[$sourceCode])) {
            return false;
        }
        $info = $availabilityBySource[$sourceCode];
        if (!$info['source_enabled']) {
            return false;
        }
        if ($info['quantity'] >= $qtyNeeded) {
            return true;
        }
        return $backorderAllowed;
    }
}
