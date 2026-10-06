<?php
/**
 * AgriSense - Irrigation engine
 *
 * Business logic shared by the REST API and the web dashboard:
 *   - validating and storing an incoming telemetry payload
 *   - raising the alerts described in requirement 15
 *   - keeping irrigation_logs consistent with the pump state the device
 *     actually reports, even if an event POST was lost
 *   - assembling the snapshot the dashboard polls
 *
 * The ESP32 remains the authority over the relay. Nothing here switches a
 * pump; it records what happened and queues instructions.
 */

class IrrigationEngine
{
    // -----------------------------------------------------------------
    // Telemetry ingestion
    // -----------------------------------------------------------------

    /**
     * Validate and store one telemetry payload from a device.
     *
     * A measurement that fails validation is stored as NULL rather than as a
     * bogus number, so a broken sensor can never overwrite good history
     * (requirement 6).
     *
     * @return array{reading_id:int,stored:array,warnings:string[]}
     */
    public static function ingest(array $device, array $payload): array
    {
        $deviceId = (int) $device['id'];
        $settings = IrrigationSetting::ensureFor($deviceId);
        $warnings = [];

        // --- Soil moisture (one or two probes) -----------------------------
        //
        // The hardware in esp32/AgriSense.ino carries two probes. Each is
        // stored in its own column so a single failing probe, or a field
        // that waters unevenly, stays visible instead of being hidden by
        // the average. `soil_moisture` remains the PRIMARY figure that
        // irrigation decisions, charts and thresholds use.
        $soilRaw  = self::readRaw($payload, 'soil_raw', $warnings);
        $soilRaw2 = self::readRaw($payload, 'soil_raw_2', $warnings);

        $probe1 = self::readPercent($payload, 'soil_moisture_1', $warnings);
        $probe2 = self::readPercent($payload, 'soil_moisture_2', $warnings);

        // A device that sent only a raw count gets it calibrated here, with
        // the same formula the firmware uses.
        if ($probe1 === null && $soilRaw !== null) {
            $probe1 = IrrigationSetting::rawToPercent($soilRaw, $settings);
        }
        if ($probe2 === null && $soilRaw2 !== null) {
            $probe2 = IrrigationSetting::rawToPercent($soilRaw2, $settings);
        }

        // An explicit soil_moisture wins - the device may average the probes
        // itself, and that is the figure it made its own pump decision on.
        $moisture = self::readPercent($payload, 'soil_moisture', $warnings);

        if ($moisture === null) {
            $available = array_values(array_filter([$probe1, $probe2], static fn ($v) => $v !== null));
            if ($available) {
                $moisture = round(array_sum($available) / count($available), 2);
            }
        } elseif ($probe1 === null && $probe2 === null) {
            // Single-probe payload: the one reading it sent is probe 1.
            $probe1 = $moisture;
        } elseif ($probe1 === null && $probe2 !== null) {
            // Firmware that reports the average plus the second probe only.
            // The average is exactly (p1 + p2) / 2, so probe 1 follows.
            $derived = round(2 * $moisture - $probe2, 2);
            if ($derived >= 0 && $derived <= 100) {
                $probe1 = $derived;
            }
        }

        // --- Air temperature -----------------------------------------------
        //
        // Same field, same meaning, whichever sensor the board carries: a
        // DHT22 on firmware 2.x/3.x, the MLX90614's ambient channel on 4.0.0.
        // Keeping one column is what lets a board be re-sensored without
        // breaking its own history.
        $temperature = null;
        if (isset($payload['temperature']) && is_numeric($payload['temperature'])) {
            $value = (float) $payload['temperature'];
            if (in_range($value, TEMP_MIN, TEMP_MAX)) {
                $temperature = round($value, 2);
            } else {
                $warnings[] = 'temperature outside plausible range, stored as unavailable';
                Alert::raiseThrottled(
                    $deviceId,
                    'abnormal_reading',
                    sprintf('Temperature reading of %.1f C is outside the plausible range (%.0f to %.0f C).',
                        $value, TEMP_MIN, TEMP_MAX),
                    'warning'
                );
            }
        }

        // --- Canopy temperature (MLX90614 object channel) -------------------
        //
        // Checked against LEAF_TEMP_MIN/MAX rather than the air bounds: this
        // is a surface temperature, and a sunlit leaf legitimately sits well
        // above the air around it.
        $leafTemperature = null;
        if (isset($payload['leaf_temperature']) && is_numeric($payload['leaf_temperature'])) {
            $value = (float) $payload['leaf_temperature'];
            if (in_range($value, LEAF_TEMP_MIN, LEAF_TEMP_MAX)) {
                $leafTemperature = round($value, 2);
            } else {
                $warnings[] = 'leaf_temperature outside plausible range, stored as unavailable';
                Alert::raiseThrottled(
                    $deviceId,
                    'abnormal_reading',
                    sprintf('Canopy temperature of %.1f C is outside the plausible range (%.0f to %.0f C). '
                        . 'Check that the infrared sensor is still aimed at the crop.',
                        $value, LEAF_TEMP_MIN, LEAF_TEMP_MAX),
                    'warning'
                );
            }
        }

        // --- Humidity ------------------------------------------------------
        $humidity = null;
        if (isset($payload['humidity']) && is_numeric($payload['humidity'])) {
            $value = (float) $payload['humidity'];
            if (in_range($value, HUMIDITY_MIN, HUMIDITY_MAX)) {
                $humidity = round($value, 2);
            } else {
                $warnings[] = 'humidity outside 0-100, stored as unavailable';
                Alert::raiseThrottled(
                    $deviceId,
                    'abnormal_reading',
                    sprintf('Humidity reading of %.1f%% is outside the valid 0-100%% range.', $value),
                    'warning'
                );
            }
        }

        $pumpStatus = strtoupper((string) ($payload['pump_status'] ?? 'OFF')) === 'ON' ? 'ON' : 'OFF';
        $mode       = strtoupper((string) ($payload['mode'] ?? '')) === 'MANUAL' ? 'MANUAL' : 'AUTO';

        // --- Persist -------------------------------------------------------
        $readingId = SensorReading::store($deviceId, [
            'soil_moisture'    => $moisture,
            'soil_moisture_1'  => $probe1,
            'soil_moisture_2'  => $probe2,
            'soil_raw'         => $soilRaw,
            'soil_raw_2'       => $soilRaw2,
            'temperature'      => $temperature,
            'leaf_temperature' => $leafTemperature,
            'humidity'         => $humidity,
            'pump_status'      => $pumpStatus,
            'mode'             => $mode,
            'source'           => 'device',
        ]);

        Device::touch(
            $deviceId,
            client_ip(),
            isset($payload['firmware_version']) ? substr((string) $payload['firmware_version'], 0, 20) : null
        );

        // --- Alerts --------------------------------------------------------
        self::evaluateAlerts($device, $settings, [
            'soil_moisture'    => $moisture,
            'soil_moisture_1'  => $probe1,
            'soil_moisture_2'  => $probe2,
            'temperature'      => $temperature,
            'leaf_temperature' => $leafTemperature,
            'humidity'         => $humidity,
            'pump_status'      => $pumpStatus,
            // The firmware's own verdict, when it sent one. It knows the
            // exact delta it measured, and it is the side that will grow
            // any smarter stress logic first.
            'leaf_stress'      => isset($payload['leaf_stress'])
                ? filter_var($payload['leaf_stress'], FILTER_VALIDATE_BOOLEAN)
                : null,
        ]);

        // --- Keep the cycle history honest ---------------------------------
        self::reconcilePumpState($deviceId, $pumpStatus, $mode, $moisture, $settings);

        return [
            'reading_id' => $readingId,
            'stored'     => [
                'soil_moisture'    => $moisture,
                'soil_moisture_1'  => $probe1,
                'soil_moisture_2'  => $probe2,
                'soil_raw'         => $soilRaw,
                'soil_raw_2'       => $soilRaw2,
                'temperature'      => $temperature,
                'leaf_temperature' => $leafTemperature,
                'humidity'         => $humidity,
                'pump_status'      => $pumpStatus,
                'mode'             => $mode,
            ],
            'warnings'   => $warnings,
        ];
    }

