<?php
/**
 * AgriSense - Sensor history (requirement 14).
 */

class SensorController extends Controller
{
    public function history(): void
    {
        Auth::requireLogin();

        $device = $this->requireDevice($this->selectedDevice());
        $range  = $this->rangeFilter('today');

        $filters = [
            'device_id'   => (int) $device['id'],
            'start'       => $range['start'],
            'end'         => $range['end'],
            'search'      => input('search'),
            'pump_status' => one_of(input('pump_status'), ['', 'ON', 'OFF'], ''),
            'only_failed' => input('only_failed') === '1',
            'sort'        => one_of(input('sort'), ['recorded_at', 'soil_moisture', 'temperature',
                                                    'leaf_temperature', 'humidity'], 'recorded_at'),
            'dir'         => one_of(input('dir'), ['desc', 'asc'], 'desc'),
        ];

        $result = SensorReading::search($filters, input_int('page', 1, 1), input_int('per_page', 25, 10, 200));

        render('sensors/history', [
            'devices' => Device::all(),
            'device'  => $device,
            'rows'    => $result['rows'],
            'meta'    => $result['meta'],
            'filters' => $filters,
            'range'   => $range,
            'series'  => SensorReading::series((int) $device['id'], $range['start'], $range['end']),
            'stats'   => SensorReading::stats((int) $device['id'], $range['start'], $range['end']),
        ], ['title' => 'Sensor history', 'active' => 'sensors']);
    }

    /** CSV export of exactly what the filtered table shows. */
    public function export(): void
    {
        Auth::requireLogin();

        $device = $this->requireDevice($this->selectedDevice());
        $range  = $this->rangeFilter('today');

        $rows = SensorReading::export([
            'device_id'   => (int) $device['id'],
            'start'       => $range['start'],
            'end'         => $range['end'],
            'search'      => input('search'),
            'pump_status' => one_of(input('pump_status'), ['', 'ON', 'OFF'], ''),
            'only_failed' => input('only_failed') === '1',
            'sort'        => one_of(input('sort'), ['recorded_at', 'soil_moisture', 'temperature',
                                                    'leaf_temperature', 'humidity'], 'recorded_at'),
            'dir'         => one_of(input('dir'), ['desc', 'asc'], 'desc'),
        ]);

        SystemLog::record(Auth::id(), 'export', 'Exported ' . count($rows) . ' sensor readings to CSV.');

        send_csv(
            sprintf('agrisense-sensors-%s-%s.csv', $device['device_code'], date('Ymd-His')),
            // Every column is exported for every device, fitted or not, so
            // that one CSV layout covers a mixed fleet and a spreadsheet
            // built on last month's export still opens this month's.
            ['ID', 'Device', 'Device code', 'Recorded at', 'Soil moisture (%)',
             'Probe 1 (%)', 'Probe 2 (%)', 'Probe 1 raw (ADC)', 'Probe 2 raw (ADC)',
             'Air temperature (C)', 'Canopy temperature (C)', 'Canopy - air (C)',
             'Humidity (%)', 'Pump', 'Mode', 'Source'],
            array_map(static fn (array $r): array => [
                $r['id'],
                $r['device_name'],
                $r['device_code'],
                $r['recorded_at'],
                $r['soil_moisture'] ?? '',
                $r['soil_moisture_1'] ?? '',
                $r['soil_moisture_2'] ?? '',
                $r['soil_raw'] ?? '',
                $r['soil_raw_2'] ?? '',
                $r['temperature'] ?? '',
                $r['leaf_temperature'] ?? '',
                $r['leaf_temperature'] !== null && $r['temperature'] !== null
                    ? number_format((float) $r['leaf_temperature'] - (float) $r['temperature'], 2, '.', '')
                    : '',
                $r['humidity'] ?? '',
                $r['pump_status'],
                $r['mode'],
                $r['source'],
            ], $rows)
        );
    }
}
