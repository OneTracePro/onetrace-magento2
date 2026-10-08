<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use OneTrace\Connector\Model\Config;
use OneTrace\Connector\Model\Connection;
use OneTrace\Connector\Model\Mapper;
use OneTrace\Connector\Model\Queue;
use Psr\Log\LoggerInterface;

class OrderCancelled implements ObserverInterface
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
        $order = $observer->getEvent()->getData('order');

        if (!$order instanceof Order || !$this->config->enabled('orders', (int) $order->getStoreId())) {
            return;
        }

        try {
            $model = $this->mapper->order($order);

            if ($model->customer->ids() !== []) {
                $this->queue->event((int) $order->getStoreId(), $this->connection->messages((int) $order->getStoreId())->orderCancelled($model, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
            }
        } catch (\Throwable $error) {
            $this->logger->error('OneTrace: cancelled order ' . $order->getIncrementId() . ': ' . $error->getMessage());
        }
    }
}
