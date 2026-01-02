<?php

/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Servicestatus\Controller;

class Client implements \FOSSBilling\InjectionAwareInterface
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
     * Register public routes.
     */
    public function register(\Box_App &$app)
    {
        $app->get('/status', 'get_index', [], static::class);
        $app->get('/status/', 'get_index', [], static::class);
        $app->get('/status/incident/:id', 'get_incident', ['id' => '[0-9]+'], static::class);
        $app->get('/status/history', 'get_history', [], static::class);
    }

    /**
     * Display public status page.
     */
    public function get_index(\Box_App $app)
    {
        return $app->render('mod_servicestatus_index');
    }

    /**
     * Display incident details.
     */
    public function get_incident(\Box_App $app, $id)
    {
        $api = $this->di['api_guest'];
        $incident = $api->servicestatus_incident_get(['id' => $id]);

        return $app->render('mod_servicestatus_incident', ['incident' => $incident]);
    }

    /**
     * Display incident history.
     */
    public function get_history(\Box_App $app)
    {
        return $app->render('mod_servicestatus_history');
    }
}
