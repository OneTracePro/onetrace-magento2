<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Connector\Model\SearchWidget;

/**
 * The quick search results page with the OneTrace search widget. layout_load_before: the onetrace_search_widget
 * handle puts the widget container in place of Magento's results. layout_generate_blocks_after: with the setting on,
 * the full page cache keeps the page no longer than the answer of the platform, so turning the widget on or off in
 * OneTrace reaches cached pages within SearchWidget::LIFETIME.
 */
class SearchWidgetPage implements ObserverInterface
{
    public const HANDLE = 'onetrace_search_widget';

    private const ACTION = 'catalogsearch_result_index';

    public function __construct(
        private readonly SearchWidget $searchWidget,
        private readonly StoreManagerInterface $storeManager,
        private readonly HttpResponse $response,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $layout = $event->getData('layout');

        if ($event->getData('full_action_name') !== self::ACTION || !$layout instanceof LayoutInterface) {
            return;
        }

        $storeId = (int) $this->storeManager->getStore()->getId();

        if ($event->getName() === 'layout_load_before') {
            if ($this->searchWidget->active($storeId)) {
                $layout->getUpdate()->addHandle(self::HANDLE);
            }

            return;
        }

        $cacheControl = $this->response->getHeader('Cache-Control');
        $value = $cacheControl ? (string) $cacheControl->getFieldValue() : '';

        if ($this->searchWidget->configured($storeId) && str_contains($value, 'public') && preg_match('/(?:^|[\s,])max-age=(\d+)/', $value, $match) === 1
            && (int) $match[1] > SearchWidget::LIFETIME) {
            $this->response->setPublicHeaders(SearchWidget::LIFETIME);
        }
    }
}
