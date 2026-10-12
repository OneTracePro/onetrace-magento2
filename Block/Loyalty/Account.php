<?php

declare(strict_types=1);

namespace OneTrace\Connector\Block\Loyalty;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Template;
use OneTrace\Connector\Model\Connection;
use OneTrace\Exception\NotFoundException;
use OneTrace\Identity;

/**
 * My Account → Bonus points: the account of the customer and the latest operations from the platform.
 */
class Account extends Template
{
    /** @var array{account: array<string, mixed>, operations: list<array<string, mixed>>}|false|null */
    private $data = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Template\Context $context,
        private readonly CustomerSession $customers,
        private readonly Connection $connection,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array{account: array<string, mixed>, operations: list<array<string, mixed>>}|false false — the platform did not answer
     */
    public function loyalty(): array|false
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $profile = Identity::userId((string) $this->customers->getCustomerId());
        $client = $this->connection->client((int) $this->_storeManager->getStore()->getId());

        try {
            $account = $client->loyalty()->account($profile['type'], $profile['value']);
            $operations = !empty($account['member']) ? $client->loyalty()->transactions($profile['type'], $profile['value'], ['limit' => 10])->getData() : [];
        } catch (NotFoundException $error) {
            $account = ['member' => false];
            $operations = [];
        } catch (\Throwable $error) {
            return $this->data = false;
        }

        return $this->data = ['account' => $account, 'operations' => array_values($operations)];
    }

    public function operation(string $type): string
    {
        $labels = [
            'earn' => __('Earned'),
            'cancel' => __('Earning cancelled'),
            'expire' => __('Expired'),
            'adjust' => __('Adjustment'),
            'redeem' => __('Used in an order'),
            'refund' => __('Returned'),
        ];

        return (string) ($labels[$type] ?? $type);
    }

    /** The reference of a hold is the cart of the store — not useful to the customer. */
    public function reference(string $reference): string
    {
        return str_starts_with($reference, 'm2:') ? '' : $reference;
    }

    public function date(string $iso): string
    {
        return $this->formatDate($iso, \IntlDateFormatter::MEDIUM);
    }

    public function joinUrl(): string
    {
        return $this->getUrl('onetrace/loyalty/join');
    }
}
