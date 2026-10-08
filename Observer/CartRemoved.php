<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote\Item;
use OneTrace\Connector\Model\Cart;
use OneTrace\Connector\Model\Connection;
use OneTrace\Connector\Model\Queue;
use Psr\Log\LoggerInterface;

class CartRemoved implements ObserverInterface
{
    public function __construct(
        private readonly Cart $cart,
        private readonly Connection $connection,
        private readonly Queue $queue,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $item = $observer->getEvent()->getData('quote_item');

        // Children of configurable items are removed together with their parent: one event per line.
        if (!$item instanceof Item || $item->getParentItemId()) {
            return;
        }

        try {
            $storeId = (int) $item->getStoreId();
            $customer = $this->cart->customer($storeId);

            if ($customer !== null) {
                $this->queue->event($storeId, $this->connection->messages($storeId)->product('remove_from_cart', $customer, $this->cart->line($item), (string) $item->getQuote()->getQuoteCurrencyCode()));
            }
        } catch (\Throwable $error) {
            $this->logger->error('OneTrace: remove from cart: ' . $error->getMessage());
        }
    }
}
