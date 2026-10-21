<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model\Source;

use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * CMS pages by id for a select, with an empty option.
 */
class CmsPages implements OptionSourceInterface
{
    public function __construct(private readonly CollectionFactory $pages)
    {
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => (string) __('-- None --')]];

        foreach ($this->pages->create()->setOrder('title', 'ASC') as $page) {
            $options[] = ['value' => (string) $page->getId(), 'label' => (string) $page->getTitle() . ' (' . (string) $page->getIdentifier() . ')'];
        }

        return $options;
    }
}
