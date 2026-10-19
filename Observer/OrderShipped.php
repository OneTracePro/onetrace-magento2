<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Shipment;
use OneTrace\Connector\Model\Shipments;

/**
 * A shipment is created (the admin, REST, GraphQL, shipping extensions): order_shipped with its first tracking
 * number, or later, when its first track is added.
 */
class OrderShipped implements ObserverInterface
{
    public function __construct(private readonly Shipments $shipments)
    {
    }

    public function execute(Observer $observer): void
    {
        $shipment = $observer->getEvent()->getData('shipment');

        if ($shipment instanceof Shipment) {
            $this->shipments->saved($shipment);
        }
    }
}
