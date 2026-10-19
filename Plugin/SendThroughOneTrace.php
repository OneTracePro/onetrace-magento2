<?php

declare(strict_types=1);

namespace OneTrace\Connector\Plugin;

use Magento\Framework\Mail\TransportInterface;
use OneTrace\Connector\Model\Email\StoreEmails;
use Psr\Log\LoggerInterface;

/**
 * Store emails through OneTrace: the outermost plugin of the mail transport (before SMTP extensions and
 * "Disable Email Communications"), so whatever OneTrace does not accept goes out the way the store is set up.
 */
class SendThroughOneTrace
{
    public function __construct(private readonly StoreEmails $emails, private readonly LoggerInterface $logger)
    {
    }

    /**
     * @return mixed
     */
    public function aroundSendMessage(TransportInterface $subject, callable $proceed)
    {
        try {
            $native = $this->emails->deliver($subject);
        } catch (\Throwable $error) {
            $this->logger->error('OneTrace: store email: ' . $error->getMessage());
            $native = true;
        }

        return $native ? $proceed() : null;
    }
}
