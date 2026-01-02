<?php

/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Servicestatus;

class Service
{
    protected ?\Pimple\Container $di = null;

    /**
     * Status constants.
     */
    public const STATUS_OPERATIONAL = 'operational';
    public const STATUS_DEGRADED = 'degraded_performance';
    public const STATUS_PARTIAL = 'partial_outage';
    public const STATUS_MAJOR = 'major_outage';
    public const STATUS_MAINTENANCE = 'maintenance';

    /**
     * Incident status constants.
     */
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
        $sql = '
            CREATE TABLE IF NOT EXISTS `service_status_component` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL,
                `description` text DEFAULT NULL,
                `status` varchar(50) NOT NULL DEFAULT "operational",
                `display_order` int(11) NOT NULL DEFAULT 0,
                `is_visible` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL,
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ';
        $this->di['db']->exec($sql);

        $sql = '
            CREATE TABLE IF NOT EXISTS `service_status_incident` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `status` varchar(50) NOT NULL DEFAULT "investigating",
                `impact` varchar(50) NOT NULL DEFAULT "partial_outage",
                `scheduled_at` datetime DEFAULT NULL,
                `resolved_at` datetime DEFAULT NULL,
                `created_at` datetime NOT NULL,
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ';
        $this->di['db']->exec($sql);

        $sql = '
            CREATE TABLE IF NOT EXISTS `service_status_incident_update` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `incident_id` int(11) NOT NULL,
                `status` varchar(50) NOT NULL,
                `message` text NOT NULL,
                `created_at` datetime NOT NULL,
                PRIMARY KEY (`id`),
                KEY `incident_id` (`incident_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ';
        $this->di['db']->exec($sql);

        $sql = '
            CREATE TABLE IF NOT EXISTS `service_status_incident_component` (
                `incident_id` int(11) NOT NULL,
                `component_id` int(11) NOT NULL,
                PRIMARY KEY (`incident_id`, `component_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ';
        $this->di['db']->exec($sql);

        return true;
    }

    /**
     * Uninstall module - drop database tables.
     */
    public function uninstall(): bool
    {
        $this->di['db']->exec('DROP TABLE IF EXISTS `service_status_incident_component`');
        $this->di['db']->exec('DROP TABLE IF EXISTS `service_status_incident_update`');
        $this->di['db']->exec('DROP TABLE IF EXISTS `service_status_incident`');
        $this->di['db']->exec('DROP TABLE IF EXISTS `service_status_component`');

        return true;
    }

    /**
     * Get all available statuses.
     */
    public function getStatuses(): array
    {
        return [
            self::STATUS_OPERATIONAL => 'Operational',
            self::STATUS_DEGRADED => 'Degraded Performance',
            self::STATUS_PARTIAL => 'Partial Outage',
            self::STATUS_MAJOR => 'Major Outage',
            self::STATUS_MAINTENANCE => 'Under Maintenance',
        ];
    }

    /**
     * Get all incident statuses.
     */
    public function getIncidentStatuses(): array
    {
        return [
            self::INCIDENT_INVESTIGATING => 'Investigating',
            self::INCIDENT_IDENTIFIED => 'Identified',
            self::INCIDENT_MONITORING => 'Monitoring',
            self::INCIDENT_RESOLVED => 'Resolved',
            self::INCIDENT_SCHEDULED => 'Scheduled',
        ];
    }

