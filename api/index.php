<?php
/**
 * AgriSense - REST API front controller (requirement 19)
 *
 * Every /api/... request lands here. Routes are matched explicitly, the
 * response is always JSON, and authentication is applied inside each
 * handler so no endpoint can be reached anonymously by accident.
 *
 * Endpoint list and payload examples: docs/API.md
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// All handlers live in one directory that no endpoint path is named after.
// When they sat in api/alerts/, api/devices/ and so on, those folder names
// collided with the endpoints themselves and mod_dir answered /api/alerts
// with a 301 before the rewrite could route it.
require_once __DIR__ . '/handlers/ApiAuth.php';
require_once __DIR__ . '/handlers/DeviceApi.php';
require_once __DIR__ . '/handlers/SensorApi.php';
require_once __DIR__ . '/handlers/IrrigationApi.php';
require_once __DIR__ . '/handlers/AlertApi.php';
require_once __DIR__ . '/handlers/ReportApi.php';

// Errors from here on are JSON, never HTML.
$_SERVER['HTTP_ACCEPT'] = 'application/json';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// ---------------------------------------------------------------------
// Resolve the path after /api/
// ---------------------------------------------------------------------
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$uri = rawurldecode($uri);

if (BASE_PATH !== '' && str_starts_with($uri, BASE_PATH)) {
    $uri = substr($uri, strlen(BASE_PATH));
}
$path = trim($uri, '/');
$path = preg_replace('#^api/?#', '', $path) ?? '';

// Fallback for servers without mod_rewrite: /api/index.php?endpoint=dashboard
if ($path === '' || $path === 'index.php') {
    $path = trim((string) ($_GET['endpoint'] ?? ''), '/');
}

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$segments = $path === '' ? [] : explode('/', $path);

// ---------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------

// GET /api/device/{id|code}/settings - the only route with a path parameter.
if (count($segments) === 3 && $segments[0] === 'device' && $segments[2] === 'settings') {
    DeviceApi::settings($segments[1]);
}

/**
 * No `break` in any arm below, deliberately: every handler is declared
 * `: never` and ends in Response::json(), which exits. PHP enforces the
 * return type, so a handler cannot fall through into the next case.
 *
 * A new handler MUST therefore be declared `: never` as well. Without that
 * return type it can return normally, and execution would run straight on
 * into the arm underneath it.
 */
switch ($path) {
    // --- Device endpoints (X-API-Key) --------------------------------
    case 'sensor-data':
        SensorApi::store();

    case 'device/status':
        DeviceApi::status();

    case 'device/command/ack':
        DeviceApi::acknowledgeCommand();

    case 'irrigation/log':
        IrrigationApi::log();

    // --- Dashboard endpoints (session cookie) ------------------------
    case 'dashboard':
        ReportApi::dashboard();

    case 'sensor-history':
        SensorApi::history();

    case 'chart-data':
        SensorApi::chartData();

    case 'irrigation-history':
        IrrigationApi::history();

    case 'alerts':
        AlertApi::index();

    case 'alerts/read':
        AlertApi::markRead();

    case 'pump/control':
        IrrigationApi::pumpControl();

    case 'pump/command-status':
        IrrigationApi::commandStatus();

    case 'report/summary':
        ReportApi::summary();

    // --- Discovery ---------------------------------------------------
    case '':
        Response::ok([
            'name'      => APP_NAME . ' REST API',
            'version'   => APP_VERSION,
            'docs'      => url('docs/API.md'),
            'endpoints' => [
                'POST /api/sensor-data'                  => 'device key',
                'POST /api/device/status'                => 'device key',
                'GET  /api/device/{id|code}/settings'    => 'device key',
                'POST /api/device/command/ack'           => 'device key',
                'POST /api/irrigation/log'               => 'device key',
                'GET  /api/dashboard'                    => 'session',
                'GET  /api/sensor-history'               => 'session',
                'GET  /api/irrigation-history'           => 'session',
                'GET  /api/chart-data'                   => 'session',
                'GET  /api/alerts'                       => 'session',
                'POST /api/alerts/read'                  => 'session',
                'POST /api/pump/control'                 => 'session',
                'GET  /api/pump/command-status'          => 'session',
                'GET  /api/report/summary'               => 'session',
            ],
        ]);

    default:
        Response::error('Unknown endpoint: /api/' . $path, 404);
}
