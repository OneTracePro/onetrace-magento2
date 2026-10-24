<?php

declare(strict_types=1);

namespace OneTrace\Connector\Block;

use Magento\Catalog\Model\Product;

/**
 * "Ask AI about this product" on the product page: <div data-cdp-ask data-product-id="{product id}">, the tracker
 * draws the product questions widget of the platform in it (Site → Product questions) when the widget is on there;
 * otherwise the element stays empty. The AI answers from the catalog: the description, attributes and the
 * knowledge base of the shop.
 */
class ProductQuestions extends \Magento\Framework\View\Element\Template
{
    /** @var string */
    protected $_template = 'OneTrace_Connector::product_questions.phtml';

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        private readonly \Magento\Framework\Registry $registry,
        private readonly \OneTrace\Connector\Model\Config $config,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The product id of the catalog and the events (a configurable product's page is the parent's), or null when
     * the block is not shown.
     */
    public function productId(): ?string
    {
        $product = $this->registry->registry('current_product');
        $storeId = (int) $this->_storeManager->getStore()->getId();

        if (!$product instanceof Product || !$product->getId()
            || !$this->config->storefront($storeId) || !$this->config->enabled('tracker', $storeId) || !$this->config->enabled('product_questions', $storeId)) {
            return null;
        }

        return (string) $product->getId();
    }
}
