<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Quote\Item;
use OneTrace\Connector\Model\Cart;
use OneTrace\Connector\Model\Connection;
use OneTrace\Connector\Model\Queue;
use Psr\Log\LoggerInterface;

class CartAdded implements ObserverInterface
{
    public function __construct(
        private readonly Cart $cart,
        private readonly Connection $connection,
        private readonly Queue $queue,
        private readonly UrlInterface $url,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $item = $observer->getEvent()->getData('quote_item');

        if (!$item instanceof Item) {
            return;
        }

        try {
            $storeId = (int) $item->getStoreId();
            $customer = $this->cart->customer($storeId);

            if ($customer !== null) {
                $quote = $item->getQuote();
                $this->queue->event($storeId, $this->connection->messages($storeId)->product(
                    'add_to_cart',
                    $customer,
                    $this->cart->line($item, (int) ($item->getQtyToAdd() ?: $item->getQty())),
                    (string) $quote->getQuoteCurrencyCode(),
                    $this->url->getUrl('checkout/cart')
                ));
            }
        } catch (\Throwable $error) {
            $this->logger->error('OneTrace: add to cart: ' . $error->getMessage());
        }
    }
}
