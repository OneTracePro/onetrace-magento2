<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Sales\Model\Order\Config as OrderConfig;

/**
 * Order statuses of the store (Stores → Order Status) for a multi-select, without an empty option.
 */
class OrderStatuses implements OptionSourceInterface
{
    public function __construct(private readonly OrderConfig $orderConfig)
    {
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];

        foreach ($this->orderConfig->getStatuses() as $code => $label) {
            $options[] = ['value' => (string) $code, 'label' => (string) $label];
        }

        return $options;
    }
}
