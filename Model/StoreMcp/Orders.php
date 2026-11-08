<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model\StoreMcp;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\MethodList;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Cart\ShippingMethodConverter;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Connector\Model\CartLink;
use OneTrace\StoreMcp\Context;
use OneTrace\StoreMcp\ToolError;
use Psr\Log\LoggerInterface;

/**
 * Orders from AI shopping assistants and the chat bot (store MCP contract v2, section 10): store_quote_order prices
 * the products in a quote that is never saved (shipping rates for the address, cart price rules, taxes, payment
 * methods), store_create_order places a real order through Magento's quote submit for the confirmed customer or a
 * guest with the confirmed email.
 *
 * Product ids are those of the uploaded catalog, mapped as in cart links (a variant of a configurable product is the
 * configurable product with its attributes). Orders are placed only with the payment methods the owner allows in
 * "Payment methods of orders" that Magento offers for the quote — methods that need no customer at the checkout
 * (check / money order, bank transfer, cash on delivery, or an extension's payment link method): Magento has no link
 * to pay an existing order online. The answer has a signed link to the order page with the payment instructions
 * (PaymentLinks); an observer of onetrace_store_mcp_payment_url may give the payment method's own link instead.
 */
class Orders
{
    /** A quote is compared with the order for this long: a changed price or total is an error, not a silent order. */
    private const QUOTE_TTL = 3600;

    /** Payment methods that need data of the customer at the checkout: never for orders without the customer. */
    private const NEEDS_INPUT = ['purchaseorder'];

    /**
     * @param list<string> $onDeliveryMethods payment methods paid when the order is received (payment_status on_delivery)
     */
    public function __construct(
        private readonly QuoteFactory $quoteFactory,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly QuoteManagement $quoteManagement,
        private readonly CartLink $cartLink,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly Customers $customers,
        private readonly ShippingMethodConverter $shippingMethods,
        private readonly MethodList $paymentMethods,
        private readonly RegionCollectionFactory $regions,
        private readonly DirectoryHelper $directory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AssistantOrders $assistantOrders,
        private readonly PaymentLinks $paymentLinks,
        private readonly Tools $tools,
        private readonly CacheCodeStore $codes,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EventManager $events,
        private readonly LoggerInterface $logger,
        private readonly array $onDeliveryMethods = ['cashondelivery'],
    ) {
    }

    /**
     * store_quote_order: arguments checked by OrderInput::quote().
     *
     * @param array{items: list<array{product_id: string, quantity: int}>, shipping: array<string, string>, shipping_method: string|null} $args
     *
     * @return array<string, mixed>
     */
    public function quote(array $args, Context $ctx): array
    {
        [$quote, $lines, $unavailable] = $this->build($args['items'], $args['shipping'], $ctx, false);
        $rates = $this->rates($quote, $args['shipping_method']);
        $payments = $lines !== [] ? $this->payments($quote) : [];
        $currency = $this->currency($quote);
        $totals = $this->totalsAddress($quote);
        $chosen = (string) $quote->getShippingAddress()->getShippingMethod();
        $items = [];

        foreach ($lines as $line) {
            $items[] = [
                'product_id' => $line['product_id'],
                'name' => $line['name'],
                'quantity' => $line['quantity'],
                'price' => self::money((float) $line['item']->getPriceInclTax(), $currency),
                'total' => self::money((float) $line['item']->getRowTotalInclTax(), $currency),
            ];
        }

        $result = [
            'items' => $items,
            'unavailable' => $unavailable,
            'subtotal' => self::money($lines !== [] ? (float) $totals->getSubtotalInclTax() : 0.0, $currency),
            'discount' => self::money($lines !== [] ? abs((float) $totals->getDiscountAmount()) : 0.0, $currency),
            'shipping_methods' => $rates,
            'shipping_method' => $chosen !== '' && $lines !== [] ? $chosen : null,
            'shipping_price' => self::money($lines !== [] && $chosen !== '' ? (float) $quote->getShippingAddress()->getShippingInclTax() : 0.0, $currency),
            'total' => self::money($lines !== [] ? (float) $quote->getGrandTotal() : 0.0, $currency),
            'payment_methods' => $payments,
        ];

        if ($lines !== [] && $result['shipping_method'] !== null) {
            $this->codes->put($this->quoteKey($args['items'], $args['shipping'], $result['shipping_method'], $ctx), ['prices' => $this->prices($result)], self::QUOTE_TTL);
        }

        return $result;
    }

