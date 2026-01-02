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
     * Get available incident status types.
     *
     * @return array
     */
    public function incident_statuses($data = [])
    {
        return $this->getService()->getIncidentStatuses();
    }
}
