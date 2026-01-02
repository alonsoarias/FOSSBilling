<?php

/**
 * FOSSBilling.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license   Apache-2.0
 *
 * Copyright FOSSBilling 2022
 * This software may contain code previously used in the BoxBilling project.
 * Copyright BoxBilling, Inc 2011-2021
 *
 * This source file is subject to the Apache-2.0 License that is bundled
 * with this source code in the file LICENSE
 */

namespace Box\Mod\Servicestatus;

use FOSSBilling\InjectionAwareInterface;

class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    // Status constants
    public const STATUS_OPERATIONAL = 'operational';
    public const STATUS_DEGRADED = 'degraded_performance';
    public const STATUS_PARTIAL_OUTAGE = 'partial_outage';
    public const STATUS_MAJOR_OUTAGE = 'major_outage';
    public const STATUS_MAINTENANCE = 'maintenance';

    // Incident status constants
    public const INCIDENT_INVESTIGATING = 'investigating';
    public const INCIDENT_IDENTIFIED = 'identified';
    public const INCIDENT_MONITORING = 'monitoring';
    public const INCIDENT_RESOLVED = 'resolved';
    public const INCIDENT_SCHEDULED = 'scheduled';

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    /**
     * Install module - create database tables.
     */
    public function install(): bool
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS `service_status_component` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(255) NOT NULL,
                `description` TEXT NULL,
                `status` VARCHAR(50) NOT NULL DEFAULT 'operational',
                `display_order` INT(11) NOT NULL DEFAULT 0,
                `group_name` VARCHAR(255) NULL,
                `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `service_status_incident` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `title` VARCHAR(255) NOT NULL,
                `status` VARCHAR(50) NOT NULL DEFAULT 'investigating',
                `impact` VARCHAR(50) NOT NULL DEFAULT 'none',
                `is_scheduled` TINYINT(1) NOT NULL DEFAULT 0,
                `scheduled_for` DATETIME NULL,
                `scheduled_until` DATETIME NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                `resolved_at` DATETIME NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `service_status_incident_update` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `incident_id` INT(11) NOT NULL,
                `status` VARCHAR(50) NOT NULL,
                `message` TEXT NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `incident_id` (`incident_id`),
                CONSTRAINT `fk_incident_update_incident` FOREIGN KEY (`incident_id`)
                    REFERENCES `service_status_incident` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `service_status_incident_component` (
                `incident_id` INT(11) NOT NULL,
                `component_id` INT(11) NOT NULL,
                PRIMARY KEY (`incident_id`, `component_id`),
                KEY `component_id` (`component_id`),
                CONSTRAINT `fk_ic_incident` FOREIGN KEY (`incident_id`)
                    REFERENCES `service_status_incident` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_ic_component` FOREIGN KEY (`component_id`)
                    REFERENCES `service_status_component` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $statement) {
            if (!empty($statement)) {
                $this->di['db']->exec($statement);
            }
        }

        return true;
    }

    /**
     * Uninstall module - drop database tables.
     */
    public function uninstall(): bool
    {
        $this->di['db']->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->di['db']->exec('DROP TABLE IF EXISTS `service_status_incident_component`');
        $this->di['db']->exec('DROP TABLE IF EXISTS `service_status_incident_update`');
        $this->di['db']->exec('DROP TABLE IF EXISTS `service_status_incident`');
        $this->di['db']->exec('DROP TABLE IF EXISTS `service_status_component`');
        $this->di['db']->exec('SET FOREIGN_KEY_CHECKS = 1');

        return true;
    }

    /**
     * Get all available statuses for components.
     */
    public function getComponentStatuses(): array
    {
        return [
            self::STATUS_OPERATIONAL => __trans('Operational'),
            self::STATUS_DEGRADED => __trans('Degraded Performance'),
            self::STATUS_PARTIAL_OUTAGE => __trans('Partial Outage'),
            self::STATUS_MAJOR_OUTAGE => __trans('Major Outage'),
            self::STATUS_MAINTENANCE => __trans('Under Maintenance'),
        ];
    }

    /**
     * Get all available statuses for incidents.
     */
    public function getIncidentStatuses(): array
    {
        return [
            self::INCIDENT_INVESTIGATING => __trans('Investigating'),
            self::INCIDENT_IDENTIFIED => __trans('Identified'),
            self::INCIDENT_MONITORING => __trans('Monitoring'),
            self::INCIDENT_RESOLVED => __trans('Resolved'),
            self::INCIDENT_SCHEDULED => __trans('Scheduled'),
        ];
    }

    /**
     * Get status color class for display.
     */
    public function getStatusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_OPERATIONAL => 'success',
            self::STATUS_DEGRADED => 'warning',
            self::STATUS_PARTIAL_OUTAGE => 'orange',
            self::STATUS_MAJOR_OUTAGE => 'danger',
            self::STATUS_MAINTENANCE => 'info',
            default => 'secondary',
        };
    }

    /**
     * Get overall system status based on all components.
     */
    public function getOverallStatus(): array
    {
        $components = $this->getVisibleComponents();

        if (empty($components)) {
            return [
                'status' => self::STATUS_OPERATIONAL,
                'label' => __trans('All Systems Operational'),
                'color' => 'success',
            ];
        }

        $hasOutage = false;
        $hasPartialOutage = false;
        $hasDegraded = false;
        $hasMaintenance = false;

        foreach ($components as $component) {
            match ($component['status']) {
                self::STATUS_MAJOR_OUTAGE => $hasOutage = true,
                self::STATUS_PARTIAL_OUTAGE => $hasPartialOutage = true,
                self::STATUS_DEGRADED => $hasDegraded = true,
                self::STATUS_MAINTENANCE => $hasMaintenance = true,
                default => null,
            };
        }

        if ($hasOutage) {
            return [
                'status' => self::STATUS_MAJOR_OUTAGE,
                'label' => __trans('Major System Outage'),
                'color' => 'danger',
            ];
        }

        if ($hasPartialOutage) {
            return [
                'status' => self::STATUS_PARTIAL_OUTAGE,
                'label' => __trans('Partial System Outage'),
                'color' => 'orange',
            ];
        }

        if ($hasDegraded) {
            return [
                'status' => self::STATUS_DEGRADED,
                'label' => __trans('Degraded System Performance'),
                'color' => 'warning',
            ];
        }

        if ($hasMaintenance) {
            return [
                'status' => self::STATUS_MAINTENANCE,
                'label' => __trans('Scheduled Maintenance'),
                'color' => 'info',
            ];
        }

        return [
            'status' => self::STATUS_OPERATIONAL,
            'label' => __trans('All Systems Operational'),
            'color' => 'success',
        ];
    }

    // ==================== COMPONENT METHODS ====================

    /**
     * Get all components.
     */
    public function getComponents(): array
    {
        $sql = 'SELECT * FROM `service_status_component` ORDER BY `display_order` ASC, `name` ASC';
        $rows = $this->di['db']->getAll($sql);

        return array_map(fn($row) => $this->componentToApiArray($row), $rows);
    }

    /**
     * Get only visible components (for public display).
     */
    public function getVisibleComponents(): array
    {
        $sql = 'SELECT * FROM `service_status_component` WHERE `is_visible` = 1 ORDER BY `display_order` ASC, `name` ASC';
        $rows = $this->di['db']->getAll($sql);

        return array_map(fn($row) => $this->componentToApiArray($row), $rows);
    }

    /**
     * Get components grouped by group_name.
     */
    public function getComponentsGrouped(): array
    {
        $components = $this->getVisibleComponents();
        $grouped = [];

        foreach ($components as $component) {
            $group = $component['group_name'] ?: __trans('Services');
            if (!isset($grouped[$group])) {
                $grouped[$group] = [];
            }
            $grouped[$group][] = $component;
        }

        return $grouped;
    }

    /**
     * Get a single component by ID.
     */
    public function getComponent(int $id): ?array
    {
        $sql = 'SELECT * FROM `service_status_component` WHERE `id` = :id';
        $row = $this->di['db']->getRow($sql, ['id' => $id]);

        return $row ? $this->componentToApiArray($row) : null;
    }

    /**
     * Create a new component.
     */
    public function createComponent(array $data): int
    {
        $sql = 'INSERT INTO `service_status_component`
                (`name`, `description`, `status`, `display_order`, `group_name`, `is_visible`, `created_at`, `updated_at`)
                VALUES (:name, :description, :status, :display_order, :group_name, :is_visible, :created_at, :updated_at)';

        $now = date('Y-m-d H:i:s');
        $this->di['db']->exec($sql, [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? self::STATUS_OPERATIONAL,
            'display_order' => $data['display_order'] ?? 0,
            'group_name' => $data['group_name'] ?? null,
            'is_visible' => isset($data['is_visible']) ? (int) $data['is_visible'] : 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->di['db']->lastInsertId();
    }

    /**
     * Update a component.
     */
    public function updateComponent(int $id, array $data): bool
    {
        $updates = [];
        $params = ['id' => $id];

        if (isset($data['name'])) {
            $updates[] = '`name` = :name';
            $params['name'] = $data['name'];
        }

        if (array_key_exists('description', $data)) {
            $updates[] = '`description` = :description';
            $params['description'] = $data['description'];
        }

        if (isset($data['status'])) {
            $updates[] = '`status` = :status';
            $params['status'] = $data['status'];
        }

        if (isset($data['display_order'])) {
            $updates[] = '`display_order` = :display_order';
            $params['display_order'] = (int) $data['display_order'];
        }

        if (array_key_exists('group_name', $data)) {
            $updates[] = '`group_name` = :group_name';
            $params['group_name'] = $data['group_name'];
        }

        if (isset($data['is_visible'])) {
            $updates[] = '`is_visible` = :is_visible';
            $params['is_visible'] = (int) $data['is_visible'];
        }

        if (empty($updates)) {
            return true;
        }

        $updates[] = '`updated_at` = :updated_at';
        $params['updated_at'] = date('Y-m-d H:i:s');

        $sql = 'UPDATE `service_status_component` SET ' . implode(', ', $updates) . ' WHERE `id` = :id';
        $this->di['db']->exec($sql, $params);

        return true;
    }

    /**
     * Delete a component.
     */
    public function deleteComponent(int $id): bool
    {
        $sql = 'DELETE FROM `service_status_component` WHERE `id` = :id';
        $this->di['db']->exec($sql, ['id' => $id]);

        return true;
    }

    /**
     * Convert component row to API array.
     */
    protected function componentToApiArray(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'status' => $row['status'],
            'status_label' => $this->getComponentStatuses()[$row['status']] ?? $row['status'],
            'status_color' => $this->getStatusColor($row['status']),
            'display_order' => (int) $row['display_order'],
            'group_name' => $row['group_name'],
            'is_visible' => (bool) $row['is_visible'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    // ==================== INCIDENT METHODS ====================

    /**
     * Get incidents with optional filters.
     */
    public function getIncidents(array $filters = []): array
    {
        $sql = 'SELECT * FROM `service_status_incident` WHERE 1=1';
        $params = [];

        if (isset($filters['status'])) {
            $sql .= ' AND `status` = :status';
            $params['status'] = $filters['status'];
        }

        if (isset($filters['is_scheduled'])) {
            $sql .= ' AND `is_scheduled` = :is_scheduled';
            $params['is_scheduled'] = (int) $filters['is_scheduled'];
        }

        if (isset($filters['resolved'])) {
            if ($filters['resolved']) {
                $sql .= ' AND `resolved_at` IS NOT NULL';
            } else {
                $sql .= ' AND `resolved_at` IS NULL';
            }
        }

        $sql .= ' ORDER BY `created_at` DESC';

        if (isset($filters['limit'])) {
            $sql .= ' LIMIT ' . (int) $filters['limit'];
        }

        $rows = $this->di['db']->getAll($sql, $params);

        return array_map(fn($row) => $this->incidentToApiArray($row), $rows);
    }

    /**
     * Get active (unresolved) incidents.
     */
    public function getActiveIncidents(): array
    {
        return $this->getIncidents(['resolved' => false, 'is_scheduled' => 0]);
    }

    /**
     * Get scheduled maintenance.
     */
    public function getScheduledMaintenance(): array
    {
        $sql = 'SELECT * FROM `service_status_incident`
                WHERE `is_scheduled` = 1
                AND (`resolved_at` IS NULL OR `scheduled_until` > NOW())
                ORDER BY `scheduled_for` ASC';

        $rows = $this->di['db']->getAll($sql);

        return array_map(fn($row) => $this->incidentToApiArray($row), $rows);
    }

    /**
     * Get recent incidents for history display.
     */
    public function getRecentIncidents(int $days = 7): array
    {
        $sql = 'SELECT * FROM `service_status_incident`
                WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL :days DAY)
                ORDER BY `created_at` DESC';

        $rows = $this->di['db']->getAll($sql, ['days' => $days]);

        return array_map(fn($row) => $this->incidentToApiArray($row), $rows);
    }

    /**
     * Get a single incident by ID.
     */
    public function getIncident(int $id): ?array
    {
        $sql = 'SELECT * FROM `service_status_incident` WHERE `id` = :id';
        $row = $this->di['db']->getRow($sql, ['id' => $id]);

        return $row ? $this->incidentToApiArray($row) : null;
    }

    /**
     * Create a new incident.
     */
    public function createIncident(array $data): int
    {
        $sql = 'INSERT INTO `service_status_incident`
                (`title`, `status`, `impact`, `is_scheduled`, `scheduled_for`, `scheduled_until`, `created_at`, `updated_at`)
                VALUES (:title, :status, :impact, :is_scheduled, :scheduled_for, :scheduled_until, :created_at, :updated_at)';

        $now = date('Y-m-d H:i:s');
        $isScheduled = !empty($data['is_scheduled']) ? 1 : 0;

        $this->di['db']->exec($sql, [
            'title' => $data['title'],
            'status' => $data['status'] ?? ($isScheduled ? self::INCIDENT_SCHEDULED : self::INCIDENT_INVESTIGATING),
            'impact' => $data['impact'] ?? 'none',
            'is_scheduled' => $isScheduled,
            'scheduled_for' => $data['scheduled_for'] ?? null,
            'scheduled_until' => $data['scheduled_until'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $incidentId = (int) $this->di['db']->lastInsertId();

        // Link affected components
        if (!empty($data['component_ids'])) {
            $this->linkIncidentComponents($incidentId, $data['component_ids']);
        }

        // Add initial update if message provided
        if (!empty($data['message'])) {
            $this->addIncidentUpdate($incidentId, [
                'status' => $data['status'] ?? ($isScheduled ? self::INCIDENT_SCHEDULED : self::INCIDENT_INVESTIGATING),
                'message' => $data['message'],
            ]);
        }

        return $incidentId;
    }

    /**
     * Update an incident.
     */
    public function updateIncident(int $id, array $data): bool
    {
        $updates = [];
        $params = ['id' => $id];

        if (isset($data['title'])) {
            $updates[] = '`title` = :title';
            $params['title'] = $data['title'];
        }

        if (isset($data['status'])) {
            $updates[] = '`status` = :status';
            $params['status'] = $data['status'];

            // Auto-set resolved_at if status is resolved
            if ($data['status'] === self::INCIDENT_RESOLVED) {
                $updates[] = '`resolved_at` = :resolved_at';
                $params['resolved_at'] = date('Y-m-d H:i:s');
            }
        }

        if (isset($data['impact'])) {
            $updates[] = '`impact` = :impact';
            $params['impact'] = $data['impact'];
        }

        if (isset($data['scheduled_for'])) {
            $updates[] = '`scheduled_for` = :scheduled_for';
            $params['scheduled_for'] = $data['scheduled_for'];
        }

        if (isset($data['scheduled_until'])) {
            $updates[] = '`scheduled_until` = :scheduled_until';
            $params['scheduled_until'] = $data['scheduled_until'];
        }

        if (empty($updates)) {
            return true;
        }

        $updates[] = '`updated_at` = :updated_at';
        $params['updated_at'] = date('Y-m-d H:i:s');

        $sql = 'UPDATE `service_status_incident` SET ' . implode(', ', $updates) . ' WHERE `id` = :id';
        $this->di['db']->exec($sql, $params);

        // Update component links if provided
        if (isset($data['component_ids'])) {
            $this->unlinkIncidentComponents($id);
            $this->linkIncidentComponents($id, $data['component_ids']);
        }

        return true;
    }

    /**
     * Delete an incident.
     */
    public function deleteIncident(int $id): bool
    {
        $sql = 'DELETE FROM `service_status_incident` WHERE `id` = :id';
        $this->di['db']->exec($sql, ['id' => $id]);

        return true;
    }

    /**
     * Link components to an incident.
     */
    protected function linkIncidentComponents(int $incidentId, array $componentIds): void
    {
        foreach ($componentIds as $componentId) {
            $sql = 'INSERT IGNORE INTO `service_status_incident_component` (`incident_id`, `component_id`) VALUES (:incident_id, :component_id)';
            $this->di['db']->exec($sql, [
                'incident_id' => $incidentId,
                'component_id' => (int) $componentId,
            ]);
        }
    }

    /**
     * Unlink all components from an incident.
     */
    protected function unlinkIncidentComponents(int $incidentId): void
    {
        $sql = 'DELETE FROM `service_status_incident_component` WHERE `incident_id` = :incident_id';
        $this->di['db']->exec($sql, ['incident_id' => $incidentId]);
    }

    /**
     * Get components linked to an incident.
     */
    public function getIncidentComponents(int $incidentId): array
    {
        $sql = 'SELECT c.* FROM `service_status_component` c
                INNER JOIN `service_status_incident_component` ic ON c.id = ic.component_id
                WHERE ic.incident_id = :incident_id
                ORDER BY c.display_order ASC';

        $rows = $this->di['db']->getAll($sql, ['incident_id' => $incidentId]);

        return array_map(fn($row) => $this->componentToApiArray($row), $rows);
    }

    /**
     * Convert incident row to API array.
     */
    protected function incidentToApiArray(array $row): array
    {
        $updates = $this->getIncidentUpdates((int) $row['id']);
        $components = $this->getIncidentComponents((int) $row['id']);

        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'status' => $row['status'],
            'status_label' => $this->getIncidentStatuses()[$row['status']] ?? $row['status'],
            'impact' => $row['impact'],
            'is_scheduled' => (bool) $row['is_scheduled'],
            'scheduled_for' => $row['scheduled_for'],
            'scheduled_until' => $row['scheduled_until'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'resolved_at' => $row['resolved_at'],
            'is_resolved' => !empty($row['resolved_at']),
            'updates' => $updates,
            'components' => $components,
        ];
    }

    // ==================== INCIDENT UPDATE METHODS ====================

    /**
     * Get updates for an incident.
     */
    public function getIncidentUpdates(int $incidentId): array
    {
        $sql = 'SELECT * FROM `service_status_incident_update` WHERE `incident_id` = :incident_id ORDER BY `created_at` DESC';
        $rows = $this->di['db']->getAll($sql, ['incident_id' => $incidentId]);

        return array_map(fn($row) => [
            'id' => (int) $row['id'],
            'incident_id' => (int) $row['incident_id'],
            'status' => $row['status'],
            'status_label' => $this->getIncidentStatuses()[$row['status']] ?? $row['status'],
            'message' => $row['message'],
            'created_at' => $row['created_at'],
        ], $rows);
    }

    /**
     * Add an update to an incident.
     */
    public function addIncidentUpdate(int $incidentId, array $data): int
    {
        $sql = 'INSERT INTO `service_status_incident_update`
                (`incident_id`, `status`, `message`, `created_at`)
                VALUES (:incident_id, :status, :message, :created_at)';

        $this->di['db']->exec($sql, [
            'incident_id' => $incidentId,
            'status' => $data['status'],
            'message' => $data['message'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Also update the incident's status and updated_at
        $this->updateIncident($incidentId, ['status' => $data['status']]);

        return (int) $this->di['db']->lastInsertId();
    }

    /**
     * Delete an incident update.
     */
    public function deleteIncidentUpdate(int $updateId): bool
    {
        $sql = 'DELETE FROM `service_status_incident_update` WHERE `id` = :id';
        $this->di['db']->exec($sql, ['id' => $updateId]);

        return true;
    }
}
