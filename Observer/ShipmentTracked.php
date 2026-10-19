<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Shipment\Track;
use OneTrace\Connector\Model\Shipments;

/**
 * A tracking number is added to a shipment: a shipment that waits for one is sent with it.
 */
class ShipmentTracked implements ObserverInterface
{
    public function __construct(private readonly Shipments $shipments)
    {
    }

    public function execute(Observer $observer): void
    {
        $track = $observer->getEvent()->getData('track');

        if ($track instanceof Track) {
            $this->shipments->tracked($track);
        }
    }
}
