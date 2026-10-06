<?php
/**
 * AgriSense - Reporting (requirement 24).
 *
 * Three report types - sensor, irrigation and device - each printable and
 * exportable to CSV, filtered by device and date range.
 */

class ReportController extends Controller
{
    public const TYPES = [
        'sensor'     => 'Sensor report',
        'irrigation' => 'Irrigation report',
        'device'     => 'Device report',
    ];

    public function index(): void
    {
        Auth::requireLogin();

        $type  = one_of(input('type'), array_keys(self::TYPES), 'sensor');
        $range = $this->rangeFilter('7days');

        $devices = Device::all();
        $device  = null;
        if ($type !== 'device') {
            $device = $this->requireDevice($this->selectedDevice());
        }

        $data = $this->buildReport($type, $device, $range);

        render('reports/index', [
            'devices' => $devices,
            'device'  => $device,
            'type'    => $type,
            'range'   => $range,
            'report'  => $data,
            'print'   => input('print') === '1',
        ], [
            'title'      => self::TYPES[$type],
            'active'     => 'reports',
            'body_class' => input('print') === '1' ? 'print-mode' : '',
        ]);
    }

    public function export(): void
    {
        Auth::requireLogin();

        $type  = one_of(input('type'), array_keys(self::TYPES), 'sensor');
        $range = $this->rangeFilter('7days');
        $device = $type === 'device' ? null : $this->requireDevice($this->selectedDevice());

        $report = $this->buildReport($type, $device, $range);

        SystemLog::record(Auth::id(), 'export',
            sprintf('Exported the %s for %s to %s.', self::TYPES[$type],
                $device['device_code'] ?? 'all devices', $range['range']));

        $stamp = date('Ymd-His');

        switch ($type) {
            case 'irrigation':
                send_csv(
                    "agrisense-irrigation-report-{$stamp}.csv",
                    ['Date', 'Start time', 'End time', 'Duration', 'Trigger',
                     'Soil moisture (%)', 'Threshold (%)', 'Stop reason', 'Operator'],
                    array_map(static fn (array $r): array => [
                        date('Y-m-d', strtotime($r['started_at'])),
                        date('H:i:s', strtotime($r['started_at'])),
                        $r['ended_at'] ? date('H:i:s', strtotime($r['ended_at'])) : 'running',
                        fmt_duration($r['duration_seconds'] !== null ? (int) $r['duration_seconds'] : null),
                        ucfirst($r['trigger_type']),
                        $r['soil_moisture'] ?? '',
                        $r['threshold'] ?? '',
                        $r['stop_reason'] ? (IrrigationLog::STOP_REASONS[$r['stop_reason']] ?? $r['stop_reason']) : '',
                        $r['operator'] ?? '',
                    ], $report['rows'])
                );
                // no break - send_csv exits

            case 'device':
                send_csv(
                    "agrisense-device-report-{$stamp}.csv",
                    ['Device', 'Device code', 'Location', 'Status', 'Connectivity', 'Last seen',
                     'IP address', 'Firmware', 'Readings', 'Irrigation cycles', 'Unread alerts'],
                    array_map(static fn (array $r): array => [
                        $r['device_name'],
                        $r['device_code'],
                        $r['location'] ?? '',
                        ucfirst($r['status']),
                        (int) $r['is_online'] === 1 ? 'Online' : 'Offline',
                        $r['last_seen'] ?? 'never',
                        $r['ip_address'] ?? '',
                        $r['firmware_version'] ?? '',
                        $r['readings'],
                        $r['cycles'],
                        $r['unread_alerts'],
                    ], $report['rows'])
                );
                // no break - send_csv exits

            case 'sensor':
            default:
                send_csv(
                    "agrisense-sensor-report-{$stamp}.csv",
                    ['Period', 'Soil moisture (%)', 'Temperature (C)', 'Humidity (%)', 'Samples'],
                    array_map(static fn (array $r): array => [
                        $r['bucket'],
                        $r['soil_moisture'] ?? '',
                        $r['temperature'] ?? '',
                        $r['humidity'] ?? '',
                        $r['samples'],
                    ], $report['rows'])
                );
        }
    }

    /**
     * Assemble the rows and summary for one report type.
     *
     * @return array{rows:array,summary:array}
     */
    private function buildReport(string $type, ?array $device, array $range): array
    {
        if ($type === 'device') {
            $rows = Database::all(
                "SELECT d.*,
                        (d.last_seen IS NOT NULL AND d.last_seen > (NOW() - INTERVAL " . DEVICE_OFFLINE_AFTER . " SECOND)) AS is_online,
                        (SELECT COUNT(*) FROM sensor_readings r WHERE r.device_id = d.id) AS readings,
                        (SELECT COUNT(*) FROM irrigation_logs l WHERE l.device_id = d.id) AS cycles,
                        (SELECT COUNT(*) FROM alerts a WHERE a.device_id = d.id AND a.is_read = 0) AS unread_alerts
                   FROM devices d
                  ORDER BY d.device_name"
            );

            $online = 0;
            foreach ($rows as $r) {
                $online += (int) $r['is_online'];
            }

            return [
                'rows'    => $rows,
                'summary' => [
                    'devices' => count($rows),
                    'online'  => $online,
                    'offline' => count($rows) - $online,
                ],
            ];
        }

        $deviceId = (int) $device['id'];

        if ($type === 'irrigation') {
            $rows = IrrigationLog::export([
                'device_id' => $deviceId,
                'start'     => $range['start'],
                'end'       => $range['end'],
            ], 5000);

            return [
                'rows'    => $rows,
                'summary' => IrrigationLog::stats($deviceId, $range['start'], $range['end']),
            ];
        }

        // Sensor report - bucketed averages plus min/max/avg headline figures.
        return [
            'rows'    => SensorReading::series($deviceId, $range['start'], $range['end']),
            'summary' => SensorReading::stats($deviceId, $range['start'], $range['end']),
        ];
    }
}
