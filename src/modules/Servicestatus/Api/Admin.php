<?php

/**
 * FOSSBilling.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license   Apache-2.0
 */

namespace Box\Mod\Servicestatus\Api;

class Admin extends \Api_Abstract
{
    /**
     * Get available component statuses.
     *
     * @return array
     */
    public function get_statuses($data = [])
    {
        return $this->getService()->getComponentStatuses();
    }

    /**
     * Get available incident statuses.
     *
     * @return array
     */
    public function get_incident_statuses($data = [])
    {
        return $this->getService()->getIncidentStatuses();
    }

    /**
     * Get overall system status.
     *
     * @return array
     */
    public function get_overall_status($data = [])
    {
        return $this->getService()->getOverallStatus();
    }

    // ==================== COMPONENT ENDPOINTS ====================

    /**
     * Get list of all components.
     *
     * @return array
     */
    public function component_get_list($data = [])
    {
        return $this->getService()->getComponents();
    }

    /**
     * Get a single component by ID.
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

        if (!$component) {
            throw new \FOSSBilling\Exception('Component not found');
        }

        return $component;
    }

    /**
     * Create a new component.
     *
     * @param string $name         Component name
     * @param string $description  Component description (optional)
     * @param string $status       Component status (optional, default: operational)
     * @param int    $display_order Display order (optional)
     * @param string $group_name   Group name for grouping components (optional)
     * @param bool   $is_visible   Whether component is visible to public (optional, default: true)
     *
     * @return int Created component ID
     */
    public function component_create($data)
    {
        $required = ['name' => 'Component name is required'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $id = $this->getService()->createComponent($data);

        $this->di['logger']->info('Created service status component #%s', $id);

        return $id;
    }

    /**
     * Update a component.
     *
     * @param int    $id           Component ID
     * @param string $name         Component name (optional)
     * @param string $description  Component description (optional)
     * @param string $status       Component status (optional)
     * @param int    $display_order Display order (optional)
     * @param string $group_name   Group name (optional)
     * @param bool   $is_visible   Visibility (optional)
     *
     * @return bool
     */
    public function component_update($data)
    {
        $required = ['id' => 'Component ID is required'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $component = $this->getService()->getComponent((int) $data['id']);

        if (!$component) {
            throw new \FOSSBilling\Exception('Component not found');
        }

        $this->getService()->updateComponent((int) $data['id'], $data);

        $this->di['logger']->info('Updated service status component #%s', $data['id']);

        return true;
    }

    /**
     * Delete a component.
     *
     * @param int $id Component ID
     *
     * @return bool
     */
    public function component_delete($data)
    {
        $required = ['id' => 'Component ID is required'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $this->getService()->deleteComponent((int) $data['id']);

        $this->di['logger']->info('Deleted service status component #%s', $data['id']);

        return true;
    }

    /**
     * Batch delete components.
     *
     * @param array $ids Array of component IDs
     *
     * @return bool
     */
    public function component_batch_delete($data)
    {
        $required = ['ids' => 'Component IDs are required'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        foreach ($data['ids'] as $id) {
            $this->component_delete(['id' => $id]);
        }

        return true;
    }

    // ==================== INCIDENT ENDPOINTS ====================

    /**
     * Get list of incidents.
     *
     * @param string $status       Filter by status (optional)
     * @param bool   $is_scheduled Filter by scheduled maintenance (optional)
     * @param bool   $resolved     Filter by resolved state (optional)
     * @param int    $limit        Limit results (optional)
     *
     * @return array
     */
    public function incident_get_list($data = [])
    {
        return $this->getService()->getIncidents($data);
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

    /**
     * Create a new incident.
     *
     * @param string $title           Incident title
     * @param string $status          Incident status (optional)
     * @param string $impact          Impact level: none, minor, major, critical (optional)
     * @param string $message         Initial update message (optional)
     * @param array  $component_ids   Affected component IDs (optional)
     * @param bool   $is_scheduled    Is this scheduled maintenance? (optional)
     * @param string $scheduled_for   Maintenance start time (optional)
     * @param string $scheduled_until Maintenance end time (optional)
     *
     * @return int Created incident ID
     */
    public function incident_create($data)
    {
        $required = ['title' => 'Incident title is required'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $id = $this->getService()->createIncident($data);

        $this->di['logger']->info('Created service status incident #%s', $id);

        return $id;
    }

    /**
     * Update an incident.
     *
     * @param int    $id             Incident ID
     * @param string $title          Incident title (optional)
     * @param string $status         Incident status (optional)
     * @param string $impact         Impact level (optional)
     * @param array  $component_ids  Affected component IDs (optional)
     * @param string $scheduled_for  Maintenance start time (optional)
     * @param string $scheduled_until Maintenance end time (optional)
     *
     * @return bool
     */
    public function incident_update($data)
    {
        $required = ['id' => 'Incident ID is required'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $incident = $this->getService()->getIncident((int) $data['id']);

        if (!$incident) {
            throw new \FOSSBilling\Exception('Incident not found');
        }

        $this->getService()->updateIncident((int) $data['id'], $data);

        $this->di['logger']->info('Updated service status incident #%s', $data['id']);

        return true;
    }

    /**
     * Delete an incident.
     *
     * @param int $id Incident ID
     *
     * @return bool
     */
    public function incident_delete($data)
    {
        $required = ['id' => 'Incident ID is required'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $this->getService()->deleteIncident((int) $data['id']);

        $this->di['logger']->info('Deleted service status incident #%s', $data['id']);

        return true;
    }

    /**
     * Batch delete incidents.
     *
     * @param array $ids Array of incident IDs
     *
     * @return bool
     */
    public function incident_batch_delete($data)
    {
        $required = ['ids' => 'Incident IDs are required'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        foreach ($data['ids'] as $id) {
            $this->incident_delete(['id' => $id]);
        }

        return true;
    }

    /**
     * Add an update to an incident.
     *
     * @param int    $incident_id Incident ID
     * @param string $status      Update status
     * @param string $message     Update message
     *
     * @return int Created update ID
     */
    public function incident_add_update($data)
    {
        $required = [
            'incident_id' => 'Incident ID is required',
            'status' => 'Update status is required',
            'message' => 'Update message is required',
        ];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $incident = $this->getService()->getIncident((int) $data['incident_id']);

        if (!$incident) {
            throw new \FOSSBilling\Exception('Incident not found');
        }

        $id = $this->getService()->addIncidentUpdate((int) $data['incident_id'], $data);

        $this->di['logger']->info('Added update to service status incident #%s', $data['incident_id']);

        return $id;
    }

    /**
     * Delete an incident update.
     *
     * @param int $id Update ID
     *
     * @return bool
     */
    public function incident_delete_update($data)
    {
        $required = ['id' => 'Update ID is required'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $this->getService()->deleteIncidentUpdate((int) $data['id']);

        return true;
    }
}
