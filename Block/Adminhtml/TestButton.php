<?php

declare(strict_types=1);

namespace OneTrace\Connector\Block\Adminhtml;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use OneTrace\Connector\Model\Queue;

/**
 * "Test connection" and "Upload the whole catalog" buttons with the queue state, on the settings page.
 */
class TestButton extends Field
{
    protected $_template = 'OneTrace_Connector::test_button.phtml';

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        private readonly Queue $queue,
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
        return $this->_toHtml();
    }

    public function testUrl(): string
    {
        return $this->getUrl('onetrace/connection/test', ['store' => (int) $this->getRequest()->getParam('store')]);
    }

    public function syncUrl(): string
    {
        return $this->getUrl('onetrace/connection/sync');
    }

    /**
     * @return array{waiting: int, retrying: int}
     */
    public function stats(): array
    {
        return $this->queue->stats();
    }

    public function lastError(): ?string
    {
        return $this->queue->lastError();
    }
}