    /**
     * store_create_order: arguments checked by OrderInput::create(), the consent and the idempotency key by the server.
     *
     * @param array{items: list<array{product_id: string, quantity: int}>, shipping: array<string, string>, shipping_method: string, payment_method: string|null, comment: string|null} $args
     *
     * @return array<string, mixed>
     */
    public function create(array $args, Context $ctx): array
    {
        $store = $this->store();
        $storeId = (int) $store->getId();
        $key = $this->assistantOrders->key($storeId, $this->who($ctx), (string) $ctx->idempotencyKey);
        $existing = $this->assistantOrders->reserve($key, $storeId, $ctx->source, $ctx->assistant);

        if ($existing === false) {
            throw new ToolError(ToolError::UNAVAILABLE, (string) __('This order is still being placed, try again in a minute.'));
        }

        if ($existing !== null) {
            /** @var Order $order */
            $order = $this->orderRepository->get($existing);

            return $this->result($order);
        }

        try {
            $order = $this->place($args, $ctx, $key);
        } catch (\Throwable $error) {
            $placed = $this->assistantOrders->release($key);

            if ($placed !== null) {
                $this->logger->warning('OneTrace: store MCP order ' . $placed . ' is placed, but: ' . $error->getMessage());
                /** @var Order $order */
                $order = $this->orderRepository->get($placed);

                return $this->result($order);
            }

            if ($error instanceof ToolError) {
                throw $error;
            }

            if ($error instanceof LocalizedException) {
                throw new ToolError(ToolError::INVALID_INPUT, $error->getMessage());
            }

            $this->logger->error('OneTrace: store MCP order: ' . $error->getMessage());

            throw $error;
        }

        return $this->result($order);
    }

    /**
     * @param array{items: list<array{product_id: string, quantity: int}>, shipping: array<string, string>, shipping_method: string, payment_method: string|null, comment: string|null} $args
     */
    private function place(array $args, Context $ctx, string $key): Order
    {
        [$quote, $lines, $unavailable] = $this->build($args['items'], $args['shipping'], $ctx, true);

        if ($unavailable !== [] || $lines === []) {
            throw new ToolError(ToolError::INVALID_INPUT, (string) __('Some products can no longer be ordered (%1): quote the order again.', implode(', ', $unavailable)));
        }

        $methods = $this->rates($quote, $args['shipping_method']);

        if (!$quote->isVirtual() && $quote->getShippingAddress()->getShippingMethod() !== $args['shipping_method']) {
            throw new ToolError(ToolError::INVALID_INPUT, (string) __('This delivery method is not available for the address. Available: %1', implode(', ', array_column($methods, 'id'))));
        }

        $payments = array_column($this->payments($quote), 'id');
        $payment = $args['payment_method'] ?? ($payments[0] ?? null);

        if ($payments === []) {
            throw new ToolError(ToolError::NOT_ALLOWED, (string) __('The store cannot take this order without the customer on its website.'));
        }

        if (!in_array($payment, $payments, true)) {
            throw new ToolError(ToolError::INVALID_INPUT, (string) __('This payment method is not available. Available: %1', implode(', ', $payments)));
        }

        $this->checkPrices($quote, $lines, $args, $ctx);

        // The payment of a quote is bound to it only once the quote is saved; the quote stays inactive, so it never
        // becomes the cart of the customer.
        $this->quoteRepository->save($quote);
        /** @var Quote $quote */
        $quote = $this->quoteRepository->get((int) $quote->getId());
        $quote->getPayment()->importData(['method' => $payment]);
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        $this->quoteRepository->save($quote);
        $this->assistantOrders->placing($key, (int) $quote->getId());
        /** @var Quote $saved */
        $saved = $this->quoteRepository->get((int) $quote->getId());
        $order = $this->quoteManagement->submit($saved);

        if (!$order instanceof Order) {
            throw new ToolError(ToolError::UNAVAILABLE, (string) __('The store could not place the order, try again later.'));
        }

        $this->assistantOrders->placed($key, (int) $order->getId());
        $this->comment($order, $ctx, $args['comment']);

        return $order;
    }

