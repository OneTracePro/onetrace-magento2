<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

use Magento\Store\Model\StoreManagerInterface;

/**
 * Connection check of a store view and the queue state — for the settings page and the CLI.
 */
class Status
{
    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly Queue $queue,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * Checks both keys of a store view; a successful check resumes paused sending.
     *
     * @return list<array{ok: bool, message: string}>
     */
    public function check(int $storeId): array
    {
        $results = [];

        foreach (['write' => $this->config->storefront($storeId), 'secret' => $this->config->server($storeId)] as $key => $configured) {
            $label = $key === 'write' ? __('Write key') : __('Secret key');

            if (!$configured) {
                $results[] = ['ok' => false, 'message' => (string) __('%1 is not set.', $label)];

                continue;
            }

            $this->connection->reset();

            try {
                $answer = $this->connection->client($storeId, $key)->checkKey($key);
                $missing = $key === 'secret' && !in_array('products.write', $answer['scopes'], true) && $this->config->enabled('catalog', $storeId);
                $loyalty = $key === 'secret' && $this->config->enabled('loyalty', $storeId) && array_diff(['loyalty.read', 'loyalty.write'], $answer['scopes']) !== [];
                $promo = $key === 'secret' && $this->config->promoPools() !== [] && !\in_array('promo.read', $answer['scopes'], true);
                $preferences = $key === 'secret' && $this->config->enabled('preferences', $storeId) && array_diff(['profiles.read', 'profiles.write'], $answer['scopes']) !== [];
                // Store emails are optional: without the permission Magento sends them itself, the check still passes.
                $emails = $key === 'secret' && !\in_array('messages.send', $answer['scopes'], true);
                $results[] = [
                    'ok' => $answer['type'] === $key && !$missing && !$loyalty && !$promo && !$preferences,
                    'message' => (string) __('%1: project "%2".', $label, (string) ($answer['project']['name'] ?? ''))
                        . ($missing ? ' ' . __('The key has no products.write permission: the catalog will not be uploaded.') : '')
                        . ($loyalty ? ' ' . __('The key has no loyalty.read and loyalty.write permissions: bonus points will not work.') : '')
                        . ($promo ? ' ' . __('The key has no promo.read permission: promo codes will not become coupons.') : '')
                        . ($preferences ? ' ' . __('The key has no profiles.read and profiles.write permissions: communication preferences in My Account will not work.') : '')
                        . ($emails ? ' ' . __('The key has no messages.send permission ("Send messages manually"): Magento sends store emails itself.') : ''),
                ];
            } catch (\Throwable $error) {
                $results[] = ['ok' => false, 'message' => $label . ': ' . $error->getMessage()];
            }
        }

        if ($results !== [] && !in_array(false, array_column($results, 'ok'), true)) {
            $this->queue->resume($storeId);
        }

        return $results;
    }

    /**
     * @return list<int>
     */
    public function storeIds(): array
    {
        return array_values(array_map(static fn ($store): int => (int) $store->getId(), $this->storeManager->getStores()));
    }
}
