<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Sales\Model\Order;
use OneTrace\Commerce\Messages;
use OneTrace\Connector\Model\Config;
use OneTrace\Connector\Model\Connection;
use OneTrace\Connector\Model\Mapper;
use OneTrace\Connector\Model\Queue;
use OneTrace\Connector\Model\StoreMcp\AssistantOrders;
use Psr\Log\LoggerInterface;

/**
 * The order is placed (any checkout, REST, GraphQL or admin): identify with the order data and order_completed; an
 * order of a shopper's AI assistant (store MCP, contract v2) carries the assistant in properties.ai_assistant.
 */
class OrderPlaced implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly Mapper $mapper,
        private readonly Queue $queue,
        private readonly SubscriberFactory $subscribers,
        private readonly AssistantOrders $assistantOrders,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');

        if (!$order instanceof Order || !$this->config->enabled('orders', (int) $order->getStoreId())) {
            return;
        }

        // The checkout must never fail because of OneTrace.
        try {
            $storeId = (int) $order->getStoreId();
            $anonymousId = $this->connection->anonymousId();

            if ($anonymousId !== null) {
                $this->mapper->rememberVisitor((int) $order->getId(), $anonymousId);
            }

            $model = $this->mapper->order($order);

            if ($model->customer->ids() === []) {
                return;
            }

            $messages = $this->connection->messages($storeId);
            $this->queue->event($storeId, $messages->identify($model->customer, $this->consents($order), $model->placedAt));
            $event = $messages->orderCompleted($model);
            $origin = $this->assistantOrders->originOfQuote((int) $order->getQuoteId());

            if ($origin !== null && $origin['assistant'] !== null) {
                $event['properties']['ai_assistant'] = $origin['assistant'];
            }

            $this->queue->event($storeId, $event);
        } catch (\Throwable $error) {
            $this->logger->error('OneTrace: order ' . $order->getIncrementId() . ': ' . $error->getMessage());
        }
    }

    /**
     * A guest who subscribed to the newsletter at checkout (an extension of the checkout) is already a subscriber.
     *
     * @return list<array{channel: string, status: string, topic?: string}>
     */
    private function consents(Order $order): array
    {
        if (!$this->config->enabled('newsletter', (int) $order->getStoreId()) || (string) $order->getCustomerEmail() === '') {
            return [];
        }

        $subscriber = $this->subscribers->create()->loadBySubscriberEmail((string) $order->getCustomerEmail(), (int) $order->getStore()->getWebsiteId());

        return $subscriber->isSubscribed() ? [Messages::subscribed('email', $this->config->consentTopic((int) $order->getStoreId()))] : [];
    }
}
