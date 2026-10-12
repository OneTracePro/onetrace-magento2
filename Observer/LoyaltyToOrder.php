<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;

/**
 * The held bonus points go from the cart to the order (the order total already has the discount).
 */
class LoyaltyToOrder implements ObserverInterface
{
    private const FIELDS = ['onetrace_loyalty_points', 'onetrace_loyalty_discount', 'base_onetrace_loyalty_discount', 'onetrace_loyalty_redemption'];

    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getData('quote');
        $order = $observer->getEvent()->getData('order');

        if (!$quote instanceof Quote || !$order instanceof Order || (int) $quote->getData('onetrace_loyalty_points') <= 0) {
            return;
        }

        foreach (self::FIELDS as $field) {
            $order->setData($field, $quote->getData($field));
        }
    }
}
