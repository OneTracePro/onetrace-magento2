<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Settings of the module by store view (Stores → Configuration → OneTrace): each store view can send to its own
 * project. The secret key is stored encrypted.
 */
class Config
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function url(?int $storeId = null): string
    {
        return rtrim(trim((string) $this->value('connection/url', $storeId)), '/');
    }

    public function writeKey(?int $storeId = null): string
    {
        return trim((string) $this->value('connection/write_key', $storeId));
    }

    public function secretKey(?int $storeId = null): string
    {
        $stored = trim((string) $this->value('connection/secret_key', $storeId));

        // Magento decrypts the field itself (backend_model in config.xml); a value set elsewhere may still be encrypted.
        return preg_match('/^\d+:\d+:/', $stored) === 1 ? trim($this->encryptor->decrypt($stored)) : $stored;
    }

    public function enabled(string $part, ?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag('onetrace/parts/' . $part, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /** The server part works: the address and the secret key are set. */
    public function server(?int $storeId = null): bool
    {
        return $this->url($storeId) !== '' && $this->secretKey($storeId) !== '';
    }

    /** The storefront part works: the address and the write key are set. */
    public function storefront(?int $storeId = null): bool
    {
        return $this->url($storeId) !== '' && $this->writeKey($storeId) !== '';
    }

    /** Subscription topic of newsletter consents; null — the whole email channel. */
    public function consentTopic(?int $storeId = null): ?string
    {
        $topic = trim((string) $this->value('consent/topic', $storeId));

        return preg_match('/^[a-z0-9_-]{1,64}$/', $topic) === 1 ? $topic : null;
    }

    /**
     * Order statuses that mean the order is delivered (Magento has no delivery state); empty — order_delivered is
     * not sent.
     *
     * @return list<string>
     */
    public function deliveredStatuses(?int $storeId = null): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->value('parts/delivered_statuses', $storeId))), static fn (string $status): bool => $status !== ''));
    }

    public function widget(string $place, ?int $storeId = null): string
    {
        $uid = trim((string) $this->value('widgets/' . $place, $storeId));

        return preg_match('/^[a-z0-9]{1,16}$/', $uid) === 1 ? $uid : '';
    }

    public function push(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag('onetrace/widgets/push', ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Store views whose catalog is uploaded: one per project (the first store view with the same address and key),
     * so store views of one project do not overwrite each other's products.
     *
     * @return list<int>
     */
    public function catalogStores(): array
    {
        $stores = [];

        foreach ($this->storeManager->getStores() as $store) {
            $id = (int) $store->getId();

            if ($store->isActive() && $this->server($id) && $this->enabled('catalog', $id)) {
                $stores[$this->url($id) . '|' . $this->secretKey($id)] ??= $id;
            }
        }

        ksort($stores);

        return array_values($stores);
    }

    /**
     * Other languages of the catalog of a store view from catalogStores(): store views of the same project with
     * another locale (the first one per locale). Their names, links, categories and attributes are translations.
     *
     * @return array<string, int> locale ("de_DE") => store view id
     */
    public function translationStores(int $mainStoreId): array
    {
        $project = $this->url($mainStoreId) . '|' . $this->secretKey($mainStoreId);
        $languages = [$this->locale($mainStoreId) => $mainStoreId];

        foreach ($this->storeManager->getStores() as $store) {
            $id = (int) $store->getId();

            if ($store->isActive() && $this->server($id) && $this->enabled('catalog', $id) && $this->url($id) . '|' . $this->secretKey($id) === $project) {
                $languages[$this->locale($id)] ??= $id;
            }
        }

        unset($languages[$this->locale($mainStoreId)]);

        return $languages;
    }

    /** Locale of a store view (Stores → Configuration → General → Locale Options): "de_DE". */
    public function locale(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Promo code pools bound to cart price rules (onetrace/promo/pools: "pool_key = rule id" per line).
     *
     * @return array<string, int> pool key → rule id
     */
    public function promoPools(): array
    {
        $pools = [];

        foreach (preg_split('/\R/', (string) $this->scopeConfig->getValue('onetrace/promo/pools')) ?: [] as $line) {
            if (preg_match('/^\s*([a-z0-9_]{1,64})\s*=\s*(\d+)\s*$/', $line, $match) === 1) {
                $pools[$match[1]] = (int) $match[2];
            }
        }

        return $pools;
    }

    private function value(string $path, ?int $storeId): mixed
    {
        return $this->scopeConfig->getValue('onetrace/' . $path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
