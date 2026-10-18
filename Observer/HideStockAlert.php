<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\LayoutInterface;
use OneTrace\Connector\Block\Notify;

/**
 * Where the "Notify when available" form of the platform is shown, Magento's own "Notify me when this product is in
 * stock" link (Magento_ProductAlert) is removed from the product page: one subscription form, not two.
 */
class HideStockAlert implements ObserverInterface
{
    public function execute(Observer $observer): void
    {
        $layout = $observer->getEvent()->getData('layout');

        if ($observer->getEvent()->getData('full_action_name') !== 'catalog_product_view' || !$layout instanceof LayoutInterface) {
            return;
        }

        $notify = $layout->getBlock('onetrace.notify');

        if ($notify instanceof Notify && $notify->productId() !== null && $layout->getBlock('productalert.stock')) {
            $layout->unsetElement('productalert.stock');
        }
    }
}
