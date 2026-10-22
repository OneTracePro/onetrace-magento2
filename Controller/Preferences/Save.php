<?php

declare(strict_types=1);

namespace OneTrace\Connector\Controller\Preferences;

use Magento\Customer\Controller\AccountInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Connector\Model\Preferences;

/**
 * POST onetrace/preferences/save: the form of My Account → Communication preferences.
 */
class Save implements HttpPostActionInterface, AccountInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CustomerSession $customers,
        private readonly Preferences $preferences,
        private readonly StoreManagerInterface $stores,
        private readonly FormKeyValidator $formKey,
        private readonly ManagerInterface $messages,
        private readonly RedirectFactory $redirects,
    ) {
    }

    public function execute(): Redirect
    {
        if ($this->formKey->validate($this->request)) {
            $action = $this->request->getParam('onetrace_action') === 'all' ? 'all' : 'save';
            $topics = $this->request->getParam('topics');
            $saved = $this->preferences->save(
                (int) $this->stores->getStore()->getId(),
                (int) $this->customers->getCustomerId(),
                $action,
                \is_array($topics) ? $topics : [],
                (string) $this->request->getParam('frequency', 'default'),
                (string) $this->request->getParam('pause', 'keep'),
            );

            if ($saved) {
                $this->messages->addSuccessMessage((string) ($action === 'all' ? __('You are unsubscribed from all newsletters.') : __('Your communication preferences are saved.')));
            } else {
                $this->messages->addErrorMessage((string) __('Could not save the settings. Please try again later.'));
            }
        }

        return $this->redirects->create()->setPath('onetrace/preferences');
    }
}
