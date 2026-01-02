<?php

/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license   http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Whmimporter\Api;

class Admin extends \Api_Abstract
{
    /**
     * Get list of WHM servers configured in FOSSBilling.
     *
     * @return array List of WHM servers
     */
    public function get_servers(): array
    {
        return $this->getService()->getWhmServers();
    }

    /**
     * Test connection to WHM server.
     *
     * @param int $server_id Server ID
     *
     * @return bool True if connection successful
     */
    public function test_connection(array $data): bool
    {
        $required = ['server_id' => 'Server ID is required'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        return $this->getService()->testConnection((int) $data['server_id']);
    }

    /**
     * Get packages from WHM server.
     *
     * @param int $server_id Server ID
     *
     * @return array List of packages from WHM server
     */
    public function get_remote_packages(array $data): array
    {
        $required = ['server_id' => 'Server ID is required'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        return $this->getService()->getRemotePackages((int) $data['server_id']);
    }

    /**
     * Get accounts from WHM server.
     *
     * @param int $server_id Server ID
     *
     * @return array List of accounts from WHM server
     */
    public function get_remote_accounts(array $data): array
    {
        $required = ['server_id' => 'Server ID is required'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        return $this->getService()->getRemoteAccounts((int) $data['server_id']);
    }

    /**
     * Import packages from WHM server as hosting plans.
     *
     * @param int   $server_id Server ID
     * @param array $packages  Array of package names to import
     *
     * @return array Import results with imported and skipped packages
     */
    public function import_packages(array $data): array
    {
        $required = [
            'server_id' => 'Server ID is required',
            'packages' => 'Package names are required',
        ];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $packages = $data['packages'];
        if (is_string($packages)) {
            $packages = json_decode($packages, true) ?? [];
        }

        if (empty($packages)) {
            throw new \FOSSBilling\InformationException('No packages selected for import');
        }

        return $this->getService()->importPackages((int) $data['server_id'], $packages);
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
     * @param int    $server_id                Server ID
     * @param array  $usernames                Array of usernames to import
     * @param int    $client_group_id          Client group ID (optional, default: 1)
     * @param string $duplicate_action         Action for duplicate clients: use_existing, update_existing, create_new (optional, default: use_existing)
     * @param string $account_duplicate_action Action for duplicate accounts: skip, update, recreate (optional, default: update)
     * @param bool   $skip_sub_accounts        Skip accounts owned by resellers (optional, default: true)
     *
     * @return array Import results with imported, skipped, skipped_sub_accounts, errors, and plans_created
     */
    public function import_accounts(array $data): array
    {
        $required = [
            'server_id' => 'Server ID is required',
            'usernames' => 'Usernames are required',
        ];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $usernames = $data['usernames'];
        if (is_string($usernames)) {
            $usernames = json_decode($usernames, true) ?? [];
        }

        if (empty($usernames)) {
            throw new \FOSSBilling\InformationException('No accounts selected for import');
        }

        $clientGroupId = isset($data['client_group_id']) ? (int) $data['client_group_id'] : 1;
        $duplicateAction = $data['duplicate_action'] ?? 'use_existing';
        $accountDuplicateAction = $data['account_duplicate_action'] ?? 'update';
        // By default, skip sub-accounts as they belong to reseller contracts
        $skipSubAccounts = !isset($data['skip_sub_accounts']) || (bool) $data['skip_sub_accounts'];

        // Validate duplicate_action
        $validActions = ['use_existing', 'update_existing', 'create_new'];
        if (!in_array($duplicateAction, $validActions, true)) {
            $duplicateAction = 'use_existing';
        }

        // Validate account_duplicate_action
        $validAccountActions = ['skip', 'update', 'recreate'];
        if (!in_array($accountDuplicateAction, $validAccountActions, true)) {
            $accountDuplicateAction = 'update';
        }

        return $this->getService()->importAccounts(
            (int) $data['server_id'],
            $usernames,
            $clientGroupId,
            $duplicateAction,
            $accountDuplicateAction,
            $skipSubAccounts
        );
    }

    /**
     * Get existing hosting plans.
     *
     * @return array List of hosting plans
     */
    public function get_hosting_plans(): array
    {
        return $this->getService()->getHostingPlans();
    }

    /**
     * Get client groups.
     *
     * @return array List of client groups
     */
    public function get_client_groups(): array
    {
        return $this->getService()->getClientGroups();
    }

    /**
     * Fix products that were imported without proper payment configuration.
     * This will add recurrent pricing to all hosting products that are missing it.
     *
     * @return array Results with fixed product count
     */
    public function fix_product_payments(): array
    {
        return $this->getService()->fixAllProductPayments();
    }

    /**
     * Debug: Get raw account data from WHM to inspect date formats.
     *
     * @param int $server_id Server ID
     *
     * @return array Raw account data with date fields highlighted
     */
    public function debug_dates(array $data): array
    {
        $required = [
            'server_id' => 'Server ID is required',
        ];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        return $this->getService()->debugDates((int) $data['server_id']);
    }

    /**
     * Delete all clients that have hosting services.
     *
     * @param bool $only_imported If true, only delete clients with hosting services (default: true)
     *
     * @return array Results with deleted count
     */
    public function delete_clients(array $data = []): array
    {
        $onlyImported = isset($data['only_imported']) ? (bool) $data['only_imported'] : true;

        return $this->getService()->deleteClients($onlyImported);
    }

    /**
     * Delete all hosting plans.
     *
     * @return array Results with deleted count
     */
    public function delete_hosting_plans(): array
    {
        return $this->getService()->deleteHostingPlans();
    }

    /**
     * Delete all hosting products.
     *
     * @return array Results with deleted count
     */
    public function delete_hosting_products(): array
    {
        return $this->getService()->deleteHostingProducts();
    }

    /**
     * Delete all hosting services (accounts) and their orders.
     *
     * @return array Results with deleted count
     */
    public function delete_hosting_services(): array
    {
        return $this->getService()->deleteHostingServices();
    }

    /**
     * Delete everything imported from WHM (clients, services, orders, products, plans).
     * This is a complete cleanup operation.
     *
     * @return array Results with all deleted counts
     */
    public function delete_all(): array
    {
        return $this->getService()->deleteAll();
    }
}
