<?php

declare(strict_types=1);

namespace OneTrace\Connector\Controller;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\PageCache\Version;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Connector\Model\CartLink as CartLinks;

/**
 * A cart link of OneTrace: GET {any page}?onetrace_cart=ID:QTY,ID:QTY (CartLinkRouter). The products go to the
 * visitor's cart on the server — without a form key: the shopper followed the link to their own cart — and the answer
 * is a 302 to the cart with the other parameters of the link (utm tags), which the tracker records on the cart page.
 * When nothing could be added, the same page without the parameter. Never cached.
 */
class CartLink implements HttpGetActionInterface
{
    public function __construct(
        private readonly Http $request,
        private readonly ResponseInterface $response,
        private readonly RedirectFactory $redirects,
        private readonly CartLinks $links,
        private readonly UrlInterface $url,
        private readonly StoreManagerInterface $storeManager,
        private readonly CookieManagerInterface $cookies,
        private readonly CookieMetadataFactory $cookieMetadata,
    ) {
    }

    public function execute(): Redirect
    {
        [$path, $query] = array_pad(explode('?', (string) $this->request->getRequestUri(), 2), 2, '');
        $added = $this->links->add($this->links->parse($this->request->getQuery(CartLinks::PARAM)), $this->request, $this->response);

        if ($added > 0) {
            $target = $this->url->getUrl('checkout/cart');
            $this->refreshPrivateContent();
        } else {
            $target = $this->origin() . '/' . ltrim($path, '/\\');
        }

        $query = CartLinks::withoutParam($query);

        return $this->redirects->create()
            ->setUrl($query !== '' ? $target . (str_contains($target, '?') ? '&' : '?') . $query : $target)
            ->setHttpResponseCode(302)
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true)
            ->setHeader('Pragma', 'no-cache', true)
            ->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
    }

    /**
     * The scheme, host and port of the store view: the page path of the request goes to the store's own domain.
     */
    private function origin(): string
    {
        $base = (array) parse_url((string) $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB, $this->request->isSecure()));
        $port = isset($base['port']) ? ':' . $base['port'] : '';

        return ($base['scheme'] ?? 'https') . '://' . ($base['host'] ?? '') . $port;
    }

    /**
     * The customer sections (the mini cart) load again on the cart page: `section_data_clean`, which customer-data.js
     * reads on page load (Magento sets it after switching the store view), and a new private content version, as
     * after Magento's own "Add to cart" (only POST requests change it).
     */
    private function refreshPrivateContent(): void
    {
        $clean = $this->cookieMetadata->createPublicCookieMetadata()
            ->setDuration(60)
            ->setPath('/')
            ->setSecure($this->request->isSecure())
            ->setHttpOnly(false)
            ->setSameSite('Lax');
        $this->cookies->setPublicCookie('section_data_clean', (string) $this->storeManager->getStore()->getCode(), $clean);

        $version = $this->cookieMetadata->createPublicCookieMetadata()
            ->setDuration(Version::COOKIE_PERIOD)
            ->setPath('/')
            ->setSecure($this->request->isSecure())
            ->setHttpOnly(false)
            ->setSameSite('Lax');
        $this->cookies->setPublicCookie(Version::COOKIE_NAME, bin2hex(random_bytes(16)), $version);
    }
}
