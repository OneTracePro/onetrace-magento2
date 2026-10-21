<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model\StoreMcp;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\StoreMcp\Context;
use OneTrace\StoreMcp\ToolError;

/**
 * Customers of the store MCP server: "customer:<id>" (an account) or "email:<address>" (guest orders with that
 * email). A customer sees the orders of the account and the guest orders placed with its email.
 */
class Customers
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly CollectionFactory $orders,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
        private readonly EventManager $events,
    ) {
    }

    /**
     * The account id and the email of a customer of the server.
     *
     * @return array{0: int|null, 1: string|null}
     */
    public function who(string $customer): array
    {
        if (preg_match('/^customer:(\d+)$/', $customer, $match) === 1) {
            try {
                return [(int) $match[1], (string) $this->customers->getById((int) $match[1])->getEmail()];
            } catch (NoSuchEntityException) {
                return [null, null];
            }
        }

        return [null, preg_match('/^email:(.+@.+)$/', $customer, $match) === 1 ? $match[1] : null];
    }

    /**
     * Who asks for a code: the account with this email in the website of the store view, otherwise the latest order
     * with this email or billing phone. With an order number the order must be theirs.
     */
    public function find(string $contact, ?string $order): ?string
    {
        $contact = trim($contact);
        $email = str_contains($contact, '@') ? strtolower($contact) : '';
        $customer = null;

        if ($email !== '') {
            try {
                $customer = 'customer:' . (int) $this->customers->get($email, (int) $this->storeManager->getStore()->getWebsiteId())->getId();
            } catch (NoSuchEntityException) {
                $customer = null;
            }
        }

        if ($customer === null) {
            $collection = $this->orders->create()->setOrder('entity_id', 'DESC')->setPageSize(1);

            if ($email !== '') {
                $collection->addFieldToFilter('customer_email', $email);
            } else {
                $digits = (string) preg_replace('/\D+/', '', $contact);

                if (strlen($digits) < 6) {
                    return null;
                }

                $collection->getSelect()->join(
                    ['billing' => $collection->getTable('sales_order_address')],
                    "billing.parent_id = main_table.entity_id AND billing.address_type = 'billing'",
                    []
                )->where("REGEXP_REPLACE(billing.telephone, '[^0-9]', '') = ?", $digits);
            }

            $first = $collection->getFirstItem();

            if (!$first instanceof Order || !$first->getId()) {
                return null;
            }

            $customer = $first->getCustomerId() ? 'customer:' . (int) $first->getCustomerId() : 'email:' . strtolower((string) $first->getCustomerEmail());
        }

        if ($order !== null && trim($order) !== '') {
            try {
                $this->order(['order' => $order], new Context($customer, 'en', null, false, null));
            } catch (ToolError) {
                return null;
            }
        }

        return $customer;
    }

    /**
     * The code by email from the "Confirmation code email" template; an observer of onetrace_store_mcp_send_code
     * may send it itself (e.g. by SMS) and set "sent" of the transport.
     */
    public function sendCode(string $customerId, string $contact, string $code, string $channel): void
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $transport = new DataObject(['customer_id' => $customerId, 'contact' => $contact, 'code' => $code, 'channel' => $channel, 'store_id' => $storeId, 'sent' => false]);
        $this->events->dispatch('onetrace_store_mcp_send_code', ['transport' => $transport]);

        if ($transport->getData('sent') === true) {
            return;
        }

        if ($channel !== 'email') {
            throw new ToolError(ToolError::UNAVAILABLE, (string) __('The store sends codes only by email.'));
        }

        $this->inlineTranslation->suspend();

        try {
            $this->transportBuilder
                ->setTemplateIdentifier((string) $this->scopeConfig->getValue('onetrace/store_mcp/code_template', ScopeInterface::SCOPE_STORE, $storeId))
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars(['code' => $code, 'store' => $this->storeManager->getStore($storeId)])
                ->setFromByScope((string) $this->scopeConfig->getValue('onetrace/store_mcp/code_identity', ScopeInterface::SCOPE_STORE, $storeId), $storeId)
                ->addTo($contact)
                ->getTransport()
                ->sendMessage();
        } finally {
            $this->inlineTranslation->resume();
        }
    }

    /**
     * Orders of the customer, newest first.
     */
    public function orders(string $customer): Collection
    {
        $collection = $this->orders->create()->setOrder('created_at', 'DESC')->setOrder('entity_id', 'DESC');
        [$id, $email] = $this->who($customer);
        $db = $collection->getConnection();
        $owners = [];

        if ($id !== null) {
            $owners[] = $db->quoteInto('main_table.customer_id = ?', $id);
        }

        if ($email !== null) {
            $owners[] = $db->quoteInto('(main_table.customer_id IS NULL AND main_table.customer_email = ?)', $email);
        }

        $collection->getSelect()->where($owners !== [] ? implode(' OR ', $owners) : '1 = 0');

        return $collection;
    }

    /**
     * The order of the customer by its number; an order of someone else is "not found".
     *
     * @param array<string, mixed> $args
     */
    public function order(array $args, Context $ctx): Order
    {
        $number = is_scalar($args['order'] ?? null) ? trim((string) $args['order'], " #\t") : '';
        $order = $number !== '' ? $this->orders((string) $ctx->customerId)->addFieldToFilter('increment_id', $number)->setPageSize(1)->getFirstItem() : null;

        if (!$order instanceof Order || !$order->getId()) {
            throw new ToolError(ToolError::NOT_FOUND, (string) __('No such order of yours.'));
        }

        return $order;
    }
}
