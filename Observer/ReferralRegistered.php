<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use OneTrace\Connector\Model\Config;
use OneTrace\Connector\Model\Referrals;

/**
 * customer_register_success: the friend's code of the registration waits for cron (after the identify of the new
 * customer leaves the queue).
 */
class ReferralRegistered implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Referrals $referrals,
        private readonly RequestInterface $request,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $customer = $observer->getEvent()->getData('customer');

        if (!\is_object($customer) || !method_exists($customer, 'getId') || !method_exists($customer, 'getStoreId')) {
            return;
        }

        $storeId = (int) $customer->getStoreId();

        if (!$this->config->server($storeId) || !$this->config->enabled('loyalty', $storeId)) {
            return;
        }

        $typed = $this->request->getParam(Referrals::FIELD);
        $code = $this->referrals->code(\is_string($typed) ? $typed : null);

        if ($code !== null) {
            $this->referrals->add($storeId, (int) $customer->getId(), $code);
        }
    }
}
