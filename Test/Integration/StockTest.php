<?php

declare(strict_types=1);

namespace OneTrace\Connector\Test\Integration;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;
use OneTrace\Connector\Model\Stock;
use OneTrace\Connector\Test\TestCase;

final class StockTest extends TestCase
{
    public function testSendsTheSalableQuantityAndResendsTheProductWhenOrdersChangeIt(): void
    {
        $msi = $this->om->get(Stock::class)->usesMsi();
        $product = $this->product(['stock' => ['use_config_manage_stock' => 1, 'qty' => 10, 'is_in_stock' => 1]]);
        $this->flush();
        self::assertSame(10, $this->uploaded((int) $product->getId())['stock']);

        $order = $this->order($product, 3);
        self::assertTrue($this->queued((int) $product->getId()), 'A placed order resends its products.');
        $this->flush();
        self::assertSame(7, $this->uploaded((int) $product->getId())['stock'], 'Units of the order are not salable.');

        // The legacy stock item: Inventory (MSI) reserves the units and deducts them only when the order is shipped.
        self::assertSame([(int) $product->getId() => $msi ? 10 : 7], $this->legacyStock()->quantities([$this->load($product)], 1, 1));

        $order->cancel()->save();
        self::assertTrue($this->queued((int) $product->getId()), 'A cancelled order resends its products.');
        $this->flush();
        self::assertSame(10, $this->uploaded((int) $product->getId())['stock']);

        $refunded = $this->order($product, 4);
        $invoice = $this->om->get(InvoiceService::class)->prepareInvoice($refunded);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE)->register();
        $this->om->create(\Magento\Framework\DB\Transaction::class)->addObject($invoice)->addObject($invoice->getOrder())->save();
        $this->flush();
        self::assertSame(6, $this->uploaded((int) $product->getId())['stock']);

        $creditmemo = $this->om->get(CreditmemoFactory::class)->createByOrder($refunded);

        foreach ($creditmemo->getAllItems() as $item) {
            $item->setBackToStock(true);
        }

