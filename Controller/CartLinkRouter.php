<?php

declare(strict_types=1);

namespace OneTrace\Connector\Controller;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use OneTrace\Connector\Model\CartLink as CartLinks;

/**
 * Any storefront page with `?onetrace_cart=` (a cart link of OneTrace) opens the cart link action before the other
 * routers. The full page cache never stores it: the answer is always a redirect without caching.
 */
class CartLinkRouter implements RouterInterface
{
    public function __construct(
        private readonly ActionFactory $actionFactory,
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        if (!$request instanceof Http || !$request->isGet() || $request->getQuery(CartLinks::PARAM) === null) {
            return null;
        }

        $request->setRouteName('onetrace')->setModuleName('onetrace')->setControllerName('cart')->setActionName('link');

        return $this->actionFactory->create(CartLink::class);
    }
}
