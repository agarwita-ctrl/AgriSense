<?php
/**
 * AgriSense - alerts (requirement 15)
 */

class Alert
{
    public const SEVERITIES = ['info', 'warning', 'critical'];

    /** Types the system raises, with the label shown in the UI. */
    public const TYPES = [
        'low_moisture'     => 'Low soil moisture',
        'pump_on'          => 'Pump activated',
        'pump_off'         => 'Pump deactivated',
        'device_offline'   => 'Device offline',
        'sensor_failure'   => 'Sensor failure',
        'wifi_failure'     => 'Wi-Fi connection failure',
        'abnormal_reading' => 'Abnormal sensor reading',
        'probe_mismatch'   => 'Soil probes disagree',
        'crop_stress'      => 'Crop water stress',
        'max_runtime'      => 'Pump runtime limit reached',
        'safe_state'       => 'System placed in safe state',
        'pump_command'     => 'Manual pump command',
        'settings_changed' => 'Irrigation settings changed',
    ];

    public static function label(string $type): string
    {
        return self::TYPES[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    /** Insert an alert. */
    public static function raise(
        ?int $deviceId,
        string $type,
        string $message,
        string $severity = 'info',
        string $source = 'device'
    ): int {
        return Database::insert(
            'INSERT INTO alerts (device_id, alert_type, message, severity, source) VALUES (?, ?, ?, ?, ?)',
            [
                $deviceId,
                substr($type, 0, 50),
                substr($message, 0, 255),
                in_array($severity, self::SEVERITIES, true) ? $severity : 'info',
                $source === 'demo' ? 'demo' : 'device',
            ]
        );
    }

    /**
     * Insert an alert only when no alert of the same type exists for this
     * device inside the given window. Keeps a flapping sensor or an offline
     * device from filling the table with duplicates.
     *
     * @return int|null New alert id, or null when suppressed.
     */
    public static function raiseThrottled(
        ?int $deviceId,
        string $type,
        string $message,
        string $severity = 'info',
        int $windowSeconds = 900
    ): ?int {
        $recent = (int) Database::scalar(
            'SELECT COUNT(*) FROM alerts
              WHERE alert_type = ?
                AND ' . ($deviceId === null ? 'device_id IS NULL' : 'device_id = ?') . '
                AND created_at > (NOW() - INTERVAL ? SECOND)',
            $deviceId === null ? [$type, $windowSeconds] : [$type, $deviceId, $windowSeconds]
        );

        if ($recent > 0) {
            return null;
        }

        return self::raise($deviceId, $type, $message, $severity);
    }

    public static function unreadCount(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM alerts WHERE is_read = 0');
    }

    /** Newest unread alerts for the header bell. */
    public static function recent(int $limit = 6): array
    {
        $limit = max(1, min($limit, 50));

        return Database::all(
            "SELECT a.id, a.alert_type, a.message, a.severity, a.is_read, a.created_at,
                    d.device_name
               FROM alerts a
               LEFT JOIN devices d ON d.id = a.device_id
              ORDER BY a.is_read ASC, a.created_at DESC
              LIMIT {$limit}"
        );
    }

    /**
     * @return array{rows:array,meta:array}
     */
    public static function search(array $filters, int $page, int $perPage = 25): array
    {
        [$where, $params] = self::buildWhere($filters);

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM alerts a LEFT JOIN devices d ON d.id = a.device_id {$where}",
            $params
        );
        $meta = paginate($total, $page, $perPage);

        $rows = Database::all(
            "SELECT a.id, a.device_id, a.alert_type, a.message, a.severity, a.is_read,
                    a.source, a.created_at, d.device_name, d.device_code
               FROM alerts a
               LEFT JOIN devices d ON d.id = a.device_id
               {$where}
              ORDER BY a.created_at DESC, a.id DESC
              LIMIT {$meta['per_page']} OFFSET {$meta['offset']}",
            $params
        );

        return ['rows' => $rows, 'meta' => $meta];
    }

    public static function export(array $filters): array
    {
        [$where, $params] = self::buildWhere($filters);

        return Database::all(
            "SELECT a.id, a.alert_type, a.message, a.severity, a.is_read, a.created_at,
                    d.device_name
               FROM alerts a
               LEFT JOIN devices d ON d.id = a.device_id
               {$where}
              ORDER BY a.created_at DESC
              LIMIT 10000",
            $params
        );
    }

    private static function buildWhere(array $filters): array
    {
        $clauses = [];
        $params  = [];

        if (!empty($filters['device_id'])) {
            $clauses[] = 'a.device_id = ?';
            $params[]  = $filters['device_id'];
        }
        if (!empty($filters['severity'])) {
            $clauses[] = 'a.severity = ?';
            $params[]  = $filters['severity'];
        }
        if (!empty($filters['alert_type'])) {
            $clauses[] = 'a.alert_type = ?';
            $params[]  = $filters['alert_type'];
        }
        if (isset($filters['is_read']) && $filters['is_read'] !== '') {
            $clauses[] = 'a.is_read = ?';
            $params[]  = (int) $filters['is_read'];
        }
        if (!empty($filters['search'])) {
            $clauses[] = '(a.message LIKE ? OR a.alert_type LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like);
        }
        if (!empty($filters['start'])) {
            $clauses[] = 'a.created_at >= ?';
            $params[]  = $filters['start'];
        }
        if (!empty($filters['end'])) {
            $clauses[] = 'a.created_at <= ?';
            $params[]  = $filters['end'];
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }

    public static function markRead(int $id): void
    {
        Database::execute('UPDATE alerts SET is_read = 1 WHERE id = ?', [$id]);
    }

    public static function markUnread(int $id): void
    {
        Database::execute('UPDATE alerts SET is_read = 0 WHERE id = ?', [$id]);
    }

    public static function markAllRead(): int
    {
        return Database::execute('UPDATE alerts SET is_read = 1 WHERE is_read = 0');
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM alerts WHERE id = ?', [$id]);
    }

    /** Counts by severity for the alerts page summary. */
    public static function summary(): array
    {
        $row = Database::first(
            "SELECT
                COUNT(*) AS total,
                SUM(is_read = 0) AS unread,
                SUM(severity = 'critical') AS critical,
                SUM(severity = 'warning') AS warning,
                SUM(severity = 'info') AS info
             FROM alerts"
        ) ?: [];

        return [
            'total'    => (int) ($row['total'] ?? 0),
            'unread'   => (int) ($row['unread'] ?? 0),
            'critical' => (int) ($row['critical'] ?? 0),
            'warning'  => (int) ($row['warning'] ?? 0),
            'info'     => (int) ($row['info'] ?? 0),
        ];
    }
}
