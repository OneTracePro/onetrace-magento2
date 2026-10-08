<?php

declare(strict_types=1);

namespace OneTrace\Connector\Block;

use Magento\Catalog\Block\Product\View\AbstractView;
use Magento\Catalog\Model\Product;

/**
 * product_viewed on the product page: in the browser, so it is counted with the page cache too.
 */
class ProductView extends \Magento\Framework\View\Element\Template
{
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        private readonly \Magento\Framework\Registry $registry,
        private readonly \OneTrace\Connector\Model\Catalog $catalog,
        private readonly \OneTrace\Connector\Model\Config $config,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function properties(): ?array
    {
        $product = $this->registry->registry('current_product');
        $storeId = (int) $this->_storeManager->getStore()->getId();

        if (!$product instanceof Product || !$this->config->storefront($storeId) || !$this->config->enabled('tracker', $storeId)) {
            return null;
        }

        return array_filter([
            'product_id' => (string) $product->getId(),
            'product_name' => (string) $product->getName(),
            'price' => (float) $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue(),
            'currency' => (string) $this->_storeManager->getStore()->getCurrentCurrencyCode(),
            'category_id' => $this->catalog->categoryId($product),
        ], static fn ($value): bool => $value !== null);
    }
}
