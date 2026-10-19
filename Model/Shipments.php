<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * order_shipped once per shipment (parcel), with the carrier and the tracking number of its first track.
 *
 * Tracks are often added after the shipment is saved (the "Add tracking number" button of the shipment page, the
 * REST API, shipping extensions), so a shipment without a track waits for one: the first saved track sends it.
 * One that gets no track in WAIT seconds is sent by cron without it. The state is kept in
 * sales_shipment.onetrace_shipped, and the messageId of the event is the shipment's increment id.
 */
class Shipments
{
    /** Shipments made before the module (or with orders off): their later tracks are not sent. */
    public const UNKNOWN = 0;

    public const WAITING = 1;

    /** Sent (or skipped: an order without a customer to identify). */
    public const SENT = 2;

    /** How long a shipment without a tracking number waits for one, seconds. */
    public const WAIT = 3600;

    private const COLUMN = 'onetrace_shipped';

    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly Mapper $mapper,
        private readonly Queue $queue,
        private readonly ResourceConnection $resource,
        private readonly CollectionFactory $shipments,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * A shipment is saved. Its own tracks are saved right after this (sales_order_shipment_save_after comes
     * before the relations), so the tracks of a new shipment are taken from the object.
     */
    public function saved(Shipment $shipment): void
    {
        $order = $shipment->getOrder();

        if ($shipment->getOrigData('entity_id') !== null || !$this->config->enabled('orders', (int) $order->getStoreId())) {
            return;
        }

        $track = null;

        foreach ((array) $shipment->getTracks() as $candidate) {
            if ($candidate instanceof Track && !$candidate->isDeleted() && trim((string) $candidate->getTrackNumber()) !== '') {
                $track = $candidate;
                break;
            }
        }

        if ($track === null) {
            $this->state($shipment, self::WAITING, self::UNKNOWN);

            return;
        }

        $this->send($shipment, $track);
    }

    /**
     * A new track of a shipment that waits for one.
     */
    public function tracked(Track $track): void
    {
        if ($track->getOrigData('entity_id') !== null || trim((string) $track->getTrackNumber()) === '' || !$track->getParentId()) {
            return;
        }

        $db = $this->resource->getConnection('sales');
        $state = $db->fetchOne($db->select()->from($this->resource->getTableName('sales_shipment', 'sales'), [self::COLUMN])->where('entity_id = ?', (int) $track->getParentId()));

        if ((int) $state === self::WAITING) {
            $this->send($track->getShipment(), $track);
        }
    }

    /**
     * Cron: shipments that got no tracking number in WAIT seconds are sent without it.
     */
    public function due(int $limit = 100): void
    {
        $shipments = $this->shipments->create()
            ->addFieldToFilter(self::COLUMN, self::WAITING)
            ->addFieldToFilter('created_at', ['lteq' => gmdate('Y-m-d H:i:s', time() - self::WAIT)])
            ->setPageSize($limit);

        foreach ($shipments as $shipment) {
            $this->send($shipment, null);
        }
    }

    private function send(Shipment $shipment, ?Track $track): void
    {
        $order = $shipment->getOrder();

        try {
            // Taken by one request only: a track saved twice or cron running alongside does not send it again.
            if (!$this->state($shipment, self::SENT)) {
                return;
            }

            $model = $this->mapper->order($order);

            if ($model->customer->ids() === []) {
                return;
            }

            $storeId = (int) $order->getStoreId();
            $this->queue->event($storeId, $this->connection->messages($storeId)->orderShipped(
                $model,
                $track !== null ? $this->carrier($track) : null,
                $track !== null ? trim((string) $track->getTrackNumber()) : null,
                null,
                null,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                (string) ($shipment->getIncrementId() ?: $shipment->getId()),
                $this->mapper->shipmentLines($shipment)
            ));
        } catch (\Throwable $error) {
            $this->logger->error('OneTrace: shipped order ' . $order->getIncrementId() . ': ' . $error->getMessage());
        }
    }

    /**
     * The carrier as the customer sees it: the title of the track ("Federal Express", or what the merchant typed for
     * a custom carrier), otherwise the carrier code of a known carrier.
     */
    private function carrier(Track $track): ?string
    {
        $title = trim((string) $track->getTitle());
        $code = trim((string) $track->getCarrierCode());

        return $title !== '' ? $title : ($code !== '' && $code !== Track::CUSTOM_CARRIER_CODE ? $code : null);
    }

    /**
     * Moves the shipment to $state (from $from, or from any other state); true if this call moved it.
     */
    private function state(Shipment $shipment, int $state, ?int $from = null): bool
    {
        $db = $this->resource->getConnection('sales');
        $where = ['entity_id = ?' => (int) $shipment->getId()];
        $where[$from === null ? self::COLUMN . ' <> ?' : self::COLUMN . ' = ?'] = $from ?? $state;
        $moved = $db->update($this->resource->getTableName('sales_shipment', 'sales'), [self::COLUMN => $state], $where) > 0;

        if ($moved) {
            $shipment->setData(self::COLUMN, $state);
        }

        return $moved;
    }
}
