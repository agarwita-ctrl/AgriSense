<?php
/**
 * AgriSense - sensor_readings
 *
 * Append-only store. Nothing in this class ever updates or deletes a
 * reading, which is what requirement 25 asks for.
 */

class SensorReading
{
    /** Insert one reading. Any measurement may be null. */
    public static function store(int $deviceId, array $values): int
    {
        return Database::insert(
            'INSERT INTO sensor_readings
                (device_id, soil_moisture, soil_moisture_1, soil_moisture_2,
                 soil_raw, soil_raw_2, temperature, leaf_temperature, humidity,
                 pump_status, mode, source, recorded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $deviceId,
                $values['soil_moisture'] ?? null,
                $values['soil_moisture_1'] ?? null,
                $values['soil_moisture_2'] ?? null,
                $values['soil_raw'] ?? null,
                $values['soil_raw_2'] ?? null,
                $values['temperature'] ?? null,
                $values['leaf_temperature'] ?? null,
                $values['humidity'] ?? null,
                ($values['pump_status'] ?? 'OFF') === 'ON' ? 'ON' : 'OFF',
                ($values['mode'] ?? 'AUTO') === 'MANUAL' ? 'MANUAL' : 'AUTO',
                ($values['source'] ?? 'device') === 'demo' ? 'demo' : 'device',
                $values['recorded_at'] ?? date('Y-m-d H:i:s'),
            ]
        );
    }

    /** Most recent reading for a device. */
    public static function latest(int $deviceId): ?array
    {
        return Database::first(
            'SELECT * FROM sensor_readings
              WHERE device_id = ?
              ORDER BY recorded_at DESC, id DESC
              LIMIT 1',
            [$deviceId]
        );
    }

    /**
     * Most recent non-null value for one column. Used so the dashboard can
     * keep showing the last good temperature while the sensor is failing
     * (requirement 6).
     */
    public static function lastValid(int $deviceId, string $column): ?array
    {
        $allowed = ['soil_moisture', 'soil_moisture_1', 'soil_moisture_2',
                    'temperature', 'leaf_temperature', 'humidity'];
        if (!in_array($column, $allowed, true)) {
            throw new InvalidArgumentException('Unknown sensor column: ' . $column);
        }

        return Database::first(
            "SELECT {$column} AS value, recorded_at
               FROM sensor_readings
              WHERE device_id = ? AND {$column} IS NOT NULL
              ORDER BY recorded_at DESC, id DESC
              LIMIT 1",
            [$deviceId]
        );
    }

    /**
     * Time-bucketed averages for the charts. The bucket width adapts to the
     * span so a 30 day chart does not try to plot 3000 points.
     *
     * @return array<int,array{bucket:string,soil_moisture:?float,temperature:?float,leaf_temperature:?float,humidity:?float,samples:int}>
     */
    public static function series(int $deviceId, string $start, string $end): array
    {
        $span   = max(60, strtotime($end) - strtotime($start));
        $bucket = match (true) {
            $span <= 6 * 3600  => 300,    // 5 minutes
            $span <= 2 * 86400 => 900,    // 15 minutes
            $span <= 8 * 86400 => 3600,   // 1 hour
            default            => 21600,  // 6 hours
        };

        return Database::all(
            "SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / {$bucket}) * {$bucket}) AS bucket,
                    ROUND(AVG(soil_moisture), 2)   AS soil_moisture,
                    ROUND(AVG(soil_moisture_1), 2) AS soil_moisture_1,
                    ROUND(AVG(soil_moisture_2), 2) AS soil_moisture_2,
                    ROUND(AVG(temperature), 2)     AS temperature,
                    ROUND(AVG(leaf_temperature), 2) AS leaf_temperature,
                    ROUND(AVG(humidity), 2)        AS humidity,
                    COUNT(*)                       AS samples
               FROM sensor_readings
              WHERE device_id = ? AND recorded_at BETWEEN ? AND ?
              GROUP BY bucket
              ORDER BY bucket",
            [$deviceId, $start, $end]
        );
    }

    /** Min / max / average across a window, for the reports page. */
    public static function stats(int $deviceId, string $start, string $end): array
    {
        $row = Database::first(
            'SELECT COUNT(*) AS samples,
                    ROUND(AVG(soil_moisture),2) AS avg_moisture,
                    MIN(soil_moisture) AS min_moisture, MAX(soil_moisture) AS max_moisture,
                    ROUND(AVG(soil_moisture_1),2) AS avg_moisture_1,
                    ROUND(AVG(soil_moisture_2),2) AS avg_moisture_2,
                    SUM(soil_moisture_1 IS NULL) AS probe1_failures,
                    SUM(soil_moisture_2 IS NULL) AS probe2_failures,
                    ROUND(AVG(temperature),2) AS avg_temperature,
                    MIN(temperature) AS min_temperature, MAX(temperature) AS max_temperature,
                    ROUND(AVG(leaf_temperature),2) AS avg_leaf_temperature,
                    MIN(leaf_temperature) AS min_leaf_temperature,
                    MAX(leaf_temperature) AS max_leaf_temperature,
                    ROUND(AVG(leaf_temperature - temperature),2) AS avg_leaf_delta,
                    MAX(leaf_temperature - temperature) AS max_leaf_delta,
                    ROUND(AVG(humidity),2) AS avg_humidity,
                    MIN(humidity) AS min_humidity, MAX(humidity) AS max_humidity,
                    -- Humidity is deliberately not counted here. A board with
                    -- no DHT22 fitted reports none, and counting that as a
                    -- failed read would put every single row of a 4.0.0
                    -- device in this figure.
                    SUM(soil_moisture IS NULL OR temperature IS NULL) AS failed_reads
               FROM sensor_readings
              WHERE device_id = ? AND recorded_at BETWEEN ? AND ?',
            [$deviceId, $start, $end]
        );

        return $row ?: [];
    }

