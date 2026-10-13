<?php

declare(strict_types=1);

namespace OneTrace\Connector\Block\Loyalty;

use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\View\Element\Template;
use OneTrace\Connector\Model\Config;
use OneTrace\Connector\Model\Referrals;

/**
 * The optional "Friend's code" field of the registration form, prefilled with the code of a referral link.
 */
class ReferralField extends Template
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Template\Context $context,
        private readonly Config $config,
        private readonly CookieManagerInterface $cookies,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function shown(): bool
    {
        $storeId = (int) $this->_storeManager->getStore()->getId();

        return $this->config->server($storeId) && $this->config->enabled('loyalty', $storeId) && $this->config->enabled('referral_field', $storeId);
    }

    public function name(): string
    {
        return Referrals::FIELD;
    }

    public function value(): string
    {
        $code = strtoupper(trim((string) $this->cookies->getCookie('cdp_ref')));

        return preg_match('/^[A-Z0-9]{4,16}$/', $code) === 1 ? $code : '';
    }

    protected function _toHtml(): string
    {
        return $this->shown() ? parent::_toHtml() : '';
    }
}
