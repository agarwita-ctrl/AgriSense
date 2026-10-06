<?php
/**
 * AgriSense - Dashboard and report endpoints
 *
 *   GET /api/dashboard        (session) - polled for live values
 *   GET /api/report/summary   (session)
 */

class ReportApi
{
    /**
     * The live snapshot the dashboard polls. Deliberately small: it is
     * requested every few seconds by every open browser.
     */
    public static function dashboard(): never
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
        } elseif (!Device::find($deviceId)) {
            Response::error('That device could not be found.', 404);
        }

        // Cheap housekeeping on the polling path, so an offline board is
        // noticed without needing a scheduled task.
        Device::flagOfflineDevices();
        PumpCommand::expireStale();

        Response::ok(IrrigationEngine::snapshot($deviceId));
    }

    /** Aggregate figures behind the reports page. */
    public static function summary(): never
    {
        Response::allowMethods('GET');
        ApiAuth::session();

        $type  = one_of(input('type'), ['sensor', 'irrigation', 'device'], 'sensor');
        $range = one_of(input('range', '7days'),
            ['today', 'yesterday', '7days', '30days', 'custom'], '7days');
        [$start, $end] = date_range($range, input('from'), input('to'));

        if ($type === 'device') {
            Response::ok([
                'type'    => 'device',
                'devices' => Device::all(),
            ]);
        }

        $deviceId = input_int('device_id', 0, 1);
        if ($deviceId === 0) {
            $default = Device::defaultDevice();
            if (!$default) {
                Response::error('No device has been registered yet.', 404);
            }
            $deviceId = (int) $default['id'];
        }

        Response::ok([
            'type'      => $type,
            'device_id' => $deviceId,
            'from'      => $start,
            'to'        => $end,
            'summary'   => $type === 'irrigation'
                ? IrrigationLog::stats($deviceId, $start, $end)
                : SensorReading::stats($deviceId, $start, $end),
            'rows'      => $type === 'irrigation'
                ? IrrigationLog::activitySeries($deviceId, $start, $end)
                : SensorReading::series($deviceId, $start, $end),
        ]);
    }
}
