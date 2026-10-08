<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use OneTrace\Connector\Model\Connection;
use OneTrace\Connector\Model\Queue;

/**
 * Saved settings: new keys are used right away, and sending paused by a rejected key resumes.
 */
class SettingsSaved implements ObserverInterface
{
    public function __construct(private readonly Queue $queue, private readonly Connection $connection)
    {
    }

    public function execute(Observer $observer): void
    {
        $this->connection->reset();
        $this->queue->resume();
    }
}
