<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

use Magento\Framework\Locale\ResolverInterface;
use OneTrace\Exception\NotFoundException;
use OneTrace\Identity;

/**
 * Communication preferences of a customer in the OneTrace preference center: subscription topics by channel, how
 * often marketing messages come, a pause and "Unsubscribe from everything" (My Account → Communication preferences).
 */
class Preferences
{
    public const PAUSE_DAYS = [30, 90];

    public function __construct(
        private readonly Connection $connection,
        private readonly ResolverInterface $locale,
    ) {
    }

    /**
     * Preferences in the store language; a customer the platform does not know yet has nothing to choose; null — the
     * platform did not answer.
     *
     * @return array<string, mixed>|null
     */
    public function state(int $storeId, int $customerId): ?array
    {
        $profile = Identity::userId((string) $customerId);

        try {
            return $this->connection->client($storeId)->profiles()->preferences($profile['type'], $profile['value'], $this->language());
        } catch (NotFoundException $error) {
            return ['channels' => [], 'topics' => []];
        } catch (\Throwable $error) {
            return null;
        }
    }

    /**
     * Sends the choice: every topic of the form in every channel that can change (an unticked box — unsubscribed),
     * the frequency and the pause; "all" unsubscribes from every marketing message.
     *
     * @param 'save'|'all' $action
     * @param array<mixed> $topics topic → channel → "1" for ticked boxes
     */
    public function save(int $storeId, int $customerId, string $action, array $topics, string $frequency, string $pause): bool
    {
        $state = $this->state($storeId, $customerId);

        if ($state === null) {
            return false;
        }

        if ($action === 'all') {
            $changes = ['unsubscribe_all' => true];
        } else {
            $blocked = [];

            foreach ((array) ($state['channels'] ?? []) as $channel) {
                if (\is_array($channel) && !empty($channel['blocked'])) {
                    $blocked[] = (string) $channel['key'];
                }
            }

            $choices = [];

            foreach ((array) ($state['topics'] ?? []) as $topic) {
                if (!\is_array($topic)) {
                    continue;
                }

                foreach ((array) ($topic['channels'] ?? []) as $channel => $subscribed) {
                    if ($subscribed !== null && !\in_array((string) $channel, $blocked, true)) {
                        $choices[(string) $topic['key']][(string) $channel] = !empty($topics[$topic['key']][$channel]);
                    }
                }
            }

            $changes = ['topics' => $choices, 'frequency' => \in_array($frequency, ['weekly', 'monthly'], true) ? $frequency : null];

            if ($pause === 'none') {
                $changes['paused_until'] = null;
            } elseif (\in_array((int) $pause, self::PAUSE_DAYS, true)) {
                $changes['paused_until'] = new \DateTimeImmutable('+' . (int) $pause . ' days');
            }
        }

        $changes['language'] = $this->language();
        $profile = Identity::userId((string) $customerId);

        try {
            $this->connection->client($storeId)->profiles()->updatePreferences($profile['type'], $profile['value'], $changes);
        } catch (\Throwable $error) {
            return false;
        }

        return true;
    }

    private function language(): string
    {
        return str_replace('_', '-', $this->locale->getLocale());
    }
}
