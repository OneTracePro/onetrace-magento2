<?php

declare(strict_types=1);

namespace OneTrace\Connector\Block;

use Magento\Catalog\Model\Product;

/**
 * "Notify when available" on the page of a product that cannot be bought: <div data-cdp-notify="{product id}">,
 * the tracker draws the platform's form of that kind in it (Site → Forms), if one is active in the project.
 */
class Notify extends \Magento\Framework\View\Element\Template
{
    /** @var string */
    protected $_template = 'OneTrace_Connector::notify.phtml';

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        private readonly \Magento\Framework\Registry $registry,
        private readonly \OneTrace\Connector\Model\Config $config,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The product id of the catalog and the events, or null when the form is not shown.
     */
    public function productId(): ?string
    {
        $product = $this->registry->registry('current_product');
        $storeId = (int) $this->_storeManager->getStore()->getId();

        if (!$product instanceof Product || !$product->getId() || $product->isSalable()
            || !$this->config->storefront($storeId) || !$this->config->enabled('tracker', $storeId) || !$this->config->enabled('notify', $storeId)) {
            return null;
        }

        return (string) $product->getId();
    }
}
