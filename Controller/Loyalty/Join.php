<?php

declare(strict_types=1);

namespace OneTrace\Connector\Controller\Loyalty;

use Magento\Customer\Controller\AccountInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Connector\Model\Connection;
use OneTrace\Identity;

/**
 * POST onetrace/loyalty/join: joins the program with explicit enrollment.
 */
class Join implements HttpPostActionInterface, AccountInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CustomerSession $customers,
        private readonly Connection $connection,
        private readonly StoreManagerInterface $stores,
        private readonly FormKeyValidator $formKey,
        private readonly ManagerInterface $messages,
        private readonly RedirectFactory $redirects,
    ) {
    }

    public function execute(): Redirect
    {
        if ($this->formKey->validate($this->request)) {
            try {
                $this->connection->client((int) $this->stores->getStore()->getId())->loyalty()->join(Identity::userId((string) $this->customers->getCustomerId()));
                $this->messages->addSuccessMessage((string) __('Welcome to the loyalty program!'));
            } catch (\Throwable $error) {
                $this->messages->addErrorMessage((string) __('Could not join the program. Please try again later.'));
            }
        }

        return $this->redirects->create()->setPath('onetrace/loyalty');
    }
}
