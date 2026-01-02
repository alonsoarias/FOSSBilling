<?php

/**
 * FOSSBilling.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license   Apache-2.0
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
     * Register client/public routes.
     */
    public function register(\Box_App &$app): void
    {
        $app->get('/status', 'get_index', [], static::class);
        $app->get('/status/', 'get_index', [], static::class);
        $app->get('/status/incident/:id', 'get_incident', ['id' => '[0-9]+'], static::class);
        $app->get('/status/history', 'get_history', [], static::class);
    }

    /**
     * Display public status page.
     */
    public function get_index(\Box_App $app): string
    {
        return $app->render('mod_servicestatus_index');
    }

    /**
     * Display incident details.
     */
    public function get_incident(\Box_App $app, int $id): string
    {
        $api = $this->di['api_guest'];
        $incident = $api->servicestatus_incident_get(['id' => $id]);

        return $app->render('mod_servicestatus_incident', ['incident' => $incident]);
    }

    /**
     * Display incident history.
     */
    public function get_history(\Box_App $app): string
    {
        return $app->render('mod_servicestatus_history');
    }
}
