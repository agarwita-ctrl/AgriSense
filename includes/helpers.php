<?php
/**
 * AgriSense - Shared helpers
 *
 * Escaping, URL building, input validation, formatting and flash messages.
 */

// ---------------------------------------------------------------------
// Output escaping (requirement 21 - XSS protection)
// ---------------------------------------------------------------------

/** Escape a value for HTML output. Every dynamic value in a view goes through this. */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escape a value for use inside a JavaScript literal or data-* attribute. */
function ejs($value): string
{
    return htmlspecialchars(
        json_encode($value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ENT_QUOTES,
        'UTF-8'
    );
}

/**
 * Per-request nonce for the Content-Security-Policy.
 *
 * Every inline <script> in a view carries it, so the browser will run the
 * scripts the application shipped and refuse anything injected into the
 * page afterwards.
 */
function csp_nonce(): string
{
    static $nonce = null;

    return $nonce ??= base64_encode(random_bytes(16));
}

/** Ready-made nonce attribute: <script <?= csp_attr() ?>> */
function csp_attr(): string
{
    return 'nonce="' . e(csp_nonce()) . '"';
}

// ---------------------------------------------------------------------
// URLs
// ---------------------------------------------------------------------

/** Absolute application path for a route, e.g. url('sensors/history'). */
function url(string $path = '', array $query = []): string
{
    $url = BASE_PATH . '/' . ltrim($path, '/');
    $url = rtrim($url, '/');
    if ($url === '') {
        $url = '/';
    }
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}

/** URL for a file under /assets, cache-busted by its modification time. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = APP_ROOT . '/assets/' . $path;
    $version = is_file($file) ? filemtime($file) : APP_VERSION;

    return BASE_PATH . '/assets/' . $path . '?v=' . $version;
}

/** Send a redirect and stop. */
function redirect(string $path, array $query = []): never
{
    header('Location: ' . url($path, $query));
    exit;
}

/** True when the given route is the one currently being served. */
function is_route(string $prefix): bool
{
    $current = $GLOBALS['CURRENT_ROUTE'] ?? '';

    return $current === $prefix || str_starts_with($current, rtrim($prefix, '/') . '/');
}

// ---------------------------------------------------------------------
// Request input
// ---------------------------------------------------------------------

/** Trimmed string from GET/POST. */
function input(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;

    return is_scalar($value) ? trim((string) $value) : $default;
}

/** Integer from GET/POST, clamped into an optional range. */
function input_int(string $key, int $default = 0, ?int $min = null, ?int $max = null): int
{
    $raw = $_POST[$key] ?? $_GET[$key] ?? null;
    $value = is_numeric($raw) ? (int) $raw : $default;
    if ($min !== null && $value < $min) {
        $value = $min;
    }
    if ($max !== null && $value > $max) {
        $value = $max;
    }

    return $value;
}

/** Float from GET/POST, or null when absent / not numeric. */
function input_float(string $key, ?float $default = null): ?float
{
    $raw = $_POST[$key] ?? $_GET[$key] ?? null;

    return is_numeric($raw) ? (float) $raw : $default;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Best-effort client IP for the audit trail. */
function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

/** True when the request expects JSON back (fetch/AJAX). */
function wants_json(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xhr    = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

    return str_contains($accept, 'application/json') || strcasecmp($xhr, 'XMLHttpRequest') === 0;
}

// ---------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------

/** Validate a date in Y-m-d form; returns the normalised date or null. */
function valid_date(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $value);

    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

/** Constrain a value to a whitelist, falling back to the first entry. */
function one_of(?string $value, array $allowed, ?string $default = null): string
{
    $default ??= $allowed[0];

    return in_array($value, $allowed, true) ? $value : $default;
}

/** Numeric value inside an inclusive range. */
function in_range($value, float $min, float $max): bool
{
    return is_numeric($value) && (float) $value >= $min && (float) $value <= $max;
}

// ---------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------

/** "05 Sep 2026, 3:42 PM" */
function fmt_datetime(?string $ts, string $format = 'd M Y, g:i A'): string
{
    if (!$ts) {
        return '--';
    }
    try {
        return (new DateTime($ts))->format($format);
    } catch (Exception) {
        return '--';
    }
}

function fmt_time(?string $ts): string
{
    return fmt_datetime($ts, 'g:i:s A');
}

function fmt_date(?string $ts): string
{
    return fmt_datetime($ts, 'd M Y');
}

/** Seconds as "4m 12s" / "1h 03m". */
function fmt_duration(?int $seconds): string
{
    if ($seconds === null) {
        return '--';
    }
    if ($seconds < 60) {
        return $seconds . 's';
    }
    if ($seconds < 3600) {
        return sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
    }

    return sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
}

/** "3 minutes ago" */
function time_ago(?string $ts): string
{
    if (!$ts) {
        return 'never';
    }
    try {
        $diff = time() - (new DateTime($ts))->getTimestamp();
    } catch (Exception) {
        return 'unknown';
    }
    if ($diff < 0) {
        $diff = 0;
    }
    if ($diff < 10) {
        return 'just now';
    }
    if ($diff < 60) {
        return $diff . ' seconds ago';
    }
    if ($diff < 3600) {
        $m = intdiv($diff, 60);
        return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $h = intdiv($diff, 3600);
        return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
    }
    $d = intdiv($diff, 86400);

    return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
}

/** Number with fixed decimals, or an em dash when the reading is missing. */
function fmt_num($value, int $decimals = 1, string $suffix = ''): string
{
    if ($value === null || $value === '') {
        return '--';
    }

    return number_format((float) $value, $decimals) . $suffix;
}

/** Bootstrap contextual colour for an alert severity. */
function severity_class(string $severity): string
{
    return match ($severity) {
        'critical' => 'danger',
        'warning'  => 'warning',
        default    => 'info',
    };
}

// ---------------------------------------------------------------------
// Flash messages
// ---------------------------------------------------------------------

/** Queue a one-shot message for the next page render. */
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** Pull and clear every queued flash message. */
function take_flashes(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);

    return $messages;
}

// ---------------------------------------------------------------------
// Pagination
// ---------------------------------------------------------------------

/**
 * Build page metadata from a total row count.
 *
 * @return array{page:int,per_page:int,total:int,pages:int,offset:int}
 */
function paginate(int $total, int $page, int $perPage = 25): array
{
    $perPage = max(1, min($perPage, 200));
    $pages   = max(1, (int) ceil($total / $perPage));
    $page    = max(1, min($page, $pages));

    return [
        'page'     => $page,
        'per_page' => $perPage,
        'total'    => $total,
        'pages'    => $pages,
        'offset'   => ($page - 1) * $perPage,
    ];
}

/**
 * Translate a named range into [start, end] datetimes.
 * Supported: today, yesterday, 7days, 30days, custom.
 */
function date_range(string $range, ?string $from = null, ?string $to = null): array
{
    $today = new DateTime('today');

    switch ($range) {
        case 'today':
            return [$today->format('Y-m-d 00:00:00'), $today->format('Y-m-d 23:59:59')];

        case 'yesterday':
            $y = (clone $today)->modify('-1 day');
            return [$y->format('Y-m-d 00:00:00'), $y->format('Y-m-d 23:59:59')];

        case '30days':
            return [(clone $today)->modify('-29 days')->format('Y-m-d 00:00:00'), $today->format('Y-m-d 23:59:59')];

        case 'custom':
            $from = valid_date($from) ?? (clone $today)->modify('-6 days')->format('Y-m-d');
            $to   = valid_date($to) ?? $today->format('Y-m-d');
            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }
            return [$from . ' 00:00:00', $to . ' 23:59:59'];

        case '7days':
        default:
            return [(clone $today)->modify('-6 days')->format('Y-m-d 00:00:00'), $today->format('Y-m-d 23:59:59')];
    }
}

/**
 * Neutralise a spreadsheet formula in an exported cell.
 *
 * Excel and LibreOffice treat a cell starting with = + - @ (or a leading
 * tab / carriage return) as a formula, so text that reached the database
 * from a form field - a device name, or the username in a failed-login
 * audit entry - could execute when the operator opens the export. Quoting
 * alone does not stop it; the value has to stop looking like a formula.
 */
function csv_cell($value): string
{
    $value = (string) ($value ?? '');

    if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
        return "'" . $value;
    }

    return $value;
}

/** Stream an array of rows to the browser as a CSV download. */
function send_csv(string $filename, array $headers, array $rows): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '', $filename) . '"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8 correctly
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        // One guard here covers every export in the application.
        fputcsv($out, array_map('csv_cell', $row));
    }
    fclose($out);
    exit;
}
