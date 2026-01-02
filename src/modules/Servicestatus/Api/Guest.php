<?php

/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

/**
 * Public Service Status API.
 */

namespace Box\Mod\Servicestatus\Api;

class Guest extends \Api_Abstract
{
    /**
     * Get overall system status.
     *
     * @return array
     */
    public function status($data = [])
    {
        $service = $this->getService();
        $overallStatus = $service->getOverallStatus();
        $statuses = $service->getStatuses();

        return [
            'status' => $overallStatus,
            'label' => $statuses[$overallStatus] ?? $overallStatus,
        ];
    }

    /**
     * Alias for status() for backwards compatibility.
     *
     * @return array
     */
    public function get_overall_status($data = [])
    {
        return $this->status($data);
    }

    /**
     * Get list of all visible components.
     *
     * @return array
     */
    public function component_list($data = [])
    {
        $service = $this->getService();

        return $service->getComponents();
    }

    /**
     * Alias for component_list() - grouped format.
     *
     * @return array
     */
    public function component_get_grouped($data = [])
    {
        return ['components' => $this->component_list($data)];
    }

    /**
     * Get active incidents.
     *
     * @return array
     */
    public function incident_list($data = [])
    {
        $service = $this->getService();

        return $service->getActiveIncidents();
    }

    /**
     * Alias for incident_list().
     *
     * @return array
     */
    public function incident_get_active($data = [])
    {
        return $this->incident_list($data);
    }

    /**
     * Alias for incident_list().
     *
     * @return array
     */
    public function get_active_incidents($data = [])
    {
        return $this->incident_list($data);
    }

    /**
     * Get scheduled maintenance.
     *
     * @return array
     */
    public function maintenance_list($data = [])
    {
        $service = $this->getService();

        return $service->getScheduledMaintenance();
    }

    /**
     * Alias for maintenance_list().
     *
     * @return array
     */
    public function maintenance_get_scheduled($data = [])
    {
        return $this->maintenance_list($data);
    }

    /**
     * Get incident history.
     *
     * @optional int $days - number of days to look back (default 90)
     *
     * @return array
     */
    public function incident_history($data = [])
    {
        $service = $this->getService();
        $days = $data['days'] ?? 90;

        return $service->getIncidentHistory((int) $days);
    }

    /**
     * Alias for incident_history().
     *
     * @return array
     */
    public function incident_get_history($data = [])
    {
        return $this->incident_history($data);
    }

    /**
     * Get incident by ID.
     *
     * @return array
     */
    public function incident_get($data)
    {
        if (!isset($data['id'])) {
            throw new \FOSSBilling\Exception('Incident ID is required');
        }

        $service = $this->getService();
        $incident = $service->getIncident((int) $data['id']);

        if (!$incident) {
            throw new \FOSSBilling\Exception('Incident not found');
        }

        return $incident;
    }

    /**
     * Get available status types.
     *
     * @return array
     */
    public function statuses($data = [])
    {
        return $this->getService()->getStatuses();
    }

    /**
     * Alias for statuses().
     *
     * @return array
     */
    public function get_statuses($data = [])
    {
        return $this->statuses($data);
    }

    /**
     * Get available incident status types.
     *
     * @return array
     */
    public function incident_statuses($data = [])
    {
        return $this->getService()->getIncidentStatuses();
    }

    /**
     * Alias for incident_statuses().
     *
     * @return array
     */
    public function get_incident_statuses($data = [])
    {
        return $this->incident_statuses($data);
    }
}
