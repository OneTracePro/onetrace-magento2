<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\FlagManager;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Connector\Model\StoreMcp\CacheCodeStore;
use OneTrace\Connector\Model\StoreMcp\Customers;
use OneTrace\Connector\Model\StoreMcp\Tools;
use OneTrace\StoreMcp\CustomerTokens;
use OneTrace\StoreMcp\Server;
use OneTrace\StoreMcp\Verification;
use Psr\Log\LoggerInterface;

/**
 * MCP server of the store for the OneTrace AI chat bot (store MCP contract v1): POST /onetrace/mcp of a store view.
 * The bot reads orders, tracking, stock, delivery and the return policy, and — when the owner allows it in the
 * settings — cancels unpaid orders and passes return and delivery change requests to the staff. Customers are
 * confirmed by the login on the storefront (cdp.chatCustomer() from the private "onetrace" section) or by a code
 * the store sends by email. Customer ids: "customer:<id>" or "email:<address>" (guest orders).
 *
 * The platform key and the token secret are generated once and kept encrypted in flags. Store views of one project
 * and website share one connection: the first of them is connected, so the bot does not get the same tools twice.
 */
class StoreMcp
{
    public const READ_TOOLS = ['store_start_verification', 'store_verify', 'store_get_customer', 'store_find_orders', 'store_get_order', 'store_get_tracking', 'store_check_availability', 'store_get_delivery_options', 'store_get_return_policy'];

    public const ACTIONS = ['store_cancel_order', 'store_create_return', 'store_change_delivery'];

    private const SECRETS_FLAG = 'onetrace_store_mcp';

