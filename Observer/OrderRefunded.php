<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Creditmemo;
use OneTrace\Connector\Model\Config;
use OneTrace\Connector\Model\Connection;
use OneTrace\Connector\Model\Mapper;
use OneTrace\Connector\Model\Queue;
use Psr\Log\LoggerInterface;

/**
 * A credit memo is refunded: order_refunded with its amount; partial refunds are told apart by the credit memo id.
 */
class OrderRefunded implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly Mapper $mapper,
        private readonly Queue $queue,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $creditmemo = $observer->getEvent()->getData('creditmemo');
        $order = $creditmemo instanceof Creditmemo ? $creditmemo->getOrder() : null;

        if ($order === null || !$this->config->enabled('orders', (int) $order->getStoreId())) {
            return;
        }

        try {
            $model = $this->mapper->order($order);

            if ($model->customer->ids() === []) {
                return;
            }

            // The credit memo is saved after this event: its increment id is already set, the entity id may not be.
            $id = (string) ($creditmemo->getIncrementId() ?: $creditmemo->getId() ?: spl_object_id($creditmemo));
            $this->queue->event((int) $order->getStoreId(), $this->connection->messages((int) $order->getStoreId())->orderRefunded(
                $model,
                (float) $creditmemo->getGrandTotal(),
                $id,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                $this->mapper->refundLines($creditmemo)
            ));
        } catch (\Throwable $error) {
            $this->logger->error('OneTrace: refunded order ' . $order->getIncrementId() . ': ' . $error->getMessage());
        }
    }
}
