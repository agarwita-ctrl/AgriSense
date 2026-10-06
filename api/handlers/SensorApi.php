<?php
/**
 * AgriSense - Sensor endpoints
 *
 *   POST /api/sensor-data      (device)
 *   GET  /api/sensor-history   (session)
 *   GET  /api/chart-data       (session)
 */

class SensorApi
{
    /**
     * Telemetry upload from the ESP32.
     *
     * Example body:
     *   {
     *     "device_id": "AGRISENSE-ESP32-01",
     *     "soil_moisture": 28.5,
     *     "soil_raw": 2630,
     *     "temperature": 30.2,
     *     "leaf_temperature": 34.8,
     *     "pump_status": "ON",
     *     "mode": "AUTO"
     *   }
     *
     * A measurement the board could not take is sent as null (or simply
     * omitted); it is stored as NULL and never as a placeholder number.
     *
     * `temperature` is the AIR temperature whichever sensor supplied it -
     * a DHT22 on firmware 2.x/3.x, the MLX90614's ambient channel on 4.0.0.
     * `leaf_temperature` is the canopy, and only an MLX90614 can measure it.
     * `humidity` needs a DHT22, so firmware 4.0.0 omits it entirely.
     */
    public static function store(): never
    {
        Response::allowMethods('POST');

        $body   = Response::body();
        $device = ApiAuth::device($body);
        ApiAuth::assertOwnsDevice($device, ApiAuth::claimedIdentifier($body));

        // The payload must claim at least one measurement, but an explicit
        // null counts: "I read the sensor and it failed" is real information.
        //
        // Rejecting an all-null reading would be actively harmful - a board
        // whose sensors have all died would stop checking in, its last_seen
        // would go stale, and the dashboard would report it as OFFLINE. The
        // operator would then go hunting for a power or Wi-Fi fault when the
        // board is alive and the sensors are the problem. Storing the nulls
        // keeps the device Online and raises a sensor_failure alert instead.
        $hasAny = false;
        foreach (['soil_moisture', 'soil_moisture_1', 'soil_moisture_2',
                  'soil_raw', 'soil_raw_2', 'temperature', 'leaf_temperature',
                  'humidity'] as $field) {
            if (array_key_exists($field, $body)) {
                $hasAny = true;
                break;
            }
        }
        if (!$hasAny) {
            Response::error(
                'Include at least one measurement field: soil_moisture, soil_moisture_1, '
                . 'soil_moisture_2, soil_raw, soil_raw_2, temperature, leaf_temperature '
                . 'or humidity. Send null for a sensor that failed to read; omit the field '
                . 'entirely for a sensor the board does not have.',
                422
            );
        }

        $result   = IrrigationEngine::ingest($device, $body);
        $settings = IrrigationSetting::ensureFor((int) $device['id']);

        // Piggy-back the current configuration and any queued command on the
        // reply so a device on a slow link needs only one round trip.
        Response::ok([
            'reading_id' => $result['reading_id'],
            'stored'     => $result['stored'],
            'warnings'   => $result['warnings'],
            'settings'   => [
                'moisture_threshold' => (float) $settings['moisture_threshold'],
                'target_moisture'    => (float) $settings['target_moisture'],
                'auto_irrigation'    => (int) $settings['auto_irrigation'] === 1,
                'max_pump_runtime'   => (int) $settings['max_pump_runtime'],
                'reading_interval'   => (int) $settings['reading_interval'],
                'min_cycle_interval' => (int) $settings['min_cycle_interval'],
                'dry_value'          => (int) $settings['dry_value'],
                'wet_value'          => (int) $settings['wet_value'],
            ],
            'command'     => DeviceApi::nextCommand((int) $device['id']),
            'server_time' => date('c'),
        ], 'Reading stored.');
    }

    /** Paginated sensor history for the dashboard tables. */
    public static function history(): never
    {
        Response::allowMethods('GET');
        ApiAuth::session();

        $deviceId = input_int('device_id', 0, 1);
        if ($deviceId === 0) {
            $default = Device::defaultDevice();
            if (!$default) {
                Response::error('No device has been registered yet.', 404);
            }
            $deviceId = (int) $default['id'];
        }

        [$start, $end] = self::range();

        $result = SensorReading::search([
            'device_id' => $deviceId,
            'start'     => $start,
            'end'       => $end,
            'search'    => input('search'),
            'sort'      => one_of(input('sort'), ['recorded_at', 'soil_moisture', 'temperature',
                                                  'leaf_temperature', 'humidity'], 'recorded_at'),
            'dir'       => one_of(input('dir'), ['desc', 'asc'], 'desc'),
        ], input_int('page', 1, 1), input_int('per_page', 25, 1, 200));

        Response::ok([
            'readings'   => $result['rows'],
            'pagination' => $result['meta'],
            'stats'      => SensorReading::stats($deviceId, $start, $end),
        ]);
    }

    /**
     * Bucketed series for the Chart.js charts, plus the irrigation activity
     * series so a range change only needs one request.
     */
    public static function chartData(): never
    {
        Response::allowMethods('GET');
        ApiAuth::session();

        $deviceId = input_int('device_id', 0, 1);
        if ($deviceId === 0) {
            $default = Device::defaultDevice();
            if (!$default) {
                Response::error('No device has been registered yet.', 404);
            }
            $deviceId = (int) $default['id'];
        }

        [$start, $end] = self::range();

        Response::ok([
            'device_id' => $deviceId,
            'from'      => $start,
            'to'        => $end,
            'series'    => SensorReading::series($deviceId, $start, $end),
            'activity'  => IrrigationLog::activitySeries($deviceId, $start, $end),
        ]);
    }

    /** Shared range parsing for the read endpoints. */
    private static function range(): array
    {
        $range = one_of(input('range', '7days'),
            ['today', 'yesterday', '7days', '30days', 'custom'], '7days');

        return date_range($range, input('from'), input('to'));
    }
}