    /**
     * Read a raw ADC value from the payload, rejecting anything outside the
     * ESP32's 12-bit range.
     */
    private static function readRaw(array $payload, string $field, array &$warnings): ?int
    {
        if (!isset($payload[$field]) || !is_numeric($payload[$field])) {
            return null;
        }

        $value = (int) $payload[$field];
        if ($value < 0 || $value > 4095) {
            $warnings[] = $field . ' out of ADC range, ignored';

            return null;
        }

        return $value;
    }

    /** Read a 0-100 percentage from the payload. */
    private static function readPercent(array $payload, string $field, array &$warnings): ?float
    {
        if (!isset($payload[$field]) || !is_numeric($payload[$field])) {
            return null;
        }

        $value = (float) $payload[$field];
        if ($value < 0 || $value > 100) {
            $warnings[] = $field . ' outside 0-100, ignored';

            return null;
        }

        return round($value, 2);
    }

    /**
     * Raise the alerts a reading warrants. Every call is throttled so a
     * sensor stuck in a bad state does not flood the alerts table.
     */
    private static function evaluateAlerts(array $device, array $settings, array $values): void
    {
        $deviceId = (int) $device['id'];
        $name     = $device['device_name'];

        $probes = (int) ($device['probe_count'] ?? 1);

        // A failed read is reported as NULL by the firmware.
        $failed = [];
        if ($values['soil_moisture'] === null) {
            $failed[] = 'soil moisture';
        } elseif ($probes >= 2) {
            // With two probes fitted, one of them dropping out still leaves
            // a usable average - but it is worth saying so.
            if ($values['soil_moisture_1'] === null) {
                $failed[] = 'soil probe 1';
            }
            if ($values['soil_moisture_2'] === null) {
                $failed[] = 'soil probe 2';
            }
        }
        // Only complain about a measurement the board is actually fitted to
        // take. Firmware 4.0.0 has no DHT22 and therefore no humidity at all;
        // treating that as a failure would raise a sensor_failure alert on
        // every single reading and bury the ones that mean something.
        if ($values['temperature'] === null && self::expectsTemperature($device)) {
            $failed[] = 'temperature';
        }
        if ($values['humidity'] === null && self::expectsHumidity($device)) {
            $failed[] = 'humidity';
        }
        if ($values['leaf_temperature'] === null && self::expectsLeafTemperature($device)) {
            $failed[] = 'canopy temperature';
        }

        // Two probes in the same bed should broadly agree. A large, sustained
        // gap usually means one has worked loose or is sitting in an air
        // pocket, and the average it feeds is no longer trustworthy.
        if ($probes >= 2
            && $values['soil_moisture_1'] !== null
            && $values['soil_moisture_2'] !== null) {
            $gap = abs($values['soil_moisture_1'] - $values['soil_moisture_2']);
            if ($gap >= 25.0) {
                Alert::raiseThrottled(
                    $deviceId,
                    'probe_mismatch',
                    sprintf('The two soil probes on %s disagree by %.1f points (%.1f%% and %.1f%%). '
                        . 'Check that both are firmly seated.',
                        $name, $gap, $values['soil_moisture_1'], $values['soil_moisture_2']),
                    'warning',
                    3600
                );
            }
        }

        if ($failed) {
            Alert::raiseThrottled(
                $deviceId,
                'sensor_failure',
                sprintf('%s could not read %s. The last valid value is still shown.',
                    $name, implode(' and ', $failed)),
                'warning'
            );

            // Losing the moisture sensor while irrigating is the dangerous
            // case: the firmware stops the pump and we record why.
            if ($values['soil_moisture'] === null && $values['pump_status'] === 'ON') {
                Alert::raiseThrottled(
                    $deviceId,
                    'safe_state',
                    $name . ' lost its soil moisture reading while the pump was running. '
                        . 'Irrigation is being stopped as a precaution.',
                    'critical',
                    300
                );
            }
        }

        if ($values['soil_moisture'] !== null
            && $values['soil_moisture'] <= (float) $settings['moisture_threshold']) {
            Alert::raiseThrottled(
                $deviceId,
                'low_moisture',
                sprintf('Soil moisture at %s is %.1f%%, at or below the %.1f%% threshold.',
                    $name, $values['soil_moisture'], (float) $settings['moisture_threshold']),
                'warning',
                1800
            );
        }

        self::evaluateCropStress($deviceId, $name, $values);
    }

