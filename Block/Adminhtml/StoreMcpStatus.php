<?php

declare(strict_types=1);

namespace OneTrace\Connector\Block\Adminhtml;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use OneTrace\Connector\Model\StoreMcp;

/**
 * Read-only state of the store MCP connection for the store view of the settings page.
 */
class StoreMcpStatus extends Field
{
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        private readonly StoreMcp $storeMcp,
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
        return '<div id="onetrace-store-mcp">' . $this->escapeHtml($this->line($this->storeId())) . '</div>';
    }

    public function line(int $storeId): string
    {
        $status = $this->storeMcp->status($storeId);

        if ($status === null) {
            return (string) __('Not connected. Turn it on and save: the store connects to the bot of the project (the secret key needs the chats.manage permission).');
        }

        if ($status['status'] !== 'ok') {
            return (string) __('Not connected: %1', $status['error'] !== '' ? $status['error'] : $status['status']);
        }

        return (string) __('Connected to the bot of the project: %1. Tools: %2.', $status['url'], implode(', ', $status['tools']));
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
