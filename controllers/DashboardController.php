<?php
/**
 * AgriSense - Dashboard and real-time monitoring.
 */

class DashboardController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();

        // Opportunistic housekeeping - cheap, indexed, and keeps the alert
        // list meaningful without needing a cron job on XAMPP.
        Device::flagOfflineDevices();
        PumpCommand::expireStale();

        $device  = $this->selectedDevice();
        $devices = Device::all();

        if (!$device) {
            render('dashboard/empty', ['isAdmin' => Auth::isAdmin()],
                ['title' => 'Dashboard', 'active' => 'dashboard']);

            return;
        }

        $deviceId = (int) $device['id'];
        $snapshot = IrrigationEngine::snapshot($deviceId);

        [$start, $end] = date_range('7days');

        render('dashboard/index', [
            'devices'        => $devices,
            'device'         => $device,
            'snapshot'       => $snapshot,
            'series'         => SensorReading::series($deviceId, $start, $end),
            'activity'       => IrrigationLog::activitySeries($deviceId, $start, $end),
            'recentAlerts'   => Alert::recent(5),
            'recentCycles'   => IrrigationLog::search(['device_id' => $deviceId], 1, 5)['rows'],
            'canControlPump' => Auth::canControlPump(),
            'demoRows'       => SensorReading::demoCount() + IrrigationLog::demoCount(),
        ], ['title' => 'Dashboard', 'active' => 'dashboard']);
    }

    /** The focused real-time monitoring screen. */
    public function monitor(): void
    {
        Auth::requireLogin();

        $device = $this->requireDevice($this->selectedDevice());
        $deviceId = (int) $device['id'];

        [$start, $end] = date_range('today');

        render('dashboard/monitor', [
            'devices'        => Device::all(),
            'device'         => $device,
            'snapshot'       => IrrigationEngine::snapshot($deviceId),
            'series'         => SensorReading::series($deviceId, $start, $end),
            'commands'       => PumpCommand::recent($deviceId, 8),
            'canControlPump' => Auth::canControlPump(),
        ], ['title' => 'Real-time monitoring', 'active' => 'monitor']);
    }
}
