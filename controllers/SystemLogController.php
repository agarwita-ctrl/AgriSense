<?php
/**
 * AgriSense - System logs and data maintenance. Administrators only.
 */

class SystemLogController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        $range   = $this->rangeFilter('30days');
        $filters = [
            'search'  => input('search'),
            'action'  => input('action_filter'),
            'user_id' => input_int('user_id', 0, 0) ?: null,
            'start'   => $range['start'],
            'end'     => $range['end'],
        ];

        $result = SystemLog::search($filters, input_int('page', 1, 1), input_int('per_page', 30, 10, 200));

        render('logs/index', [
            'rows'    => $result['rows'],
            'meta'    => $result['meta'],
            'filters' => $filters,
            'range'   => $range,
            'actions' => SystemLog::actions(),
            'users'   => User::all(),
        ], ['title' => 'System logs', 'active' => 'logs']);
    }

    public function export(): void
    {
        Auth::requireAdmin();

        $range = $this->rangeFilter('30days');
        $rows  = SystemLog::export([
            'search'  => input('search'),
            'action'  => input('action_filter'),
            'user_id' => input_int('user_id', 0, 0) ?: null,
            'start'   => $range['start'],
            'end'     => $range['end'],
        ]);

        send_csv(
            'agrisense-system-logs-' . date('Ymd-His') . '.csv',
            ['ID', 'Date/time', 'User', 'Username', 'Action', 'Description', 'IP address'],
            array_map(static fn (array $r): array => [
                $r['id'],
                $r['created_at'],
                $r['user_name'] ?? 'System',
                $r['username'] ?? '',
                $r['action'],
                $r['description'] ?? '',
                $r['ip_address'] ?? '',
            ], $rows)
        );
    }

    /**
     * Delete every row the seeder created, leaving real hardware data
     * untouched (development rule 6).
     */
    public function purgeDemoData(): void
    {
        Auth::requireAdmin();
        csrf_guard();

        $removed = Database::transaction(static function (): array {
            $readings = SensorReading::purgeDemo();
            $cycles   = IrrigationLog::purgeDemo();
            $alerts   = Database::execute("DELETE FROM alerts WHERE source = 'demo'");

            return ['readings' => $readings, 'cycles' => $cycles, 'alerts' => $alerts];
        });

        SystemLog::record(Auth::id(), 'purge_demo_data', sprintf(
            'Removed sample data: %d readings, %d irrigation cycles, %d alerts.',
            $removed['readings'], $removed['cycles'], $removed['alerts']
        ));

        flash('success', sprintf(
            'Sample data removed: %d readings, %d irrigation cycles and %d alerts. Live device data was not touched.',
            $removed['readings'], $removed['cycles'], $removed['alerts']
        ));
        redirect('logs');
    }
}
