<?php

/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

/**
 * Service Status Administration API.
 */

namespace Box\Mod\Servicestatus\Api;

class Admin extends \Api_Abstract
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
     * Alias for status() - backwards compatibility.
     *
     * @return array
     */
    public function get_overall_status($data = [])
    {
        return $this->status($data);
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

    // ==================== COMPONENT ENDPOINTS ====================

    /**
     * Get list of all components (including hidden).
     *
     * @return array
     */
    public function component_list($data = [])
    {
        return $this->getService()->getComponentsAdmin();
    }

    /**
     * Get component by ID.
     *
     * @return array
     */
    public function component_get($data)
    {
        if (!isset($data['id'])) {
            throw new \FOSSBilling\Exception('Component ID is required');
        }

        $component = $this->getService()->getComponent((int) $data['id']);

        if (!$component) {
            throw new \FOSSBilling\Exception('Component not found');
        }

        return $component;
    }

    /**
     * Create a new component.
     *
     * @optional string $description - component description
     * @optional string $status - component status (default: operational)
     * @optional int $display_order - display order (default: 0)
     * @optional bool $is_visible - visibility status (default: true)
     *
     * @return int - new component ID
     */
    public function component_create($data)
    {
        $required = [
            'name' => 'Component name is required',
        ];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        return $this->getService()->createComponent($data);
    }

    /**
     * Update a component.
     *
     * @optional string $description - component description
     * @optional string $status - component status
     * @optional int $display_order - display order
     * @optional bool $is_visible - visibility status
     *
     * @return bool
     */
    public function component_update($data)
    {
        $required = [
            'id' => 'Component ID is required',
            'name' => 'Component name is required',
        ];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        return $this->getService()->updateComponent((int) $data['id'], $data);
    }

    /**
     * Delete a component.
     *
     * @return bool
     */
    public function component_delete($data)
    {
        $required = [
            'id' => 'Component ID is required',
        ];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        return $this->getService()->deleteComponent((int) $data['id']);
    }

    /**
     * Update component status quickly.
     *
     * @return bool
     */
    public function component_status_update($data)
    {
        $required = [
            'id' => 'Component ID is required',
            'status' => 'Status is required',
        ];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $component = $this->getService()->getComponent((int) $data['id']);
        if (!$component) {
            throw new \FOSSBilling\Exception('Component not found');
        }

        $component['status'] = $data['status'];

        return $this->getService()->updateComponent((int) $data['id'], $component);
    }

    // ==================== INCIDENT ENDPOINTS ====================

    /**
     * Get list of all incidents.
     *
     * @optional string $status - filter by status
     *
     * @return array
     */
    public function incident_list($data = [])
    {
        return $this->getService()->getIncidentsAdmin($data);
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

        $incident = $this->getService()->getIncident((int) $data['id']);

        if (!$incident) {
            throw new \FOSSBilling\Exception('Incident not found');
        }

        return $incident;
    }

    /**
     * Create a new incident.
     *
     * @optional string $status - incident status (default: investigating)
     * @optional string $impact - impact level (default: partial_outage)
     * @optional string $message - initial update message
     * @optional array $components - array of affected component IDs
     * @optional string $scheduled_at - for scheduled maintenance
     *
     * @return int - new incident ID
     */
    public function incident_create($data)
    {
        $required = [
            'title' => 'Incident title is required',
        ];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        return $this->getService()->createIncident($data);
    }

    /**
     * Update an incident.
     *
     * @optional string $status - incident status
     * @optional string $impact - impact level
     * @optional array $components - array of affected component IDs
     * @optional string $scheduled_at - for scheduled maintenance
     *
     * @return bool
     */
    public function incident_update($data)
    {
        $required = [
            'id' => 'Incident ID is required',
            'title' => 'Incident title is required',
        ];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        return $this->getService()->updateIncident((int) $data['id'], $data);
    }

    /**
     * Delete an incident.
     *
     * @return bool
     */
    public function incident_delete($data)
    {
        $required = [
            'id' => 'Incident ID is required',
        ];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        return $this->getService()->deleteIncident((int) $data['id']);
    }

    /**
     * Add an update to an incident.
     *
     * @return int - new update ID
     */
    public function incident_update_add($data)
    {
        $required = [
            'incident_id' => 'Incident ID is required',
            'status' => 'Status is required',
            'message' => 'Message is required',
        ];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        return $this->getService()->addIncidentUpdate(
            (int) $data['incident_id'],
            $data['status'],
            $data['message']
        );
    }
}