        $this->om->get(CreditmemoManagementInterface::class)->refund($creditmemo, true);
        self::assertTrue($this->queued((int) $product->getId()), 'A refund resends its products.');
        $this->flush();
        self::assertSame(10, $this->uploaded((int) $product->getId())['stock'], 'Returned to stock.');
    }

    public function testResendsTheProductWhenItsStockIsSaved(): void
    {
        $product = $this->product(['stock' => ['use_config_manage_stock' => 1, 'qty' => 10, 'is_in_stock' => 1]]);
        $this->flush();

        $registry = $this->om->get(StockRegistryInterface::class);
        $item = $registry->getStockItemBySku($product->getSku());
        $item->setQty(4);
        $this->admin(fn () => $registry->updateStockItemBySku($product->getSku(), $item));
        self::assertTrue($this->queued((int) $product->getId()), 'A saved legacy stock item resends the product.');
        $this->flush();
        self::assertSame(4, $this->uploaded((int) $product->getId())['stock']);

        if ($this->om->get(Stock::class)->usesMsi()) {
            $sourceItem = $this->om->create(\Magento\InventoryApi\Api\Data\SourceItemInterface::class);
            $sourceItem->setSku($product->getSku());
            $sourceItem->setSourceCode('default');
            $sourceItem->setQuantity(0);
            $sourceItem->setStatus(0);
            $this->om->get(\Magento\InventoryApi\Api\SourceItemsSaveInterface::class)->execute([$sourceItem]);
            self::assertTrue($this->queued((int) $product->getId()), 'Saved source items of Inventory resend the product.');
        } else {
            $item = $registry->getStockItemBySku($product->getSku());
            $item->setQty(0)->setIsInStock(false);
            $this->admin(fn () => $registry->updateStockItemBySku($product->getSku(), $item));
        }

        $this->flush();
        $uploaded = $this->uploaded((int) $product->getId());
        self::assertSame(0, $uploaded['stock']);
        self::assertFalse($uploaded['available']);
    }

    public function testLeavesTheStockOutWhereItIsNotTrackedOrBackordersAreAllowed(): void
    {
        $unmanaged = $this->product(['stock' => ['use_config_manage_stock' => 0, 'manage_stock' => 0, 'is_in_stock' => 1]]);
        $backorders = $this->product(['stock' => ['use_config_manage_stock' => 1, 'qty' => 0, 'is_in_stock' => 1, 'use_config_backorders' => 0, 'backorders' => 1]]);
        $this->flush();

        foreach ([$unmanaged, $backorders] as $product) {
            self::assertArrayNotHasKey('stock', $this->uploaded((int) $product->getId()));
            self::assertSame([], $this->legacyStock()->quantities([$this->load($product)], 1, 1));
        }
    }

    public function testAConfigurableProductHasTheStockOfItsEnabledChildren(): void
    {
        $red = $this->product(['visibility' => Visibility::VISIBILITY_NOT_VISIBLE, 'stock' => ['use_config_manage_stock' => 1, 'qty' => 3, 'is_in_stock' => 1]]);
        $blue = $this->product(['visibility' => Visibility::VISIBILITY_NOT_VISIBLE, 'stock' => ['use_config_manage_stock' => 1, 'qty' => 4, 'is_in_stock' => 1]]);
        $parent = $this->product(['stock' => ['use_config_manage_stock' => 1, 'is_in_stock' => 1]]);
        $parent->setTypeId(ConfigurableType::TYPE_CODE);
        $parent = $this->admin(fn () => $this->om->get(ProductRepositoryInterface::class)->save($parent));
        $this->om->get(Configurable::class)->saveProducts($parent, [(int) $red->getId(), (int) $blue->getId()]);

        foreach ([true, false] as $msi) {
            $stock = $msi ? $this->om->get(Stock::class) : $this->legacyStock();
            self::assertSame([(int) $parent->getId() => 7], $stock->quantities([$this->load($parent)], 1, 1));
        }

        // A child's stock change resends the parent.
        $registry = $this->om->get(StockRegistryInterface::class);
        $item = $registry->getStockItemBySku($blue->getSku());
        $item->setQty(1);
        $this->admin(fn () => $registry->updateStockItemBySku($blue->getSku(), $item));
        self::assertTrue($this->queued((int) $parent->getId()));
        $this->flush();
        self::assertSame(4, $this->uploaded((int) $parent->getId())['stock']);

        // A child with backorders can be sold below zero: no total for the parent.
        $item = $registry->getStockItemBySku($red->getSku());
        $item->setUseConfigBackorders(false)->setBackorders(1);
        $this->admin(fn () => $registry->updateStockItemBySku($red->getSku(), $item));
        $this->flush();
        self::assertArrayNotHasKey('stock', $this->uploaded((int) $parent->getId()));

        foreach ([true, false] as $msi) {
            $stock = $msi ? $this->om->get(Stock::class) : $this->legacyStock();
            self::assertSame([], $stock->quantities([$this->load($parent)], 1, 1));
        }

        // A child without tracked stock can be sold without limit either.
        $item = $registry->getStockItemBySku($red->getSku());
        $item->setUseConfigBackorders(false)->setBackorders(0)->setUseConfigManageStock(false)->setManageStock(false);
        $this->admin(fn () => $registry->updateStockItemBySku($red->getSku(), $item));
        $this->flush();
        self::assertArrayNotHasKey('stock', $this->uploaded((int) $parent->getId()));
    }

    public function testBackordersOfTheStoreSettingsLeaveTheStockOut(): void
    {
        $writer = $this->om->get(\Magento\Config\Model\ResourceModel\Config::class);
        $writer->saveConfig('cataloginventory/item_options/backorders', '1');
        $this->reinit();

        try {
            $product = $this->product(['stock' => ['use_config_manage_stock' => 1, 'use_config_backorders' => 1, 'qty' => 0, 'is_in_stock' => 1]]);
            $this->flush();

            self::assertTrue($this->uploaded((int) $product->getId())['available']);
            self::assertArrayNotHasKey('stock', $this->uploaded((int) $product->getId()), 'Zero units with backorders is not "sold out".');
            self::assertSame([], $this->legacyStock()->quantities([$this->load($product)], 1, 1));
        } finally {
            $writer->deleteConfig('cataloginventory/item_options/backorders');
            $this->reinit();
        }
    }

    private function legacyStock(): Stock
    {
        return $this->om->create(Stock::class, ['msi' => false]);
    }

    private function load(ProductInterface $product): \Magento\Catalog\Model\Product
    {
        /** @var \Magento\Catalog\Model\Product */
        return $this->om->create(\Magento\Catalog\Model\Product::class)->load($product->getId());
    }

    private function queued(int $productId): bool
    {
        $db = $this->om->get(ResourceConnection::class)->getConnection();

        return (bool) $db->fetchOne($db->select()->from($db->getTableName('onetrace_queue'), ['COUNT(*)'])->where('kind = ?', 'product')->where('item_key = ?', 'p' . $productId));
    }
}
