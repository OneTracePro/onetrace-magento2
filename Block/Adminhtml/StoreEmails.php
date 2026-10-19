<?php

declare(strict_types=1);

namespace OneTrace\Connector\Block\Adminhtml;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use OneTrace\Connector\Model\Config;
use OneTrace\Connector\Model\Email\Settings;
use OneTrace\Resource\Emails;

/**
 * Read-only state of store emails for the store view of the settings page: they are turned on in the platform
 * (Store emails section), not here.
 */
class StoreEmails extends Field
{
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        private readonly Config $config,
        private readonly Settings $settings,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $lines = array_map(fn (string $line): string => '<p>' . $this->escapeHtml($line) . '</p>', $this->lines($this->storeId()));

        return '<div id="onetrace-store-emails">' . implode('', $lines) . '</div>';
    }

    /**
     * @return list<string>
     */
    public function lines(int $storeId): array
    {
        if (!$this->config->server($storeId)) {
            return [(string) __('Set the platform address and the secret key.')];
        }

        $settings = $this->settings->get($storeId);

        if ($settings === null) {
            return [(string) __('Not available: the secret key needs the messages.send permission ("Send messages manually"), or the platform did not answer. Magento sends its emails itself.')];
        }

        $templates = array_values(array_filter(Emails::ORDER_KINDS, static fn (string $kind): bool => ($settings['templates'][$kind] ?? false) === true));

        return [
            (string) ($settings['relay'] ? __('Store emails go through OneTrace as they are.') : __('Store emails are sent by Magento.')),
            (string) ($templates !== [] ? __('Order emails from OneTrace templates: %1.', implode(', ', $templates)) : __('Order emails from OneTrace templates: none.')),
            (string) __('If OneTrace does not accept an email, Magento sends it itself. Settings are read every 5 minutes and when this page is saved.'),
        ];
    }

    /** The store view of the scope: a store view, the default one of a website, or the default one. */
    private function storeId(): int
    {
        $request = $this->getRequest();

        try {
            if ((int) $request->getParam('store') > 0) {
                return (int) $request->getParam('store');
            }

            if ((int) $request->getParam('website') > 0) {
                return (int) $this->_storeManager->getWebsite((int) $request->getParam('website'))->getDefaultStore()->getId();
            }

            return (int) $this->_storeManager->getDefaultStoreView()?->getId();
        } catch (\Throwable) {
            return 0;
        }
    }
}
