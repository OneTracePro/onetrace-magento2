<?php

declare(strict_types=1);

namespace OneTrace\Connector\Controller\Order;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Connector\Block\StoreMcp\OrderPayment;
use OneTrace\Connector\Model\StoreMcp\PaymentLinks;

/**
 * GET /onetrace/order/pay?order=…&expires=…&signature=… — the payment page of an order placed by an AI shopping
 * assistant or the chat bot (the payment_url of store_create_order): the order, its total and the payment
 * instructions of its payment method. A wrong or expired link is "not found". Never cached, not indexed.
 */
class Pay implements HttpGetActionInterface
{
    public function __construct(
        private readonly Http $request,
        private readonly PageFactory $pages,
        private readonly ForwardFactory $forwards,
        private readonly PaymentLinks $links,
        private readonly OrderRepositoryInterface $orders,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function execute(): Page|Forward
    {
        $id = $this->links->verify($this->request->getQuery('order'), $this->request->getQuery('expires'), $this->request->getQuery('signature'));

        try {
            $order = $id !== null ? $this->orders->get($id) : null;
        } catch (NoSuchEntityException) {
            $order = null;
        }

        $website = (int) $this->storeManager->getStore()->getWebsiteId();

        if (!$order instanceof Order || (int) $this->storeManager->getStore((int) $order->getStoreId())->getWebsiteId() !== $website) {
            return $this->forwards->create()->forward('noroute');
        }

        $page = $this->pages->create();
        $page->getConfig()->getTitle()->set((string) __('Order # %1', $order->getIncrementId()));
        $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');
        $block = $page->getLayout()->getBlock('onetrace.order.payment');

        if ($block instanceof OrderPayment) {
            $block->setOrder($order);
        }

        $page->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);

        return $page;
    }
}
