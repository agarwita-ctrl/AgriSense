<?php
/**
 * AgriSense - system_logs (audit trail)
 */

class SystemLog
{
    /**
     * Append an audit entry. Never throws: an audit failure must not take
     * down the action being audited, so problems are logged and swallowed.
     */
    public static function record(?int $userId, string $action, string $description = ''): void
    {
        try {
            Database::execute(
                'INSERT INTO system_logs (user_id, action, description, ip_address)
                 VALUES (?, ?, ?, ?)',
                [$userId, substr($action, 0, 80), substr($description, 0, 500), client_ip()]
            );
        } catch (Throwable $e) {
            error_log('[AgriSense] Could not write system log: ' . $e->getMessage());
        }
    }

    /** Distinct action names, for the filter dropdown. */
    public static function actions(): array
    {
        return array_column(
            Database::all('SELECT DISTINCT action FROM system_logs ORDER BY action'),
            'action'
        );
    }

    /**
     * Filtered, paginated audit trail.
     *
     * @return array{rows:array,meta:array}
     */
    public static function search(array $filters, int $page, int $perPage = 25): array
    {
        [$where, $params] = self::buildWhere($filters);

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM system_logs sl LEFT JOIN users u ON u.id = sl.user_id {$where}",
            $params
        );
        $meta = paginate($total, $page, $perPage);

        $rows = Database::all(
            "SELECT sl.id, sl.action, sl.description, sl.ip_address, sl.created_at,
                    u.name AS user_name, u.username
               FROM system_logs sl
               LEFT JOIN users u ON u.id = sl.user_id
               {$where}
              ORDER BY sl.created_at DESC, sl.id DESC
              LIMIT {$meta['per_page']} OFFSET {$meta['offset']}",
            $params
        );

        return ['rows' => $rows, 'meta' => $meta];
    }

    /** Every matching row, unpaginated - used by the CSV export. */
    public static function export(array $filters): array
    {
        [$where, $params] = self::buildWhere($filters);

        return Database::all(
            "SELECT sl.id, sl.action, sl.description, sl.ip_address, sl.created_at,
                    u.name AS user_name, u.username
               FROM system_logs sl
               LEFT JOIN users u ON u.id = sl.user_id
               {$where}
              ORDER BY sl.created_at DESC, sl.id DESC
              LIMIT 10000",
            $params
        );
    }

    /**
     * Shared WHERE builder. Every fragment is parameterised - no user input
     * is ever concatenated into the SQL string.
     */
    private static function buildWhere(array $filters): array
    {
        $clauses = [];
        $params  = [];

        if (!empty($filters['search'])) {
            $clauses[] = '(sl.description LIKE ? OR sl.action LIKE ? OR u.name LIKE ? OR u.username LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($filters['action'])) {
            $clauses[] = 'sl.action = ?';
            $params[]  = $filters['action'];
        }
        if (!empty($filters['user_id'])) {
            $clauses[] = 'sl.user_id = ?';
            $params[]  = $filters['user_id'];
        }
        if (!empty($filters['start'])) {
            $clauses[] = 'sl.created_at >= ?';
            $params[]  = $filters['start'];
        }
        if (!empty($filters['end'])) {
            $clauses[] = 'sl.created_at <= ?';
            $params[]  = $filters['end'];
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }
}
