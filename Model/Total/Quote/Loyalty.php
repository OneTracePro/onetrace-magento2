<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model\Total\Quote;

use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;

/**
 * Bonus points of the cart (OneTrace loyalty): the held discount, at most the allowed share of the current cart and
 * the goods amount — a smaller cart is held again before the order (Loyalty::prepare()).
 */
class Loyalty extends AbstractTotal
{
    public const CODE = 'onetrace_loyalty';

    public function __construct()
    {
        $this->setCode(self::CODE);
    }

    public function collect(Quote $quote, ShippingAssignmentInterface $shippingAssignment, Total $total): self
    {
        parent::collect($quote, $shippingAssignment, $total);
        $amount = (float) $quote->getData('onetrace_loyalty_discount');

        if ($amount <= 0 || count($shippingAssignment->getItems()) === 0) {
            return $this;
        }

        $basis = max(0.0, (float) $total->getSubtotalInclTax() + (float) $total->getDiscountAmount());
        $ratio = (float) $quote->getData('onetrace_loyalty_ratio');
        $discount = min($amount, $basis, $ratio > 0 ? round($basis * $ratio, 2) : $amount);
        $rate = (float) $quote->getBaseToQuoteRate() ?: 1.0;

        $total->setTotalAmount(self::CODE, -$discount);
        $total->setBaseTotalAmount(self::CODE, -round($discount / $rate, 4));

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetch(Quote $quote, Total $total): array
    {
        // Reading the totals (cart, REST, GraphQL) builds the total from the address data: the amount is in "{code}_amount".
        $amount = (float) ($total->getTotalAmount(self::CODE) ?: $total->getData(self::CODE . '_amount'));

        if ($amount === 0.0) {
            return [];
        }

        return ['code' => self::CODE, 'title' => __('Bonus points (%1)', (int) $quote->getData('onetrace_loyalty_points')), 'value' => $amount];
    }

    public function getLabel(): \Magento\Framework\Phrase
    {
        return __('Bonus points');
    }
}
