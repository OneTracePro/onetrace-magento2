<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\UrlInterface;
use OneTrace\Connector\Model\Cart;
use OneTrace\Connector\Model\Connection;
use OneTrace\Connector\Model\Queue;
use Psr\Log\LoggerInterface;

/**
 * The checkout page is opened: once per cart contents in the session.
 */
class CheckoutStarted implements ObserverInterface
{
    private const SESSION_KEY = 'onetrace_checkout_hash';

    public function __construct(
        private readonly Cart $cart,
        private readonly Connection $connection,
        private readonly Queue $queue,
        private readonly CheckoutSession $session,
        private readonly UrlInterface $url,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $quote = $this->session->getQuote();
            $storeId = (int) $quote->getStoreId();
            $customer = $this->cart->customer($storeId);

            if ($customer === null || !$quote->getId() || !$quote->hasItems()) {
                return;
            }

            $lines = [];
            $hash = [];

            foreach ($quote->getAllVisibleItems() as $item) {
                $lines[] = $this->cart->line($item);
                $hash[] = $item->getProductId() . 'x' . $item->getQty();
            }

            $hash = sha1(implode(',', $hash) . '|' . $quote->getGrandTotal());

            if ($this->session->getData(self::SESSION_KEY) === $hash) {
                return;
            }

            $this->session->setData(self::SESSION_KEY, $hash);
            $this->queue->event($storeId, $this->connection->messages($storeId)->checkoutStarted($customer, $lines, (float) $quote->getGrandTotal(), (string) $quote->getQuoteCurrencyCode(), $this->url->getUrl('checkout/cart')));
        } catch (\Throwable $error) {
            $this->logger->error('OneTrace: checkout started: ' . $error->getMessage());
        }
    }
}
