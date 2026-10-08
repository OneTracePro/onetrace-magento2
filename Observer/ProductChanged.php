<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use OneTrace\Connector\Model\Catalog;
use OneTrace\Connector\Model\Queue;

/**
 * A changed product goes to the catalog queue; a configurable child uploads its parent.
 */
class ProductChanged implements ObserverInterface
{
    public function __construct(private readonly Catalog $catalog, private readonly Queue $queue)
    {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getData('product');

        if ($product instanceof Product && $product->getId()) {
            foreach ($this->catalog->catalogIds((int) $product->getId(), (int) $product->getVisibility()) as $id) {
                $this->queue->product($id);
            }
        }
    }
}
