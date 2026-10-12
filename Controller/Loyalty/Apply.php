<?php

declare(strict_types=1);

namespace OneTrace\Connector\Controller\Loyalty;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use OneTrace\Connector\Model\Loyalty;
use OneTrace\Connector\Model\LoyaltyState;

/**
 * POST onetrace/loyalty/apply (mode = apply | remove, points, form_key): holds or removes the points of the cart.
 */
class Apply implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CheckoutSession $session,
        private readonly Loyalty $loyalty,
        private readonly LoyaltyState $state,
        private readonly FormKeyValidator $formKey,
        private readonly JsonFactory $json,
    ) {
    }

    public function execute(): Json
    {
        $result = $this->json->create();
        $quote = $this->session->getQuote();

        if (!$this->formKey->validate($this->request) || !$this->loyalty->enabled((int) $quote->getStoreId())) {
            return $result->setHttpResponseCode(403)->setData(['message' => (string) __('Please reload the page and try again.')]);
        }

        try {
            $this->request->getParam('mode') === 'remove'
                ? $this->loyalty->remove($quote)
                : $this->loyalty->apply($quote, max(0, (int) $this->request->getParam('points')));
        } catch (LocalizedException $error) {
            return $result->setHttpResponseCode(422)->setData(['message' => $error->getMessage()]);
        }

        return $result->setData(['loyalty' => $this->state->build($quote)]);
    }
}
