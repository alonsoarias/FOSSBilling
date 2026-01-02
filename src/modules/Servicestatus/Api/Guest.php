<?php

/**
 * FOSSBilling.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license   Apache-2.0
 */

namespace Box\Mod\Servicestatus\Api;

class Guest extends \Api_Abstract
{
    /**
     * Get overall system status.
     *
     * @return array Overall status with status, label, and color
     */
    public function get_overall_status($data = [])
    {
        return $this->getService()->getOverallStatus();
    }

    /**
     * Get available component statuses for reference.
     *
     * @return array
     */
    public function get_statuses($data = [])
    {
        return $this->getService()->getComponentStatuses();
    }

    /**
     * Get list of visible components.
     *
     * @return array
     */
    public function component_get_list($data = [])
    {
        return $this->getService()->getVisibleComponents();
    }

    /**
     * Get components grouped by group name.
     *
     * @return array Components grouped by group_name
     */
    public function component_get_grouped($data = [])
    {
        return $this->getService()->getComponentsGrouped();
    }

    /**
     * Get a single visible component by ID.
     *
     * @param int $id Component ID
     *
     * @return array
     */
    public function component_get($data)
    {
        $required = ['id' => 'Component ID is required'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $component = $this->getService()->getComponent((int) $data['id']);

        if (!$component || !$component['is_visible']) {
            throw new \FOSSBilling\Exception('Component not found');
        }

        return $component;
    }

    /**
     * Get active (unresolved) incidents.
     *
     * @return array
     */
    public function incident_get_active($data = [])
    {
        return $this->getService()->getActiveIncidents();
    }

    /**
     * Get scheduled maintenance.
     *
     * @return array
     */
    public function maintenance_get_scheduled($data = [])
    {
        return $this->getService()->getScheduledMaintenance();
    }

    /**
     * Get recent incidents for history display.
     *
     * @param int $days Number of days to look back (default: 7)
     *
     * @return array
     */
    public function incident_get_recent($data = [])
    {
        $days = isset($data['days']) ? (int) $data['days'] : 7;

        return $this->getService()->getRecentIncidents($days);
    }

    /**
     * Get a single incident by ID.
     *
     * @param int $id Incident ID
     *
     * @return array
     */
    public function incident_get($data)
    {
        $required = ['id' => 'Incident ID is required'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $incident = $this->getService()->getIncident((int) $data['id']);

        if (!$incident) {
            throw new \FOSSBilling\Exception('Incident not found');
        }

        return $incident;
    }
}
