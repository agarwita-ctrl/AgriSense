<?php
/**
 * AgriSense - Irrigation endpoints
 *
 *   POST /api/irrigation/log        (device)
 *   POST /api/pump/control          (session)
 *   GET  /api/pump/command-status   (session)
 *   GET  /api/irrigation-history    (session)
 */

class IrrigationApi
{
    /**
     * The device reports that a cycle started or stopped.
     *
     * Body:
     *   { "device_id":"AGRISENSE-ESP32-01", "action":"start",
     *     "trigger_type":"automatic", "soil_moisture":28.5 }
     *   { "device_id":"AGRISENSE-ESP32-01", "action":"stop",
     *     "soil_moisture":61.2, "stop_reason":"target_reached" }
     */
    public static function log(): never
    {
        Response::allowMethods('POST');

        $body   = Response::body();
        $device = ApiAuth::device($body);
        ApiAuth::assertOwnsDevice($device, ApiAuth::claimedIdentifier($body));

        $deviceId = (int) $device['id'];
        $action   = strtolower((string) ($body['action'] ?? ''));

        if (!in_array($action, ['start', 'stop'], true)) {
            Response::error('action must be "start" or "stop".', 422);
        }

        $moisture = isset($body['soil_moisture']) && is_numeric($body['soil_moisture'])
            ? round((float) $body['soil_moisture'], 2)
            : null;

        if ($moisture !== null && ($moisture < 0 || $moisture > 100)) {
            Response::error('soil_moisture must be between 0 and 100.', 422);
        }

        $settings = IrrigationSetting::ensureFor($deviceId);

        if ($action === 'start') {
            $trigger = strtolower((string) ($body['trigger_type'] ?? 'automatic'));
            $trigger = in_array($trigger, IrrigationLog::TRIGGERS, true) ? $trigger : 'automatic';

            // A manual cycle carries the operator who asked for it, taken
            // from the command the device is acting on.
            $operator = null;
            if (!empty($body['command_id'])) {
                $cmd = PumpCommand::find((int) $body['command_id']);
                if ($cmd && (int) $cmd['device_id'] === $deviceId) {
                    $operator = $cmd['requested_by'] !== null ? (int) $cmd['requested_by'] : null;
                }
            }

            $existing = IrrigationLog::openCycle($deviceId);
            if ($existing) {
                // Already recorded - most likely a retry after a lost reply.
                Response::ok([
                    'cycle_id'  => (int) $existing['id'],
                    'duplicate' => true,
                ], 'A cycle is already open for this device.');
            }

            $id = IrrigationLog::start(
                $deviceId,
                $trigger,
                $moisture,
                (float) $settings['moisture_threshold'],
                $operator
            );

            Alert::raise(
                $deviceId,
                'pump_on',
                sprintf('Pump started on %s (%s)%s.',
                    $device['device_name'],
                    $trigger,
                    $moisture !== null ? sprintf(' at %.1f%% soil moisture', $moisture) : ''
                ),
                'info'
            );

            Response::ok(['cycle_id' => $id, 'duplicate' => false], 'Irrigation cycle opened.');
        }

        // --- stop ---------------------------------------------------------
        $reason = (string) ($body['stop_reason'] ?? 'target_reached');
        if (!array_key_exists($reason, IrrigationLog::STOP_REASONS)) {
            $reason = 'target_reached';
        }

        $closed = IrrigationLog::closeOpen($deviceId, $moisture, $reason);

        if (!$closed) {
            Response::ok(['cycle_id' => null, 'duplicate' => true],
                'No open cycle to close - nothing to do.');
        }

        $duration = (int) $closed['duration_seconds'];

        Alert::raise(
            $deviceId,
            $reason === 'max_runtime' ? 'max_runtime' : 'pump_off',
            $reason === 'max_runtime'
                ? sprintf('Pump on %s was stopped by the %s safety limit.',
                    $device['device_name'], fmt_duration((int) $settings['max_pump_runtime']))
                : sprintf('Pump stopped on %s after %s (%s).',
                    $device['device_name'], fmt_duration($duration),
                    IrrigationLog::STOP_REASONS[$reason]),
            $reason === 'max_runtime' ? 'warning' : 'info'
        );

        Response::ok([
            'cycle_id'         => (int) $closed['id'],
            'duration_seconds' => $duration,
            'duplicate'        => false,
        ], 'Irrigation cycle closed.');
    }

    /**
     * Queue a manual pump command from the dashboard. Requires a session,
     * the CSRF token and the pump-control permission.
     */
    public static function pumpControl(): never
    {
        Response::allowMethods('POST');
        ApiAuth::sessionWrite();

        if (!Auth::canControlPump()) {
            SystemLog::record(Auth::id(), 'access_denied', 'Attempted pump control without permission.');
            Response::error('You are not authorised to operate the pump.', 403);
        }

        $body     = Response::body();
        $deviceId = isset($body['device_id']) ? (int) $body['device_id'] : input_int('device_id', 0, 1);
        $command  = strtoupper((string) ($body['command'] ?? input('command')));

        $device = Device::find($deviceId);
        if (!$device) {
            Response::error('That device could not be found.', 404);
        }

        $result = IrrigationEngine::requestPumpCommand($device, $command, Auth::id());

        if (!$result['ok']) {
            Response::error($result['error'], 422);
        }

        Response::ok([
            'command_id' => $result['command_id'],
            'command'    => $command,
        ], $result['message']);
    }

    /** Poll what happened to a queued command. */
    public static function commandStatus(): never
    {
        Response::allowMethods('GET');
        ApiAuth::session();

        $status = PumpCommand::status(input_int('id', 0, 1));
        if (!$status) {
            Response::error('That command could not be found.', 404);
        }

        Response::ok(['command' => $status]);
    }

    /** Paginated irrigation history. */
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

        $range = one_of(input('range', '7days'),
            ['today', 'yesterday', '7days', '30days', 'custom'], '7days');
        [$start, $end] = date_range($range, input('from'), input('to'));

        $result = IrrigationLog::search([
            'device_id'    => $deviceId,
            'start'        => $start,
            'end'          => $end,
            'trigger_type' => one_of(input('trigger_type'), ['', 'automatic', 'manual'], ''),
            'search'       => input('search'),
        ], input_int('page', 1, 1), input_int('per_page', 25, 1, 200));

        Response::ok([
            'cycles'     => $result['rows'],
            'pagination' => $result['meta'],
            'stats'      => IrrigationLog::stats($deviceId, $start, $end),
            'activity'   => IrrigationLog::activitySeries($deviceId, $start, $end),
        ]);
    }
}
