<?php

declare(strict_types=1);

namespace OneTrace\Connector\Block;

use Magento\CatalogSearch\Helper\Data as SearchHelper;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Search\Model\QueryFactory;

/**
 * The quick search results page with the OneTrace search widget: <div data-cdp-search-results> instead of Magento's
 * product list and layered navigation (layout handle onetrace_search_widget). The tracker reads the query from the
 * page address and draws the results; "Add to cart" posts to onetrace/cart/add.
 */
class SearchResults extends Template
{
    /** @var string */
    protected $_template = 'OneTrace_Connector::search_results.phtml';

    public function __construct(
        Context $context,
        private readonly SearchHelper $searchHelper,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /** The parameter of the query in the page address. */
    public function queryParam(): string
    {
        return QueryFactory::QUERY_VAR_NAME;
    }

    /** The add-to-cart address of the widget: {id} is replaced with the product id of the catalog. */
    public function cartUrl(): string
    {
        return str_replace('/id/ID', '/id/{id}', $this->getUrl('onetrace/cart/add', ['id' => 'ID']));
    }

    /**
     * The title and the breadcrumbs of Magento's results page, which the removed search.result block sets.
     */
    protected function _prepareLayout()
    {
        $title = __("Search results for: '%1'", $this->searchHelper->getEscapedQueryText());
        $this->pageConfig->getTitle()->set($title);
        $breadcrumbs = $this->getLayout()->getBlock('breadcrumbs');

        if ($breadcrumbs instanceof \Magento\Theme\Block\Html\Breadcrumbs) {
            $breadcrumbs->addCrumb('home', ['label' => __('Home'), 'title' => __('Go to Home Page'), 'link' => $this->_storeManager->getStore()->getBaseUrl()])
                ->addCrumb('search', ['label' => $title, 'title' => $title]);
        }

        return parent::_prepareLayout();
    }
}