    private const STATUS_FLAG = 'onetrace_store_mcp_status';

    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly FlagManager $flags,
        private readonly EncryptorInterface $encryptor,
        private readonly CacheCodeStore $codes,
        private readonly Customers $customers,
        private readonly Tools $tools,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function enabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag('onetrace/store_mcp/enabled', ScopeInterface::SCOPE_STORE, $storeId) && $this->config->server($storeId);
    }

    /**
     * Tools the owner allows the bot to use in the store view.
     *
     * @return list<string>
     */
    public function allowedTools(?int $storeId = null): array
    {
        $value = (string) $this->scopeConfig->getValue('onetrace/store_mcp/tools', ScopeInterface::SCOPE_STORE, $storeId);

        return array_values(array_intersect(array_merge(self::READ_TOOLS, self::ACTIONS), explode(',', $value)));
    }

    public function server(int $storeId): Server
    {
        $tools = $this->tools;

        return new Server($this->key(), $this->tokens(), [
            'store_get_customer' => [$tools, 'customer'],
            'store_find_orders' => [$tools, 'findOrders'],
            'store_get_order' => [$tools, 'getOrder'],
            'store_get_tracking' => [$tools, 'tracking'],
            'store_check_availability' => [$tools, 'availability'],
            'store_get_delivery_options' => [$tools, 'delivery'],
            'store_get_return_policy' => [$tools, 'returnPolicy'],
            'store_cancel_order' => [$tools, 'cancel'],
            'store_create_return' => [$tools, 'createReturn'],
            'store_change_delivery' => [$tools, 'changeDelivery'],
        ], [
            'name' => 'magento2:' . (string) parse_url((string) $this->storeManager->getStore($storeId)->getBaseUrl(), PHP_URL_HOST),
            'version' => Connection::VERSION,
            'enabled' => $this->allowedTools($storeId),
            'verification' => new Verification($this->codes, [$this->customers, 'find'], [$this->customers, 'sendCode']),
        ]);
    }

    /**
     * Address of the server of a store view: its secure URL, with the store code when store views share an address.
     */
    public function url(int $storeId): string
    {
        /** @var Store $store */
        $store = $this->storeManager->getStore($storeId);
        $default = $this->storeManager->getWebsite($store->getWebsiteId())->getDefaultStore();
        $query = !$store->isUseStoreInUrl() && $default !== null && (int) $default->getId() !== $storeId ? ['___store' => $store->getCode()] : [];

        return $store->getUrl('onetrace/mcp', ['_secure' => true, '_nosid' => true, '_query' => $query]);
    }

    public function key(): string
    {
        return $this->secrets()['key'];
    }

    public function tokens(): CustomerTokens
    {
        return new CustomerTokens($this->secrets()['secret']);
    }

    /**
     * The chat token of the signed-in customer for cdp.chatCustomer(); null when the bot has no store MCP here.
     */
    public function customerToken(int $storeId, int $customerId): ?string
    {
        return $customerId > 0 && $this->enabled($storeId) ? $this->tokens()->issue('customer:' . $customerId) : null;
    }

    /**
     * After the settings are saved: connects the server of each project (and website) to its bot, and disconnects
     * the ones that are turned off.
     */
    public function connect(): void
    {
        $previous = $this->statuses();
        $statuses = [];

        foreach ($this->groups() as $storeIds) {
            $storeId = $storeIds[0];
            $url = $this->url($storeId);

            try {
                $result = $this->connection->client($storeId)->chats()->connectStoreMcp($url, $this->key(), $this->storeName($storeId), 'magento2');
                $status = ['status' => (string) ($result['status'] ?? 'error'), 'error' => (string) ($result['error'] ?? ''), 'tools' => array_values(array_filter((array) ($result['tools'] ?? []), 'is_string'))];
            } catch (\Throwable $error) {
                $status = ['status' => 'error', 'error' => $error->getMessage(), 'tools' => []];
            }

            $statuses[(string) $storeId] = $status + ['url' => $url, 'stores' => $storeIds, 'at' => time()];
        }

        foreach ($previous as $storeId => $status) {
            if (isset($statuses[$storeId]) && $statuses[$storeId]['url'] === $status['url']) {
                continue;
            }

            try {
                if ($this->config->server((int) $storeId)) {
                    $this->connection->client((int) $storeId)->chats()->disconnectStoreMcp($status['url']);
                }
            } catch (\Throwable $error) {
                $this->logger->warning('OneTrace: store MCP of store view ' . $storeId . ' was not disconnected: ' . $error->getMessage());
            }
        }

        $this->flags->saveFlag(self::STATUS_FLAG, $statuses);
    }

    /**
     * The connection that serves a store view, if any.
     *
     * @return array{status: string, error: string, tools: list<string>, url: string, stores: list<int>, at: int}|null
     */
    public function status(int $storeId): ?array
    {
        foreach ($this->statuses() as $status) {
            if (in_array($storeId, $status['stores'], true)) {
                return $status;
            }
        }

        return null;
    }

    /**
     * @return array<string, array{status: string, error: string, tools: list<string>, url: string, stores: list<int>, at: int}>
     */
    private function statuses(): array
    {
        $statuses = [];

        foreach ((array) $this->flags->getFlagData(self::STATUS_FLAG) as $storeId => $status) {
            if (is_array($status) && is_string($status['url'] ?? null)) {
                $statuses[(string) $storeId] = [
                    'status' => (string) ($status['status'] ?? 'error'),
                    'error' => (string) ($status['error'] ?? ''),
                    'tools' => array_values(array_filter((array) ($status['tools'] ?? []), 'is_string')),
                    'url' => $status['url'],
                    'stores' => array_map('intval', (array) ($status['stores'] ?? [])),
                    'at' => (int) ($status['at'] ?? 0),
                ];
            }
        }

        return $statuses;
    }

    /**
     * Active store views with the store MCP on, by project and website: the first one serves the group.
     *
     * @return list<list<int>>
     */
    private function groups(): array
    {
        $groups = [];

        foreach ($this->storeManager->getStores() as $store) {
            $id = (int) $store->getId();

            if ($store->isActive() && $this->enabled($id)) {
                $groups[$this->config->url($id) . '|' . $this->config->secretKey($id) . '|' . $store->getWebsiteId()][] = $id;
            }
        }

        ksort($groups);

        return array_values($groups);
    }

    private function storeName(int $storeId): string
    {
        $name = trim((string) $this->scopeConfig->getValue('general/store_information/name', ScopeInterface::SCOPE_STORE, $storeId));

        return $name !== '' ? $name : (string) $this->storeManager->getWebsite($this->storeManager->getStore($storeId)->getWebsiteId())->getName();
    }

    /**
     * @return array{key: string, secret: string}
     */
    private function secrets(): array
    {
        $stored = $this->flags->getFlagData(self::SECRETS_FLAG);
        $key = is_array($stored) ? $this->encryptor->decrypt((string) ($stored['key'] ?? '')) : '';
        $secret = is_array($stored) ? $this->encryptor->decrypt((string) ($stored['secret'] ?? '')) : '';

        if (strlen($key) < 32 || strlen($secret) < 32) {
            $key = bin2hex(random_bytes(24));
            $secret = bin2hex(random_bytes(32));
            $this->flags->saveFlag(self::SECRETS_FLAG, ['key' => $this->encryptor->encrypt($key), 'secret' => $this->encryptor->encrypt($secret)]);
        }

        return ['key' => $key, 'secret' => $secret];
    }
}
