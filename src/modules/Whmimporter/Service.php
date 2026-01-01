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

        if ($server->manager !== 'Whm') {
            throw new InformationException('Selected server is not a WHM/cPanel server');
        }

        return $server;
    }

    /**
     * Make a request to WHM API.
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

        $username = $server->username;
        $accessHash = $server->accesshash;
        $password = $server->password;

        if (!empty($accessHash)) {
            $authHeader = 'WHM ' . $username . ':' . preg_replace('/\s+/', '', $accessHash);
        } else {
            $authHeader = 'Basic ' . base64_encode($username . ':' . $password);
        }

        try {
            $response = $client->request('POST', $url, [
                'headers' => ['Authorization' => $authHeader],
                'body' => $params,
            ]);
        } catch (HttpExceptionInterface $error) {
            throw new Exception('WHM API Error: :error', [':error' => $error->getMessage()]);
        }

        $body = $response->getContent();
        $json = json_decode($body);

        if (!is_object($json)) {
            throw new Exception('Invalid response from WHM server');
        }

        if (isset($json->status) && $json->status != '1') {
            throw new Exception('WHM Error: :msg', [':msg' => $json->statusmsg ?? 'Unknown error']);
        }

        return $json;
    }

    /**
     * Get packages from WHM server.
     */
    public function getRemotePackages(int $serverId): array
    {
        $server = $this->getServer($serverId);
        $response = $this->whmRequest($server, 'listpkgs');

        $packages = [];
        if (isset($response->package) && is_array($response->package)) {
            foreach ($response->package as $pkg) {
                $packages[] = [
                    'name' => $pkg->name,
                    'quota' => $pkg->QUOTA ?? 'unlimited',
                    'bandwidth' => $pkg->BWLIMIT ?? 'unlimited',
                    'max_ftp' => $pkg->MAXFTP ?? 'unlimited',
                    'max_sql' => $pkg->MAXSQL ?? 'unlimited',
                    'max_pop' => $pkg->MAXPOP ?? 'unlimited',
                    'max_sub' => $pkg->MAXSUB ?? 'unlimited',
                    'max_park' => $pkg->MAXPARK ?? 'unlimited',
                    'max_addon' => $pkg->MAXADDON ?? 'unlimited',
                    'has_shell' => ($pkg->HASSHELL ?? 'n') !== 'n',
                    'has_cgi' => ($pkg->CGI ?? 'n') !== 'n',
                ];
            }
        }

        return $packages;
    }

    /**
     * Get accounts from WHM server.
     */
    public function getRemoteAccounts(int $serverId): array
    {
        $server = $this->getServer($serverId);
        $response = $this->whmRequest($server, 'listaccts');

        $accounts = [];
        if (isset($response->acct) && is_array($response->acct)) {
            foreach ($response->acct as $acct) {
                $accounts[] = [
                    'domain' => $acct->domain,
                    'user' => $acct->user,
                    'email' => $acct->email ?? '',
                    'owner' => $acct->owner ?? 'root',
                    'plan' => $acct->plan,
                    'ip' => $acct->ip,
                    'suspended' => (bool) ($acct->suspended ?? false),
                    'suspendtime' => $acct->suspendtime ?? null,
                    'startdate' => $acct->startdate ?? null,
                    'diskused' => $acct->diskused ?? '0M',
                    'disklimit' => $acct->disklimit ?? 'unlimited',
                ];
            }
        }

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
                $model->reseller = false;
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
     */
    public function testConnection(int $serverId): bool
    {
        $server = $this->getServer($serverId);

        try {
            $response = $this->whmRequest($server, 'version');

            return isset($response->version);
        } catch (\Exception $e) {
            throw new Exception('Connection failed: :error', [':error' => $e->getMessage()]);
        }
    }
}
