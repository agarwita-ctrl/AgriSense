<?php
/**
 * AgriSense - Device endpoints
 *
 *   POST /api/device/status
 *   GET  /api/device/{id|code}/settings
 *   POST /api/device/command/ack
 */

class DeviceApi
{
    /**
     * Heartbeat. The firmware calls this on boot and whenever it has no
     * fresh sensor data to send, so the dashboard can still tell that the
     * board is alive.
     */
    public static function status(): never
    {
        Response::allowMethods('POST');

        $body   = Response::body();
        $device = ApiAuth::device($body);
        ApiAuth::assertOwnsDevice($device, ApiAuth::claimedIdentifier($body));

        Device::touch(
            (int) $device['id'],
            client_ip(),
            isset($body['firmware_version']) ? substr((string) $body['firmware_version'], 0, 20) : null,
            isset($body['rssi']) ? (int) $body['rssi'] : null,
            isset($body['uptime_seconds']) ? (int) $body['uptime_seconds'] : null
        );

        // The firmware reports Wi-Fi trouble it recovered from so the
        // operator can see a flaky link before it becomes an outage.
        if (!empty($body['wifi_reconnects']) && (int) $body['wifi_reconnects'] > 0) {
            Alert::raiseThrottled(
                (int) $device['id'],
                'wifi_failure',
                sprintf('%s reconnected to Wi-Fi %d time(s) since it started up.',
                    $device['device_name'], (int) $body['wifi_reconnects']),
                'warning',
                1800
            );
        }

        if (!empty($body['sensor_error'])) {
            Alert::raiseThrottled(
                (int) $device['id'],
                'sensor_failure',
                substr((string) $body['sensor_error'], 0, 200),
                'warning'
            );
        }

        Response::ok([
            'device_code' => $device['device_code'],
            'server_time' => date('c'),
            'command'     => self::nextCommand((int) $device['id']),
        ], 'Status received.');
    }

    /**
     * The firmware's configuration poll. Returns the thresholds, the
     * calibration, the timings and any queued manual command.
     */
    public static function settings(string $identifier): never
    {
        Response::allowMethods('GET');

        $device = ApiAuth::device();
        ApiAuth::assertOwnsDevice($device, $identifier);

        $deviceId = (int) $device['id'];
        $settings = IrrigationSetting::ensureFor($deviceId);

        Device::touch($deviceId, client_ip());

        Response::ok([
            'device' => [
                'id'   => $deviceId,
                'code' => $device['device_code'],
                'name' => $device['device_name'],
            ],
            'settings' => [
                'moisture_threshold' => (float) $settings['moisture_threshold'],
                'target_moisture'    => (float) $settings['target_moisture'],
                'dry_value'          => (int) $settings['dry_value'],
                'wet_value'          => (int) $settings['wet_value'],
                'auto_irrigation'    => (int) $settings['auto_irrigation'] === 1,
                'max_pump_runtime'   => (int) $settings['max_pump_runtime'],
                'reading_interval'   => (int) $settings['reading_interval'],
                'min_cycle_interval' => (int) $settings['min_cycle_interval'],
                'updated_at'         => $settings['updated_at'],
            ],
            'command'     => self::nextCommand($deviceId),
            'server_time' => date('c'),
        ]);
    }

    /**
     * The device confirms it carried out a queued command. Recording the
     * outcome is what lets the dashboard show "confirmed by device" rather
     * than merely "sent" (requirement 27, rule 4).
     */
    public static function acknowledgeCommand(): never
    {
        Response::allowMethods('POST');

        $body   = Response::body();
        $device = ApiAuth::device($body);

        $commandId = isset($body['command_id']) ? (int) $body['command_id'] : 0;
        if ($commandId <= 0) {
            Response::error('command_id is required.', 422);
        }

        $command = PumpCommand::find($commandId);
        if (!$command) {
            Response::error('That command does not exist.', 404);
        }
        if ((int) $command['device_id'] !== (int) $device['id']) {
            Response::error('That command belongs to a different device.', 403);
        }

        $ok      = !isset($body['success']) || (bool) $body['success'];
        $message = isset($body['message']) ? (string) $body['message'] : '';

        // False when the command was already settled or superseded - a
        // retried ack from a device on a flaky link. Nothing below should
        // run twice, so the outcome gates the side effects.
        $settled = PumpCommand::acknowledge($commandId, $ok, $message);

        if (!$settled) {
            Response::ok([
                'command_id' => $commandId,
                'status'     => $command['status'],
                'duplicate'  => true,
            ], 'That command was already settled.');
        }

        // A mode change is only real once the firmware confirms it, so the
        // stored flag is updated here rather than when the command is queued.
        // That keeps the dashboard, the settings page and the board in step.
        if ($ok && in_array($command['command'], ['MODE_AUTO', 'MODE_MANUAL'], true)) {
            IrrigationSetting::setAutoMode(
                (int) $device['id'],
                $command['command'] === 'MODE_AUTO',
                $command['requested_by'] !== null ? (int) $command['requested_by'] : null
            );
        }

        if (!$ok) {
            Alert::raise(
                (int) $device['id'],
                'pump_command',
                sprintf('%s could not carry out %s: %s',
                    $device['device_name'], $command['command'], $message ?: 'no reason given'),
                'critical'
            );
        }

        Response::ok(['command_id' => $commandId, 'status' => $ok ? 'acknowledged' : 'failed']);
    }

    /**
     * Claim the next queued command for a device, in the compact shape the
     * firmware expects.
     */
    public static function nextCommand(int $deviceId): ?array
    {
        $cmd = PumpCommand::claimNext($deviceId);

        return $cmd === null ? null : [
            'id'      => (int) $cmd['id'],
            'command' => $cmd['command'],
        ];
    }
}
