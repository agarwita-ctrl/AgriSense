<?php
/**
 * AgriSense - Alert endpoints
 *
 *   GET  /api/alerts        (session)
 *   POST /api/alerts/read   (session)
 */

class AlertApi
{
    public static function index(): never
    {
        Response::allowMethods('GET');
        ApiAuth::session();

        // The header bell asks for the short unread list.
        if (input('recent') === '1') {
            Response::ok([
                'unread' => Alert::unreadCount(),
                'alerts' => array_map(static fn (array $a): array => [
                    'id'         => (int) $a['id'],
                    'type'       => $a['alert_type'],
                    'type_label' => Alert::label($a['alert_type']),
                    'message'    => $a['message'],
                    'severity'   => $a['severity'],
                    'is_read'    => (int) $a['is_read'] === 1,
                    'device'     => $a['device_name'],
                    'created_at' => $a['created_at'],
                    'ago'        => time_ago($a['created_at']),
                ], Alert::recent(input_int('limit', 6, 1, 20))),
            ]);
        }

        $range = one_of(input('range', '30days'),
            ['today', 'yesterday', '7days', '30days', 'custom'], '30days');
        [$start, $end] = date_range($range, input('from'), input('to'));

        $result = Alert::search([
            'device_id'  => input_int('device_id', 0, 0) ?: null,
            'severity'   => one_of(input('severity'), array_merge([''], Alert::SEVERITIES), ''),
            'alert_type' => input('alert_type'),
            'is_read'    => one_of(input('is_read'), ['', '0', '1'], ''),
            'search'     => input('search'),
            'start'      => $start,
            'end'        => $end,
        ], input_int('page', 1, 1), input_int('per_page', 25, 1, 200));

        Response::ok([
            'alerts'     => $result['rows'],
            'pagination' => $result['meta'],
            'summary'    => Alert::summary(),
        ]);
    }

    /** Mark one alert, or every unread alert, as read. */
    public static function markRead(): never
    {
        Response::allowMethods('POST');
        ApiAuth::sessionWrite();

        $body = Response::body();

        if (!empty($body['all'])) {
            $count = Alert::markAllRead();
            SystemLog::record(Auth::id(), 'alerts_read', 'Marked ' . $count . ' alerts as read.');

            Response::ok(['marked' => $count, 'unread' => 0], $count . ' alerts marked as read.');
        }

        $id = isset($body['id']) ? (int) $body['id'] : 0;
        if ($id <= 0) {
            Response::error('Provide an alert id, or set "all": true.', 422);
        }

        Alert::markRead($id);

        Response::ok(['marked' => 1, 'unread' => Alert::unreadCount()], 'Alert marked as read.');
    }
}
