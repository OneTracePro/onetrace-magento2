<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model\StoreMcp;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Shipping\Model\Config as ShippingConfig;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Connector\Model\Config;
use OneTrace\Connector\Model\Stock;
use OneTrace\StoreMcp\Context;
use OneTrace\StoreMcp\ToolError;
use Psr\Log\LoggerInterface;

/**
 * Tools of the store MCP server (contract sections 4.1–4.3). Magento Open Source has no returns and no delivery
 * changes of its own: those requests become an order comment and an email to the staff ("Store request email").
 */
class Tools
{
    /**
     * @param list<string> $cancellableStates order states the bot may cancel (unpaid orders by default); an order
     *                                        is cancelled only when Magento can cancel it and nothing is paid
     */
    public function __construct(
        private readonly Customers $customers,
        private readonly Config $config,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderManagementInterface $orderManagement,
        private readonly ProductRepositoryInterface $products,
        private readonly ProductCollectionFactory $productCollections,
        private readonly Visibility $visibility,
        private readonly Stock $stock,
        private readonly ShippingConfig $shippingConfig,
        private readonly PageRepositoryInterface $pages,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly CacheInterface $cache,
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
        private readonly LoggerInterface $logger,
        private readonly array $cancellableStates = [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT],
    ) {
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function customer(array $args, Context $ctx): array
    {
        [$id, $email] = $this->customers->who((string) $ctx->customerId);
        $data = ['id' => $ctx->customerId, 'email' => $email];

        if ($id !== null) {
            $customer = $this->customerRepository->getById($id);
            $data['first_name'] = (string) $customer->getFirstname();

            foreach ($customer->getAddresses() ?? [] as $address) {
                if ($address->isDefaultBilling() || !isset($data['phone'])) {
                    $data['phone'] = (string) $address->getTelephone();
                }
            }
        }

        return self::filled($data);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function findOrders(array $args, Context $ctx): array
    {
        $limit = max(1, min(20, is_numeric($args['limit'] ?? null) ? (int) $args['limit'] : 5));
        $status = is_string($args['status'] ?? null) && $args['status'] !== '' ? $args['status'] : null;
        $orders = [];

        // The contract status is derived from the state, shipments and settings: filtered after reading.
        foreach ($this->customers->orders((string) $ctx->customerId)->setPageSize($status === null ? $limit : 50) as $order) {
            if (!$order instanceof Order || ($status !== null && $this->status($order) !== $status)) {
                continue;
            }

            $orders[] = [
                'order' => (string) $order->getIncrementId(),
                'created_at' => self::date($order->getCreatedAt()),
                'status' => $this->status($order),
                'status_label' => (string) $order->getFrontendStatusLabel(),
                'total' => self::money((float) $order->getGrandTotal(), (string) $order->getOrderCurrencyCode()),
                'items_count' => count($order->getAllVisibleItems()),
            ];

            if (count($orders) >= $limit) {
                break;
            }
        }

        return ['orders' => $orders];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function getOrder(array $args, Context $ctx): array
    {
        $order = $this->customers->order($args, $ctx);
        $currency = (string) $order->getOrderCurrencyCode();
        $track = $this->tracks($order)[0] ?? null;
        $address = $order->getShippingAddress();
        $items = [];

        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = ['name' => (string) $item->getName(), 'sku' => (string) $item->getSku(), 'quantity' => (int) $item->getQtyOrdered(), 'price' => self::money((float) $item->getPriceInclTax(), $currency)];
        }

        return [
            'order' => (string) $order->getIncrementId(),
            'created_at' => self::date($order->getCreatedAt()),
            'status' => $this->status($order),
            'status_label' => (string) $order->getFrontendStatusLabel(),
            'items' => $items,
            'total' => self::money((float) $order->getGrandTotal(), $currency),
            'payment' => ['method' => $this->paymentTitle($order), 'status' => (float) $order->getTotalDue() <= 0.0 ? 'paid' : 'unpaid'],
            'shipping' => self::filled([
                'method' => (string) $order->getShippingDescription(),
                'address' => $address !== null ? trim((string) $address->getCity() . ', ' . implode(' ', (array) $address->getStreet()), ', ') : null,
                'carrier' => $track['carrier'] ?? null,
                'tracking_number' => $track['number'] ?? null,
            ]),
            'can_cancel' => $this->cancellable($order),
            'can_return' => $this->received($order),
        ];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function tracking(array $args, Context $ctx): array
    {
        $order = $this->customers->order($args, $ctx);
        $tracks = $this->tracks($order);

        if ($tracks === [] && !$order->hasShipments()) {
            throw new ToolError(ToolError::NOT_FOUND, (string) __('The order has not been shipped yet.'));
        }

        $events = [];

        foreach ($order->getShipmentsCollection() ?: [] as $shipment) {
            $events[] = ['at' => self::date($shipment->getCreatedAt()), 'status' => 'shipped', 'location' => null];
        }

        return self::filled([
            'carrier' => $tracks[0]['carrier'] ?? null,
            'tracking_number' => $tracks[0]['number'] ?? null,
            'status' => $this->status($order),
            'events' => $events,
        ]);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function availability(array $args, Context $ctx): array
    {
        $store = $this->storeManager->getStore();
        $storeId = (int) $store->getId();
        $products = [];

        try {
            if (is_string($args['sku'] ?? null) && $args['sku'] !== '') {
                $products = [$this->products->get($args['sku'], false, $storeId)];
            } elseif (is_scalar($args['product_id'] ?? null) && (int) $args['product_id'] > 0) {
                $products = [$this->products->getById((int) $args['product_id'], false, $storeId)];
            } elseif (is_string($args['query'] ?? null) && trim($args['query']) !== '') {
                $like = '%' . addcslashes(trim($args['query']), '%_\\') . '%';
                $products = $this->productCollections->create()
                    ->setStoreId($storeId)
                    ->addStoreFilter($storeId)
                    ->addAttributeToSelect(['name', 'price', 'special_price', 'url_key', 'status', 'visibility'])
                    ->addAttributeToFilter('status', Status::STATUS_ENABLED)
                    ->setVisibility($this->visibility->getVisibleInSiteIds())
                    ->addAttributeToFilter([['attribute' => 'name', 'like' => $like], ['attribute' => 'sku', 'like' => $like]])
                    ->setPageSize(10)
                    ->getItems();
            }
        } catch (NoSuchEntityException) {
            $products = [];
        }

        $products = array_values(array_filter($products, fn ($product): bool => $product instanceof Product
            && (int) $product->getStatus() === Status::STATUS_ENABLED
            && in_array((int) $product->getVisibility(), $this->visibility->getVisibleInSiteIds(), true)
            && in_array((int) $store->getWebsiteId(), array_map('intval', $product->getWebsiteIds()), true)));
        $quantities = $this->stock->quantities($products, $storeId, (int) $store->getWebsiteId());
        $result = [];

        foreach ($products as $product) {
            $result[] = self::filled([
                'product_id' => (string) $product->getId(),
                'name' => (string) $product->getName(),
                'sku' => (string) $product->getSku(),
                'url' => (string) $product->getProductUrl(),
                'price' => self::money((float) $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue(), (string) $store->getBaseCurrencyCode()),
                'in_stock' => (bool) $product->isSalable(),
                'quantity' => $quantities[(int) $product->getId()] ?? null,
                'variants' => $this->variants($product),
            ]);
        }

        return ['products' => $result];
    }

    /**
     * Active shipping methods for the country: the flat rate per order and free shipping with its threshold have a
     * price; carriers that quote by weight or address have none.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function delivery(array $args, Context $ctx): array
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $currency = (string) $this->storeManager->getStore()->getBaseCurrencyCode();
        $country = is_string($args['country'] ?? null) && $args['country'] !== '' ? strtoupper($args['country']) : (string) $this->value('general/country/default', $storeId);
        $methods = [];

        foreach (array_keys($this->shippingConfig->getActiveCarriers($storeId)) as $code) {
            $path = 'carriers/' . $code . '/';

            if ($this->value($path . 'sallowspecific', $storeId) && !in_array($country, explode(',', (string) $this->value($path . 'specificcountry', $storeId)), true)) {
                continue;
            }

            $price = $this->value($path . 'price', $storeId);
            $threshold = $code === 'freeshipping' ? $this->value($path . 'free_shipping_subtotal', $storeId) : ($this->value($path . 'free_shipping_enable', $storeId) ? $this->value($path . 'free_shipping_subtotal', $storeId) : null);
            $methods[] = self::filled([
                'name' => (string) ($this->value($path . 'title', $storeId) ?: $code),
                'price' => match (true) {
                    $code === 'freeshipping' => self::money(0.0, $currency),
                    $code === 'flatrate' && $this->value($path . 'type', $storeId) === 'O' && is_numeric($price) => self::money((float) $price, $currency),
                    default => null,
                },
                'free_from' => is_numeric($threshold) && (float) $threshold > 0 ? self::money((float) $threshold, $currency) : null,
            ]);
        }

        return ['methods' => $methods];
    }

    /**
     * The CMS page of the "Return policy page" setting.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function returnPolicy(array $args, Context $ctx): array
    {
        $store = $this->storeManager->getStore();
        $pageId = (int) $this->value('onetrace/store_mcp/return_page', (int) $store->getId());

        try {
            $page = $pageId > 0 ? $this->pages->getById($pageId) : null;
        } catch (NoSuchEntityException) {
            $page = null;
        }

        if ($page === null || !$page->isActive()) {
            throw new ToolError(ToolError::NOT_FOUND, (string) __('The store has not published a return policy.'));
        }

        $text = (string) preg_replace('/\{\{[^}]*\}\}/', ' ', (string) $page->getContent());

        return [
            'text' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5))), 0, 3000),
            'url' => $store->getUrl('', ['_direct' => (string) $page->getIdentifier()]),
        ];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function cancel(array $args, Context $ctx): array
    {
        $order = $this->customers->order($args, $ctx);
        $done = ['order' => (string) $order->getIncrementId(), 'status' => 'cancelled', 'message' => (string) __('The order is cancelled.')];

        if ($order->getState() === Order::STATE_CANCELED) {
            return $done;
        }

        if (!$this->cancellable($order) || !$this->orderManagement->cancel((int) $order->getId())) {
            throw new ToolError(ToolError::NOT_ALLOWED, (string) __('This order can no longer be cancelled in the chat, an operator will help.'));
        }

        $order = $this->orderRepository->get((int) $order->getId());
        $reason = is_string($args['reason'] ?? null) ? trim($args['reason']) : '';
        $order->addCommentToStatusHistory(trim(__('Cancelled by the customer in the OneTrace chat.') . ' ' . $reason))->setIsCustomerNotified(false);
        $this->orderRepository->save($order);

        return $done;
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function createReturn(array $args, Context $ctx): array
    {
        $order = $this->customers->order($args, $ctx);

        if (!$this->received($order)) {
            throw new ToolError(ToolError::NOT_ALLOWED, (string) __('A return is possible after the order is received.'));
        }

        $items = [];

        foreach (is_array($args['items'] ?? null) ? $args['items'] : [] as $item) {
            if (is_array($item) && is_scalar($item['sku'] ?? null)) {
                $items[] = (string) $item['sku'] . ' × ' . (is_numeric($item['quantity'] ?? null) ? (int) $item['quantity'] : 1);
            }
        }

        $text = (string) __('Return request from the OneTrace chat: %1. Reason: %2', implode(', ', $items), is_string($args['reason'] ?? null) ? $args['reason'] : '');

        return $this->request($order, $ctx, $text, 'return');
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function changeDelivery(array $args, Context $ctx): array
    {
        $order = $this->customers->order($args, $ctx);

        if ($order->hasShipments() || !in_array($order->getState(), [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT, Order::STATE_PROCESSING], true)) {
            throw new ToolError(ToolError::NOT_ALLOWED, (string) __('The order has already been shipped, the delivery can no longer be changed.'));
        }

        $parts = array_filter([
            is_string($args['address'] ?? null) ? (string) __('address: %1', $args['address']) : null,
            is_string($args['date'] ?? null) ? (string) __('date: %1', $args['date']) : null,
            is_string($args['time_window'] ?? null) ? (string) __('time: %1', $args['time_window']) : null,
        ]);

        return $this->request($order, $ctx, (string) __('Delivery change request from the OneTrace chat: %1', implode(', ', $parts)), 'delivery');
    }

    /**
     * A request for the staff: an order comment and the "Store request email"; repeated with the same idempotency
     * key — the same answer.
     *
     * @return array<string, mixed>
     */
    private function request(Order $order, Context $ctx, string $text, string $kind): array
    {
        $cacheId = 'onetrace_mcp_request_' . sha1((string) $ctx->idempotencyKey);
        $done = $ctx->idempotencyKey !== null ? $this->cache->load($cacheId) : false;

        if (is_string($done) && is_array($result = json_decode($done, true))) {
            return $result;
        }

        $order->addCommentToStatusHistory($text)->setIsCustomerNotified(false);
        $this->orderRepository->save($order);
        $this->notifyStaff($order, $text, $kind);

        $result = $kind === 'return'
            ? ['return_id' => 'R' . $order->getIncrementId() . '-' . count($order->getStatusHistories()), 'status' => 'requested', 'instructions' => (string) __('The store will contact you about the return.')]
            : ['order' => (string) $order->getIncrementId(), 'message' => (string) __('The request is passed to the store; it will confirm the change.')];

        if ($ctx->idempotencyKey !== null) {
            $this->cache->save((string) json_encode($result), $cacheId, ['ONETRACE_STORE_MCP'], 86400);
        }

        return $result;
    }

    private function notifyStaff(Order $order, string $text, string $kind): void
    {
        $storeId = (int) $order->getStoreId();
        $identity = (string) $this->value('onetrace/store_mcp/request_identity', $storeId);
        $to = (string) $this->value('trans_email/ident_' . $identity . '/email', $storeId);

        if ($to === '') {
            return;
        }

        $this->inlineTranslation->suspend();

        try {
            $this->transportBuilder
                ->setTemplateIdentifier((string) $this->value('onetrace/store_mcp/request_template', $storeId))
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    'order_number' => (string) $order->getIncrementId(),
                    'request' => $text,
                    'kind' => (string) ($kind === 'return' ? __('return request') : __('delivery change request')),
                ])
                ->setFromByScope($identity, $storeId)
                ->addTo($to)
                ->getTransport()
                ->sendMessage();
        } catch (\Throwable $error) {
            // The comment is on the order: the email is only a notice.
            $this->logger->warning('OneTrace: store MCP request email of order ' . $order->getIncrementId() . ': ' . $error->getMessage());
        } finally {
            $this->inlineTranslation->resume();
        }
    }

    /**
     * Contract status: the state, "shipped" for a processing order with shipments and "delivered" for the
     * "Delivered order statuses".
     */
    private function status(Order $order): string
    {
        if (in_array((string) $order->getStatus(), $this->config->deliveredStatuses((int) $order->getStoreId()), true)) {
            return 'delivered';
        }

        return match ((string) $order->getState()) {
            Order::STATE_NEW, Order::STATE_PENDING_PAYMENT => 'pending',
            Order::STATE_HOLDED, Order::STATE_PAYMENT_REVIEW => 'on_hold',
            Order::STATE_COMPLETE => 'completed',
            Order::STATE_CANCELED => 'cancelled',
            Order::STATE_CLOSED => 'refunded',
            default => $order->hasShipments() ? 'shipped' : 'processing',
        };
    }

    private function cancellable(Order $order): bool
    {
        return in_array((string) $order->getState(), $this->cancellableStates, true) && $order->canCancel() && (float) $order->getTotalPaid() <= 0.0;
    }

    private function received(Order $order): bool
    {
        return in_array($this->status($order), ['completed', 'delivered'], true);
    }

    /**
     * @return list<array{carrier: string|null, number: string}>
     */
    private function tracks(Order $order): array
    {
        $tracks = [];

        foreach ($order->getTracksCollection() as $track) {
            $number = trim((string) $track->getTrackNumber());

            if ($number !== '') {
                $title = trim((string) $track->getTitle());
                $tracks[] = ['carrier' => $title !== '' ? $title : ((string) $track->getCarrierCode() ?: null), 'number' => $number];
            }
        }

        return $tracks;
    }

    private function paymentTitle(Order $order): string
    {
        $payment = $order->getPayment();

        if ($payment === null) {
            return '';
        }

        $title = $payment->getAdditionalInformation('method_title');

        if (is_string($title) && $title !== '') {
            return $title;
        }

        try {
            return (string) $payment->getMethodInstance()->getTitle();
        } catch (\Throwable) {
            return (string) $payment->getMethod();
        }
    }

    /**
     * Variants of a configurable product: the option labels of its configurable attributes.
     *
     * @return list<array{attributes: array<string, string>, sku: string, in_stock: bool}>
     */
    private function variants(Product $product): array
    {
        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            return [];
        }

        /** @var Configurable $type */
        $type = $product->getTypeInstance();
        $codes = [];

        foreach ($type->getConfigurableAttributes($product) as $attribute) {
            $codes[] = (string) $attribute->getProductAttribute()->getAttributeCode();
        }

        $variants = [];

        foreach ($type->getUsedProducts($product) as $child) {
            if (!$child instanceof Product || (int) $child->getStatus() !== Status::STATUS_ENABLED) {
                continue;
            }

            $attributes = [];

            foreach ($codes as $code) {
                $label = $child->getAttributeText($code);
                $attributes[$code] = is_string($label) ? $label : (string) $child->getData($code);
            }

            $variants[] = ['attributes' => $attributes, 'sku' => (string) $child->getSku(), 'in_stock' => (bool) $child->isSalable()];
        }

        return $variants;
    }

    private function value(string $path, int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function filled(array $data): array
    {
        return array_filter($data, static fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @return array{amount: float, currency: string}
     */
    private static function money(float $amount, string $currency): array
    {
        return ['amount' => round($amount, 2), 'currency' => $currency];
    }

    private static function date(?string $date): ?string
    {
        return $date !== null && $date !== '' ? (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->format(DATE_ATOM) : null;
    }
}
