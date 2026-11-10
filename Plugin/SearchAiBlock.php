<?php

declare(strict_types=1);

namespace OneTrace\Connector\Plugin;

use Magento\CatalogSearch\Block\Result;
use OneTrace\Connector\Model\SearchAiBlock as Block;

/**
 * The AI block above the quick search results: the results block is rendered after its products were searched.
 */
class SearchAiBlock
{
    public function __construct(private readonly Block $block)
    {
    }

    public function afterToHtml(Result $subject, string $html): string
    {
        return $this->block->take() . $html;
    }
}
