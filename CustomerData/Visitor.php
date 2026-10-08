<?php

declare(strict_types=1);

namespace OneTrace\Connector\CustomerData;

use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Customer\Helper\Session\CurrentCustomer;

/**
 * Private section "onetrace": the id of the signed-in customer for the tracker.
 */
class Visitor implements SectionSourceInterface
{
    public function __construct(private readonly CurrentCustomer $currentCustomer)
    {
    }

    /**
     * @return array{id: string}
     */
    public function getSectionData(): array
    {
        return ['id' => $this->currentCustomer->getCustomerId() ? (string) $this->currentCustomer->getCustomerId() : ''];
    }
}
