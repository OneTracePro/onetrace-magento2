<?php

declare(strict_types=1);

namespace OneTrace\Connector\Cron;

use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use OneTrace\Connector\Model\Loyalty;

/**
 * Retries the confirmation of bonus points of recent orders (the hold lives 30 minutes on the platform).
 */
class LoyaltyConfirm
{
    public function __construct(
        private readonly CollectionFactory $orders,
        private readonly Loyalty $loyalty,
    ) {
    }

    public function execute(): void
    {
        $orders = $this->orders->create()
            ->addFieldToFilter('onetrace_loyalty_redemption', ['notnull' => true])
            ->addFieldToFilter('onetrace_loyalty_confirmed_at', ['null' => true])
            ->addFieldToFilter('created_at', ['gteq' => gmdate('Y-m-d H:i:s', time() - 2400)])
            ->setPageSize(100);

        foreach ($orders as $order) {
            $this->loyalty->confirm($order);
        }
    }
}
