<?php

declare(strict_types=1);

namespace OneTrace\Connector\Test\Integration;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use OneTrace\Connector\Cron\Shipments;
use OneTrace\Connector\Test\TestCase;

final class ShipmentsTest extends TestCase
{
    public function testSendsEachParcelOnceWithItsCarrierTrackingNumberAndProducts(): void
    {
        $product = $this->product(['price' => 40.0]);
        $order = $this->order($product, 2);
        $item = (int) $order->getAllVisibleItems()[0]->getItemId();

        $first = $this->ship($order, [$item => 1], ['title' => 'DHL Express', 'carrier_code' => 'custom', 'number' => 'JD0146 0001']);
        $second = $this->ship($order, [$item => 1], ['title' => '', 'carrier_code' => 'ups', 'number' => '1Z999AA10123456784']);

        // Saving again (a comment, an email flag) is not a new parcel.
        $this->load($first)->addComment('Packed')->save();
        $this->flush();

        $shipped = $this->shipped($order);

        self::assertCount(2, $shipped);
        self::assertSame($order->getIncrementId(), $shipped[0]['properties']['order_id']);
        self::assertSame('DHL Express', $shipped[0]['properties']['carrier']);
        self::assertSame('JD0146 0001', $shipped[0]['properties']['tracking_number']);
        self::assertArrayNotHasKey('tracking_url', $shipped[0]['properties']);
        self::assertSame((string) $product->getId(), $shipped[0]['properties']['products'][0]['product_id']);
        self::assertSame(1, $shipped[0]['properties']['products'][0]['quantity']);
        self::assertEquals(40.0, $shipped[0]['properties']['products'][0]['price']);
        self::assertStringEndsWith(':order_shipped:' . $first->getIncrementId(), $shipped[0]['messageId']);
        self::assertSame('ups', $shipped[1]['properties']['carrier'], 'Without a title: the carrier code.');
        self::assertStringEndsWith(':order_shipped:' . $second->getIncrementId(), $shipped[1]['messageId']);

        foreach ($shipped as $message) {
            self::assertContract($message);
        }
    }

    public function testAShipmentWithoutATrackIsSentWithTheFirstTrackAddedLater(): void
    {
        $order = $this->order($this->product());
        $shipment = $this->ship($order);
        $this->flush();
        self::assertSame([], $this->shipped($order), 'Waits for a tracking number.');

        // The "Add tracking number" button of the shipment page.
        $this->addTrack($shipment, 'DPD', '05212345678901');
        $this->addTrack($shipment, 'DPD', '05212345678902');
        $this->flush();

        $shipped = $this->shipped($order);

        self::assertCount(1, $shipped);
        self::assertSame('DPD', $shipped[0]['properties']['carrier']);
        self::assertSame('05212345678901', $shipped[0]['properties']['tracking_number']);
        self::assertStringEndsWith(':order_shipped:' . $shipment->getIncrementId(), $shipped[0]['messageId']);
        self::assertContract($shipped[0]);
    }

    public function testCronSendsAShipmentThatGotNoTrackWithinAnHour(): void
    {
        $order = $this->order($this->product());
        $shipment = $this->ship($order);
        $cron = $this->om->get(Shipments::class);

        $cron->execute();
        $this->flush();
        self::assertSame([], $this->shipped($order), 'A new shipment still waits.');

        $resource = $this->om->get(ResourceConnection::class);
        $resource->getConnection('sales')->update($resource->getTableName('sales_shipment', 'sales'), ['created_at' => gmdate('Y-m-d H:i:s', time() - 3700)], ['entity_id = ?' => (int) $shipment->getId()]);
        $cron->execute();
        $cron->execute();
        $this->addTrack($shipment, 'DPD', '05212345678903');
        $this->flush();

        $shipped = $this->shipped($order);

        self::assertCount(1, $shipped);
        self::assertArrayNotHasKey('tracking_number', $shipped[0]['properties']);
        self::assertContract($shipped[0]);
    }

    public function testOrderDeliveredOnceWhenTheOrderMovesToADeliveredStatus(): void
    {
        $order = $this->order($this->product());
        $this->setStatus($order, 'delivered');
        $this->flush();
        self::assertSame([], $this->platform->events('order_delivered'), 'No statuses are set by default.');

        $writer = $this->om->get(\Magento\Config\Model\ResourceModel\Config::class);
        $writer->saveConfig('onetrace/parts/delivered_statuses', 'delivered,picked_up');
        $this->reinit();

        try {
            $order = $this->order($this->product());
            $this->setStatus($order, 'processing');
            $this->setStatus($order, 'delivered');
            $this->load($order)->addCommentToStatusHistory('Signed by the customer')->save();
            $this->setStatus($order, 'picked_up');
            $this->flush();

            $delivered = $this->platform->events('order_delivered');

            self::assertCount(1, $delivered);
            self::assertSame(['order_id' => $order->getIncrementId()], $delivered[0]['properties']);
            self::assertContract($delivered[0]);
        } finally {
            $writer->deleteConfig('onetrace/parts/delivered_statuses');
            $this->reinit();
        }
    }

    /**
     * A shipment through the API of Magento (the admin form and REST use the same factory).
     *
     * @param array<int, int> $items order item id → quantity; empty — everything
     * @param array{title: string, carrier_code: string, number: string}|null $track
     */
    private function ship(Order $order, array $items = [], ?array $track = null): Shipment
    {
        $itemFactory = $this->om->get(ShipmentItemCreationInterfaceFactory::class);
        $lines = [];

        foreach ($items as $id => $quantity) {
            $lines[] = $itemFactory->create()->setOrderItemId($id)->setQty($quantity);
        }

        $tracks = $track === null ? [] : [$this->om->get(ShipmentTrackCreationInterfaceFactory::class)->create()
            ->setTitle($track['title'])->setCarrierCode($track['carrier_code'])->setTrackNumber($track['number'])];
        $id = $this->om->get(ShipOrderInterface::class)->execute((int) $order->getId(), $lines, false, false, null, $tracks);

        /** @var Shipment */
        return $this->om->create(ShipmentRepositoryInterface::class)->get((int) $id);
    }

    /**
     * order_shipped of one order: cron also sends shipments of other tests that are still waiting.
     *
     * @return list<array<string, mixed>>
     */
    private function shipped(Order $order): array
    {
        return array_values(array_filter($this->platform->events('order_shipped'), static fn (array $message): bool => $message['properties']['order_id'] === $order->getIncrementId()));
    }

    private function addTrack(Shipment $shipment, string $title, string $number): void
    {
        $track = $this->om->get(TrackFactory::class)->create()->setNumber($number)->setCarrierCode('custom')->setTitle($title);
        $this->load($shipment)->addTrack($track)->save();
    }

    private function setStatus(Order $order, string $status): void
    {
        $this->om->create(OrderRepositoryInterface::class)->save($this->load($order)->setStatus($status));
    }

    /**
     * @template T of Order|Shipment
     *
     * @param T $entity
     *
     * @return T
     */
    private function load(Order|Shipment $entity): Order|Shipment
    {
        return $this->om->create($entity::class)->load($entity->getId());
    }
}
