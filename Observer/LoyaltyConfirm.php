<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use OneTrace\Connector\Model\Loyalty;

/**
 * The order with bonus points is placed: the redemption is confirmed with the order number right away; if the
 * platform does not answer, the onetrace_loyalty_confirm cron job retries while the hold is alive.
 */
class LoyaltyConfirm implements ObserverInterface
{
    public function __construct(private readonly Loyalty $loyalty)
    {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');

        if ($order instanceof Order && (string) $order->getData('onetrace_loyalty_redemption') !== '') {
            $this->loyalty->confirm($order);
        }
    }
}
