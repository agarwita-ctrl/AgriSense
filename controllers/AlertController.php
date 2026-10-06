<?php
/**
 * AgriSense - Alerts (requirement 15).
 */

class AlertController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();

        Device::flagOfflineDevices();

        $range   = $this->rangeFilter('30days');
        $filters = [
            'device_id'  => input_int('device_id', 0, 0) ?: null,
            'severity'   => one_of(input('severity'), array_merge([''], Alert::SEVERITIES), ''),
            'alert_type' => one_of(input('alert_type'), array_merge([''], array_keys(Alert::TYPES)), ''),
            'is_read'    => one_of(input('is_read'), ['', '0', '1'], ''),
            'search'     => input('search'),
            'start'      => $range['start'],
            'end'        => $range['end'],
        ];

        $result = Alert::search($filters, input_int('page', 1, 1), input_int('per_page', 25, 10, 200));

        render('alerts/index', [
            'devices' => Device::all(),
            'rows'    => $result['rows'],
            'meta'    => $result['meta'],
            'filters' => $filters,
            'range'   => $range,
            'summary' => Alert::summary(),
        ], ['title' => 'Alerts', 'active' => 'alerts']);
    }

    public function markRead(): void
    {
        Auth::requireLogin();
        csrf_guard();

        Alert::markRead(input_int('id', 0, 1));

        if (wants_json()) {
            Response::ok(['unread' => Alert::unreadCount()], 'Alert marked as read.');
        }
        redirect('alerts');
    }

    public function markUnread(): void
    {
        Auth::requireLogin();
        csrf_guard();

        Alert::markUnread(input_int('id', 0, 1));

        if (wants_json()) {
            Response::ok(['unread' => Alert::unreadCount()], 'Alert marked as unread.');
        }
        redirect('alerts');
    }

    public function markAllRead(): void
    {
        Auth::requireLogin();
        csrf_guard();

        $count = Alert::markAllRead();
        SystemLog::record(Auth::id(), 'alerts_read', 'Marked ' . $count . ' alerts as read.');

        if (wants_json()) {
            Response::ok(['unread' => 0, 'marked' => $count], $count . ' alerts marked as read.');
        }

        flash('success', $count . ' alert' . ($count === 1 ? '' : 's') . ' marked as read.');
        redirect('alerts');
    }

    public function delete(): void
    {
        Auth::requireAdmin();
        csrf_guard();

        Alert::delete(input_int('id', 0, 1));
        flash('success', 'Alert deleted.');
        redirect('alerts');
    }

    public function export(): void
    {
        Auth::requireLogin();

        $range = $this->rangeFilter('30days');
        $rows  = Alert::export([
            'device_id'  => input_int('device_id', 0, 0) ?: null,
            'severity'   => one_of(input('severity'), array_merge([''], Alert::SEVERITIES), ''),
            'alert_type' => one_of(input('alert_type'), array_merge([''], array_keys(Alert::TYPES)), ''),
            'is_read'    => one_of(input('is_read'), ['', '0', '1'], ''),
            'search'     => input('search'),
            'start'      => $range['start'],
            'end'        => $range['end'],
        ]);

        SystemLog::record(Auth::id(), 'export', 'Exported ' . count($rows) . ' alerts to CSV.');

        send_csv(
            'agrisense-alerts-' . date('Ymd-His') . '.csv',
            ['ID', 'Date/time', 'Device', 'Type', 'Severity', 'Message', 'Status'],
            array_map(static fn (array $r): array => [
                $r['id'],
                $r['created_at'],
                $r['device_name'] ?? 'System',
                Alert::label($r['alert_type']),
                ucfirst($r['severity']),
                $r['message'],
                (int) $r['is_read'] === 1 ? 'Read' : 'Unread',
            ], $rows)
        );
    }
}
