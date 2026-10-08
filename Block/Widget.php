<?php

declare(strict_types=1);

namespace OneTrace\Connector\Block;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Widget\Block\BlockInterface;
use OneTrace\Connector\Model\Config;

/**
 * A recommendation widget from the "Site → Widgets" section of the platform: a CMS widget, or the product page and
 * cart widgets from the settings. The current product and the cart products are passed to it.
 */
class Widget extends Template implements BlockInterface
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly Registry $registry,
        private readonly CheckoutSession $checkoutSession,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    protected function _toHtml(): string
    {
        $storeId = (int) $this->_storeManager->getStore()->getId();
        $uid = (string) ($this->getData('widget_id') ?: ($this->getData('place') ? $this->config->widget((string) $this->getData('place'), $storeId) : ''));

        if (preg_match('/^[a-z0-9]{1,16}$/', $uid) !== 1 || !$this->config->storefront($storeId)) {
            return '';
        }

        $items = [];
        $exclude = [];
        $product = $this->registry->registry('current_product');
        $category = $this->registry->registry('current_category');

        if ($product instanceof \Magento\Catalog\Model\Product) {
            $items[] = (string) $product->getId();
        }

        if ($this->getData('place') === 'cart') {
            foreach ($this->checkoutSession->getQuote()->getAllVisibleItems() as $item) {
                $exclude[] = (string) $item->getProductId();
            }

            $items = $items ?: $exclude;
        }

        return sprintf(
            '<div class="onetrace-widget" data-cdp-widget="%s"%s%s%s></div>',
            $this->escapeHtmlAttr($uid),
            $items !== [] ? ' data-item="' . $this->escapeHtmlAttr(implode(',', array_unique($items))) . '"' : '',
            $category instanceof \Magento\Catalog\Model\Category && !$product ? ' data-category="' . $this->escapeHtmlAttr((string) $category->getId()) . '"' : '',
            $exclude !== [] ? ' data-exclude="' . $this->escapeHtmlAttr(implode(',', array_unique($exclude))) . '"' : ''
        );
    }
}
