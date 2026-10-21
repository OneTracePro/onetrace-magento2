<?php

declare(strict_types=1);

namespace OneTrace\Connector\CustomerData;

use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Connector\Model\StoreMcp;

/**
 * Private section "onetrace": the id of the signed-in customer for the tracker and their token for the AI chat bot
 * (cdp.chatCustomer(), when the store MCP is connected).
 */
class Visitor implements SectionSourceInterface
{
    public function __construct(
        private readonly CurrentCustomer $currentCustomer,
        private readonly StoreMcp $storeMcp,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * @return array{id: string, chat: string}
     */
    public function getSectionData(): array
    {
        $id = (int) $this->currentCustomer->getCustomerId();

        return [
            'id' => $id > 0 ? (string) $id : '',
            'chat' => (string) $this->storeMcp->customerToken((int) $this->storeManager->getStore()->getId(), $id),
        ];
    }
}
