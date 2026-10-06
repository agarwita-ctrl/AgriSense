<?php
/**
 * AgriSense - irrigation_logs
 *
 * One row per irrigation CYCLE. A cycle is opened when the pump starts and
 * closed when it stops; while it is open, ended_at is NULL and action is
 * 'start'.
 */

class IrrigationLog
{
    public const TRIGGERS = ['automatic', 'manual'];

    public const STOP_REASONS = [
        'target_reached'     => 'Target moisture reached',
        'manual_stop'        => 'Stopped manually',
        'max_runtime'        => 'Maximum runtime reached',
        'sensor_failure'     => 'Sensor failure - safe stop',
        'device_reset'       => 'Device restarted',
        'reconciled_unknown' => 'Stopped - reason not reported',
    ];

    /** The cycle currently running on a device, if any. */
    public static function openCycle(int $deviceId): ?array
    {
        return Database::first(
            'SELECT * FROM irrigation_logs
              WHERE device_id = ? AND ended_at IS NULL
              ORDER BY started_at DESC, id DESC
              LIMIT 1',
            [$deviceId]
        );
    }

    /**
     * Start a cycle. If a previous cycle was left open - typically because
     * the ESP32 lost power mid-run - it is closed first with the
     * device_reset reason so the history never contains two open rows.
     */
    public static function start(
        int $deviceId,
        string $triggerType,
        ?float $moisture,
        ?float $threshold,
        ?int $userId = null,
        ?string $startedAt = null
    ): int {
        return Database::transaction(function () use ($deviceId, $triggerType, $moisture, $threshold, $userId, $startedAt) {
            $stale = self::openCycle($deviceId);
            if ($stale) {
                self::close((int) $stale['id'], null, 'device_reset');
            }

            return Database::insert(
                'INSERT INTO irrigation_logs
                    (device_id, trigger_type, action, soil_moisture, threshold, started_at, created_by)
                 VALUES (?, ?, \'start\', ?, ?, ?, ?)',
                [
                    $deviceId,
                    in_array($triggerType, self::TRIGGERS, true) ? $triggerType : 'automatic',
                    $moisture,
                    $threshold,
                    $startedAt ?: date('Y-m-d H:i:s'),
                    $userId,
                ]
            );
        });
    }

    /**
     * Close a cycle, computing its duration from the stored start time.
     * Returns the closed row, or null when the id was already closed.
     */
    public static function close(
        int $id,
        ?float $endMoisture = null,
        string $stopReason = 'target_reached',
        ?string $endedAt = null
    ): ?array {
        $row = Database::first('SELECT * FROM irrigation_logs WHERE id = ?', [$id]);
        if (!$row || $row['ended_at'] !== null) {
            return null;
        }

        $endedAt ??= date('Y-m-d H:i:s');
        $duration = max(0, strtotime($endedAt) - strtotime($row['started_at']));

        if (!array_key_exists($stopReason, self::STOP_REASONS)) {
            $stopReason = 'target_reached';
        }

        Database::execute(
            "UPDATE irrigation_logs
                SET action = 'stop', ended_at = ?, duration_seconds = ?, end_moisture = ?, stop_reason = ?
              WHERE id = ?",
            [$endedAt, $duration, $endMoisture, $stopReason, $id]
        );

        return Database::first('SELECT * FROM irrigation_logs WHERE id = ?', [$id]);
    }

    /** Close whatever cycle is running on a device. */
    public static function closeOpen(
        int $deviceId,
        ?float $endMoisture = null,
        string $stopReason = 'target_reached'
    ): ?array {
        $open = self::openCycle($deviceId);

        return $open ? self::close((int) $open['id'], $endMoisture, $stopReason) : null;
    }

    /** Seconds since the last cycle ended - drives the min_cycle_interval rule. */
    public static function secondsSinceLastCycle(int $deviceId): ?int
    {
        $value = Database::scalar(
            'SELECT TIMESTAMPDIFF(SECOND, MAX(ended_at), NOW())
               FROM irrigation_logs WHERE device_id = ? AND ended_at IS NOT NULL',
            [$deviceId]
        );

        return $value === null ? null : (int) $value;
    }

    /**
     * @return array{rows:array,meta:array}
     */
    public static function search(array $filters, int $page, int $perPage = 25): array
    {
        [$where, $params] = self::buildWhere($filters);

        // The count joins the same tables as the listing query - the search
        // filter can reference both the device and the operator.
        $total = (int) Database::scalar(
            "SELECT COUNT(*)
               FROM irrigation_logs l
               JOIN devices d ON d.id = l.device_id
               LEFT JOIN users u ON u.id = l.created_by
               {$where}",
            $params
        );
        $meta = paginate($total, $page, $perPage);

        $rows = Database::all(
            "SELECT l.*, d.device_name, d.device_code, u.name AS operator
               FROM irrigation_logs l
               JOIN devices d ON d.id = l.device_id
               LEFT JOIN users u ON u.id = l.created_by
               {$where}
              ORDER BY l.started_at DESC, l.id DESC
              LIMIT {$meta['per_page']} OFFSET {$meta['offset']}",
            $params
        );

        return ['rows' => $rows, 'meta' => $meta];
    }

    public static function export(array $filters, int $limit = 20000): array
    {
        [$where, $params] = self::buildWhere($filters);
        $limit = max(1, min($limit, 50000));

        return Database::all(
            "SELECT l.*, d.device_name, d.device_code, u.name AS operator
               FROM irrigation_logs l
               JOIN devices d ON d.id = l.device_id
               LEFT JOIN users u ON u.id = l.created_by
               {$where}
              ORDER BY l.started_at DESC, l.id DESC
              LIMIT {$limit}",
            $params
        );
    }

    private static function buildWhere(array $filters): array
    {
        $clauses = [];
        $params  = [];

        if (!empty($filters['device_id'])) {
            $clauses[] = 'l.device_id = ?';
            $params[]  = $filters['device_id'];
        }
        if (!empty($filters['trigger_type'])) {
            $clauses[] = 'l.trigger_type = ?';
            $params[]  = $filters['trigger_type'];
        }
        if (!empty($filters['stop_reason'])) {
            $clauses[] = 'l.stop_reason = ?';
            $params[]  = $filters['stop_reason'];
        }
        if (!empty($filters['start'])) {
            $clauses[] = 'l.started_at >= ?';
            $params[]  = $filters['start'];
        }
        if (!empty($filters['end'])) {
            $clauses[] = 'l.started_at <= ?';
            $params[]  = $filters['end'];
        }
        if (!empty($filters['source'])) {
            $clauses[] = 'l.source = ?';
            $params[]  = $filters['source'];
        }
        if (!empty($filters['search'])) {
            $clauses[] = '(d.device_name LIKE ? OR d.device_code LIKE ? OR u.name LIKE ? OR l.stop_reason LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like);
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }

    /** Headline numbers for the reports page. */
    public static function stats(int $deviceId, string $start, string $end): array
    {
        $row = Database::first(
            "SELECT COUNT(*) AS cycles,
                    SUM(trigger_type = 'automatic') AS automatic_cycles,
                    SUM(trigger_type = 'manual')    AS manual_cycles,
                    COALESCE(SUM(duration_seconds), 0) AS total_seconds,
                    ROUND(AVG(duration_seconds))       AS avg_seconds,
                    MAX(duration_seconds)              AS longest_seconds
               FROM irrigation_logs
              WHERE device_id = ? AND started_at BETWEEN ? AND ?",
            [$deviceId, $start, $end]
        ) ?: [];

        return [
            'cycles'           => (int) ($row['cycles'] ?? 0),
            'automatic_cycles' => (int) ($row['automatic_cycles'] ?? 0),
            'manual_cycles'    => (int) ($row['manual_cycles'] ?? 0),
            'total_seconds'    => (int) ($row['total_seconds'] ?? 0),
            'avg_seconds'      => (int) ($row['avg_seconds'] ?? 0),
            'longest_seconds'  => (int) ($row['longest_seconds'] ?? 0),
        ];
    }

    /**
     * Per-day irrigation activity for the activity chart: how many cycles
     * ran and how long the pump was on.
     */
    public static function activitySeries(int $deviceId, string $start, string $end): array
    {
        return Database::all(
            "SELECT DATE(started_at) AS day,
                    COUNT(*) AS cycles,
                    SUM(trigger_type = 'automatic') AS automatic_cycles,
                    SUM(trigger_type = 'manual')    AS manual_cycles,
                    ROUND(COALESCE(SUM(duration_seconds), 0) / 60, 1) AS minutes
               FROM irrigation_logs
              WHERE device_id = ? AND started_at BETWEEN ? AND ?
              GROUP BY DATE(started_at)
              ORDER BY day",
            [$deviceId, $start, $end]
        );
    }

    public static function purgeDemo(): int
    {
        return Database::execute("DELETE FROM irrigation_logs WHERE source = 'demo'");
    }

    public static function demoCount(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM irrigation_logs WHERE source = 'demo'");
    }
}