    /**
     * Comments of the placed order: where it came from (for the staff) and the comment of the customer. The order is
     * placed already: a failure here is only logged.
     */
    private function comment(Order $order, Context $ctx, ?string $comment): void
    {
        try {
            $order->addCommentToStatusHistory($ctx->fromAssistant()
                ? (string) __('Placed through OneTrace by the AI assistant of the customer: %1.', self::assistantName((string) $ctx->assistant))
                : (string) __('Placed by the customer in the OneTrace chat.'))->setIsCustomerNotified(0);

            if ($comment !== null) {
                $order->addCommentToStatusHistory((string) __('Comment of the customer: %1', $comment))->setIsCustomerNotified(0)->setIsVisibleOnFront(1);
            }

            $this->orderRepository->save($order);
        } catch (\Throwable $error) {
            $this->logger->error('OneTrace: comments of store MCP order ' . $order->getIncrementId() . ': ' . $error->getMessage());
        }
    }

    /**
     * The answer of store_create_order, also for a repeat with the same idempotency key.
     *
     * @return array<string, mixed>
     */
    private function result(Order $order): array
    {
        $method = (string) $order->getPayment()?->getMethod();
        $paymentStatus = match (true) {
            (float) $order->getTotalDue() <= 0.0 => 'paid',
            in_array($method, $this->onDeliveryMethods, true) => 'on_delivery',
            default => 'pending',
        };
        $transport = new DataObject(['order' => $order, 'payment_url' => $this->paymentLinks->url($order), 'payment_status' => $paymentStatus]);
        // A payment extension with its own link to pay the order (a payment link method) gives it here.
        $this->events->dispatch('onetrace_store_mcp_payment_url', ['transport' => $transport]);

        return [
            'order' => (string) $order->getIncrementId(),
            'status' => $this->tools->status($order),
            'total' => self::money((float) $order->getGrandTotal(), (string) $order->getOrderCurrencyCode()),
            'payment_status' => in_array($transport->getData('payment_status'), ['pending', 'on_delivery', 'paid'], true) ? $transport->getData('payment_status') : $paymentStatus,
            'payment_url' => (string) $transport->getData('payment_url'),
        ];
    }