    /**
     * Warn when the canopy is running hot relative to the air.
     *
     * This is advisory, exactly as it is on the device: it never queues a
     * command and never touches the pump. A misaimed infrared sensor - one
     * that has drifted round to face bare soil or open sky - reads 15 to 20
     * degrees off, and that must not be able to water a field.
     *
     * Throttled to one an hour. Stress is a condition that persists for
     * hours, not an event, so an alert per reading would be noise.
     */
    private static function evaluateCropStress(int $deviceId, string $name, array $values): void
    {
        $leaf = $values['leaf_temperature'];
        $air  = $values['temperature'];

        if ($leaf === null || $air === null) {
            return;
        }

        $delta = $leaf - $air;

        // The device's own verdict wins when it sent one - it measured both
        // channels itself, in the same instant, from the same sensor. The
        // delta check is the fallback for a payload that carries the two
        // temperatures without the flag.
        $stressed = $values['leaf_stress'] ?? ($delta >= LEAF_STRESS_DELTA);
        if (!$stressed) {
            return;
        }

        Alert::raiseThrottled(
            $deviceId,
            'crop_stress',
            sprintf('Canopy at %s is %.1f C above air temperature (%.1f C vs %.1f C), which suggests '
                . 'the crop is not transpiring freely.', $name, $delta, $leaf, $air),
            'warning',
            3600
        );
    }

