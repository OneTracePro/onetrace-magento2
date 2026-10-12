<?php

declare(strict_types=1);

namespace OneTrace\Connector\Controller\Loyalty;

use Magento\Customer\Controller\AccountInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * My Account → Bonus points (signed-in customers only: AccountInterface).
 */
class Index implements HttpGetActionInterface, AccountInterface
{
    public function __construct(private readonly PageFactory $pages)
    {
    }

    public function execute(): Page
    {
        $page = $this->pages->create();
        $page->getConfig()->getTitle()->set((string) __('Bonus points'));

        return $page;
    }
}
