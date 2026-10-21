<?php

declare(strict_types=1);

namespace OneTrace\Connector\Block;

use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use OneTrace\Connector\Model\Config;

/**
 * The tracker in <head>: the same for every visitor, so it is cached with the page; the signed-in customer comes
 * from the private "onetrace" section (customer-data).
 */
class Tracker extends Template
{
    /** Methods the stub queues until cdp.js loads: the same list as in the platform's install code. */
    private const STUB_METHODS = ['init', 'page', 'track', 'identify', 'alias', 'reset', 'setLanguage', 'widgets', 'renderRecommendations', 'searchBoxes', 'chatCustomer'];

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly CookieHelper $cookieHelper,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function enabled(): bool
    {
        $storeId = (int) $this->_storeManager->getStore()->getId();

        return $this->config->storefront($storeId) && $this->config->enabled('tracker', $storeId);
    }

    /**
     * Suggestions of the tracker in the store search box ("Product search" setting).
     */
    public function searchEnabled(): bool
    {
        $storeId = (int) $this->_storeManager->getStore()->getId();

        return $this->enabled() && $this->config->enabled('search', $storeId);
    }

    /** Category link of the suggestions: {id} is replaced with the category id of the catalog. */
    public function categoryUrl(): string
    {
        return str_replace('ID', '{id}', $this->getUrl('catalog/category/view', ['id' => 'ID']));
    }

    /**
     * Settings of the inline script: the tracker address, cdp.init() options and whether to wait for the
     * cookie consent of Magento's "Cookie Restriction Mode".
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $store = $this->_storeManager->getStore();
        $storeId = (int) $store->getId();

        return [
            'script' => $this->config->url($storeId) . '/tracker/cdp.js',
            'key' => $this->config->writeKey($storeId),
            'options' => ['host' => $this->config->url($storeId), 'language' => 'page'],
            'methods' => self::STUB_METHODS,
            'consent' => $this->config->enabled('wait_for_consent', $storeId) && $this->cookieHelper->isCookieRestrictionModeEnabled()
                ? ['cookie' => CookieHelper::IS_USER_ALLOWED_SAVE_COOKIE, 'website' => (string) $store->getWebsiteId()]
                : null,
        ];
    }
}