    // -----------------------------------------------------------------
    // Which measurements a board is fitted to take
    //
    // Two independent flags, because the sensors changed independently:
    // firmware 2.x/3.x carried a DHT22, 4.0.0 carries an MLX90614 instead,
    // and both are still in service. Air temperature comes from either, so
    // it is the one measurement that survived the swap.
    // -----------------------------------------------------------------

    public static function expectsTemperature(array $device): bool
    {
        return self::hasDht22($device) || self::hasMlx90614($device);
    }

    public static function expectsHumidity(array $device): bool
    {
        return self::hasDht22($device);
    }

    public static function expectsLeafTemperature(array $device): bool
    {
        return self::hasMlx90614($device);
    }

    private static function hasDht22(array $device): bool
    {
        // Defaults to fitted: rows written before the flag existed were all
        // DHT22 boards.
        return (int) ($device['has_dht22'] ?? 1) === 1;
    }

    private static function hasMlx90614(array $device): bool
    {
        // Defaults to absent: the sensor is newer than the column.
        return (int) ($device['has_mlx90614'] ?? 0) === 1;
    }

    /**
     * Compare the pump state the device reports with the open cycle in the
     * database and fix any disagreement.
     *
     * Devices post explicit start/stop events, but a dropped request would
     * otherwise leave the history wrong forever. This is the safety net.
     */
    private static function reconcilePumpState(
        int $deviceId,
        string $pumpStatus,
        string $mode,
        ?float $moisture,
        array $settings
    ): void {
        $open = IrrigationLog::openCycle($deviceId);

        if ($pumpStatus === 'ON' && !$open) {
            // Pump is running but we have no record of it starting.
            IrrigationLog::start(
                $deviceId,
                $mode === 'MANUAL' ? 'manual' : 'automatic',
                $moisture,
                (float) $settings['moisture_threshold']
            );
            Alert::raiseThrottled(
                $deviceId,
                'pump_on',
                'Pump reported as running; irrigation cycle recorded.',
                'info',
                60
            );

            return;
        }

        if ($pumpStatus === 'OFF' && $open) {
            // Give the device's own stop event a moment to arrive before
            // closing the cycle ourselves.
            if (time() - strtotime($open['started_at']) < 15) {
                return;
            }

            // Not 'target_reached'. Reaching here means the device's stop
            // event never arrived, and the events most likely to go missing
            // are the ones worth seeing: a max-runtime cut-off or a sensor
            // failure stops the pump and then tries to reach a network that
            // may be the reason it fired. Recording those as a clean
            // success would hide exactly what the operator needs to know.
            IrrigationLog::close((int) $open['id'], $moisture, 'reconciled_unknown');
        }
    }

