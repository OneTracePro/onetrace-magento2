<?php

declare(strict_types=1);

namespace OneTrace\Connector\Block\Preferences;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Template;
use OneTrace\Connector\Model\Preferences;

/**
 * My Account → Communication preferences: topics by channel, frequency and pause from the OneTrace preference center.
 */
class Account extends Template
{
    /** @var array<string, mixed>|false|null */
    private $state = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Template\Context $context,
        private readonly CustomerSession $customers,
        private readonly Preferences $preferences,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<string, mixed>|false false — the platform did not answer
     */
    public function state(): array|false
    {
        if ($this->state === null) {
            $this->state = $this->preferences->state((int) $this->_storeManager->getStore()->getId(), (int) $this->customers->getCustomerId()) ?? false;
        }

        return $this->state;
    }

    /**
     * @return list<array{key: string, name: string, blocked: bool}>
     */
    public function channels(): array
    {
        $state = $this->state();
        $channels = [];

        foreach ($state !== false && \is_array($state['channels'] ?? null) ? $state['channels'] : [] as $channel) {
            if (\is_array($channel) && \is_string($channel['key'] ?? null)) {
                $name = \is_string($channel['name'] ?? null) ? $channel['name'] : $channel['key'];
                $channels[] = ['key' => $channel['key'], 'name' => mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1), 'blocked' => !empty($channel['blocked'])];
            }
        }

        return $channels;
    }

    /**
     * @return list<array{key: string, name: string, description: string, channels: array<string, bool|null>}>
     */
    public function topics(): array
    {
        $state = $this->state();
        $topics = [];

        foreach ($state !== false && \is_array($state['topics'] ?? null) ? $state['topics'] : [] as $topic) {
            if (\is_array($topic) && \is_string($topic['key'] ?? null)) {
                $topics[] = [
                    'key' => $topic['key'],
                    'name' => \is_string($topic['name'] ?? null) ? $topic['name'] : $topic['key'],
                    'description' => \is_string($topic['description'] ?? null) ? trim($topic['description']) : '',
                    'channels' => array_map(static fn ($value): ?bool => $value === null ? null : (bool) $value, (array) ($topic['channels'] ?? [])),
                ];
            }
        }

        return $topics;
    }

    public function frequency(): string
    {
        $state = $this->state();

        return $state !== false && \in_array($state['frequency'] ?? null, ['weekly', 'monthly'], true) ? (string) $state['frequency'] : 'default';
    }

    /**
     * Choices of the pause: keep or resume a running pause, or pause for one of the periods.
     *
     * @return array<string, string>
     */
    public function pauses(): array
    {
        $state = $this->state();
        $paused = $state !== false && \is_string($state['paused_until'] ?? null) ? $state['paused_until'] : null;
        $pauses = $paused !== null
            ? ['keep' => (string) __('Paused until %1', $this->formatDate($paused, \IntlDateFormatter::MEDIUM)), 'none' => (string) __('Resume now')]
            : ['none' => (string) __('No pause')];

        foreach (Preferences::PAUSE_DAYS as $days) {
            $pauses[(string) $days] = (string) __('Pause for %1 days', $days);
        }

        return $pauses;
    }

    public function saveUrl(): string
    {
        return $this->getUrl('onetrace/preferences/save');
    }
}
