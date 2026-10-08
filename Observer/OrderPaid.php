<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Invoice;
use OneTrace\Connector\Model\Config;
use OneTrace\Connector\Model\Connection;
use OneTrace\Connector\Model\Mapper;
use OneTrace\Connector\Model\Queue;
use Psr\Log\LoggerInterface;

/**
 * An invoice is paid: online payments capture right away, offline ones (check, cash on delivery, bank transfer)
 * when the merchant records the payment. Several invoices of one order give one order_paid (the same messageId).
 */
class OrderPaid implements ObserverInterface
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
        $invoice = $observer->getEvent()->getData('invoice');
        $order = $invoice instanceof Invoice ? $invoice->getOrder() : null;

        if ($order === null || !$this->config->enabled('orders', (int) $order->getStoreId())) {
            return;
        }

        try {
            $model = $this->mapper->order($order);

            if ($model->customer->ids() !== []) {
                $this->queue->event((int) $order->getStoreId(), $this->connection->messages((int) $order->getStoreId())->orderPaid($model, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
            }
        } catch (\Throwable $error) {
            $this->logger->error('OneTrace: paid order ' . $order->getIncrementId() . ': ' . $error->getMessage());
        }
    }
}
