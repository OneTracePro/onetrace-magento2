<?php

declare(strict_types=1);

namespace OneTrace\Connector\Test\Integration;

use OneTrace\Connector\Model\Queue;
use OneTrace\Connector\Test\TestCase;

final class QueueTest extends TestCase
{
    public function testKeepsMessagesForARetryAndPausesOnARejectedKey(): void
    {
        $queue = $this->om->get(Queue::class);
        $queue->event(1, ['type' => 'track', 'userId' => '1', 'event' => 'x']);
        $this->platform->push(503, ['message' => 'down']);

        self::assertSame(['sent' => 0, 'retried' => 1, 'dropped' => 0], $this->flush());
        self::assertSame(['waiting' => 1, 'retrying' => 1], $queue->stats());

        $queue->event(1, ['type' => 'track', 'userId' => '1', 'event' => 'y']);
        $this->platform->push(401, ['message' => 'Invalid key']);
        $this->flush();

        self::assertTrue($queue->paused(1));
        self::assertSame(['sent' => 0, 'retried' => 0, 'dropped' => 0], $this->flush(), 'Nothing is sent until the settings are fixed.');
    }

    public function testDropsOnlyTheInvalidMessageOfABatch(): void
    {
        $queue = $this->om->get(Queue::class);
        $queue->event(1, ['type' => 'track', 'userId' => '1', 'event' => 'good']);
        $queue->event(1, ['type' => 'track', 'userId' => '1', 'event' => 'bad']);
        $this->platform->responder = static function (\OneTrace\Http\Request $request): ?\OneTrace\Http\Response {
            return str_contains((string) $request->getBody(), '"bad"') ? new \OneTrace\Http\Response(422, ['content-type' => 'application/json'], '{"message":"invalid","errors":{}}') : null;
        };

        self::assertSame(['sent' => 1, 'retried' => 0, 'dropped' => 1], $this->flush());
    }
}