    /**
     * A quote of the store view for the customer (customer group prices) or a guest, with the products that can be
     * ordered and the address. A product the quote refuses (sold out, not enough stock, needs a choice) goes to the
     * unavailable ids, and the quote is built again without it: Magento keeps traces of a refused item.
     *
     * @param list<array{product_id: string, quantity: int}> $items
     * @param array<string, string> $shipping
     *
     * @return array{0: Quote, 1: list<array{product_id: string, name: string, quantity: int, item: Item}>, 2: list<string>}
     */
    private function build(array $items, array $shipping, Context $ctx, bool $forOrder): array
    {
        $store = $this->store();
        $candidates = [];
        $unavailable = [];

        foreach ($items as $item) {
            $candidate = preg_match('/^\d{1,10}$/', $item['product_id']) === 1
                ? $this->cartLink->candidate((int) $item['product_id'], $item['quantity'], (int) $store->getId(), (int) $store->getWebsiteId())
                : null;

            if ($candidate === null) {
                $unavailable[] = $item['product_id'];
            } else {
                $candidates[] = ['product_id' => $item['product_id'], 'quantity' => $item['quantity'], 'name' => (string) $this->cartLink->product((int) $item['product_id'], (int) $store->getId(), (int) $store->getWebsiteId())?->getName(), 'candidate' => $candidate];
            }
        }

        while (true) {
            $quote = $this->emptyQuote($store, $shipping, $ctx, $forOrder);
            $lines = [];

            foreach ($candidates as $index => $candidate) {
                try {
                    $added = $quote->addProduct($candidate['candidate'][0], new DataObject($candidate['candidate'][1]));
                } catch (LocalizedException) {
                    $added = null;
                }

                if (!$added instanceof Item || $added->getHasError()) {
                    $unavailable[] = $candidate['product_id'];
                    unset($candidates[$index]);

                    continue 2;
                }

                $lines[] = ['product_id' => $candidate['product_id'], 'name' => $candidate['name'], 'quantity' => $candidate['quantity'], 'item' => $added];
            }

            return [$quote, $lines, $unavailable];
        }
    }

    /**
     * @param array<string, string> $shipping
     */
    private function emptyQuote(Store $store, array $shipping, Context $ctx, bool $forOrder): Quote
    {
        $quote = $this->quoteFactory->create();
        $quote->setStore($store)->setIsActive(false)->setIsMultiShipping(0);
        [$customerId, $email] = $this->customers->who((string) $ctx->customerId);
        $customer = $customerId !== null ? $this->customerRepository->getById($customerId) : null;

        if ($customer instanceof CustomerInterface) {
            $quote->assignCustomer($customer);
        } else {
            $email ??= $ctx->guestEmail();

            if ($forOrder && $email === null) {
                throw new ToolError(ToolError::NOT_ALLOWED, (string) __('The store places guest orders with an email: confirm the email address.'));
            }

            $quote->setCustomerIsGuest(true)
                ->setCheckoutMethod('guest')
                ->setCustomerGroupId(GroupInterface::NOT_LOGGED_IN_ID)
                ->setCustomerEmail((string) $email)
                ->setCustomerFirstname($shipping['first_name'] ?? null)
                ->setCustomerLastname($shipping['last_name'] ?? null);
        }

        $address = $this->address($shipping, $store, $ctx, $customer, $forOrder);

        foreach ([$quote->getShippingAddress(), $quote->getBillingAddress()] as $quoteAddress) {
            $quoteAddress->addData($address)->setCustomerAddressId(null)->setSaveInAddressBook(0);
        }

        $quote->getShippingAddress()->setSameAsBilling(1);

        return $quote;
    }

    /**
     * The address of the quote: the given fields, the country of the store without one; the state or region of a
     * country that needs one is taken from the city and address lines (the contract has no field for it).
     *
     * @param array<string, string> $shipping
     *
     * @return array<string, mixed>
     */
    private function address(array $shipping, Store $store, Context $ctx, ?CustomerInterface $customer, bool $forOrder): array
    {
        $country = $shipping['country'] ?? (string) $this->scopeConfig->getValue('general/country/default', ScopeInterface::SCOPE_STORE, (int) $store->getId());
        $phone = $shipping['phone'] ?? $ctx->guestPhone();

        foreach ($customer !== null && $phone === null ? (array) $customer->getAddresses() : [] as $saved) {
            $phone = (string) $saved->getTelephone() ?: $phone;
        }

        $address = [
            'firstname' => $shipping['first_name'] ?? $customer?->getFirstname(),
            'lastname' => $shipping['last_name'] ?? $customer?->getLastname(),
            'street' => $shipping['address'] ?? null,
            'city' => $shipping['city'] ?? null,
            'postcode' => $shipping['postcode'] ?? null,
            'country_id' => $country,
            'telephone' => $phone,
            'email' => $customer?->getEmail() ?? $ctx->guestEmail(),
            'region_id' => null,
            'region' => null,
        ];
        $region = $this->region($country, trim(($shipping['city'] ?? '') . ', ' . ($shipping['address'] ?? '') . ' ' . ($shipping['postcode'] ?? ''), ', '));

        if ($region !== null) {
            $address['region_id'] = $region['id'];
            $address['region'] = $region['name'];
        } elseif ($forOrder && $this->directory->isRegionRequired($country)) {
            throw new ToolError(ToolError::INVALID_INPUT, (string) __('The delivery address needs the state or region: add it to the address.'));
        }

        // Every field is set: the default address of an account must not fill in what the customer did not give.
        return $address;
    }