    /**
     * Get overall system status.
     */
    public function getOverallStatus(): string
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare('SELECT status FROM service_status_component WHERE is_visible = 1');
        $stmt->execute();
        $statuses = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($statuses)) {
            return self::STATUS_OPERATIONAL;
        }

        $priority = [
            self::STATUS_MAJOR => 5,
            self::STATUS_PARTIAL => 4,
            self::STATUS_MAINTENANCE => 3,
            self::STATUS_DEGRADED => 2,
            self::STATUS_OPERATIONAL => 1,
        ];

        $worst = self::STATUS_OPERATIONAL;
        foreach ($statuses as $status) {
            if (isset($priority[$status]) && $priority[$status] > $priority[$worst]) {
                $worst = $status;
            }
        }

        return $worst;
    }

    /**
     * Get all visible components.
     */
    public function getComponents(): array
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare('SELECT * FROM service_status_component WHERE is_visible = 1 ORDER BY display_order ASC, id ASC');
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get all components (including hidden) for admin.
     */
    public function getComponentsAdmin(): array
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare('SELECT * FROM service_status_component ORDER BY display_order ASC, id ASC');
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get component by ID.
     */
    public function getComponent(int $id): ?array
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare('SELECT * FROM service_status_component WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    /**
     * Create a new component.
     */
    public function createComponent(array $data): int
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare('
            INSERT INTO service_status_component (name, description, status, display_order, is_visible, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['status'] ?? self::STATUS_OPERATIONAL,
            $data['display_order'] ?? 0,
            isset($data['is_visible']) ? (int) $data['is_visible'] : 1,
        ]);

        $id = (int) $pdo->lastInsertId();
        $this->di['logger']->info('Created service status component #%s', $id);

        return $id;
    }

    /**
     * Update a component.
     */
    public function updateComponent(int $id, array $data): bool
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare('
            UPDATE service_status_component
            SET name = ?, description = ?, status = ?, display_order = ?, is_visible = ?, updated_at = NOW()
            WHERE id = ?
        ');
        $result = $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['status'] ?? self::STATUS_OPERATIONAL,
            $data['display_order'] ?? 0,
            isset($data['is_visible']) ? (int) $data['is_visible'] : 1,
            $id,
        ]);

        $this->di['logger']->info('Updated service status component #%s', $id);

        return $result;
    }

    /**
     * Delete a component.
     */
    public function deleteComponent(int $id): bool
    {
        $pdo = $this->di['pdo'];

        // Remove from incident relationships
        $stmt = $pdo->prepare('DELETE FROM service_status_incident_component WHERE component_id = ?');
        $stmt->execute([$id]);

        // Delete component
        $stmt = $pdo->prepare('DELETE FROM service_status_component WHERE id = ?');
        $result = $stmt->execute([$id]);

        $this->di['logger']->info('Deleted service status component #%s', $id);

        return $result;
    }

    /**
     * Get active incidents.
     */
    public function getActiveIncidents(): array
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare('
            SELECT * FROM service_status_incident
            WHERE status != ?
            ORDER BY created_at DESC
        ');
        $stmt->execute([self::INCIDENT_RESOLVED]);
        $incidents = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($incidents as &$incident) {
            $incident['components'] = $this->getIncidentComponents($incident['id']);
            $incident['updates'] = $this->getIncidentUpdates($incident['id']);
        }

        return $incidents;
    }

    /**
     * Get scheduled maintenance.
     */
    public function getScheduledMaintenance(): array
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare('
            SELECT * FROM service_status_incident
            WHERE status = ? AND scheduled_at >= NOW()
            ORDER BY scheduled_at ASC
        ');
        $stmt->execute([self::INCIDENT_SCHEDULED]);
        $incidents = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($incidents as &$incident) {
            $incident['components'] = $this->getIncidentComponents($incident['id']);
            $incident['updates'] = $this->getIncidentUpdates($incident['id']);
        }

        return $incidents;
    }

    /**
     * Get incident history.
     */
    public function getIncidentHistory(int $days = 90): array
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare('
            SELECT * FROM service_status_incident
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY created_at DESC
        ');
        $stmt->execute([$days]);
        $incidents = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($incidents as &$incident) {
            $incident['components'] = $this->getIncidentComponents($incident['id']);
            $incident['updates'] = $this->getIncidentUpdates($incident['id']);
        }

        return $incidents;
    }

    /**
     * Get all incidents for admin.
     */
    public function getIncidentsAdmin(array $data = []): array
    {
        $pdo = $this->di['pdo'];

        $sql = 'SELECT * FROM service_status_incident WHERE 1=1';
        $params = [];

        if (!empty($data['status'])) {
            $sql .= ' AND status = ?';
            $params[] = $data['status'];
        }

        $sql .= ' ORDER BY created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $incidents = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($incidents as &$incident) {
            $incident['components'] = $this->getIncidentComponents($incident['id']);
            $incident['updates'] = $this->getIncidentUpdates($incident['id']);
        }

        return $incidents;
    }

    /**
     * Get incident by ID.
     */
    public function getIncident(int $id): ?array
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare('SELECT * FROM service_status_incident WHERE id = ?');
        $stmt->execute([$id]);
        $incident = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$incident) {
            return null;
        }

        $incident['components'] = $this->getIncidentComponents($id);
        $incident['updates'] = $this->getIncidentUpdates($id);

        return $incident;
    }

    /**
     * Get components affected by an incident.
     */
    public function getIncidentComponents(int $incidentId): array
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare('
            SELECT c.* FROM service_status_component c
            INNER JOIN service_status_incident_component ic ON c.id = ic.component_id
            WHERE ic.incident_id = ?
        ');
        $stmt->execute([$incidentId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get incident updates.
     */
    public function getIncidentUpdates(int $incidentId): array
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare('
            SELECT * FROM service_status_incident_update
            WHERE incident_id = ?
            ORDER BY created_at DESC
        ');
        $stmt->execute([$incidentId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Create a new incident.
     */
    public function createIncident(array $data): int
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare('
            INSERT INTO service_status_incident (title, status, impact, scheduled_at, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $data['title'],
            $data['status'] ?? self::INCIDENT_INVESTIGATING,
            $data['impact'] ?? self::STATUS_PARTIAL,
            $data['scheduled_at'] ?? null,
        ]);

        $incidentId = (int) $pdo->lastInsertId();

        // Add affected components
        if (!empty($data['components'])) {
            $this->setIncidentComponents($incidentId, $data['components']);
        }

        // Add initial update message
        if (!empty($data['message'])) {
            $this->addIncidentUpdate($incidentId, $data['status'] ?? self::INCIDENT_INVESTIGATING, $data['message']);
        }

        // Update component statuses based on impact
        if (!empty($data['components'])) {
            $this->updateComponentsStatus($data['components'], $data['impact'] ?? self::STATUS_PARTIAL);
        }

        $this->di['logger']->info('Created service status incident #%s', $incidentId);

        return $incidentId;
    }

    /**
     * Update an incident.
     */
    public function updateIncident(int $id, array $data): bool
    {
        $pdo = $this->di['pdo'];

        $resolvedAt = null;
        if (($data['status'] ?? null) === self::INCIDENT_RESOLVED) {
            $resolvedAt = date('Y-m-d H:i:s');
        }

        $stmt = $pdo->prepare('
            UPDATE service_status_incident
            SET title = ?, status = ?, impact = ?, scheduled_at = ?, resolved_at = COALESCE(?, resolved_at), updated_at = NOW()
            WHERE id = ?
        ');
        $result = $stmt->execute([
            $data['title'],
            $data['status'] ?? self::INCIDENT_INVESTIGATING,
            $data['impact'] ?? self::STATUS_PARTIAL,
            $data['scheduled_at'] ?? null,
            $resolvedAt,
            $id,
        ]);

        // Update affected components
        if (isset($data['components'])) {
            $this->setIncidentComponents($id, $data['components']);
        }

        // If resolved, reset component statuses
        if (($data['status'] ?? null) === self::INCIDENT_RESOLVED) {
            $this->resetComponentsStatus($id);
        }

        $this->di['logger']->info('Updated service status incident #%s', $id);

        return $result;
    }

    /**
     * Delete an incident.
     */
    public function deleteIncident(int $id): bool
    {
        $pdo = $this->di['pdo'];

        // Delete updates
        $stmt = $pdo->prepare('DELETE FROM service_status_incident_update WHERE incident_id = ?');
        $stmt->execute([$id]);

        // Delete component relationships
        $stmt = $pdo->prepare('DELETE FROM service_status_incident_component WHERE incident_id = ?');
        $stmt->execute([$id]);

        // Delete incident
        $stmt = $pdo->prepare('DELETE FROM service_status_incident WHERE id = ?');
        $result = $stmt->execute([$id]);

        $this->di['logger']->info('Deleted service status incident #%s', $id);

        return $result;
    }

    /**
     * Set incident components.
     */
    protected function setIncidentComponents(int $incidentId, array $componentIds): void
    {
        $pdo = $this->di['pdo'];

        // Remove existing relationships
        $stmt = $pdo->prepare('DELETE FROM service_status_incident_component WHERE incident_id = ?');
        $stmt->execute([$incidentId]);

        // Add new relationships
        $stmt = $pdo->prepare('INSERT INTO service_status_incident_component (incident_id, component_id) VALUES (?, ?)');
        foreach ($componentIds as $componentId) {
            $stmt->execute([$incidentId, $componentId]);
        }
    }

    /**
     * Add an update to an incident.
     */
    public function addIncidentUpdate(int $incidentId, string $status, string $message): int
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare('
            INSERT INTO service_status_incident_update (incident_id, status, message, created_at)
            VALUES (?, ?, ?, NOW())
        ');
        $stmt->execute([$incidentId, $status, $message]);

        // Update incident status
        $stmt = $pdo->prepare('UPDATE service_status_incident SET status = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$status, $incidentId]);

        // If resolved, update resolved_at
        if ($status === self::INCIDENT_RESOLVED) {
            $stmt = $pdo->prepare('UPDATE service_status_incident SET resolved_at = NOW() WHERE id = ?');
            $stmt->execute([$incidentId]);
            $this->resetComponentsStatus($incidentId);
        }

        return (int) $pdo->lastInsertId();
    }

    /**
     * Update multiple component statuses.
     */
    protected function updateComponentsStatus(array $componentIds, string $status): void
    {
        $pdo = $this->di['pdo'];
        $stmt = $pdo->prepare('UPDATE service_status_component SET status = ?, updated_at = NOW() WHERE id = ?');
        foreach ($componentIds as $componentId) {
            $stmt->execute([$status, $componentId]);
        }
    }

    /**
     * Reset component statuses after incident resolution.
     */
    protected function resetComponentsStatus(int $incidentId): void
    {
        $components = $this->getIncidentComponents($incidentId);
        foreach ($components as $component) {
            // Check if component has other active incidents
            $pdo = $this->di['pdo'];
            $stmt = $pdo->prepare('
                SELECT COUNT(*) FROM service_status_incident i
                INNER JOIN service_status_incident_component ic ON i.id = ic.incident_id
                WHERE ic.component_id = ? AND i.status != ? AND i.id != ?
            ');
            $stmt->execute([$component['id'], self::INCIDENT_RESOLVED, $incidentId]);
            $count = $stmt->fetchColumn();

            if ($count == 0) {
                // No other active incidents, reset to operational
                $stmt = $pdo->prepare('UPDATE service_status_component SET status = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([self::STATUS_OPERATIONAL, $component['id']]);
            }
        }
    }
}
