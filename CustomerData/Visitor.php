<?php

declare(strict_types=1);

namespace OneTrace\Connector\CustomerData;

use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Connector\Model\Connection;
use OneTrace\Connector\Model\StoreMcp;

/**
 * Private section "onetrace": the id of the signed-in customer for the tracker, its signature with the identity key
 * (cdp.identify(id, {}, { userHash }), ADR-158 of the platform) and the customer's token for the AI chat bot
 * (cdp.chatCustomer(), when the store MCP is connected).
 */
class Visitor implements SectionSourceInterface
{
    public function __construct(
        private readonly CurrentCustomer $currentCustomer,
        private readonly StoreMcp $storeMcp,
        private readonly StoreManagerInterface $storeManager,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{id: string, hash: string, chat: string}
     */
    public function getSectionData(): array
    {
        $id = (int) $this->currentCustomer->getCustomerId();
        $storeId = (int) $this->storeManager->getStore()->getId();

        return [
            'id' => $id > 0 ? (string) $id : '',
            'hash' => $id > 0 ? (string) $this->connection->userHash($storeId, (string) $id) : '',
            'chat' => (string) $this->storeMcp->customerToken($storeId, $id),
        ];
    }
}