    /**
     * The region of the country named in the text: its name, or its code as a separate word (TX, NSW).
     *
     * @return array{id: int, name: string}|null
     */
    private function region(string $country, string $text): ?array
    {
        if ($text === '') {
            return null;
        }

        $lower = mb_strtolower($text);
        $found = null;

        foreach ($this->regions->create()->addCountryFilter($country) as $region) {
            $names = array_filter([(string) $region->getData('default_name'), (string) $region->getName()]);

            foreach ($names as $name) {
                if (preg_match('/(^|[^\p{L}])' . preg_quote(mb_strtolower($name), '/') . '($|[^\p{L}])/u', $lower) === 1 && ($found === null || mb_strlen($name) > mb_strlen($found['name']))) {
                    $found = ['id' => (int) $region->getId(), 'name' => $name];
                }
            }

            $code = (string) $region->getCode();

            if ($found === null && $code !== '' && preg_match('/(^|[^\p{L}\d])' . preg_quote($code, '/') . '($|[^\p{L}\d])/u', $text) === 1) {
                $found = ['id' => (int) $region->getId(), 'name' => (string) ($region->getData('default_name') ?: $code)];
            }
        }

        return $found;
    }

    /**
     * Shipping rates of the address after the totals; the given method when the address has it, otherwise the first
     * one, and the totals with it.
     *
     * @return list<array{id: string, name: string, price: array{amount: float, currency: string}}>
     */
    private function rates(Quote $quote, ?string $wanted): array
    {
        if ($quote->isVirtual() || $quote->getAllItems() === []) {
            $quote->collectTotals();

            return [];
        }

        $address = $quote->getShippingAddress();
        $address->setShippingMethod($wanted)->setCollectShippingRates(true);
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        $currency = $this->currency($quote);
        $rates = [];

        foreach ($address->getGroupedAllShippingRates() as $carrierRates) {
            foreach ($carrierRates as $rate) {
                if ($rate->getErrorMessage()) {
                    continue;
                }

                $method = $this->shippingMethods->modelToDataObject($rate, $currency);
                $rates[] = [
                    'id' => (string) $rate->getCode(),
                    'name' => trim((string) $method->getCarrierTitle() . ' — ' . (string) $method->getMethodTitle(), ' —'),
                    'price' => self::money((float) $method->getPriceInclTax(), $currency),
                ];
            }
        }

        $ids = array_column($rates, 'id');
        $chosen = $wanted !== null && in_array($wanted, $ids, true) ? $wanted : ($ids[0] ?? null);

        if ($chosen !== $wanted) {
            $address->setShippingMethod($chosen)->setCollectShippingRates(true);
            $quote->setTotalsCollectedFlag(false)->collectTotals();
        }

        return $rates;
    }

