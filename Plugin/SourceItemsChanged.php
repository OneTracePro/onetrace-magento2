<?php

declare(strict_types=1);

namespace OneTrace\Connector\Plugin;

use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use OneTrace\Connector\Model\Catalog;
use OneTrace\Connector\Model\Queue;
use Psr\Log\LoggerInterface;

/**
 * Inventory (MSI): saved or deleted source items (the product form, the REST API, imports through the service)
 * change the salable quantity — their products and configurable parents go to the catalog queue. Declared for
 * SourceItemsSaveInterface and SourceItemsDeleteInterface; the subject is not typed, MSI may be removed.
 */
class SourceItemsChanged
{
    public function __construct(
        private readonly ProductResource $productResource,
        private readonly Catalog $catalog,
        private readonly Queue $queue,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param object $subject
     * @param mixed $result
     * @param array<object> $sourceItems
     *
     * @return mixed
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterExecute($subject, $result, array $sourceItems = [])
    {
        try {
            $skus = [];

            foreach ($sourceItems as $sourceItem) {
                if (method_exists($sourceItem, 'getSku') && (string) $sourceItem->getSku() !== '') {
                    $skus[] = (string) $sourceItem->getSku();
                }
            }

            if ($skus !== []) {
                foreach ($this->catalog->withParents(array_map('intval', $this->productResource->getProductsIdsBySkus(array_unique($skus)))) as $id) {
                    $this->queue->product($id);
                }
            }
        } catch (\Throwable $error) {
            $this->logger->error('OneTrace: source items: ' . $error->getMessage());
        }

        return $result;
    }
}
