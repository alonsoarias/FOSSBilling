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
use Symfony\Component\HttpClient\HttpClient;
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
     * Make a request to WHM API.
     * Uses the same authentication logic as Server_Manager_Whm for consistency.
     *
     * @see https://api.docs.cpanel.net/whm/introduction/
     */
    protected function whmRequest(\Model_ServiceHostingServer $server, string $action, array $params = []): mixed
    {
        // Build config exactly like Server_Manager does
        $config = [
            'host' => $server->hostname,
            'port' => $server->port ?: '2087',
            'secure' => $server->secure,
            'username' => $server->username,
            'password' => $server->password,
            'accesshash' => $server->accesshash,
        ];

        $client = HttpClient::create([
            'bindto' => BIND_TO,
            'verify_peer' => false,
            'verify_host' => false,
            'timeout' => 90,
        ]);

        // Construct URL exactly like Server_Manager_Whm
        $url = ($config['secure'] ? 'https' : 'http') . '://' . $config['host'] . ':' . $config['port'] . '/json-api/' . $action;

        // Construct auth header exactly like Server_Manager_Whm
        $username = $config['username'];
        $accessHash = $config['accesshash'];
        $password = $config['password'];

        $authHeader = (!empty($accessHash))
            ? 'WHM ' . $username . ':' . $accessHash
            : 'Basic ' . $username . ':' . $password;

        $this->di['logger']->debug('WHM API Request: :action to :url', [':action' => $action, ':url' => $url]);

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

        try {
            $manager = $this->getServerManager($server);

            // Use Server_Manager's getPackages method which handles auth correctly
            $packages = $manager->getPackages();

            $this->di['logger']->info('Retrieved :count packages from WHM server :server', [
                ':count' => count($packages),
                ':server' => $server->name,
            ]);

            return $packages;
        } catch (\Exception $e) {
            $this->di['logger']->error('Failed to get packages from WHM server: :error', [':error' => $e->getMessage()]);
            throw new Exception('Failed to get packages: :error', [':error' => $e->getMessage()]);
        }
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
     * Each account is automatically associated with its cPanel package.
     * If the package doesn't exist in FOSSBilling, it will be created.
     *
     * Important: By default, sub-accounts (accounts owned by resellers) are excluded from import.
     * These accounts belong to reseller contracts and are not independent billable products.
     * Only the reseller account itself represents the contractual relationship.
     *
     * @param int    $serverId               Server ID
     * @param array  $usernames              Array of usernames to import
     * @param int    $clientGroupId          Client group ID
     * @param string $duplicateAction        Action for duplicate clients: use_existing, update_existing, create_new
     * @param string $accountDuplicateAction Action for duplicate accounts: skip, update, recreate
     * @param bool   $skipSubAccounts        Skip accounts owned by resellers (default: true)
     *
     * @return array Import results with imported, skipped, skipped_sub_accounts, errors, and plans_created
     */
    public function importAccounts(int $serverId, array $usernames, int $clientGroupId = 1, string $duplicateAction = 'use_existing', string $accountDuplicateAction = 'skip', bool $skipSubAccounts = true): array
    {
        $server = $this->getServer($serverId);
        $remoteAccounts = $this->getRemoteAccounts($serverId);

        // Get remote packages to have full package data for creating hosting plans
        $remotePackages = $this->getRemotePackages($serverId);
        $packagesByName = [];
        foreach ($remotePackages as $pkg) {
            $packagesByName[$pkg['name']] = $pkg;
        }

        $imported = [];
        $skipped = [];
        $skippedSubAccounts = [];
        $errors = [];
        $plansCreated = [];

        foreach ($remoteAccounts as $acct) {
            if (!in_array($acct['user'], $usernames)) {
                continue;
            }

            // Skip sub-accounts (accounts owned by resellers) if configured
            // Sub-accounts belong to reseller contracts and are not independent billable products
            $isSubAccount = $acct['is_owned_by_reseller'] ?? false;
            if ($skipSubAccounts && $isSubAccount) {
                $skippedSubAccounts[] = [
                    'username' => $acct['user'],
                    'domain' => $acct['domain'],
                    'owner' => $acct['owner'] ?? 'unknown',
                    'reason' => 'Sub-account owned by reseller - not an independent billable product',
                ];
                continue;
            }

            try {
                // Check if account already exists in FOSSBilling
                $existingService = $this->di['db']->findOne('ServiceHosting', 'username = ? AND service_hosting_server_id = ?', [
                    $acct['user'],
                    $serverId,
                ]);

                if ($existingService) {
                    switch ($accountDuplicateAction) {
                        case 'update':
                            // Update existing service and order
                            $result = $this->updateExistingAccount($existingService, $acct, $serverId, $packagesByName, $plansCreated, $clientGroupId, $duplicateAction);
                            $imported[] = array_merge($result, ['action' => 'updated']);
                            continue 2;

                        case 'recreate':
                            // Delete existing service and order, then create new
                            $this->deleteExistingAccount($existingService);
                            // Continue to create new account below
                            break;

                        case 'skip':
                        default:
                            $skipped[] = $acct['user'];
                            continue 2;
                    }
                }

                // Get or create hosting plan and product based on cPanel package name
                $planName = $acct['plan'] ?? 'default';
                [$hostingPlan, $product] = $this->findOrCreateHostingPlanAndProduct($planName, $serverId, $packagesByName, $plansCreated);

                // Parse domain into SLD and TLD
                [$sld, $tld] = $this->parseDomain($acct['domain']);

                // Find or create client based on duplicate action
                $client = $this->findOrCreateClient($acct, $clientGroupId, $duplicateAction);

                // Determine if account is a reseller
                $isReseller = $acct['is_reseller'] ?? false;

                // Create hosting service
                $model = $this->di['db']->dispense('ServiceHosting');
                $model->client_id = $client->id;
                $model->service_hosting_server_id = $serverId;
                $model->service_hosting_hp_id = $hostingPlan->id;
                $model->sld = $sld;
                $model->tld = $tld;
                $model->ip = $acct['ip'];
                $model->username = $acct['user'];
                $model->pass = '********'; // We don't have access to real passwords
                $model->reseller = $isReseller;
                // Use cPanel account creation date (prefer unix timestamp)
                $createdAt = $this->parseWhmDate($acct['unix_startdate'] ?? null, $acct['startdate'] ?? null);
                $model->created_at = $createdAt;
                $model->updated_at = date('Y-m-d H:i:s');
                $serviceId = $this->di['db']->store($model);

                // Create an order for this service using the correct product
                $orderId = $this->createOrderForService($client, $model, $acct, $product);

                $imported[] = [
                    'username' => $acct['user'],
                    'domain' => $acct['domain'],
                    'plan' => $planName,
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

        $this->di['logger']->info('Imported :count WHM accounts from server :server (:sub_count sub-accounts skipped)', [
            ':count' => count($imported),
            ':server' => $server->name,
            ':sub_count' => count($skippedSubAccounts),
        ]);

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'skipped_sub_accounts' => $skippedSubAccounts,
            'errors' => $errors,
            'plans_created' => array_values($plansCreated),
        ];
    }

    /**
     * Find or create a hosting plan and product based on cPanel package name.
     * Returns both the hosting plan and the associated product.
     *
     * @return array [\Model_ServiceHostingHp, \Model_Product]
     */
    protected function findOrCreateHostingPlanAndProduct(string $planName, int $serverId, array $packagesByName, array &$plansCreated): array
    {
        // Check if we already processed this plan in this import
        if (isset($plansCreated[$planName])) {
            $hp = $this->di['db']->load('ServiceHostingHp', $plansCreated[$planName]['hp_id']);
            $product = $this->di['db']->load('Product', $plansCreated[$planName]['product_id']);

            return [$hp, $product];
        }

        // Try to find existing hosting plan by name
        $existingPlan = $this->di['db']->findOne('ServiceHostingHp', 'name = ?', [$planName]);

        if ($existingPlan) {
            // Find or create the product for this existing plan
            $product = $this->findOrCreateProductForHostingPlan($existingPlan, $serverId, $planName);

            return [$existingPlan, $product];
        }

        // Create new hosting plan using package data from cPanel
        $hostingService = $this->di['mod_service']('servicehosting');

        $pkgData = $packagesByName[$planName] ?? [];

        // Convert 'unlimited' to a high number
        $quota = $this->parseLimit($pkgData['quota'] ?? 'unlimited', 1024 * 1024);
        $bandwidth = $this->parseLimit($pkgData['bandwidth'] ?? 'unlimited', 1024 * 1024);
        $maxFtp = $this->parseLimit($pkgData['max_ftp'] ?? 'unlimited', 999);
        $maxSql = $this->parseLimit($pkgData['max_sql'] ?? 'unlimited', 999);
        $maxPop = $this->parseLimit($pkgData['max_pop'] ?? 'unlimited', 999);
        $maxSub = $this->parseLimit($pkgData['max_sub'] ?? 'unlimited', 999);
        $maxPark = $this->parseLimit($pkgData['max_park'] ?? 'unlimited', 999);
        $maxAddon = $this->parseLimit($pkgData['max_addon'] ?? 'unlimited', 999);

        $hpId = $hostingService->createHp($planName, [
            'quota' => $quota,
            'bandwidth' => $bandwidth,
            'max_ftp' => $maxFtp,
            'max_sql' => $maxSql,
            'max_pop' => $maxPop,
            'max_sub' => $maxSub,
            'max_park' => $maxPark,
            'max_addon' => $maxAddon,
        ]);

        $hp = $this->di['db']->load('ServiceHostingHp', $hpId);

        // Create the product for this hosting plan
        $product = $this->createProductForHostingPlan($hp, $serverId, $planName);

        $plansCreated[$planName] = [
            'hp_id' => $hpId,
            'product_id' => $product->id,
        ];

        $this->di['logger']->info('Created hosting plan and product :name during WHM account import', [':name' => $planName]);

        return [$hp, $product];
    }

    /**
     * Find or create a product for an existing hosting plan.
     */
    protected function findOrCreateProductForHostingPlan(\Model_ServiceHostingHp $hp, int $serverId, string $planName): \Model_Product
    {
        // Try to find an existing product configured for this hosting plan and server
        $products = $this->di['db']->find('Product', "type = 'hosting' AND status = 'enabled'");

        foreach ($products as $product) {
            $config = json_decode($product->config ?? '{}', true);
            if (isset($config['hosting_plan_id']) && $config['hosting_plan_id'] == $hp->id
                && isset($config['server_id']) && $config['server_id'] == $serverId) {
                // Ensure product has valid payment configuration
                if (empty($product->product_payment_id)) {
                    $this->fixProductPayment($product);
                }

                return $product;
            }
        }

        // No existing product found, create one
        return $this->createProductForHostingPlan($hp, $serverId, $planName);
    }

    /**
     * Fix a product that doesn't have proper payment configuration.
     */
    protected function fixProductPayment(\Model_Product $product): void
    {
        // Create ProductPayment with recurrent pricing
        $modelPayment = $this->di['db']->dispense('ProductPayment');
        $modelPayment->type = \Model_ProductPayment::RECURRENT;

        // Set default prices to 0
        $modelPayment->w_price = 0;
        $modelPayment->w_setup_price = 0;
        $modelPayment->w_enabled = 0;

        $modelPayment->m_price = 0;
        $modelPayment->m_setup_price = 0;
        $modelPayment->m_enabled = 1;

        $modelPayment->q_price = 0;
        $modelPayment->q_setup_price = 0;
        $modelPayment->q_enabled = 1;

        $modelPayment->b_price = 0;
        $modelPayment->b_setup_price = 0;
        $modelPayment->b_enabled = 1;

        $modelPayment->a_price = 0;
        $modelPayment->a_setup_price = 0;
        $modelPayment->a_enabled = 1;

        $modelPayment->bia_price = 0;
        $modelPayment->bia_setup_price = 0;
        $modelPayment->bia_enabled = 0;

        $modelPayment->tria_price = 0;
        $modelPayment->tria_setup_price = 0;
        $modelPayment->tria_enabled = 0;

        $paymentId = $this->di['db']->store($modelPayment);

        // Update product with payment ID
        $product->product_payment_id = $paymentId;
        $this->di['db']->store($product);

        $this->di['logger']->info('Fixed product payment for product :id', [':id' => $product->id]);
    }

    /**
     * Create a product for a hosting plan with recurrent pricing.
     */
    protected function createProductForHostingPlan(\Model_ServiceHostingHp $hp, int $serverId, string $planName): \Model_Product
    {
        // Create ProductPayment with recurrent pricing (monthly, quarterly, semi-annual, annual)
        $modelPayment = $this->di['db']->dispense('ProductPayment');
        $modelPayment->type = \Model_ProductPayment::RECURRENT;

        // Set default prices to 0 (admin can adjust later)
        // Monthly (1M)
        $modelPayment->w_price = 0;
        $modelPayment->w_setup_price = 0;
        $modelPayment->w_enabled = 0;

        $modelPayment->m_price = 0;
        $modelPayment->m_setup_price = 0;
        $modelPayment->m_enabled = 1;

        // Quarterly (3M)
        $modelPayment->q_price = 0;
        $modelPayment->q_setup_price = 0;
        $modelPayment->q_enabled = 1;

        // Semi-annual (6M)
        $modelPayment->b_price = 0;
        $modelPayment->b_setup_price = 0;
        $modelPayment->b_enabled = 1;

        // Annual (1Y)
        $modelPayment->a_price = 0;
        $modelPayment->a_setup_price = 0;
        $modelPayment->a_enabled = 1;

        // Biennial (2Y)
        $modelPayment->bia_price = 0;
        $modelPayment->bia_setup_price = 0;
        $modelPayment->bia_enabled = 0;

        // Triennial (3Y)
        $modelPayment->tria_price = 0;
        $modelPayment->tria_setup_price = 0;
        $modelPayment->tria_enabled = 0;

        $paymentId = $this->di['db']->store($modelPayment);

        // Create the product
        $model = $this->di['db']->dispense('Product');
        $model->product_payment_id = $paymentId;
        $model->product_category_id = $this->getOrCreateHostingCategory();
        $model->status = \Model_Product::STATUS_ENABLED;
        $model->title = 'Hosting - ' . $planName;
        $model->slug = $this->di['tools']->slug('hosting-' . $planName);
        $model->type = 'hosting';
        $model->setup = 'after_order';
        $model->hidden = 0;

        // Configure hosting settings
        $model->config = json_encode([
            'server_id' => $serverId,
            'hosting_plan_id' => $hp->id,
        ]);

        $model->description = 'Hosting plan imported from cPanel/WHM: ' . $planName;
        $model->updated_at = date('Y-m-d H:i:s');
        $model->created_at = date('Y-m-d H:i:s');

        // Try to save, handle duplicate slug
        try {
            $this->di['db']->store($model);
        } catch (\Exception $e) {
            $model->slug = $this->di['tools']->slug('hosting-' . $planName) . '-' . random_int(1, 9999);
            $this->di['db']->store($model);
        }

        $this->di['logger']->info('Created product :title for hosting plan', [':title' => $model->title]);

        return $model;
    }

    /**
     * Get or create a product category for hosting products.
     */
    protected function getOrCreateHostingCategory(): int
    {
        // Try to find existing hosting category
        $category = $this->di['db']->findOne('ProductCategory', "title LIKE '%Hosting%' OR title LIKE '%hosting%'");

        if ($category) {
            return $category->id;
        }

        // Create hosting category
        $category = $this->di['db']->dispense('ProductCategory');
        $category->title = 'Web Hosting';
        $category->description = 'Web hosting plans';
        $category->updated_at = date('Y-m-d H:i:s');
        $category->created_at = date('Y-m-d H:i:s');

        return $this->di['db']->store($category);
    }

    /**
     * Find existing client by email or create a new one.
     *
     * @param array  $acct            Account data from WHM
     * @param int    $clientGroupId   Client group ID
     * @param string $duplicateAction Action for duplicate clients: use_existing, update_existing, create_new
     */
    protected function findOrCreateClient(array $acct, int $clientGroupId, string $duplicateAction = 'use_existing'): \Model_Client
    {
        $email = !empty($acct['email']) ? $acct['email'] : $acct['user'] . '@' . $acct['domain'];

        // Try to find existing client by email
        $existingClient = $this->di['db']->findOne('Client', 'email = ?', [$email]);

        if ($existingClient) {
            switch ($duplicateAction) {
                case 'update_existing':
                    // Update existing client with WHM data including creation date
                    $existingClient->first_name = ucfirst($acct['user']);
                    // Update created_at with WHM date (prefer unix timestamp)
                    $createdAt = $this->parseWhmDate($acct['unix_startdate'] ?? null, $acct['startdate'] ?? null);
                    $existingClient->created_at = $createdAt;
                    $existingClient->updated_at = date('Y-m-d H:i:s');
                    $this->di['db']->store($existingClient);
                    $this->di['logger']->info('Updated existing client :email during WHM import with date :date', [
                        ':email' => $email,
                        ':date' => $createdAt,
                    ]);

                    return $existingClient;

                case 'create_new':
                    // Create new client with modified email
                    $newEmail = $this->generateUniqueEmail($email);
                    $client = $this->createNewClient($newEmail, $acct, $clientGroupId);
                    $this->di['logger']->info('Created new client :email (original: :original) during WHM import', [
                        ':email' => $newEmail,
                        ':original' => $email,
                    ]);

                    return $client;

                case 'use_existing':
                default:
                    // Use existing client as-is
                    return $existingClient;
            }
        }

        // Create new client
        return $this->createNewClient($email, $acct, $clientGroupId);
    }

    /**
     * Generate a unique email by adding a suffix.
     */
    protected function generateUniqueEmail(string $email): string
    {
        $parts = explode('@', $email);
        $localPart = $parts[0];
        $domain = $parts[1] ?? 'localhost';

        $counter = 1;
        do {
            $newEmail = $localPart . '_imported' . $counter . '@' . $domain;
            $existing = $this->di['db']->findOne('Client', 'email = ?', [$newEmail]);
            $counter++;
        } while ($existing && $counter < 100);

        return $newEmail;
    }

    /**
     * Create a new client record.
     * Uses the cPanel account creation date as the client registration date.
     */
    protected function createNewClient(string $email, array $acct, int $clientGroupId): \Model_Client
    {
        $client = $this->di['db']->dispense('Client');
        $client->email = $email;
        $client->first_name = ucfirst($acct['user']);
        $client->last_name = 'Imported';
        $client->status = 'active';
        $client->email_approved = true;
        $client->client_group_id = $clientGroupId;

        // Use cPanel account creation date for client registration (prefer unix timestamp)
        $createdAt = $this->parseWhmDate($acct['unix_startdate'] ?? null, $acct['startdate'] ?? null);
        $client->created_at = $createdAt;
        $client->updated_at = date('Y-m-d H:i:s');

        // Generate a random password hash
        $passwordManager = $this->di['password'];
        $client->pass = $passwordManager->hashIt(bin2hex(random_bytes(16)));

        $this->di['db']->store($client);

        $this->di['logger']->info('Created client :email during WHM import with date :date', [
            ':email' => $email,
            ':date' => $createdAt,
        ]);

        return $client;
    }

    /**
     * Update an existing hosting account with new data from WHM.
     */
    protected function updateExistingAccount(
        \Model_ServiceHosting $existingService,
        array $acct,
        int $serverId,
        array $packagesByName,
        array &$plansCreated,
        int $clientGroupId,
        string $duplicateAction
    ): array {
        // Get or create hosting plan and product based on cPanel package name
        $planName = $acct['plan'] ?? 'default';
        [$hostingPlan, $product] = $this->findOrCreateHostingPlanAndProduct($planName, $serverId, $packagesByName, $plansCreated);

        // Parse domain into SLD and TLD
        [$sld, $tld] = $this->parseDomain($acct['domain']);

        // Find or create client
        $client = $this->findOrCreateClient($acct, $clientGroupId, $duplicateAction);

        // Parse cPanel account creation date (prefer unix timestamp)
        $createdAt = $this->parseWhmDate($acct['unix_startdate'] ?? null, $acct['startdate'] ?? null);

        // Update existing service with ALL data including dates
        $existingService->client_id = $client->id;
        $existingService->service_hosting_hp_id = $hostingPlan->id;
        $existingService->sld = $sld;
        $existingService->tld = $tld;
        $existingService->ip = $acct['ip'];
        $existingService->reseller = $acct['is_reseller'] ?? false;
        $existingService->created_at = $createdAt;  // Update creation date from cPanel
        $existingService->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($existingService);

        // Also update client created_at if needed
        if ($client->created_at > $createdAt) {
            $client->created_at = $createdAt;
            $this->di['db']->store($client);
        }

        // Find and update existing order, or create new one
        $existingOrder = $this->di['db']->findOne('ClientOrder', 'service_id = ? AND service_type = ?', [
            $existingService->id,
            'hosting',
        ]);

        if ($existingOrder) {
            $existingOrder->client_id = $client->id;
            $existingOrder->product_id = $product->id;
            $existingOrder->title = $product->title . ' - ' . $acct['domain'];
            $existingOrder->status = $acct['suspended'] ? 'suspended' : 'active';
            $existingOrder->config = json_encode([
                'server_id' => $serverId,
                'hosting_plan_id' => $hostingPlan->id,
                'sld' => $sld,
                'tld' => $tld,
                'import' => true,
                'domain' => [
                    'action' => 'owndomain',
                    'owndomain_sld' => $sld,
                    'owndomain_tld' => $tld,
                ],
            ]);
            // Update ALL dates from cPanel
            $existingOrder->created_at = $createdAt;
            $existingOrder->activated_at = $acct['suspended'] ? null : $createdAt;
            $existingOrder->updated_at = date('Y-m-d H:i:s');
            $orderId = $this->di['db']->store($existingOrder);
        } else {
            $orderId = $this->createOrderForService($client, $existingService, $acct, $product);
        }

        $this->di['logger']->info('Updated existing account :username during WHM import with date :date', [
            ':username' => $acct['user'],
            ':date' => $createdAt,
        ]);

        return [
            'username' => $acct['user'],
            'domain' => $acct['domain'],
            'plan' => $planName,
            'client_id' => $client->id,
            'service_id' => $existingService->id,
            'order_id' => $orderId,
            'is_reseller' => $acct['is_reseller'] ?? false,
        ];
    }

    /**
     * Delete an existing hosting account and its order.
     */
    protected function deleteExistingAccount(\Model_ServiceHosting $existingService): void
    {
        // Find and delete associated order
        $existingOrder = $this->di['db']->findOne('ClientOrder', 'service_id = ? AND service_type = ?', [
            $existingService->id,
            'hosting',
        ]);

        if ($existingOrder) {
            $this->di['db']->trash($existingOrder);
        }

        // Delete the service
        $this->di['db']->trash($existingService);

        $this->di['logger']->info('Deleted existing account :username during WHM reimport', [':username' => $existingService->username]);
    }

    /**
     * Create an order for the imported service.
     * Uses the product that was created/found for the hosting plan.
     */
    protected function createOrderForService(\Model_Client $client, \Model_ServiceHosting $service, array $acct, \Model_Product $product): int
    {
        // Create order linked to the correct product
        $order = $this->di['db']->dispense('ClientOrder');
        $order->client_id = $client->id;
        $order->product_id = $product->id;
        $order->group_id = uniqid();
        $order->group_master = 1;
        $order->title = $product->title . ' - ' . $acct['domain'];
        $order->currency = $this->getDefaultCurrency();
        $order->service_id = $service->id;
        $order->service_type = 'hosting';
        $order->period = '1Y';  // Annual by default for imported accounts
        $order->quantity = 1;
        $order->unit = $product->unit ?? 'product';
        $order->price = 0;  // Price is 0 for imported accounts
        $order->discount = 0;
        $order->status = $acct['suspended'] ? 'suspended' : 'active';
        $order->invoice_option = 'no-invoice';
        $order->config = json_encode([
            'server_id' => $service->service_hosting_server_id,
            'hosting_plan_id' => $service->service_hosting_hp_id,
            'sld' => $service->sld,
            'tld' => $service->tld,
            'import' => true,
            'domain' => [
                'action' => 'owndomain',
                'owndomain_sld' => $service->sld,
                'owndomain_tld' => $service->tld,
            ],
        ]);

        // Parse WHM date format to MySQL datetime format (prefer unix timestamp)
        $createdAt = $this->parseWhmDate($acct['unix_startdate'] ?? null, $acct['startdate'] ?? null);
        $order->created_at = $createdAt;
        $order->updated_at = date('Y-m-d H:i:s');

        // Set activation date if account is active
        if (!$acct['suspended']) {
            $order->activated_at = $createdAt;
        }

        return $this->di['db']->store($order);
    }

    /**
     * Find or create a generic hosting product (deprecated, kept for backward compatibility).
     * @deprecated Use findOrCreateHostingPlanAndProduct instead
     */
    protected function findOrCreateHostingProduct(\Model_ServiceHosting $service): \Model_Product
    {
        // Look for an existing hosting product configured for this server and plan
        $products = $this->di['db']->find('Product', "type = 'hosting' AND status = 'enabled'");

        foreach ($products as $product) {
            $config = json_decode($product->config ?? '{}', true);
            if (isset($config['hosting_plan_id']) && $config['hosting_plan_id'] == $service->service_hosting_hp_id
                && isset($config['server_id']) && $config['server_id'] == $service->service_hosting_server_id) {
                return $product;
            }
        }

        // No matching product found, create one
        $hp = $this->di['db']->load('ServiceHostingHp', $service->service_hosting_hp_id);

        return $this->createProductForHostingPlan($hp, $service->service_hosting_server_id, $hp->name ?? 'Imported');
    }

    /**
     * Parse WHM date to MySQL datetime format.
     * WHM provides both a unix_startdate (Unix timestamp) and startdate (string format "YY Mon DD HH:MM").
     * We prefer unix_startdate as it's unambiguous.
     *
     * @param string|int|null $unixStartDate Unix timestamp from WHM (preferred)
     * @param string|null     $startDate     String date from WHM (format: "YY Mon DD HH:MM")
     *
     * @return string MySQL datetime format (Y-m-d H:i:s)
     */
    protected function parseWhmDate($unixStartDate = null, ?string $startDate = null): string
    {
        // Prefer unix timestamp as it's unambiguous
        if (!empty($unixStartDate) && is_numeric($unixStartDate)) {
            return date('Y-m-d H:i:s', (int) $unixStartDate);
        }

        if (empty($startDate)) {
            return date('Y-m-d H:i:s');
        }

        // Handle if startDate is a unix timestamp (legacy support)
        if (is_numeric($startDate)) {
            return date('Y-m-d H:i:s', (int) $startDate);
        }

        // Try to parse WHM format: "YY Mon DD HH:MM"
        // Example: "25 May 19 01:00" -> May 19, 2025 01:00:00
        if (preg_match('/^(\d{2})\s+(\w+)\s+(\d{1,2})\s+(\d{2}:\d{2})$/', $startDate, $matches)) {
            $year = (int) $matches[1];
            $month = $matches[2];
            $day = (int) $matches[3];
            $time = $matches[4];

            // Convert 2-digit year to 4-digit (assume 2000s for years < 70)
            $fullYear = $year < 70 ? 2000 + $year : 1900 + $year;

            $dateStr = "$day $month $fullYear $time";
            $timestamp = strtotime($dateStr);
            if ($timestamp !== false) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }

        // Try standard parsing as fallback
        $timestamp = strtotime($startDate);
        if ($timestamp !== false) {
            return date('Y-m-d H:i:s', $timestamp);
        }

        // Fallback to current date
        $this->di['logger']->warning('Could not parse WHM date: :date', [':date' => $startDate]);

        return date('Y-m-d H:i:s');
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

    /**
     * Fix all products that are missing payment configuration.
     * This repairs products that were imported without proper pricing.
     */
    public function fixAllProductPayments(): array
    {
        $products = $this->di['db']->find('Product', '1');
        $fixed = [];
        $alreadyOk = 0;

        foreach ($products as $product) {
            if (empty($product->product_payment_id)) {
                $this->fixProductPayment($product);
                $fixed[] = [
                    'id' => $product->id,
                    'title' => $product->title,
                ];
            } else {
                $alreadyOk++;
            }
        }

        $this->di['logger']->info('Fixed :count products with missing payment configuration', [':count' => count($fixed)]);

        return [
            'fixed' => $fixed,
            'fixed_count' => count($fixed),
            'already_ok' => $alreadyOk,
        ];
    }

    /**
     * Delete all imported clients.
     * This will delete clients that have orders for hosting products.
     *
     * @param bool $onlyImported If true, only delete clients with hosting services
     *
     * @return array Results with deleted count
     */
    public function deleteClients(bool $onlyImported = true): array
    {
        $deleted = [];
        $errors = [];

        if ($onlyImported) {
            // Only delete clients that have hosting orders (imported from WHM)
            $sql = "SELECT DISTINCT c.* FROM client c
                    INNER JOIN client_order co ON c.id = co.client_id
                    WHERE co.service_type = 'hosting'";
            $clients = $this->di['db']->getAll($sql);

            foreach ($clients as $clientData) {
                try {
                    $client = $this->di['db']->load('Client', $clientData['id']);
                    if ($client) {
                        // Delete all orders for this client
                        $orders = $this->di['db']->find('ClientOrder', 'client_id = ?', [$client->id]);
                        foreach ($orders as $order) {
                            // Delete associated service if hosting
                            if ($order->service_type === 'hosting' && $order->service_id) {
                                $service = $this->di['db']->load('ServiceHosting', $order->service_id);
                                if ($service) {
                                    $this->di['db']->trash($service);
                                }
                            }
                            $this->di['db']->trash($order);
                        }

                        // Delete client
                        $this->di['db']->trash($client);
                        $deleted[] = [
                            'id' => $clientData['id'],
                            'email' => $clientData['email'],
                            'name' => $clientData['first_name'] . ' ' . $clientData['last_name'],
                        ];
                    }
                } catch (\Exception $e) {
                    $errors[] = [
                        'id' => $clientData['id'],
                        'error' => $e->getMessage(),
                    ];
                }
            }
        } else {
            // Delete ALL clients (dangerous)
            $clients = $this->di['db']->find('Client', '1');
            foreach ($clients as $client) {
                try {
                    // Delete all orders for this client
                    $orders = $this->di['db']->find('ClientOrder', 'client_id = ?', [$client->id]);
                    foreach ($orders as $order) {
                        if ($order->service_type === 'hosting' && $order->service_id) {
                            $service = $this->di['db']->load('ServiceHosting', $order->service_id);
                            if ($service) {
                                $this->di['db']->trash($service);
                            }
                        }
                        $this->di['db']->trash($order);
                    }

                    $deleted[] = [
                        'id' => $client->id,
                        'email' => $client->email,
                        'name' => $client->first_name . ' ' . $client->last_name,
                    ];
                    $this->di['db']->trash($client);
                } catch (\Exception $e) {
                    $errors[] = [
                        'id' => $client->id,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        $this->di['logger']->info('Deleted :count clients during WHM cleanup', [':count' => count($deleted)]);

        return [
            'deleted' => $deleted,
            'deleted_count' => count($deleted),
            'errors' => $errors,
        ];
    }

    /**
     * Delete all hosting packages (plans).
     *
     * @return array Results with deleted count
     */
    public function deleteHostingPlans(): array
    {
        $deleted = [];
        $errors = [];

        $plans = $this->di['db']->find('ServiceHostingHp', '1');

        foreach ($plans as $plan) {
            try {
                // Check if plan is in use by any service
                $inUse = $this->di['db']->findOne('ServiceHosting', 'service_hosting_hp_id = ?', [$plan->id]);

                if ($inUse) {
                    $errors[] = [
                        'id' => $plan->id,
                        'name' => $plan->name,
                        'error' => 'Plan is in use by hosting services',
                    ];
                    continue;
                }

                $deleted[] = [
                    'id' => $plan->id,
                    'name' => $plan->name,
                ];
                $this->di['db']->trash($plan);
            } catch (\Exception $e) {
                $errors[] = [
                    'id' => $plan->id,
                    'name' => $plan->name ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->di['logger']->info('Deleted :count hosting plans during WHM cleanup', [':count' => count($deleted)]);

        return [
            'deleted' => $deleted,
            'deleted_count' => count($deleted),
            'errors' => $errors,
        ];
    }

    /**
     * Delete all hosting products.
     *
     * @return array Results with deleted count
     */
    public function deleteHostingProducts(): array
    {
        $deleted = [];
        $errors = [];

        $products = $this->di['db']->find('Product', "type = 'hosting'");

        foreach ($products as $product) {
            try {
                // Check if product is in use by any order
                $inUse = $this->di['db']->findOne('ClientOrder', 'product_id = ?', [$product->id]);

                if ($inUse) {
                    $errors[] = [
                        'id' => $product->id,
                        'title' => $product->title,
                        'error' => 'Product is in use by client orders',
                    ];
                    continue;
                }

                // Delete associated payment configuration
                if ($product->product_payment_id) {
                    $payment = $this->di['db']->load('ProductPayment', $product->product_payment_id);
                    if ($payment) {
                        $this->di['db']->trash($payment);
                    }
                }

                $deleted[] = [
                    'id' => $product->id,
                    'title' => $product->title,
                ];
                $this->di['db']->trash($product);
            } catch (\Exception $e) {
                $errors[] = [
                    'id' => $product->id,
                    'title' => $product->title ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->di['logger']->info('Deleted :count hosting products during WHM cleanup', [':count' => count($deleted)]);

        return [
            'deleted' => $deleted,
            'deleted_count' => count($deleted),
            'errors' => $errors,
        ];
    }

    /**
     * Delete all hosting services (without deleting clients).
     *
     * @return array Results with deleted count
     */
    public function deleteHostingServices(): array
    {
        $deleted = [];
        $errors = [];

        $services = $this->di['db']->find('ServiceHosting', '1');

        foreach ($services as $service) {
            try {
                // Delete associated order
                $order = $this->di['db']->findOne('ClientOrder', 'service_id = ? AND service_type = ?', [
                    $service->id,
                    'hosting',
                ]);

                if ($order) {
                    $this->di['db']->trash($order);
                }

                $deleted[] = [
                    'id' => $service->id,
                    'username' => $service->username,
                    'domain' => $service->sld . $service->tld,
                ];
                $this->di['db']->trash($service);
            } catch (\Exception $e) {
                $errors[] = [
                    'id' => $service->id,
                    'username' => $service->username ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->di['logger']->info('Deleted :count hosting services during WHM cleanup', [':count' => count($deleted)]);

        return [
            'deleted' => $deleted,
            'deleted_count' => count($deleted),
            'errors' => $errors,
        ];
    }

    /**
     * Delete everything imported from WHM (clients, services, orders, products, plans).
     * This is a complete cleanup operation.
     *
     * @return array Results with all deleted counts
     */
    public function deleteAll(): array
    {
        // Order matters: delete in reverse dependency order
        // 1. Delete hosting services (and their orders)
        $services = $this->deleteHostingServices();

        // 2. Delete clients that had hosting services
        $clients = $this->deleteClients(true);

        // 3. Delete hosting products
        $products = $this->deleteHostingProducts();

        // 4. Delete hosting plans
        $plans = $this->deleteHostingPlans();

        $this->di['logger']->info('Complete WHM cleanup performed');

        return [
            'services' => $services,
            'clients' => $clients,
            'products' => $products,
            'plans' => $plans,
            'total_deleted' => $services['deleted_count'] + $clients['deleted_count'] + $products['deleted_count'] + $plans['deleted_count'],
        ];
    }

    /**
     * Debug: Get raw date information from WHM accounts.
     * This helps diagnose date parsing issues.
     */
    public function debugDates(int $serverId): array
    {
        $server = $this->getServer($serverId);
        $response = $this->whmRequest($server, 'listaccts');

        // Get list of resellers to identify reseller accounts
        $resellerList = [];
        try {
            $resellerList = $this->getRemoteResellers($serverId);
        } catch (\Exception $e) {
            // If we can't get resellers, continue without that info
            $this->di['logger']->warning('Could not fetch reseller list for debug: :error', [
                ':error' => $e->getMessage(),
            ]);
        }

        // Get raw account list
        $accountList = [];
        if (isset($response->acct) && is_array($response->acct)) {
            $accountList = $response->acct;
        } elseif (isset($response->data->acct) && is_array($response->data->acct)) {
            $accountList = $response->data->acct;
        }

        $results = [];
        $resellerCount = 0;
        $subAccountCount = 0;

        foreach ($accountList as $acct) {
            $rawStartDate = $acct->startdate ?? null;
            $unixStartDate = $acct->unix_startdate ?? null;
            $username = $acct->user ?? 'unknown';
            $owner = $acct->owner ?? 'root';
            $isReseller = in_array($username, $resellerList, true);
            $isSubAccount = $owner !== 'root';

            if ($isReseller) {
                $resellerCount++;
            }
            if ($isSubAccount) {
                $subAccountCount++;
            }

            // Test our parsing function (now uses unix_startdate preferentially)
            $parsedDate = $this->parseWhmDate($unixStartDate, $rawStartDate);

            $results[] = [
                'user' => $username,
                'domain' => $acct->domain ?? 'unknown',
                'owner' => $owner,
                'is_reseller' => $isReseller,
                'is_sub_account' => $isSubAccount,
                'raw_startdate' => $rawStartDate,
                'unix_startdate' => $unixStartDate,
                'unix_as_date' => $unixStartDate ? date('Y-m-d H:i:s', (int) $unixStartDate) : null,
                'parsed_result' => $parsedDate,
                'raw_type' => gettype($rawStartDate),
            ];
        }

        return [
            'server' => $server->name,
            'total_accounts' => count($accountList),
            'reseller_count' => $resellerCount,
            'sub_account_count' => $subAccountCount,
            'reseller_list' => $resellerList,
            'accounts' => $results,
            'note' => 'Showing all accounts. is_reseller=true means the account has reseller privileges. is_sub_account=true means the account is owned by a reseller (not root).',
        ];
    }
}
