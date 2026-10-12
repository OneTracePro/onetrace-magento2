<?php

declare(strict_types=1);

namespace OneTrace\Connector\Test\Integration;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Service\InvoiceService;
use OneTrace\Connector\Model\Loyalty;
use OneTrace\Connector\Model\LoyaltyState;
use OneTrace\Connector\Test\TestCase;
use OneTrace\Http\Request;
use OneTrace\Http\Response;

/**
 * Bonus points: the hold and the onetrace_loyalty total of the quote, the hold again before the order when the cart
 * shrank, the confirmation with the increment id, the share in the invoice and a partial credit memo, the block state.
 */
final class LoyaltyTest extends TestCase
{
    protected const SETTINGS = parent::SETTINGS + ['onetrace/parts/loyalty' => 1];

    private int $holds = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform->responder = function (Request $request): ?Response {
            $path = (string) parse_url($request->getUrl(), PHP_URL_PATH);
            $body = json_decode((string) $request->getBody(), true);

            if ($path === '/api/v1/loyalty/calculate') {
                $max = (int) floor($body['amount'] * 0.3);

                return $this->json(200, ['member' => true, 'balance' => 500, 'max_points' => $max, 'max_discount' => $max, 'earn_points' => 4, 'point_value' => 1, 'currency' => 'USD']);
            }

            if ($path === '/api/v1/loyalty/redemptions') {
                $this->holds++;

                return $this->json(201, ['id' => 'r' . $this->holds, 'status' => 'held', 'points' => $body['points'], 'discount' => (float) $body['points'], 'held_until' => gmdate('c', time() + 1800)]);
            }

            if (preg_match('#/confirm$#', $path) === 1) {
                return $this->json(200, ['status' => 'confirmed']);
            }

            return null;
        };
    }

    public function testHoldsThePointsAndDiscountsTheCartTotals(): void
    {
        $quote = $this->customerQuote(2);
        $loyalty = $this->om->get(Loyalty::class);

        self::assertSame(29, $loyalty->offer($quote)['max_points']);
        $loyalty->apply($quote, 25);

        $hold = $this->request('/api/v1/loyalty/redemptions');
        self::assertSame('user_id', $hold['body']['profile']['type']);
        self::assertSame((string) $quote->getCustomerId(), $hold['body']['profile']['value']);
        self::assertSame(99.9, $hold['body']['amount']);
        self::assertSame('m2:' . $quote->getId(), $hold['body']['cart_id']);
        // As the cart totals of REST and the storefront read them.
        $segments = $this->om->get(\Magento\Quote\Model\Quote\TotalsReader::class)->fetch($quote, $quote->getShippingAddress()->getData());
        self::assertSame(-25.0, (float) $segments['onetrace_loyalty']->getValue());
        self::assertSame('Bonus points (25)', (string) $segments['onetrace_loyalty']->getTitle());
        self::assertEqualsWithDelta(99.9 + 10 - 25, (float) $quote->getGrandTotal(), 0.001, 'Flat rate shipping 2 × 5.');

        $state = $this->om->get(LoyaltyState::class)->build($quote);
        self::assertStringContainsString('25', (string) $state['applied']);

        $loyalty->remove($quote);
        self::assertEqualsWithDelta(109.9, (float) $quote->getGrandTotal(), 0.001);
        self::assertNotNull($this->request('/api/v1/loyalty/redemptions/r1/cancel'));
    }

    public function testHoldsAgainBeforeTheOrderConfirmsItAndSplitsTheDiscountInInvoiceAndCreditMemo(): void
    {
        $quote = $this->customerQuote(2);
        $this->om->get(Loyalty::class)->apply($quote, 29);

        // The cart shrinks before the order: 49.95 × 30 % → 14 points, held again by placeOrder.
        $item = $quote->getAllVisibleItems()[0];
        $item->setQty(1);
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        $this->om->get(CartRepositoryInterface::class)->save($quote);

        $orderId = $this->om->get(CartManagementInterface::class)->placeOrder((int) $quote->getId());
        /** @var Order $order */
        $order = $this->om->get(OrderRepositoryInterface::class)->get((int) $orderId);

        self::assertSame(14, (int) $order->getData('onetrace_loyalty_points'));
        self::assertSame(14.0, (float) $order->getData('onetrace_loyalty_discount'));
        self::assertEqualsWithDelta(49.95 + 5 - 14, (float) $order->getGrandTotal(), 0.001);
        self::assertSame(['order_id' => (string) $order->getIncrementId()], $this->request('/api/v1/loyalty/redemptions/r2/confirm')['body']);
        self::assertNotEmpty($this->om->get(OrderRepositoryInterface::class)->get((int) $orderId)->getData('onetrace_loyalty_confirmed_at'));

        $invoice = $this->om->get(InvoiceService::class)->prepareInvoice($order);
        $invoice->register();
        self::assertSame(14.0, (float) $invoice->getData('onetrace_loyalty_discount'));
        self::assertEqualsWithDelta((float) $order->getGrandTotal(), (float) $invoice->getGrandTotal(), 0.001);
        $invoice->getOrder()->setIsInProcess(true);
        $invoice->save();
        $order->save();

        $creditmemo = $this->om->get(CreditmemoFactory::class)->createByOrder($order, ['qtys' => [(int) $order->getAllVisibleItems()[0]->getId() => 1], 'shipping_amount' => 0]);
        self::assertSame(14.0, (float) $creditmemo->getData('onetrace_loyalty_discount'));
        self::assertEqualsWithDelta(49.95 - 14, (float) $creditmemo->getGrandTotal(), 0.001);
    }

    public function testAGuestCartGetsTheSignInHint(): void
    {
        $quote = $this->om->get(CartRepositoryInterface::class)->get($this->om->get(CartManagementInterface::class)->createEmptyCart());
        $quote->setStoreId(1)->addProduct($this->om->get(ProductRepositoryInterface::class)->get($this->product()->getSku(), false, 1, true), 1);
        $quote->collectTotals();

        self::assertArrayHasKey('link', (array) $this->om->get(LoyaltyState::class)->build($quote));
        self::assertNull($this->om->get(Loyalty::class)->offer($quote));
    }

    /**
     * A signed-in customer's cart with the addresses, flat rate shipping and check/money order.
     */
    private function customerQuote(int $quantity): Quote
    {
        $customer = $this->om->get(CustomerInterfaceFactory::class)->create();
        $customer->setWebsiteId(1)->setStoreId(1)->setEmail('loyal-' . bin2hex(random_bytes(4)) . '@example.com')->setFirstname('Mary')->setLastname('Tester');
        $customer = $this->om->get(AccountManagementInterface::class)->createAccount($customer);

        $quotes = $this->om->get(CartRepositoryInterface::class);
        $quote = $quotes->get($this->om->get(CartManagementInterface::class)->createEmptyCartForCustomer((int) $customer->getId()));
        $quote->setStoreId(1);
        $quote->addProduct($this->om->get(ProductRepositoryInterface::class)->get($this->product()->getSku(), false, 1, true), $quantity);
        $address = ['firstname' => 'Mary', 'lastname' => 'Tester', 'street' => 'Torstraße 1', 'city' => 'Berlin', 'country_id' => 'DE', 'postcode' => '10119', 'telephone' => '+49 151 1234-5678'];
        $quote->getBillingAddress()->addData($address);
        $quote->getShippingAddress()->addData($address)->setCollectShippingRates(true)->collectShippingRates()->setShippingMethod('flatrate_flatrate');
        $quote->getPayment()->importData(['method' => 'checkmo']);
        $quote->collectTotals();
        $quotes->save($quote);

        return $quotes->get((int) $quote->getId());
    }

    /**
     * @return array{method: string, path: string, body: mixed}|null
     */
    private function request(string $path): ?array
    {
        foreach (array_reverse($this->platform->requests) as $request) {
            if ($request['path'] === $path) {
                return $request;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): Response
    {
        return new Response($status, ['content-type' => 'application/json'], (string) json_encode($body));
    }
}
