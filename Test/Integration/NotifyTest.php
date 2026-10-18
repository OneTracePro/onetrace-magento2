<?php

declare(strict_types=1);

namespace OneTrace\Connector\Test\Integration;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Event\Observer;
use Magento\Framework\Registry;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\App\Emulation;
use OneTrace\Connector\Block\Notify;
use OneTrace\Connector\Observer\HideStockAlert;
use OneTrace\Connector\Test\TestCase;

final class NotifyTest extends TestCase
{
    public function testShowsTheFormOnThePageOfAProductThatCannotBeBought(): void
    {
        $soldOut = $this->product(['stock' => ['use_config_manage_stock' => 1, 'qty' => 0, 'is_in_stock' => 0]]);
        $inStock = $this->product();

        self::assertSame((string) $soldOut->getId(), $this->page((int) $soldOut->getId())[0]);
        self::assertNull($this->page((int) $inStock->getId())[0]);
    }

    public function testHidesMagentosStockAlertLinkOnlyWhereTheFormIsShown(): void
    {
        $soldOut = $this->product(['stock' => ['use_config_manage_stock' => 1, 'qty' => 0, 'is_in_stock' => 0]]);

        self::assertFalse($this->page((int) $soldOut->getId())[1], 'The stock alert block of Magento_ProductAlert is removed.');

        $writer = $this->om->get(\Magento\Config\Model\ResourceModel\Config::class);
        $writer->saveConfig('onetrace/parts/notify', '0');
        $this->reinit();

        try {
            [$productId, $alert] = $this->page((int) $soldOut->getId());
            self::assertNull($productId, 'The setting turns the form off.');
            self::assertTrue($alert, 'Magento\'s link stays without the form.');
        } finally {
            $writer->deleteConfig('onetrace/parts/notify');
            $this->reinit();
        }
    }

    public function testTheFormIsOnByDefault(): void
    {
        self::assertTrue($this->om->get(\OneTrace\Connector\Model\Config::class)->enabled('notify', 1));
    }

    /**
     * The product page of store view 1: the product id of the form block and whether the stock alert block is
     * left. The HTML of the page is checked by tests/env/smoke.sh.
     *
     * @return array{0: ?string, 1: bool}
     */
    private function page(int $productId): array
    {
        $emulation = $this->om->get(Emulation::class);
        $registry = $this->om->get(Registry::class);
        $emulation->startEnvironmentEmulation(1, Area::AREA_FRONTEND, true);

        try {
            $registry->unregister('current_product');
            $registry->register('current_product', $this->om->get(ProductRepositoryInterface::class)->getById($productId, false, 1, true));
            $layout = $this->om->create(LayoutInterface::class);
            $notify = $layout->createBlock(Notify::class, 'onetrace.notify');
            $layout->createBlock(\Magento\Framework\View\Element\Text::class, 'productalert.stock');
            $this->om->get(HideStockAlert::class)->execute(new Observer(['event' => new \Magento\Framework\Event(['layout' => $layout, 'full_action_name' => 'catalog_product_view'])]));

            return [$notify->productId(), (bool) $layout->getBlock('productalert.stock')];
        } finally {
            $registry->unregister('current_product');
            $emulation->stopEnvironmentEmulation();
        }
    }
}
