<?php

declare(strict_types=1);

namespace OneTrace\Connector\Test\Integration;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Config\Model\ResourceModel\Config as ConfigWriter;
use Magento\Framework\App\Area;
use Magento\Framework\Registry;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\App\Emulation;
use OneTrace\Connector\Block\ProductQuestions;
use OneTrace\Connector\Test\TestCase;

final class ProductQuestionsTest extends TestCase
{
    public function testShowsTheAskAiElementWithTheProductIdByDefault(): void
    {
        $product = $this->product();

        self::assertTrue($this->om->get(\OneTrace\Connector\Model\Config::class)->enabled('product_questions', 1));
        self::assertSame((string) $product->getId(), $this->page((int) $product->getId()));
    }

    public function testTheSettingAndTheTrackerTurnTheElementOff(): void
    {
        $product = $this->product();
        $writer = $this->om->get(ConfigWriter::class);

        foreach (['onetrace/parts/product_questions', 'onetrace/parts/tracker'] as $path) {
            $writer->saveConfig($path, '0');
            $this->reinit();

            try {
                self::assertNull($this->page((int) $product->getId()), $path);
            } finally {
                $writer->deleteConfig($path);
                $this->reinit();
            }
        }
    }

    public function testNothingWithoutAProduct(): void
    {
        self::assertNull($this->page(null));
    }

    /**
     * The product id of the block on the product page of store view 1, null — nothing is shown. The HTML and its
     * place in the page are checked by tests/env/smoke.sh.
     */
    private function page(?int $productId): ?string
    {
        $emulation = $this->om->get(Emulation::class);
        $registry = $this->om->get(Registry::class);
        $emulation->startEnvironmentEmulation(1, Area::AREA_FRONTEND, true);

        try {
            $registry->unregister('current_product');

            if ($productId !== null) {
                $registry->register('current_product', $this->om->get(ProductRepositoryInterface::class)->getById($productId, false, 1, true));
            }

            return $this->om->create(LayoutInterface::class)->createBlock(ProductQuestions::class, 'onetrace.product.questions')->productId();
        } finally {
            $registry->unregister('current_product');
            $emulation->stopEnvironmentEmulation();
        }
    }
}
