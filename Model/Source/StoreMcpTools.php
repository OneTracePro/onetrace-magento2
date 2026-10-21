<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Tools of the store MCP server the AI chat bot may use: reading, then actions.
 */
class StoreMcpTools implements OptionSourceInterface
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $labels = [
            'store_start_verification' => __('Send a confirmation code to the customer'),
            'store_verify' => __('Check the confirmation code'),
            'store_get_customer' => __('Customer name and contacts'),
            'store_find_orders' => __('Orders of the customer'),
            'store_get_order' => __('Order details'),
            'store_get_tracking' => __('Shipment tracking'),
            'store_check_availability' => __('Product availability'),
            'store_get_delivery_options' => __('Shipping methods'),
            'store_get_return_policy' => __('Return policy'),
            'store_cancel_order' => __('Action: cancel an unpaid order'),
            'store_create_return' => __('Action: return request to the staff'),
            'store_change_delivery' => __('Action: delivery change request to the staff'),
        ];
        $options = [];

        foreach ($labels as $value => $label) {
            $options[] = ['value' => $value, 'label' => (string) $label];
        }

        return $options;
    }
}
