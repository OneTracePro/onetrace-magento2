<?php

declare(strict_types=1);

namespace OneTrace\Connector\Controller\Mcp;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Connector\Model\StoreMcp;

/**
 * POST /onetrace/mcp: the store MCP server for the OneTrace AI chat bot. The platform authenticates with the key of
 * the server (Authorization: Bearer), not with a form key.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly Http $request,
        private readonly RawFactory $rawFactory,
        private readonly StoreMcp $storeMcp,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function execute(): Raw
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $result = $this->rawFactory->create();

        if (!$this->storeMcp->enabled($storeId)) {
            return $result->setHttpResponseCode(404)->setContents('');
        }

        $response = $this->storeMcp->server($storeId)->handle('POST', ['Authorization' => (string) $this->request->getHeader('Authorization')], (string) $this->request->getContent());
        $result->setHttpResponseCode($response['status'])->setHeader('Cache-Control', 'no-store', true);

        foreach ($response['headers'] as $name => $value) {
            $result->setHeader($name, $value, true);
        }

        return $result->setContents($response['body']);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
