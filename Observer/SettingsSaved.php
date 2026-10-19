<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use OneTrace\Connector\Model\Connection;
use OneTrace\Connector\Model\Email\Settings;
use OneTrace\Connector\Model\Queue;

/**
 * Saved settings: new keys are used right away, sending paused by a rejected key resumes, and store email settings
 * of the platform are read again.
 */
class SettingsSaved implements ObserverInterface
{
    public function __construct(private readonly Queue $queue, private readonly Connection $connection, private readonly Settings $emailSettings)
    {
    }

    public function execute(Observer $observer): void
    {
        $this->connection->reset();
        $this->queue->resume();
        $this->emailSettings->clean();
    }
}
