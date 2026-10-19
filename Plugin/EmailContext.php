<?php

declare(strict_types=1);

namespace OneTrace\Connector\Plugin;

use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use OneTrace\Connector\Model\Email\Context;

/**
 * Remembers the template of an email built by the TransportBuilder (all emails of Magento and of most extensions)
 * for the transport it makes: the kind of the email and its order come from there.
 */
class EmailContext
{
    public function __construct(private readonly Context $context)
    {
    }

    /**
     * @param string|int $templateIdentifier
     *
     * @return null
     */
    public function beforeSetTemplateIdentifier(TransportBuilder $subject, $templateIdentifier)
    {
        $this->context->remember($subject, 'identifier', $templateIdentifier);

        return null;
    }

    /**
     * @param array<string, mixed>|null $templateVars
     *
     * @return null
     */
    public function beforeSetTemplateVars(TransportBuilder $subject, $templateVars)
    {
        $this->context->remember($subject, 'vars', $templateVars);

        return null;
    }

    /**
     * @param array<string, mixed>|null $templateOptions
     *
     * @return null
     */
    public function beforeSetTemplateOptions(TransportBuilder $subject, $templateOptions)
    {
        $this->context->remember($subject, 'options', $templateOptions);

        return null;
    }

    public function afterGetTransport(TransportBuilder $subject, TransportInterface $result): TransportInterface
    {
        $this->context->built($subject, $result);

        return $result;
    }
}
