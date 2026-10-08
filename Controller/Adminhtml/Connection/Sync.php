<?php

declare(strict_types=1);

namespace OneTrace\Connector\Controller\Adminhtml\Connection;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use OneTrace\Connector\Model\CatalogSync;

class Sync extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'OneTrace_Connector::config';

    public function __construct(Context $context, private readonly JsonFactory $jsonFactory, private readonly CatalogSync $sync)
    {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $count = $this->sync->queueAll();

        return $this->jsonFactory->create()->setData(['results' => [['ok' => true, 'message' => (string) __('%1 products are queued; cron uploads them in the background.', $count)]]]);
    }
}