    // -----------------------------------------------------------------
    // Dashboard snapshot
    // -----------------------------------------------------------------

    /**
     * Everything the live dashboard needs for one device, in one array.
     * Shared by the HTML view and by GET /api/dashboard so the two can never
     * disagree.
     */
    public static function snapshot(int $deviceId): array
    {
        $device = Device::find($deviceId);
        if (!$device) {
            throw new RuntimeException('Device not found.');
        }

        $settings = IrrigationSetting::ensureFor($deviceId);
        $latest   = SensorReading::latest($deviceId);
        $open     = IrrigationLog::openCycle($deviceId);
        $online   = Device::isOnline($device);

        // Fall back to the last good value when the newest read failed.
        $moisture    = self::valueOrLastGood($deviceId, $latest, 'soil_moisture');
        $probe1      = self::valueOrLastGood($deviceId, $latest, 'soil_moisture_1');
        $probe2      = self::valueOrLastGood($deviceId, $latest, 'soil_moisture_2');
        $temperature = self::valueOrLastGood($deviceId, $latest, 'temperature');
        $leafTemp    = self::valueOrLastGood($deviceId, $latest, 'leaf_temperature');
        $humidity    = self::valueOrLastGood($deviceId, $latest, 'humidity');

        // The gap between canopy and air is the figure worth reading, so it
        // is computed once here rather than in each of the places that show
        // it. Only meaningful when both come from the same reading: pairing
        // a current canopy value with a stale air value would invent a delta
        // that was never measured.
        $leafDelta = null;
        if ($leafTemp['value'] !== null && $temperature['value'] !== null
            && !$leafTemp['stale'] && !$temperature['stale']) {
            $leafDelta = round($leafTemp['value'] - $temperature['value'], 1);
        }

        [$todayStart, $todayEnd] = date_range('today');
        $today = IrrigationLog::stats($deviceId, $todayStart, $todayEnd);

        $pumpOn = $latest !== null && $latest['pump_status'] === 'ON';

        return [
            'device' => [
                'id'          => (int) $device['id'],
                'name'        => $device['device_name'],
                'code'        => $device['device_code'],
                'location'    => $device['location'],
                'status'      => $device['status'],
                'online'      => $online,
                'last_seen'   => $device['last_seen'],
                'last_seen_h' => time_ago($device['last_seen']),
                'ip_address'  => $device['ip_address'],
                'firmware'    => $device['firmware_version'],
                'probe_count'  => (int) ($device['probe_count'] ?? 1),
                'has_dht22'    => (int) ($device['has_dht22'] ?? 1) === 1,
                'has_mlx90614' => (int) ($device['has_mlx90614'] ?? 0) === 1,
                // What to show, rather than what is bolted to the board:
                // air temperature comes from either sensor, so the view does
                // not have to know which one this device carries.
                'show_temperature' => self::expectsTemperature($device),
                'show_humidity'    => self::expectsHumidity($device),
                'show_leaf'        => self::expectsLeafTemperature($device),
            ],
            'readings' => [
                'soil_moisture'    => $moisture,
                'soil_moisture_1'  => $probe1,
                'soil_moisture_2'  => $probe2,
                'temperature'      => $temperature,
                'leaf_temperature' => $leafTemp,
                'leaf_delta'       => $leafDelta,
                'leaf_stress'      => $leafDelta !== null && $leafDelta >= LEAF_STRESS_DELTA,
                'humidity'         => $humidity,
                'soil_raw'         => $latest['soil_raw'] ?? null,
                'soil_raw_2'       => $latest['soil_raw_2'] ?? null,
                'recorded_at'      => $latest['recorded_at'] ?? null,
                'recorded_h'       => time_ago($latest['recorded_at'] ?? null),
            ],
            'pump' => [
                'status'          => $pumpOn ? 'ON' : 'OFF',
                'mode'            => (int) $settings['auto_irrigation'] === 1 ? 'AUTO' : 'MANUAL',
                'running_cycle'   => $open ? [
                    'id'           => (int) $open['id'],
                    'trigger_type' => $open['trigger_type'],
                    'started_at'   => $open['started_at'],
                    'elapsed'      => max(0, time() - strtotime($open['started_at'])),
                ] : null,
                'pending_command' => PumpCommand::pendingCount($deviceId) > 0,
            ],
            'settings' => [
                'moisture_threshold' => (float) $settings['moisture_threshold'],
                'target_moisture'    => (float) $settings['target_moisture'],
                'auto_irrigation'    => (int) $settings['auto_irrigation'] === 1,
                'max_pump_runtime'   => (int) $settings['max_pump_runtime'],
                'reading_interval'   => (int) $settings['reading_interval'],
                'min_cycle_interval' => (int) $settings['min_cycle_interval'],
            ],
            'today' => [
                'cycles'        => $today['cycles'],
                'total_seconds' => $today['total_seconds'],
                'total_h'       => fmt_duration($today['total_seconds']),
            ],
            'alerts' => [
                'unread' => Alert::unreadCount(),
            ],
            'server_time' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array{value:?float,stale:bool,recorded_at:?string}
     */
    private static function valueOrLastGood(int $deviceId, ?array $latest, string $column): array
    {
        if ($latest !== null && $latest[$column] !== null) {
            return [
                'value'       => (float) $latest[$column],
                'stale'       => false,
                'recorded_at' => $latest['recorded_at'],
            ];
        }

        $fallback = SensorReading::lastValid($deviceId, $column);

        return [
            'value'       => $fallback ? (float) $fallback['value'] : null,
            'stale'       => $fallback !== null,   // shown, but flagged as not current
            'recorded_at' => $fallback['recorded_at'] ?? null,
        ];
    }

    // -----------------------------------------------------------------
    // Manual control
    // -----------------------------------------------------------------

    /**
     * Queue a manual pump instruction after checking every safety rule in
     * requirement 27 that the server can check.
     *
     * @return array{ok:bool,error?:string,command_id?:int,message?:string}
     */
    public static function requestPumpCommand(array $device, string $command, ?int $userId): array
    {
        $deviceId = (int) $device['id'];

        if (!in_array($command, PumpCommand::COMMANDS, true)) {
            return ['ok' => false, 'error' => 'Unknown pump command.'];
        }

        if ($device['status'] !== 'active') {
            return ['ok' => false, 'error' => 'This device is not active, so it cannot accept commands.'];
        }

        if (!Device::isOnline($device)) {
            return [
                'ok'    => false,
                'error' => 'The device is offline. It last reported ' . time_ago($device['last_seen'])
                    . ', so the command would not reach it.',
            ];
        }

        $settings = IrrigationSetting::ensureFor($deviceId);

        // Turning the pump on by hand only makes sense in manual mode -
        // in automatic mode the firmware would immediately override it.
        if ($command === 'PUMP_ON') {
            if ((int) $settings['auto_irrigation'] === 1) {
                return [
                    'ok'    => false,
                    'error' => 'Switch the device to Manual mode before starting the pump by hand.',
                ];
            }

            $rest = IrrigationLog::secondsSinceLastCycle($deviceId);
            $minRest = (int) $settings['min_cycle_interval'];
            if ($rest !== null && $minRest > 0 && $rest < $minRest) {
                return [
                    'ok'    => false,
                    'error' => sprintf(
                        'The last irrigation finished %s ago. Please wait %s so the soil can absorb the water.',
                        fmt_duration($rest),
                        fmt_duration($minRest - $rest)
                    ),
                ];
            }

            if (IrrigationLog::openCycle($deviceId)) {
                return ['ok' => false, 'error' => 'The pump is already running.'];
            }
        }

        // Switching back to automatic while the pump is running by hand is
        // allowed - the firmware re-evaluates the thresholds immediately.

        $id = PumpCommand::queue($deviceId, $command, $userId);

        SystemLog::record(
            $userId,
            'pump_control',
            sprintf('Queued %s for %s (%s).', $command, $device['device_name'], $device['device_code'])
        );

        Alert::raise(
            $deviceId,
            'pump_command',
            PumpCommand::LABELS[$command] . ' requested for ' . $device['device_name'] . '.',
            'info'
        );

        return [
            'ok'         => true,
            'command_id' => $id,
            'message'    => PumpCommand::LABELS[$command] . ' sent. Waiting for the device to confirm.',
        ];
    }
}
