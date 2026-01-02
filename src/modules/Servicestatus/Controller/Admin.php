<?php

/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Servicestatus\Controller;

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

    /**
     * Return admin navigation items.
     */
    public function fetchNavigation()
    {
        return [
            'subpages' => [
                [
                    'location' => 'system',
                    'index' => 800,
                    'label' => __trans('Service Status'),
                    'uri' => $this->di['url']->adminLink('servicestatus'),
                    'class' => '',
                ],
            ],
        ];
    }

    /**
     * Register admin routes.
     */
    public function register(\Box_App &$app)
    {
        $app->get('/servicestatus', 'get_index', [], static::class);
        $app->get('/servicestatus/', 'get_index', [], static::class);
        $app->get('/servicestatus/component/:id', 'get_component', ['id' => '[0-9]+'], static::class);
        $app->get('/servicestatus/incident/:id', 'get_incident', ['id' => '[0-9]+'], static::class);
    }

    /**
     * Display admin index page.
     */
    public function get_index(\Box_App $app)
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_servicestatus_index');
    }

    /**
     * Display component edit page.
     */
    public function get_component(\Box_App $app, $id)
    {
        $this->di['is_admin_logged'];
        $api = $this->di['api_admin'];
        $component = $api->servicestatus_component_get(['id' => $id]);

        return $app->render('mod_servicestatus_component', ['component' => $component]);
    }

    /**
     * Display incident edit page.
     */
    public function get_incident(\Box_App $app, $id)
    {
        $this->di['is_admin_logged'];
        $api = $this->di['api_admin'];
        $incident = $api->servicestatus_incident_get(['id' => $id]);

        return $app->render('mod_servicestatus_incident', ['incident' => $incident]);
    }
}
