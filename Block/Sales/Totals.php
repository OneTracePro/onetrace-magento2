<?php

declare(strict_types=1);

namespace OneTrace\Connector\Block\Sales;

use Magento\Framework\DataObject;
use Magento\Framework\View\Element\AbstractBlock;

/**
 * The bonus points row in the totals of the order, invoice and credit memo pages and emails (the parent totals block
 * calls initTotals() of its children).
 */
class Totals extends AbstractBlock
{
    public function initTotals(): self
    {
        $parent = $this->getParentBlock();
        $source = $parent && method_exists($parent, 'getSource') ? $parent->getSource() : null;
        $amount = $source instanceof DataObject ? (float) $source->getData('onetrace_loyalty_discount') : 0.0;

        if ($amount > 0 && method_exists($parent, 'addTotal')) {
            $order = method_exists($parent, 'getOrder') ? $parent->getOrder() : null;
            $points = $order instanceof DataObject ? (int) $order->getData('onetrace_loyalty_points') : 0;
            $parent->addTotal(new DataObject([
                'code' => 'onetrace_loyalty',
                'label' => $points > 0 ? __('Bonus points (%1)', $points) : __('Bonus points'),
                'value' => -$amount,
                'base_value' => -(float) $source->getData('base_onetrace_loyalty_discount'),
            ]), 'discount');
        }

        return $this;
    }
}
