<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Newsletter\Model\Subscriber;
use OneTrace\Commerce\Customer;
use OneTrace\Commerce\Messages;
use OneTrace\Connector\Model\Config;
use OneTrace\Connector\Model\Connection;
use OneTrace\Connector\Model\Mapper;
use OneTrace\Connector\Model\Queue;
use Psr\Log\LoggerInterface;

/**
 * The store's newsletter subscription — registration form, account page, footer form, admin — becomes the email
 * consent of the profile. "Not activated" (waiting for the confirmation email) is not a subscription yet.
 */
class NewsletterChanged implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly Mapper $mapper,
        private readonly Queue $queue,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $subscriber = $observer->getEvent()->getData('subscriber');

        if (!$subscriber instanceof Subscriber || !$subscriber->isStatusChanged()) {
            return;
        }

        $storeId = (int) $subscriber->getStoreId();
        $status = (int) $subscriber->getSubscriberStatus();

        if (!$this->config->enabled('newsletter', $storeId) || !in_array($status, [Subscriber::STATUS_SUBSCRIBED, Subscriber::STATUS_UNSUBSCRIBED], true)) {
            return;
        }

        try {
            $topic = $this->config->consentTopic($storeId);
            $consent = $status === Subscriber::STATUS_SUBSCRIBED ? Messages::subscribed('email', $topic) : Messages::unsubscribed('email', $topic);
            $customer = (int) $subscriber->getCustomerId()
                ? $this->mapper->customer((int) $subscriber->getCustomerId())
                : (new Customer(null, $this->connection->anonymousId()))->email((string) $subscriber->getSubscriberEmail());

            $this->queue->event($storeId, $this->connection->messages($storeId)->identify($customer, [$consent]));
        } catch (\Throwable $error) {
            $this->logger->error('OneTrace: newsletter subscriber ' . $subscriber->getId() . ': ' . $error->getMessage());
        }
    }
}
