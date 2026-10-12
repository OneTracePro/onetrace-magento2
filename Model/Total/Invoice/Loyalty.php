<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model\Total\Invoice;

use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Invoice\Total\AbstractTotal;

/**
 * Bonus points of the order in the invoice: what is not invoiced yet, at most the invoice total.
 */
class Loyalty extends AbstractTotal
{
    public function collect(Invoice $invoice): self
    {
        $order = $invoice->getOrder();
        $total = (float) $order->getData('onetrace_loyalty_discount');
        $baseTotal = (float) $order->getData('base_onetrace_loyalty_discount');

        if ($total <= 0) {
            return $this;
        }

        $invoiced = 0.0;
        $baseInvoiced = 0.0;

        foreach ($order->getInvoiceCollection() as $previous) {
            if ((int) $previous->getId() !== (int) $invoice->getId() && (int) $previous->getState() !== Invoice::STATE_CANCELED) {
                $invoiced += (float) $previous->getData('onetrace_loyalty_discount');
                $baseInvoiced += (float) $previous->getData('base_onetrace_loyalty_discount');
            }
        }

        $amount = max(0.0, min($total - $invoiced, (float) $invoice->getGrandTotal()));
        $baseAmount = max(0.0, min($baseTotal - $baseInvoiced, (float) $invoice->getBaseGrandTotal()));

        $invoice->setData('onetrace_loyalty_discount', $amount);
        $invoice->setData('base_onetrace_loyalty_discount', $baseAmount);
        $invoice->setGrandTotal((float) $invoice->getGrandTotal() - $amount);
        $invoice->setBaseGrandTotal((float) $invoice->getBaseGrandTotal() - $baseAmount);

        return $this;
    }
}
