<?php

declare(strict_types=1);

namespace OneTrace\Connector\Controller\Loyalty;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use OneTrace\Connector\Model\LoyaltyState;

/**
 * GET onetrace/loyalty/state: the bonus points block of the current cart.
 */
class State implements HttpGetActionInterface
{
    public function __construct(
        private readonly CheckoutSession $session,
        private readonly LoyaltyState $state,
        private readonly JsonFactory $json,
    ) {
    }

    public function execute(): Json
    {
        $result = $this->json->create();
        $result->setHeader('Cache-Control', 'no-store', true);

        return $result->setData(['loyalty' => $this->state->build($this->session->getQuote())]);
    }
}
