<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model\Email;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\UrlFactory;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Item;
use Magento\Sales\Model\Order\Shipment;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Commerce\Customer;
use OneTrace\Commerce\LineItem;
use OneTrace\Connector\Model\Config;
use OneTrace\Connector\Model\Mapper;

/**
 * The `order` of an order email built from a OneTrace template (Order::emailData()): the number, the order page,
 * products with their images, links and options, totals, payment and shipping, addresses, the parcel's tracking
 * number of a shipment and the refunded amount of a credit memo. Links are storefront links of the order's store
 * view, also when the email is sent from the admin or cron.
 */
class OrderData
{
    public function __construct(
        private readonly Mapper $mapper,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly CollectionFactory $products,
        private readonly UrlFactory $urlFactory,
        private readonly \Magento\Framework\Url\EncoderInterface $urlEncoder,
    ) {
    }

    /**
     * @param array<string, mixed> $vars template variables of the Magento email (shipment, creditmemo, comment)
     *
     * @return array<string, mixed>
     */
    public function build(Order $order, string $kind, array $vars = []): array
    {
        $storeId = (int) $order->getStoreId();
        $shipment = ($vars['shipment'] ?? null) instanceof Shipment ? $vars['shipment'] : null;
        $creditmemo = ($vars['creditmemo'] ?? null) instanceof Creditmemo ? $vars['creditmemo'] : null;

        if ($kind === 'order_shipped' && $shipment !== null) {
            $items = $this->quantities($shipment->getAllItems());
        } elseif ($kind === 'order_refunded' && $creditmemo !== null) {
            $items = $this->quantities($creditmemo->getAllItems());
        } else {
            $items = [];

            foreach ($order->getAllVisibleItems() as $item) {
                $items[] = [$item, (float) $item->getQtyOrdered()];
            }
        }

        $model = $this->mapper->order($order)->with([
            'payment_method' => $this->paymentTitle($order),
            'shipping_method' => (string) $order->getShippingDescription(),
        ]);
        $model->lines = $this->lines($items, $storeId);

        $extra = [
            'number' => (string) $order->getIncrementId(),
            'url' => $this->orderUrl($order),
            'payment_instructions' => $this->paymentInstructions($order),
            'shipping_address' => $this->address($order->getShippingAddress(), $storeId),
            'billing_address' => $this->address($order->getBillingAddress(), $storeId),
        ];

        if ($shipment !== null) {
            foreach ($shipment->getAllTracks() as $track) {
                if (trim((string) $track->getTrackNumber()) === '') {
                    continue;
                }

                $title = trim((string) $track->getTitle());
                $extra['carrier'] = $title !== '' ? $title : (string) $track->getCarrierCode();
                $extra['tracking_number'] = trim((string) $track->getTrackNumber());
                $extra['tracking_url'] = $this->url($storeId, '', [
                    '_direct' => 'shipping/tracking/popup',
                    '_query' => ['hash' => $this->urlEncoder->encode('ship_id:' . $shipment->getId() . ':' . $shipment->getProtectCode())],
                ]);
                break;
            }
        }

        if ($creditmemo !== null) {
            $extra['refund_amount'] = (float) $creditmemo->getGrandTotal();
        }

        $comment = $vars['comment'] ?? null;

        if (is_scalar($comment) || $comment instanceof \Stringable) {
            $extra['note'] = strip_tags((string) $comment);
        }

        return $model->emailData($extra);
    }

    /** BCP 47 language of the order's store view: the version of the OneTrace template. */
    public function language(int $storeId): ?string
    {
        return Customer::languageTag($this->config->locale($storeId));
    }

    /**
     * @param iterable<\Magento\Sales\Model\Order\Shipment\Item|\Magento\Sales\Model\Order\Creditmemo\Item> $documentItems
     *
     * @return list<array{0: Item, 1: float}>
     */
    private function quantities(iterable $documentItems): array
    {
        $items = [];

        foreach ($documentItems as $documentItem) {
            $item = $documentItem->getOrderItem();

            if ($item instanceof Item && !$item->getParentItemId() && (float) $documentItem->getQty() > 0) {
                $items[] = [$item, (float) $documentItem->getQty()];
            }
        }

        return $items;
    }

    /**
     * @param list<array{0: Item, 1: float}> $items
     *
     * @return list<LineItem>
     */
    private function lines(array $items, int $storeId): array
    {
        $ids = [];

        foreach ($items as [$item]) {
            $ids[] = (int) $item->getProductId();

            foreach ($item->getChildrenItems() as $child) {
                $ids[] = (int) $child->getProductId();
            }
        }

        $products = $this->productData(array_values(array_unique(array_filter($ids))), $storeId);
        $lines = [];

        foreach ($items as [$item, $quantity]) {
            $children = $item->getChildrenItems();
            $child = $children !== [] ? reset($children) : null;
            $parent = $products[(int) $item->getProductId()] ?? [];
            $variant = $child !== null ? ($products[(int) $child->getProductId()] ?? []) : [];
            $ordered = max(1.0, (float) $item->getQtyOrdered());

            $lines[] = (new LineItem(
                (int) $item->getProductId(),
                (string) $item->getName(),
                (float) $item->getRowTotal() / $ordered,
                max(1, (int) round($quantity)),
                $child !== null ? (int) $child->getProductId() : null
            ))->withDisplay([
                'sku' => (string) $item->getSku(),
                'image_url' => $variant['image'] ?? $parent['image'] ?? null,
                'url' => $parent['url'] ?? null,
                'options' => $this->options($item),
            ]);
        }

        return $lines;
    }

