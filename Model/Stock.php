<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockItemCriteriaInterfaceFactory;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Magento\CatalogInventory\Model\ResourceModel\Stock\Status as StockStatus;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;

/**
 * Units in stock of catalog items ("stock" of the platform), only where the store tracks them: the salable quantity
 * of Inventory (MSI) for the stock of the website, or the legacy stock item without MSI. Products without stock
 * management or with backorders get none — their stock does not limit sales; a configurable product gets the sum
 * of its enabled children, when all of them have one.
 *
 * MSI may be removed from a store, so its services are resolved by name only when its modules are enabled.
 */
class Stock
{
    private const MSI_MODULES = ['Magento_InventoryApi', 'Magento_InventorySalesApi', 'Magento_InventorySales', 'Magento_InventoryConfigurationApi', 'Magento_InventoryConfiguration'];

    /** @var array<int, int|null> website id => MSI stock id */
    private array $stockIds = [];

    /**
     * @param bool|null $msi null — MSI when its modules are enabled; tests pass false for the legacy stock
     */
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ObjectManagerInterface $objectManager,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly StockItemRepositoryInterface $stockItems,
        private readonly StockItemCriteriaInterfaceFactory $criteria,
        private readonly Configurable $configurable,
        private readonly CollectionFactory $products,
        private readonly StockStatus $stockStatus,
        private ?bool $msi = null,
    ) {
    }

    /**
     * Salability of the stock index (of the website's stock with MSI) for Product::isSalable() of the catalog: a
     * collection has none by itself, and every enabled product would be available. A product missing from the
     * index (saved before the indexer ran) keeps the status of the product.
     */
    public function joinSalability(Collection $collection): void
    {
        if (!array_key_exists('stock_status_index', (array) $collection->getSelect()->getPart(\Magento\Framework\DB\Select::FROM))) {
            $this->stockStatus->addStockDataToCollection($collection, false);
        }
    }

    /**
     * After the collection is loaded: no row in the stock index — the salability of the product type.
     */
    public function salabilityLoaded(Product $product): void
    {
        if ($product->hasData('is_salable') && $product->getData('is_salable') === null) {
            $product->unsetData('is_salable');
        }
    }

    /**
     * @param array<Product> $products products of one store view
     *
     * @return array<int, int> product id => units; products without tracked stock are left out
     */
    public function quantities(array $products, int $storeId, int $websiteId): array
    {
        $own = [];
        $children = [];

        foreach ($products as $product) {
            $id = (int) $product->getId();

            if ($product->getTypeId() === ConfigurableType::TYPE_CODE) {
                $children[$id] = array_map('intval', array_merge([], ...array_values($this->configurable->getChildrenIds($id))));
            } elseif ($this->stockConfiguration->isQty($product->getTypeId())) {
                $own[$id] = (string) $product->getSku();
            }
        }

        $childSkus = $this->enabledChildren(array_unique(array_merge([], ...array_values($children))), $storeId);
        $units = $this->units($own + $childSkus, $websiteId);
        $result = array_intersect_key($units, $own);

        foreach ($children as $id => $ids) {
            $ids = array_values(array_filter($ids, static fn (int $child): bool => isset($childSkus[$child])));

            // A child without tracked stock can be sold without limit: the parent has no meaningful total either.
            if ($ids !== [] && array_diff_key(array_flip($ids), $units) === []) {
                $result[$id] = array_sum(array_intersect_key($units, array_flip($ids)));
            }
        }

        return $result;
    }

    public function usesMsi(): bool
    {
        return $this->msi ??= array_filter(self::MSI_MODULES, fn (string $module): bool => !$this->modules->isEnabled($module)) === []
            && interface_exists('Magento\InventorySalesApi\Api\GetProductSalableQtyInterface');
    }

    /**
     * @param array<int, string> $skus product id => sku of products whose own stock is tracked
     *
     * @return array<int, int> product id => units
     */
    private function units(array $skus, int $websiteId): array
    {
        if ($skus === []) {
            return [];
        }

        return $this->usesMsi() ? $this->msiUnits($skus, $websiteId) : $this->legacyUnits(array_keys($skus));
    }

    /**
     * @param array<int, string> $skus
     *
     * @return array<int, int>
     */
    private function msiUnits(array $skus, int $websiteId): array
    {
        $stockId = $this->stockId($websiteId);

        if ($stockId === null) {
            return [];
        }

        $configuration = $this->objectManager->get('Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface');
        $salable = $this->objectManager->get('Magento\InventorySalesApi\Api\GetProductSalableQtyInterface');
        $units = [];

        foreach ($skus as $id => $sku) {
            try {
                $item = $configuration->execute($sku, $stockId);

                if ($item->isManageStock() && (int) $item->getBackorders() === 0) {
                    $units[$id] = max(0, (int) floor((float) $salable->execute($sku, $stockId)));
                }
            } catch (\Exception) {
                // Not assigned to the stock of the website, or a type without source items.
                continue;
            }
        }

        return $units;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, int>
     */
    private function legacyUnits(array $ids): array
    {
        $criteria = $this->criteria->create();
        $criteria->setProductsFilter($ids);
        $criteria->setScopeFilter($this->stockConfiguration->getDefaultScopeId());
        $units = [];

        /** @var \Magento\CatalogInventory\Model\Stock\Item $item */
        foreach ($this->stockItems->getList($criteria)->getItems() as $item) {
            if ($item->getManageStock() && (int) $item->getBackorders() === 0) {
                $units[(int) $item->getProductId()] = $item->getIsInStock() ? max(0, (int) floor((float) $item->getQty() - (float) $item->getMinQty())) : 0;
            }
        }

        return $units;
    }

    private function stockId(int $websiteId): ?int
    {
        if (!array_key_exists($websiteId, $this->stockIds)) {
            try {
                $stock = $this->objectManager->get('Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface')->execute($websiteId);
                $this->stockIds[$websiteId] = $stock->getStockId() !== null ? (int) $stock->getStockId() : null;
            } catch (\Exception) {
                $this->stockIds[$websiteId] = null;
            }
        }

        return $this->stockIds[$websiteId];
    }

    /**
     * Children enabled in the store view, with their own stock.
     *
     * @param array<int> $ids
     *
     * @return array<int, string> id => sku
     */
    private function enabledChildren(array $ids, int $storeId): array
    {
        if ($ids === []) {
            return [];
        }

        $skus = [];
        $collection = $this->products->create()
            ->setStoreId($storeId)
            ->addIdFilter($ids)
            ->addAttributeToFilter('status', Status::STATUS_ENABLED);

        /** @var Product $child */
        foreach ($collection as $child) {
            if ($this->stockConfiguration->isQty($child->getTypeId())) {
                $skus[(int) $child->getId()] = (string) $child->getSku();
            }
        }

        return $skus;
    }
}
