<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use OneTrace\Connector\Model\Queue;

class ProductDeleted implements ObserverInterface
{
    public function __construct(private readonly Queue $queue)
    {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getData('product');

        if ($product instanceof Product && $product->getId()) {
            $this->queue->delete((int) $product->getId());
        }
    }
}