    /**
     * Images and storefront links of products in the store view.
     *
     * @param list<int> $ids
     *
     * @return array<int, array{image?: string, url?: string}>
     */
    private function productData(array $ids, int $storeId): array
    {
        if ($ids === []) {
            return [];
        }

        $data = [];

        try {
            $media = rtrim((string) $this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/') . '/catalog/product/';
            $collection = $this->products->create()
                ->setStoreId($storeId)
                ->addIdFilter($ids)
                ->addAttributeToSelect(['thumbnail', 'small_image', 'image', 'url_key', 'visibility'])
                ->addUrlRewrite();

            /** @var Product $product */
            foreach ($collection as $product) {
                $row = [];

                foreach (['thumbnail', 'small_image', 'image'] as $attribute) {
                    $file = (string) $product->getData($attribute);

                    if ($file !== '' && $file !== 'no_selection') {
                        $row['image'] = $media . ltrim($file, '/');
                        break;
                    }
                }

                $path = (string) $product->getData('request_path');

                if ($path !== '' && (int) $product->getVisibility() !== \Magento\Catalog\Model\Product\Visibility::VISIBILITY_NOT_VISIBLE) {
                    $row['url'] = $this->url($storeId, '', ['_direct' => $path]);
                }

                $data[(int) $product->getId()] = $row;
            }
        } catch (\Throwable) {
            // Products without images and links are still listed.
        }

        return $data;
    }

    /** Variant, custom and bundle options of an order line: «Size: M, Color: White». */
    private function options(Item $item): string
    {
        $options = (array) $item->getProductOptions();
        $values = [];

        foreach (['attributes_info', 'options', 'additional_options'] as $key) {
            foreach ((array) ($options[$key] ?? []) as $option) {
                if (is_array($option) && isset($option['label'])) {
                    $value = $option['print_value'] ?? $option['value'] ?? '';
                    $values[] = $option['label'] . ': ' . strip_tags(is_array($value) ? implode(', ', $value) : (string) $value);
                }
            }
        }

        foreach ((array) ($options['bundle_options'] ?? []) as $option) {
            if (is_array($option) && isset($option['label'])) {
                $titles = array_map(static fn ($value): string => is_array($value) ? trim(($value['qty'] ?? 1) . ' × ' . ($value['title'] ?? '')) : '', (array) ($option['value'] ?? []));
                $values[] = $option['label'] . ': ' . implode(', ', array_filter($titles));
            }
        }

        return mb_substr(implode(', ', $values), 0, 500);
    }

    /** The order page of the customer's account; the "Orders and Returns" form for a guest. */
    private function orderUrl(Order $order): ?string
    {
        return $order->getCustomerIsGuest() || !$order->getCustomerId()
            ? $this->url((int) $order->getStoreId(), 'sales/guest/form')
            : $this->url((int) $order->getStoreId(), 'sales/order/view', ['order_id' => (int) $order->getId()]);
    }

    /**
     * A storefront link of the store view (the frontend URL builder: in the admin and cron the default one builds
     * admin links).
     *
     * @param array<string, mixed> $params
     */
    private function url(int $storeId, string $route, array $params = []): ?string
    {
        try {
            /** @var \Magento\Framework\Url $url */
            $url = $this->urlFactory->create();

            return $url->setScope($storeId)->getUrl($route, $params + ['_nosid' => true, '_scope' => $storeId]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function paymentTitle(Order $order): string
    {
        $payment = $order->getPayment();

        if ($payment === null) {
            return '';
        }

        $title = (string) ($payment->getAdditionalInformation('method_title') ?? '');

        if ($title === '') {
            try {
                $title = (string) $payment->getMethodInstance()->getTitle();
            } catch (\Throwable) {
                $title = '';
            }
        }

        return $title;
    }

    /** Instructions of the bank transfer and cash on delivery methods, kept with the order. */
    private function paymentInstructions(Order $order): ?string
    {
        $payment = $order->getPayment();
        $instructions = $payment !== null ? $payment->getAdditionalInformation('instructions') : null;

        return is_string($instructions) && trim($instructions) !== '' ? trim($instructions) : null;
    }

    /**
     * @return array<string, string>|null
     */
    private function address(?Address $address, int $storeId): ?array
    {
        if ($address === null) {
            return null;
        }

        $street = array_values(array_filter(array_map('trim', (array) $address->getStreet())));
        $country = (string) $address->getCountryId();

        if ($country !== '' && class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-' . $country, $this->config->locale($storeId) ?: 'en_US');
            $country = $name !== '' && $name !== $country ? $name : $country;
        }

        return \OneTrace\Commerce\Order::address([
            'name' => trim((string) $address->getFirstname() . ' ' . (string) $address->getLastname()),
            'company' => (string) $address->getCompany(),
            'address1' => $street[0] ?? '',
            'address2' => implode(', ', array_slice($street, 1)),
            'city' => (string) $address->getCity(),
            'region' => (string) $address->getRegion(),
            'postcode' => (string) $address->getPostcode(),
            'country' => $country,
            'phone' => (string) $address->getTelephone(),
        ]);
    }
}
