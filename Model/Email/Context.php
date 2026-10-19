<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model\Email;

use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;

/**
 * What an email is: the template identifier, the template variables (the order, the shipment, the customer …) and
 * the options (store, area) given to the TransportBuilder that made its transport. The rendered message alone does
 * not tell an order confirmation from a password reset.
 */
class Context
{
    /** @var \WeakMap<TransportBuilder, array{identifier?: string, vars?: array<string, mixed>, options?: array<string, mixed>}> */
    private \WeakMap $builders;

    /** @var \WeakMap<TransportInterface, array{identifier?: string, vars?: array<string, mixed>, options?: array<string, mixed>}> */
    private \WeakMap $transports;

    public function __construct()
    {
        $this->builders = new \WeakMap();
        $this->transports = new \WeakMap();
    }

    /**
     * @param 'identifier'|'vars'|'options' $field
     */
    public function remember(TransportBuilder $builder, string $field, mixed $value): void
    {
        $context = $this->builders[$builder] ?? [];
        $context[$field] = $value;
        $this->builders[$builder] = $context;
    }

    /** The builder made a transport (and forgot its template). */
    public function built(TransportBuilder $builder, TransportInterface $transport): void
    {
        if (isset($this->builders[$builder])) {
            $this->transports[$transport] = $this->builders[$builder];
            unset($this->builders[$builder]);
        }
    }

    /**
     * @return array{identifier: string, vars: array<string, mixed>, options: array<string, mixed>}|null
     */
    public function of(TransportInterface $transport): ?array
    {
        $context = $this->transports[$transport] ?? null;

        if ($context === null) {
            return null;
        }

        return [
            'identifier' => is_scalar($context['identifier'] ?? null) ? (string) $context['identifier'] : '',
            'vars' => is_array($context['vars'] ?? null) ? $context['vars'] : [],
            'options' => is_array($context['options'] ?? null) ? $context['options'] : [],
        ];
    }
}
