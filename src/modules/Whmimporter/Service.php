<?php

/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license   http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Whmimporter;

use FOSSBilling\Exception;
use FOSSBilling\InformationException;
use FOSSBilling\InjectionAwareInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    /**
     * Get list of WHM servers configured in FOSSBilling.
     */
    public function getWhmServers(): array
    {
        $sql = "SELECT * FROM service_hosting_server WHERE manager = 'Whm' ORDER BY name ASC";
        $servers = $this->di['db']->getAll($sql);

        $result = [];
        foreach ($servers as $server) {
            $result[] = [
                'id' => $server['id'],
                'name' => $server['name'],
                'hostname' => $server['hostname'],
                'ip' => $server['ip'],
                'active' => $server['active'],
            ];
        }

        return $result;
    }

    /**
     * Get server model by ID.
     */
    public function getServer(int $serverId): \Model_ServiceHostingServer
    {
        $server = $this->di['db']->getExistingModelById('ServiceHostingServer', $serverId, 'Server not found');

        // Case-insensitive check for WHM manager
        if (strtolower($server->manager) !== 'whm') {
            throw new InformationException('Selected server is not a WHM/cPanel server');
        }

        return $server;
    }

    /**
     * Get the Server_Manager instance for a server model.
     * Uses the same approach as the Servicehosting module.
     */
    protected function getServerManager(\Model_ServiceHostingServer $model): \Server_Manager
    {
        $config = [];
        $config['ip'] = $model->ip;
        $config['host'] = $model->hostname;
        $config['port'] = $model->port;
        $config['config'] = json_decode($model->config ?? '', true) ?? [];
        $config['secure'] = $model->secure;
        $config['username'] = $model->username;
        $config['password'] = $model->password;
        $config['accesshash'] = $model->accesshash;

        $manager = $this->di['server_manager']($model->manager, $config);

        if (!$manager instanceof \Server_Manager) {
            throw new Exception('Server manager :adapter is invalid.', [':adapter' => $model->manager]);
        }

        return $manager;
    }

    /**
     * Make a request to WHM API using the Server_Manager.
     *
     * WHM API supports multiple response formats. This method handles:
     * - Legacy format: status/statusmsg at root level
     * - Modern format: metadata.result/metadata.reason
     * - cPanel result format: result[0].status/result[0].statusmsg
     * - Data result format: data.result/data.reason
     *
     * @see https://api.docs.cpanel.net/whm/introduction/
     */
    protected function whmRequest(\Model_ServiceHostingServer $server, string $action, array $params = []): mixed
    {
        $client = $this->di['http_client']->withOptions([
            'verify_peer' => false,
            'verify_host' => false,
            'timeout' => 120,
        ]);

        $port = $server->port ?: '2087';
        $protocol = $server->secure ? 'https' : 'http';
        $url = "{$protocol}://{$server->hostname}:{$port}/json-api/{$action}";

        // Add API version parameter for consistent behavior
        if (!isset($params['api.version'])) {
            $params['api.version'] = 1;
        }

        $username = $server->username;
        $accessHash = $server->accesshash;
        $password = $server->password;

        // WHM supports two authentication methods:
        // 1. Access Hash: "WHM username:accesshash" (recommended)
        // 2. Basic Auth: "Basic base64(username:password)"
        if (!empty($accessHash)) {
            // Remove any whitespace from access hash (may contain newlines)
            $authHeader = 'WHM ' . $username . ':' . preg_replace('/\s+/', '', $accessHash);
        } else {
            $authHeader = 'Basic ' . base64_encode($username . ':' . $password);
        }

        $this->di['logger']->debug('WHM API Request: :action', [':action' => $action]);

        try {
            $response = $client->request('POST', $url, [
                'headers' => ['Authorization' => $authHeader],
                'body' => $params,
            ]);
        } catch (HttpExceptionInterface $error) {
            $this->di['logger']->error('WHM API HTTP Error: :error', [':error' => $error->getMessage()]);
            throw new Exception('WHM API Error: :error', [':error' => $error->getMessage()]);
        }

        $body = $response->getContent();
        $json = json_decode($body);

        if (!is_object($json)) {
            $this->di['logger']->error('WHM API Invalid Response for :action', [':action' => $action]);
            throw new Exception('Invalid response from WHM server');
        }

        // Check for errors in various WHM API response formats
        $this->validateWhmResponse($json, $action);

        return $json;
    }

    /**
     * Validate WHM API response for errors.
     *
     * WHM API has multiple error response formats depending on the endpoint and API version.
     */
    protected function validateWhmResponse(object $json, string $action): void
    {
        // Format 1: cpanelresult.error (cPanel API errors)
        if (isset($json->cpanelresult->error)) {
            $this->di['logger']->error('WHM cPanel Error [:action]: :error', [
                ':action' => $action,
                ':error' => $json->cpanelresult->error,
            ]);
            throw new Exception('WHM Error: :msg', [':msg' => $json->cpanelresult->error]);
        }

        // Format 2: data.result = 0 (Modern API format)
        if (isset($json->data->result) && $json->data->result == '0') {
            $reason = $json->data->reason ?? 'Unknown error';
            $this->di['logger']->error('WHM Data Error [:action]: :error', [
                ':action' => $action,
                ':error' => $reason,
            ]);
            throw new Exception('WHM Error: :msg', [':msg' => $reason]);
        }

        // Format 3: result[0].status = 0 (Account operations)
        if (isset($json->result) && is_array($json->result) && isset($json->result[0]->status)) {
            if ($json->result[0]->status == 0) {
                $msg = $json->result[0]->statusmsg ?? 'Unknown error';
                $this->di['logger']->error('WHM Result Error [:action]: :error', [
                    ':action' => $action,
                    ':error' => $msg,
                ]);
                throw new Exception('WHM Error: :msg', [':msg' => $msg]);
            }
        }

        // Format 4: status != 1 (Legacy format)
        if (isset($json->status) && $json->status != '1') {
            $msg = $json->statusmsg ?? 'Unknown error';
            $this->di['logger']->error('WHM Status Error [:action]: :error', [
                ':action' => $action,
                ':error' => $msg,
            ]);
            throw new Exception('WHM Error: :msg', [':msg' => $msg]);
        }

        // Format 5: metadata.result = 0 (Newer API format with metadata)
        if (isset($json->metadata->result) && $json->metadata->result == 0) {
            $reason = $json->metadata->reason ?? 'Unknown error';
            $this->di['logger']->error('WHM Metadata Error [:action]: :error', [
                ':action' => $action,
                ':error' => $reason,
            ]);
            throw new Exception('WHM Error: :msg', [':msg' => $reason]);
        }
    }

    /**
     * Get packages from WHM server.
     *
     * WHM API 'listpkgs' returns package data in one of two formats:
     * - Legacy: { "package": [...] }
     * - Modern: { "data": { "pkg": [...] }, "metadata": {...} }
     *
     * Package fields reference:
     * - QUOTA: Disk space in MB
     * - BWLIMIT: Bandwidth limit in MB/month
     * - MAXFTP/MAXSQL/MAXPOP/MAXSUB/MAXPARK/MAXADDON: Resource limits
     * - HASSHELL: Shell access (y/n or 1/0)
     * - CGI: CGI access (y/n or 1/0)
     * - FEATURELIST: cPanel feature list name
     * - IP: Dedicated IP (y/n or 1/0)
     *
     * @see https://api.docs.cpanel.net/openapi/whm/operation/listpkgs/
     */
    public function getRemotePackages(int $serverId): array
    {
        $server = $this->getServer($serverId);
        $response = $this->whmRequest($server, 'listpkgs');

        // Handle both legacy and modern API response formats
        $packageList = [];
        if (isset($response->package) && is_array($response->package)) {
            // Legacy format: $response->package
            $packageList = $response->package;
        } elseif (isset($response->data->pkg) && is_array($response->data->pkg)) {
            // Modern format: $response->data->pkg
            $packageList = $response->data->pkg;
        }

        $packages = [];
        foreach ($packageList as $pkg) {
            $packages[] = [
                'name' => $pkg->name ?? '',
                'quota' => $this->normalizeLimit($pkg->QUOTA ?? 'unlimited'),
                'bandwidth' => $this->normalizeLimit($pkg->BWLIMIT ?? 'unlimited'),
                'max_ftp' => $this->normalizeLimit($pkg->MAXFTP ?? 'unlimited'),
                'max_sql' => $this->normalizeLimit($pkg->MAXSQL ?? 'unlimited'),
                'max_pop' => $this->normalizeLimit($pkg->MAXPOP ?? 'unlimited'),
                'max_sub' => $this->normalizeLimit($pkg->MAXSUB ?? 'unlimited'),
                'max_park' => $this->normalizeLimit($pkg->MAXPARK ?? 'unlimited'),
                'max_addon' => $this->normalizeLimit($pkg->MAXADDON ?? 'unlimited'),
                'max_email_lists' => $this->normalizeLimit($pkg->MAXLST ?? 'unlimited'),
                'has_shell' => $this->normalizeBoolean($pkg->HASSHELL ?? 'n'),
                'has_cgi' => $this->normalizeBoolean($pkg->CGI ?? 'n'),
                'has_ip' => $this->normalizeBoolean($pkg->IP ?? 'n'),
                'feature_list' => $pkg->FEATURELIST ?? 'default',
                'theme' => $pkg->CPMOD ?? 'paper_lantern',
            ];
        }

        $this->di['logger']->info('Retrieved :count packages from WHM server :server', [
            ':count' => count($packages),
            ':server' => $server->name,
        ]);

        return $packages;
    }

    /**
     * Normalize a limit value from WHM (handles 'unlimited', null, numeric strings).
     */
    protected function normalizeLimit(mixed $value): string
    {
        if ($value === null || $value === '' || strtolower((string) $value) === 'unlimited') {
            return 'unlimited';
        }

        return (string) $value;
    }

    /**
     * Normalize a boolean value from WHM (handles 'y'/'n', '1'/'0', 1/0, true/false).
     */
    protected function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $strValue = strtolower((string) $value);

        return in_array($strValue, ['y', 'yes', '1', 'true', 'on'], true);
    }

    /**
     * Get list of resellers from WHM server.
     *
     * WHM API 'listresellers' returns a simple array of reseller usernames.
     * Response formats:
     * - Legacy: { "reseller": ["user1", "user2"] }
     * - Modern: { "data": { "reseller": ["user1", "user2"] }, "metadata": {...} }
     *
     * Note: This endpoint requires root-level WHM access.
     *
     * @see https://api.docs.cpanel.net/openapi/whm/operation/listresellers/
     */
    public function getRemoteResellers(int $serverId): array
    {
        $server = $this->getServer($serverId);
        $response = $this->whmRequest($server, 'listresellers');

        // Handle both legacy and modern API response formats
        $resellerList = [];
        if (isset($response->reseller) && is_array($response->reseller)) {
            // Legacy format: $response->reseller
            $resellerList = $response->reseller;
        } elseif (isset($response->data->reseller) && is_array($response->data->reseller)) {
            // Modern format: $response->data->reseller
            $resellerList = $response->data->reseller;
        }

        $resellers = [];
        foreach ($resellerList as $reseller) {
            $resellers[] = is_string($reseller) ? $reseller : (string) $reseller;
        }

        $this->di['logger']->info('Retrieved :count resellers from WHM server :server', [
            ':count' => count($resellers),
            ':server' => $server->name,
        ]);

        return $resellers;
    }

    /**
     * Get accounts from WHM server.
     *
     * WHM API 'listaccts' returns account data in one of two formats:
     * - Legacy: { "acct": [...] }
     * - Modern: { "data": { "acct": [...] }, "metadata": {...} }
     *
     * Account fields reference:
     * - domain: Primary domain for the account
     * - user: cPanel username
     * - email: Contact email address
     * - owner: Account owner (root or reseller username)
     * - plan: Hosting package name
     * - ip: IP address assigned to the account
     * - suspended: Suspension status (0/1 or false/true)
     * - suspendreason: Reason for suspension if suspended
     * - suspendtime: Unix timestamp of suspension
     * - startdate: Human-readable creation date
     * - unix_startdate: Unix timestamp of creation
     * - diskused: Disk space used (e.g., "65M")
     * - disklimit: Disk space limit (e.g., "500M" or "unlimited")
     * - shell: Shell path (e.g., "/usr/bin/bash")
     * - partition: Disk partition (e.g., "home")
     *
     * @param int  $serverId         Server ID
     * @param bool $includeResellers Whether to also fetch reseller status for each account
     *
     * @see https://api.docs.cpanel.net/openapi/whm/operation/listaccts/
     */
    public function getRemoteAccounts(int $serverId, bool $includeResellers = true): array
    {
        $server = $this->getServer($serverId);
        $response = $this->whmRequest($server, 'listaccts');

        // Get list of resellers to identify reseller accounts
        $resellerList = [];
        if ($includeResellers) {
            try {
                $resellerList = $this->getRemoteResellers($serverId);
            } catch (\Exception $e) {
                // If we can't get resellers, continue without that info
                // This can happen if the user doesn't have root access
                $this->di['logger']->warning('Could not fetch reseller list: :error', [
                    ':error' => $e->getMessage(),
                ]);
            }
        }

        // Handle both legacy and modern API response formats
        $accountList = [];
        if (isset($response->acct) && is_array($response->acct)) {
            // Legacy format: $response->acct
            $accountList = $response->acct;
        } elseif (isset($response->data->acct) && is_array($response->data->acct)) {
            // Modern format: $response->data->acct
            $accountList = $response->data->acct;
        }

        $accounts = [];
        foreach ($accountList as $acct) {
            $username = $acct->user ?? '';
            $isReseller = in_array($username, $resellerList, true);
            $owner = $acct->owner ?? 'root';

            // Handle suspended field which can be 0, "0", 1, "1", true, or false
            $suspended = false;
            if (isset($acct->suspended)) {
                $suspended = $this->normalizeBoolean($acct->suspended);
            }

            $accounts[] = [
                'domain' => $acct->domain ?? '',
                'user' => $username,
                'email' => $acct->email ?? '',
                'owner' => $owner,
                'is_reseller' => $isReseller,
                'is_owned_by_reseller' => $owner !== 'root',
                'plan' => $acct->plan ?? '',
                'ip' => $acct->ip ?? '',
                'suspended' => $suspended,
                'suspend_reason' => $acct->suspendreason ?? '',
                'suspendtime' => $acct->suspendtime ?? null,
                'startdate' => $acct->startdate ?? null,
                'unix_startdate' => $acct->unix_startdate ?? null,
                'diskused' => $acct->diskused ?? '0M',
                'disklimit' => $acct->disklimit ?? 'unlimited',
                'shell' => $acct->shell ?? '',
                'partition' => $acct->partition ?? 'home',
            ];
        }

        $this->di['logger']->info('Retrieved :count accounts from WHM server :server', [
            ':count' => count($accounts),
            ':server' => $server->name,
        ]);

        return $accounts;
    }

    /**
     * Import packages from WHM server as hosting plans.
     */
    public function importPackages(int $serverId, array $packageNames): array
    {
        $server = $this->getServer($serverId);
        $remotePackages = $this->getRemotePackages($serverId);

        $hostingService = $this->di['mod_service']('servicehosting');
        $imported = [];
        $skipped = [];

        foreach ($remotePackages as $pkg) {
            if (!in_array($pkg['name'], $packageNames)) {
                continue;
            }

            // Check if hosting plan already exists
            $existing = $this->di['db']->findOne('ServiceHostingHp', 'name = ?', [$pkg['name']]);
            if ($existing) {
                $skipped[] = $pkg['name'];
                continue;
            }

            // Convert 'unlimited' to a high number
            $quota = $this->parseLimit($pkg['quota'], 1024 * 1024);
            $bandwidth = $this->parseLimit($pkg['bandwidth'], 1024 * 1024);
            $maxFtp = $this->parseLimit($pkg['max_ftp'], 999);
            $maxSql = $this->parseLimit($pkg['max_sql'], 999);
            $maxPop = $this->parseLimit($pkg['max_pop'], 999);
            $maxSub = $this->parseLimit($pkg['max_sub'], 999);
            $maxPark = $this->parseLimit($pkg['max_park'], 999);
            $maxAddon = $this->parseLimit($pkg['max_addon'], 999);

            // Create hosting plan
            $hpId = $hostingService->createHp($pkg['name'], [
                'quota' => $quota,
                'bandwidth' => $bandwidth,
                'max_ftp' => $maxFtp,
                'max_sql' => $maxSql,
                'max_pop' => $maxPop,
                'max_sub' => $maxSub,
                'max_park' => $maxPark,
                'max_addon' => $maxAddon,
            ]);

            $imported[] = [
                'id' => $hpId,
                'name' => $pkg['name'],
            ];
        }

        $this->di['logger']->info('Imported :count WHM packages from server :server', [
            ':count' => count($imported),
            ':server' => $server->name,
        ]);

        return [
            'imported' => $imported,
            'skipped' => $skipped,
        ];
    }

    /**
     * Import accounts from WHM server.
     */
    public function importAccounts(int $serverId, array $usernames, int $hostingPlanId, int $clientGroupId = 1): array
    {
        $server = $this->getServer($serverId);
        $remoteAccounts = $this->getRemoteAccounts($serverId);

        $hostingPlan = $this->di['db']->getExistingModelById('ServiceHostingHp', $hostingPlanId, 'Hosting plan not found');

        $imported = [];
        $skipped = [];
        $errors = [];

        foreach ($remoteAccounts as $acct) {
            if (!in_array($acct['user'], $usernames)) {
                continue;
            }

            try {
                // Check if account already exists in FOSSBilling
                $existingService = $this->di['db']->findOne('ServiceHosting', 'username = ? AND service_hosting_server_id = ?', [
                    $acct['user'],
                    $serverId,
                ]);

                if ($existingService) {
                    $skipped[] = $acct['user'];
                    continue;
                }

                // Parse domain into SLD and TLD
                [$sld, $tld] = $this->parseDomain($acct['domain']);

                // Find or create client
                $client = $this->findOrCreateClient($acct, $clientGroupId);

                // Determine if account is a reseller
                $isReseller = $acct['is_reseller'] ?? false;

                // Create hosting service
                $model = $this->di['db']->dispense('ServiceHosting');
                $model->client_id = $client->id;
                $model->service_hosting_server_id = $serverId;
                $model->service_hosting_hp_id = $hostingPlanId;
                $model->sld = $sld;
                $model->tld = $tld;
                $model->ip = $acct['ip'];
                $model->username = $acct['user'];
                $model->pass = '********'; // We don't have access to real passwords
                $model->reseller = $isReseller;
                $model->created_at = date('Y-m-d H:i:s');
                $model->updated_at = date('Y-m-d H:i:s');
                $serviceId = $this->di['db']->store($model);

                // Create an order for this service
                $orderId = $this->createOrderForService($client, $model, $acct);

                $imported[] = [
                    'username' => $acct['user'],
                    'domain' => $acct['domain'],
                    'client_id' => $client->id,
                    'service_id' => $serviceId,
                    'order_id' => $orderId,
                    'is_reseller' => $isReseller,
                ];
            } catch (\Exception $e) {
                $errors[] = [
                    'username' => $acct['user'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->di['logger']->info('Imported :count WHM accounts from server :server', [
            ':count' => count($imported),
            ':server' => $server->name,
        ]);

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Find existing client by email or create a new one.
     */
    protected function findOrCreateClient(array $acct, int $clientGroupId): \Model_Client
    {
        $email = !empty($acct['email']) ? $acct['email'] : $acct['user'] . '@' . $acct['domain'];

        // Try to find existing client by email
        $client = $this->di['db']->findOne('Client', 'email = ?', [$email]);

        if ($client) {
            return $client;
        }

        // Create new client
        $client = $this->di['db']->dispense('Client');
        $client->email = $email;
        $client->first_name = ucfirst($acct['user']);
        $client->last_name = 'Imported';
        $client->status = 'active';
        $client->email_approved = true;
        $client->client_group_id = $clientGroupId;
        $client->created_at = date('Y-m-d H:i:s');
        $client->updated_at = date('Y-m-d H:i:s');

        // Generate a random password hash
        $passwordManager = $this->di['password'];
        $client->pass = $passwordManager->hashIt(bin2hex(random_bytes(16)));

        $this->di['db']->store($client);

        $this->di['logger']->info('Created client :email during WHM import', [':email' => $email]);

        return $client;
    }

    /**
     * Create an order for the imported service.
     */
    protected function createOrderForService(\Model_Client $client, \Model_ServiceHosting $service, array $acct): int
    {
        // Find or create a hosting product
        $product = $this->findOrCreateHostingProduct($service);

        // Create order
        $order = $this->di['db']->dispense('ClientOrder');
        $order->client_id = $client->id;
        $order->product_id = $product->id;
        $order->group_id = uniqid();
        $order->group_master = 1;
        $order->title = 'Hosting - ' . $acct['domain'];
        $order->currency = $this->getDefaultCurrency();
        $order->service_id = $service->id;
        $order->service_type = 'hosting';
        $order->period = '1Y';
        $order->quantity = 1;
        $order->unit = 'product';
        $order->price = 0;
        $order->discount = 0;
        $order->status = $acct['suspended'] ? 'suspended' : 'active';
        $order->invoice_option = 'no-invoice';
        $order->config = json_encode([
            'server_id' => $service->service_hosting_server_id,
            'hosting_plan_id' => $service->service_hosting_hp_id,
            'sld' => $service->sld,
            'tld' => $service->tld,
            'import' => true,
        ]);
        $order->created_at = $acct['startdate'] ?? date('Y-m-d H:i:s');
        $order->updated_at = date('Y-m-d H:i:s');

        // Set activation date if account is active
        if (!$acct['suspended']) {
            $order->activated_at = $acct['startdate'] ?? date('Y-m-d H:i:s');
        }

        return $this->di['db']->store($order);
    }

    /**
     * Find or create a generic hosting product.
     */
    protected function findOrCreateHostingProduct(\Model_ServiceHosting $service): \Model_Product
    {
        // Look for an existing hosting product
        $product = $this->di['db']->findOne('Product', "type = 'hosting' AND status = 'enabled' ORDER BY id ASC");

        if ($product) {
            return $product;
        }

        // Create a generic hosting product
        $product = $this->di['db']->dispense('Product');
        $product->type = 'hosting';
        $product->title = 'Imported Hosting';
        $product->slug = 'imported-hosting';
        $product->description = 'Hosting product created for imported accounts';
        $product->status = 'enabled';
        $product->hidden = 1;
        $product->setup = 'after_payment';
        $product->config = json_encode([
            'server_id' => $service->service_hosting_server_id,
            'hosting_plan_id' => $service->service_hosting_hp_id,
        ]);
        $product->created_at = date('Y-m-d H:i:s');
        $product->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($product);

        return $product;
    }

    /**
     * Parse domain into SLD and TLD.
     */
    protected function parseDomain(string $domain): array
    {
        $parts = explode('.', $domain);

        if (count($parts) < 2) {
            return [$domain, '.com'];
        }

        // Handle multi-part TLDs like .co.uk
        if (count($parts) > 2 && strlen($parts[count($parts) - 2]) <= 3) {
            $tld = '.' . $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
            array_pop($parts);
            array_pop($parts);
            $sld = implode('.', $parts);
        } else {
            $tld = '.' . array_pop($parts);
            $sld = implode('.', $parts);
        }

        return [$sld, $tld];
    }

    /**
     * Parse limit value from WHM (handle 'unlimited').
     */
    protected function parseLimit(mixed $value, int $unlimitedValue): int
    {
        if ($value === 'unlimited' || $value === null || $value === '') {
            return $unlimitedValue;
        }

        return (int) $value;
    }

    /**
     * Get default currency code.
     */
    protected function getDefaultCurrency(): string
    {
        $currency = $this->di['db']->findOne('Currency', 'is_default = 1');

        return $currency ? $currency->code : 'USD';
    }

    /**
     * Get existing hosting plans.
     */
    public function getHostingPlans(): array
    {
        $sql = 'SELECT id, name FROM service_hosting_hp ORDER BY name ASC';
        $plans = $this->di['db']->getAll($sql);

        return $plans;
    }

    /**
     * Get client groups.
     */
    public function getClientGroups(): array
    {
        $sql = 'SELECT id, title FROM client_group ORDER BY title ASC';
        $groups = $this->di['db']->getAll($sql);

        return $groups;
    }

    /**
     * Test connection to WHM server.
     * Uses the Server_Manager for consistent connection handling with Servicehosting module.
     */
    public function testConnection(int $serverId): bool
    {
        $server = $this->getServer($serverId);

        try {
            $manager = $this->getServerManager($server);

            return $manager->testConnection();
        } catch (\Exception $e) {
            throw new Exception('Connection failed: :error', [':error' => $e->getMessage()]);
        }
    }
}
