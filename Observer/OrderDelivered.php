<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use OneTrace\Connector\Model\Config;
use OneTrace\Connector\Model\Connection;
use OneTrace\Connector\Model\Email\StoreEmails;
use OneTrace\Connector\Model\Mapper;
use OneTrace\Connector\Model\Queue;
use Psr\Log\LoggerInterface;

/**
 * Magento has no delivery state: the order moves to one of the statuses of the "Delivered order statuses" setting
 * (empty by default). order_delivered once per order, remembered in sales_order.onetrace_delivered_at; with the
 * OneTrace template of the order_delivered email on, also the email (Magento has none).
 */
class OrderDelivered implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly Mapper $mapper,
        private readonly Queue $queue,
        private readonly LoggerInterface $logger,
        private readonly StoreEmails $emails,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');

        if (!$order instanceof Order || !$order->getId() || $order->getData('onetrace_delivered_at') || !$order->dataHasChangedFor('status')) {
            return;
        }

        $storeId = (int) $order->getStoreId();

        if (!in_array((string) $order->getStatus(), $this->config->deliveredStatuses($storeId), true)) {
            return;
        }

        try {
            $order->setData('onetrace_delivered_at', gmdate('Y-m-d H:i:s'));
            $order->getResource()->saveAttribute($order, 'onetrace_delivered_at');
            // The email after the order is saved: no request to the platform while the order row is locked.
            $order->getResource()->addCommitCallback(function () use ($order): void {
                try {
                    $this->emails->sendOrderEmail($order, 'order_delivered');
                } catch (\Throwable $error) {
                    $this->logger->error('OneTrace: delivered order email ' . $order->getIncrementId() . ': ' . $error->getMessage());
                }
            });

            if (!$this->config->enabled('orders', $storeId)) {
                return;
            }

            $model = $this->mapper->order($order);

            if ($model->customer->ids() !== []) {
                $this->queue->event($storeId, $this->connection->messages($storeId)->orderDelivered($model, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
            }
        } catch (\Throwable $error) {
            $this->logger->error('OneTrace: delivered order ' . $order->getIncrementId() . ': ' . $error->getMessage());
        }
    }
}
