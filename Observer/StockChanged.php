<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use OneTrace\Connector\Model\Catalog;
use OneTrace\Connector\Model\Queue;
use Psr\Log\LoggerInterface;

/**
 * Stock and salability of products change: an order is placed or cancelled, a credit memo is refunded, a legacy
 * stock item is saved. The products and their configurable parents go to the catalog queue, which reads the stock
 * when it sends them (after Magento has applied the change).
 */
class StockChanged implements ObserverInterface
{
    public function __construct(private readonly Catalog $catalog, private readonly Queue $queue, private readonly LoggerInterface $logger)
    {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $ids = [];

        foreach (['order', 'creditmemo'] as $key) {
            $entity = $event->getData($key);

            if (is_object($entity) && method_exists($entity, 'getAllItems')) {
                foreach ($entity->getAllItems() as $item) {
                    $ids[] = (int) $item->getProductId();
                }
            }
        }

        $stockItem = $event->getData('item');

        if ($stockItem instanceof \Magento\CatalogInventory\Api\Data\StockItemInterface) {
            $ids[] = (int) $stockItem->getProductId();
        }

        try {
            foreach ($this->catalog->withParents(array_filter($ids)) as $id) {
                $this->queue->product($id);
            }
        } catch (\Throwable $error) {
            $this->logger->error('OneTrace: stock change: ' . $error->getMessage());
        }
    }
}
