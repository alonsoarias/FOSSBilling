<?php

/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license   http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Whmimporter\Controller;

class Admin implements \FOSSBilling\InjectionAwareInterface
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

    public function fetchNavigation(): array
    {
        return [
            'subpages' => [
                [
                    'location' => 'system',
                    'label' => __trans('WHM Importer'),
                    'uri' => $this->di['url']->adminLink('whmimporter'),
                    'index' => 500,
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/whmimporter', 'get_index', [], static::class);
        $app->get('/whmimporter/packages/:server_id', 'get_packages', ['server_id' => '[0-9]+'], static::class);
        $app->get('/whmimporter/accounts/:server_id', 'get_accounts', ['server_id' => '[0-9]+'], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_whmimporter_index');
    }

    public function get_packages(\Box_App $app, int $server_id): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_whmimporter_packages', ['server_id' => $server_id]);
    }

    public function get_accounts(\Box_App $app, int $server_id): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_whmimporter_accounts', ['server_id' => $server_id]);
    }
}
