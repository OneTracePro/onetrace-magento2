<?php

declare(strict_types=1);

namespace OneTrace\Connector\Controller\Adminhtml\Connection;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use OneTrace\Connector\Model\Status;

class Test extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'OneTrace_Connector::config';

    public function __construct(Context $context, private readonly JsonFactory $jsonFactory, private readonly Status $status)
    {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        return $this->jsonFactory->create()->setData(['results' => $this->status->check((int) $this->getRequest()->getParam('store'))]);
    }
}
