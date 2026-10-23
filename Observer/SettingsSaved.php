<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use OneTrace\Connector\Model\Connection;
use OneTrace\Connector\Model\Email\Settings;
use OneTrace\Connector\Model\Queue;
use OneTrace\Connector\Model\SearchWidget;
use OneTrace\Connector\Model\StoreMcp;

/**
 * Saved settings: new keys are used right away, sending paused by a rejected key resumes, store email settings
 * and the search widget state of the platform are read again, and the store MCP server is connected to the chat bot
 * (or disconnected).
 */
class SettingsSaved implements ObserverInterface
{
    public function __construct(private readonly Queue $queue, private readonly Connection $connection, private readonly Settings $emailSettings, private readonly StoreMcp $storeMcp, private readonly SearchWidget $searchWidget)
    {
    }

    public function execute(Observer $observer): void
    {
        $this->connection->reset();
        $this->queue->resume();
        $this->emailSettings->clean();
        $this->searchWidget->clean();
        $this->storeMcp->connect();
    }
}
