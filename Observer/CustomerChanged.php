<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Address;
use Magento\Customer\Model\Customer as CustomerModel;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use OneTrace\Connector\Model\Config;
use OneTrace\Connector\Model\Connection;
use OneTrace\Connector\Model\Mapper;
use OneTrace\Connector\Model\Queue;
use Psr\Log\LoggerInterface;

/**
 * identify on registration, login, account and address changes.
 */
class CustomerChanged implements ObserverInterface
{
    /** @var array<int, true> customers already identified in this request */
    private array $sent = [];

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
        $event = $observer->getEvent();
        $customer = $event->getData('customer_data_object') ?? $event->getData('customer');
        $address = $event->getData('customer_address');

        if ($customer === null && $address instanceof Address) {
            $customer = (int) $address->getCustomerId() ?: null;
        }

        $customerId = match (true) {
            $customer instanceof CustomerInterface, $customer instanceof CustomerModel => (int) $customer->getId(),
            is_int($customer) => $customer,
            default => 0,
        };

        if ($customerId === 0 || isset($this->sent[$customerId])) {
            return;
        }

        try {
            $data = $this->mapper->customer($customerId);
            $storeId = $customer instanceof CustomerInterface || $customer instanceof CustomerModel ? (int) $customer->getStoreId() : 0;

            if ($this->config->server($storeId)) {
                $this->sent[$customerId] = true;
                $this->queue->event($storeId, $this->connection->messages($storeId)->identify($data));
            }
        } catch (\Throwable $error) {
            $this->logger->error('OneTrace: customer ' . $customerId . ': ' . $error->getMessage());
        }
    }
}
