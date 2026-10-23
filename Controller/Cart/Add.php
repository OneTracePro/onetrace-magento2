<?php

declare(strict_types=1);

namespace OneTrace\Connector\Controller\Cart;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Checkout\Model\Cart;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * POST onetrace/cart/add/id/{id} (quantity, form_key; X-Requested-With: XMLHttpRequest): "Add to cart" of the OneTrace
 * search widget. {id} is the product id of the catalog the module uploads. A simple or virtual product without
 * required options goes to the cart like with Magento's own button → {"ok": true, "count": items in the cart};
 * a product that needs a choice, cannot be bought or fails to be added → {"ok": false, "redirect": its page}, which
 * the widget opens. Without the header the answer is 400: a form of another website cannot send it without a CORS
 * preflight; the form key is checked here (CsrfAwareActionInterface: JSON answers instead of Magento's redirect).
 */
class Add implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** Product types added without a choice on the product page. */
    private const TYPES = [Type::TYPE_SIMPLE, Type::TYPE_VIRTUAL];

    public function __construct(
        private readonly Http $request,
        private readonly JsonFactory $json,
        private readonly FormKeyValidator $formKey,
        private readonly ProductRepositoryInterface $products,
        private readonly StoreManagerInterface $storeManager,
        private readonly Cart $cart,
        private readonly CheckoutSession $session,
        private readonly EventManager $eventManager,
        private readonly ResponseInterface $response,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): Json
    {
        $result = $this->json->create()->setHeader('Cache-Control', 'no-store', true);

        if ($this->request->getHeader('X-Requested-With') !== 'XMLHttpRequest') {
            return $result->setHttpResponseCode(400)->setData(['ok' => false]);
        }

        if (!$this->formKey->validate($this->request)) {
            return $result->setHttpResponseCode(403)->setData(['ok' => false, 'message' => (string) __('Please reload the page and try again.')]);
        }

        $store = $this->storeManager->getStore();
        $product = $this->product((int) $this->request->getParam('id'), (int) $store->getId(), (int) $store->getWebsiteId());

        if ($product === null) {
            return $result->setHttpResponseCode(404)->setData(['ok' => false, 'redirect' => $store->getBaseUrl()]);
        }

        if (!in_array($product->getTypeId(), self::TYPES, true) || $product->getRequiredOptions() || !$product->isSalable()) {
            return $result->setData(['ok' => false, 'redirect' => $product->getProductUrl()]);
        }

        try {
            $this->cart->addProduct($product, ['qty' => max(1, (int) $this->request->getParam('quantity'))]);
            $this->cart->save();
            $this->eventManager->dispatch('checkout_cart_add_product_complete', ['product' => $product, 'request' => $this->request, 'response' => $this->response]);
        } catch (LocalizedException $error) {
            // Stock, minimum and maximum quantities: the product page shows Magento's own message.
            $this->session->getUseNotice(true);

            return $result->setData(['ok' => false, 'redirect' => $this->session->getRedirectUrl(true) ?: $product->getProductUrl(), 'message' => $error->getMessage()]);
        } catch (\Throwable $error) {
            $this->logger->error('OneTrace: add to cart from the search widget: ' . $error->getMessage());

            return $result->setData(['ok' => false, 'redirect' => $product->getProductUrl()]);
        }

        return $result->setData(['ok' => true, 'count' => (int) $this->cart->getSummaryQty()]);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /** An enabled product of the website, or null. */
    private function product(int $id, int $storeId, int $websiteId): ?Product
    {
        try {
            $product = $id > 0 ? $this->products->getById($id, false, $storeId, true) : null;
        } catch (NoSuchEntityException) {
            return null;
        }

        return $product instanceof Product && (int) $product->getStatus() === Status::STATUS_ENABLED && in_array($websiteId, array_map('intval', (array) $product->getWebsiteIds()), true)
            ? $product
            : null;
    }
}
