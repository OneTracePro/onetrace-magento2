<?php

declare(strict_types=1);

namespace OneTrace\Connector\Plugin;

use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use OneTrace\Connector\Model\Loyalty;

/**
 * Before the order is placed (checkout, REST, GraphQL): the hold of the bonus points must be alive and match the
 * cart — see Loyalty::prepare().
 */
class PrepareLoyalty
{
    public function __construct(
        private readonly CartRepositoryInterface $quotes,
        private readonly Loyalty $loyalty,
    ) {
    }

    /**
     * @param int|string $cartId
     * @param mixed $paymentMethod
     * @return array{0: int|string, 1: mixed}
     */
    public function beforePlaceOrder(CartManagementInterface $subject, $cartId, $paymentMethod = null): array
    {
        $quote = $this->quotes->getActive((int) $cartId);

        if ($quote instanceof Quote && (int) $quote->getData('onetrace_loyalty_points') > 0) {
            $this->loyalty->prepare($quote);
        }

        return [$cartId, $paymentMethod];
    }
}
