<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model\Total\Creditmemo;

use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Total\AbstractTotal;

/**
 * Bonus points of the order in the credit memo: in proportion to the refunded goods, at most what is not refunded
 * yet. The platform returns the redeemed points itself by the order_refunded event.
 */
class Loyalty extends AbstractTotal
{
    public function collect(Creditmemo $creditmemo): self
    {
        $order = $creditmemo->getOrder();
        $total = (float) $order->getData('onetrace_loyalty_discount');
        $baseTotal = (float) $order->getData('base_onetrace_loyalty_discount');
        $subtotal = (float) $order->getSubtotal();

        if ($total <= 0 || $subtotal <= 0) {
            return $this;
        }

        $refunded = 0.0;
        $baseRefunded = 0.0;

        foreach ($order->getCreditmemosCollection() ?: [] as $previous) {
            if ((int) $previous->getId() !== (int) $creditmemo->getId() && (int) $previous->getState() !== Creditmemo::STATE_CANCELED) {
                $refunded += (float) $previous->getData('onetrace_loyalty_discount');
                $baseRefunded += (float) $previous->getData('base_onetrace_loyalty_discount');
            }
        }

        $share = min(1.0, (float) $creditmemo->getSubtotal() / $subtotal);
        $amount = max(0.0, min($total - $refunded, round($total * $share, 2), (float) $creditmemo->getGrandTotal()));
        $baseAmount = max(0.0, min($baseTotal - $baseRefunded, round($baseTotal * $share, 2), (float) $creditmemo->getBaseGrandTotal()));

        $creditmemo->setData('onetrace_loyalty_discount', $amount);
        $creditmemo->setData('base_onetrace_loyalty_discount', $baseAmount);
        $creditmemo->setGrandTotal((float) $creditmemo->getGrandTotal() - $amount);
        $creditmemo->setBaseGrandTotal((float) $creditmemo->getBaseGrandTotal() - $baseAmount);

        return $this;
    }
}