    /**
     * Paginated, sortable history.
     *
     * @return array{rows:array,meta:array}
     */
    public static function search(array $filters, int $page, int $perPage = 25): array
    {
        [$where, $params] = self::buildWhere($filters);
        $order = self::orderBy($filters['sort'] ?? '', $filters['dir'] ?? '');

        // The count must join the same tables as the listing query, because
        // the search filter can reference device columns.
        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM sensor_readings r JOIN devices d ON d.id = r.device_id {$where}",
            $params
        );
        $meta = paginate($total, $page, $perPage);

        $rows = Database::all(
            "SELECT r.id, r.device_id, r.soil_moisture, r.soil_moisture_1, r.soil_moisture_2,
                    r.soil_raw, r.soil_raw_2, r.temperature, r.leaf_temperature, r.humidity,
                    r.pump_status, r.mode, r.source, r.recorded_at,
                    d.device_name, d.device_code, d.probe_count, d.has_dht22, d.has_mlx90614
               FROM sensor_readings r
               JOIN devices d ON d.id = r.device_id
               {$where}
              ORDER BY {$order}
              LIMIT {$meta['per_page']} OFFSET {$meta['offset']}",
            $params
        );

        return ['rows' => $rows, 'meta' => $meta];
    }

    public static function export(array $filters, int $limit = 20000): array
    {
        [$where, $params] = self::buildWhere($filters);
        $order = self::orderBy($filters['sort'] ?? '', $filters['dir'] ?? '');
        $limit = max(1, min($limit, 50000));

        return Database::all(
            "SELECT r.id, r.soil_moisture, r.soil_moisture_1, r.soil_moisture_2,
                    r.soil_raw, r.soil_raw_2, r.temperature, r.leaf_temperature, r.humidity,
                    r.pump_status, r.mode, r.source, r.recorded_at, d.device_name, d.device_code
               FROM sensor_readings r
               JOIN devices d ON d.id = r.device_id
               {$where}
              ORDER BY {$order}
              LIMIT {$limit}",
            $params
        );
    }

    /** Whitelist based ORDER BY - the sort key never reaches SQL unchecked. */
    private static function orderBy(string $sort, string $dir): string
    {
        $columns = [
            'recorded_at'     => 'r.recorded_at',
            'soil_moisture'   => 'r.soil_moisture',
            'soil_moisture_1' => 'r.soil_moisture_1',
            'soil_moisture_2' => 'r.soil_moisture_2',
            'temperature'      => 'r.temperature',
            'leaf_temperature' => 'r.leaf_temperature',
            'humidity'         => 'r.humidity',
            'device'           => 'd.device_name',
        ];
        $column    = $columns[$sort] ?? 'r.recorded_at';
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        return $column . ' ' . $direction . ', r.id ' . $direction;
    }

    private static function buildWhere(array $filters): array
    {
        $clauses = [];
        $params  = [];

        if (!empty($filters['device_id'])) {
            $clauses[] = 'r.device_id = ?';
            $params[]  = $filters['device_id'];
        }
        if (!empty($filters['start'])) {
            $clauses[] = 'r.recorded_at >= ?';
            $params[]  = $filters['start'];
        }
        if (!empty($filters['end'])) {
            $clauses[] = 'r.recorded_at <= ?';
            $params[]  = $filters['end'];
        }
        if (!empty($filters['pump_status'])) {
            $clauses[] = 'r.pump_status = ?';
            $params[]  = $filters['pump_status'];
        }
        if (!empty($filters['source'])) {
            $clauses[] = 'r.source = ?';
            $params[]  = $filters['source'];
        }
        if (!empty($filters['search'])) {
            // Free text search runs against the device and the timestamp.
            $clauses[] = '(d.device_name LIKE ? OR d.device_code LIKE ? OR r.recorded_at LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like);
        }
        if (!empty($filters['only_failed'])) {
            // Soil and air temperature only. Humidity is null on every
            // reading from a board with no DHT22 fitted, so including it
            // here would make the "failed reads" filter select everything.
            $clauses[] = '(r.soil_moisture IS NULL OR r.temperature IS NULL)';
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }

    /** Remove every demo row (admin action, keeps live data untouched). */
    public static function purgeDemo(): int
    {
        return Database::execute("DELETE FROM sensor_readings WHERE source = 'demo'");
    }

    public static function demoCount(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM sensor_readings WHERE source = 'demo'");
    }
}