    /**
     * Payment methods Magento offers for the quote that the owner allows for orders without the customer; the free
     * method of a zero total always.
     *
     * @return list<array{id: string, name: string}>
     */
    private function payments(Quote $quote): array
    {
        $allowed = array_diff(explode(',', (string) $this->scopeConfig->getValue('onetrace/store_mcp/payment_methods', ScopeInterface::SCOPE_STORE, (int) $quote->getStoreId())), self::NEEDS_INPUT);
        $methods = [];

        foreach ($this->paymentMethods->getAvailableMethods($quote) as $method) {
            $code = (string) $method->getCode();

            if (in_array($code, $allowed, true) || $code === 'free') {
                $methods[] = ['id' => $code, 'name' => (string) $method->getTitle()];
            }
        }

        return $methods;
    }

    /**
     * The order must cost what the last quote of the same products, address and delivery said (store_quote_order
     * in the last hour): otherwise the customer agreed to other prices.
     *
     * @param list<array{product_id: string, name: string, quantity: int, item: Item}> $lines
     * @param array{items: list<array{product_id: string, quantity: int}>, shipping: array<string, string>, shipping_method: string, payment_method: string|null, comment: string|null} $args
     */
    private function checkPrices(Quote $quote, array $lines, array $args, Context $ctx): void
    {
        $quoted = $this->codes->get($this->quoteKey($args['items'], $args['shipping'], $args['shipping_method'], $ctx));

        if (!is_array($quoted['prices'] ?? null)) {
            return;
        }

        $currency = $this->currency($quote);
        $now = ['total' => self::money((float) $quote->getGrandTotal(), $currency)['amount'], 'currency' => $currency];

        foreach ($lines as $line) {
            $now['item:' . $line['product_id']] = self::money((float) $line['item']->getPriceInclTax(), $currency)['amount'];
        }

        if ($quoted['prices'] != $now) {
            throw new ToolError(ToolError::INVALID_INPUT, (string) __('Prices have changed since the quote (the total is now %1 %2): quote the order again and confirm the new total.', $now['total'], $currency));
        }
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, float|string>
     */
    private function prices(array $result): array
    {
        $prices = ['total' => $result['total']['amount'], 'currency' => $result['total']['currency']];

        foreach ($result['items'] as $item) {
            $prices['item:' . $item['product_id']] = $item['price']['amount'];
        }

        return $prices;
    }

    /**
     * @param list<array{product_id: string, quantity: int}> $items
     * @param array<string, string> $shipping
     */
    private function quoteKey(array $items, array $shipping, string $method, Context $ctx): string
    {
        $lines = [];

        foreach ($items as $item) {
            $lines[$item['product_id']] = $item['quantity'];
        }

        ksort($lines);

        return 'onetrace_mcp_quote_' . sha1((string) json_encode([
            (int) $this->store()->getId(),
            $this->customers->who((string) $ctx->customerId)[0] ?? 'guest',
            $lines,
            $shipping['country'] ?? '',
            mb_strtolower($shipping['city'] ?? ''),
            $shipping['postcode'] ?? '',
            $method,
        ]));
    }

    private function who(Context $ctx): string
    {
        return $ctx->customerId !== null ? (string) $ctx->customerId : 'guest:' . (string) $ctx->guestContact;
    }

    private function totalsAddress(Quote $quote): Address
    {
        return $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
    }

    /** The currency the customer pays in: the display currency of the store view (the quote is not saved). */
    private function currency(Quote $quote): string
    {
        return (string) ($quote->getQuoteCurrencyCode() ?: $this->store()->getCurrentCurrencyCode());
    }

    private function store(): Store
    {
        /** @var Store */
        return $this->storeManager->getStore();
    }

    private static function assistantName(string $assistant): string
    {
        return ['chatgpt' => 'ChatGPT', 'claude' => 'Claude', 'perplexity' => 'Perplexity', 'gemini' => 'Gemini', 'copilot' => 'Copilot'][$assistant] ?? (string) __('another assistant');
    }

    /**
     * @return array{amount: float, currency: string}
     */
    private static function money(float $amount, string $currency): array
    {
        return ['amount' => round($amount, 2), 'currency' => $currency];
    }
}
