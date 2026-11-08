<?php

declare(strict_types=1);

namespace OneTrace\Connector\Block\StoreMcp;

use Magento\Framework\View\Element\Template;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Model\Order;

/**
 * The payment page of an order of the store MCP: products, totals, the delivery and the payment instructions of the
 * payment method (Magento's payment info block, as in the order email).
 */
class OrderPayment extends Template
{
    private ?Order $order = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Template\Context $context,
        private readonly PaymentHelper $paymentHelper,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function setOrder(Order $order): self
    {
        $this->order = $order;

        return $this;
    }

    public function order(): ?Order
    {
        return $this->order;
    }

    public function isPaid(): bool
    {
        return $this->order !== null && (float) $this->order->getTotalDue() <= 0.0;
    }

    public function isCancelled(): bool
    {
        return $this->order !== null && in_array($this->order->getState(), [Order::STATE_CANCELED, Order::STATE_CLOSED], true);
    }

    public function price(float $amount): string
    {
        return $this->order !== null ? (string) $this->order->formatPriceTxt($amount) : '';
    }

    /**
     * The payment info block of the method with its instructions (bank account, payee, cash on delivery terms).
     */
    public function paymentHtml(): string
    {
        $payment = $this->order?->getPayment();

        if (!$payment instanceof InfoInterface) {
            return '';
        }

        try {
            return (string) $this->paymentHelper->getInfoBlockHtml($payment, (int) $this->order->getStoreId());
        } catch (\Throwable $error) {
            $this->_logger->warning('OneTrace: payment page of order ' . $this->order->getIncrementId() . ': ' . $error->getMessage());

            return $this->escapeHtml((string) $payment->getMethod());
        }
    }
}
