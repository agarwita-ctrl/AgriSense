<?php
/**
 * AgriSense - Web front controller
 *
 * Every browser request that is not a real file on disk is routed here by
 * .htaccess. Routes are declared explicitly; anything not on the list gets a
 * 404 rather than reaching a file by guesswork.
 */

require_once __DIR__ . '/includes/bootstrap.php';

require_once APP_ROOT . '/controllers/Controller.php';
require_once APP_ROOT . '/controllers/AuthController.php';
require_once APP_ROOT . '/controllers/DashboardController.php';
require_once APP_ROOT . '/controllers/SensorController.php';
require_once APP_ROOT . '/controllers/IrrigationController.php';
require_once APP_ROOT . '/controllers/DeviceController.php';
require_once APP_ROOT . '/controllers/AlertController.php';
require_once APP_ROOT . '/controllers/ReportController.php';
require_once APP_ROOT . '/controllers/UserController.php';
require_once APP_ROOT . '/controllers/SystemLogController.php';

Auth::startSession();

// ---------------------------------------------------------------------
// Work out the requested route, e.g. "irrigation/history"
// ---------------------------------------------------------------------
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$uri  = rawurldecode($uri);

if (BASE_PATH !== '' && str_starts_with($uri, BASE_PATH)) {
    $uri = substr($uri, strlen(BASE_PATH));
}
$route  = trim($uri, '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Requests that arrive as /index.php/... or ?r=... still work without
// mod_rewrite, which keeps the project runnable on a bare Apache install.
if ($route === 'index.php' || $route === '') {
    $route = trim((string) ($_GET['r'] ?? ''), '/');
}

$GLOBALS['CURRENT_ROUTE'] = $route === '' ? 'dashboard' : $route;

// ---------------------------------------------------------------------
// Route table:  route => [GET handler, POST handler]
// ---------------------------------------------------------------------
$routes = [
    ''                      => ['GET' => [DashboardController::class, 'index']],
    'dashboard'             => ['GET' => [DashboardController::class, 'index']],
    'monitor'               => ['GET' => [DashboardController::class, 'monitor']],

    'login'                 => ['GET'  => [AuthController::class, 'showLogin'],
                                'POST' => [AuthController::class, 'login']],
    'logout'                => ['POST' => [AuthController::class, 'logout']],
    'profile'               => ['GET'  => [AuthController::class, 'profile'],
                                'POST' => [AuthController::class, 'updatePassword']],

    'sensors/history'       => ['GET' => [SensorController::class, 'history']],
    'sensors/export'        => ['GET' => [SensorController::class, 'export']],

    'irrigation/history'    => ['GET' => [IrrigationController::class, 'history']],
    'irrigation/export'     => ['GET' => [IrrigationController::class, 'exportHistory']],
    'irrigation/settings'   => ['GET'  => [IrrigationController::class, 'settings'],
                                'POST' => [IrrigationController::class, 'saveSettings']],
    'irrigation/pump'       => ['POST' => [IrrigationController::class, 'pumpControl']],

    'devices'               => ['GET' => [DeviceController::class, 'index']],
    'devices/create'        => ['GET'  => [DeviceController::class, 'create'],
                                'POST' => [DeviceController::class, 'store']],
    'devices/edit'          => ['GET'  => [DeviceController::class, 'edit'],
                                'POST' => [DeviceController::class, 'update']],
    'devices/rotate-key'    => ['POST' => [DeviceController::class, 'rotateKey']],
    'devices/delete'        => ['POST' => [DeviceController::class, 'delete']],

    'alerts'                => ['GET' => [AlertController::class, 'index']],
    'alerts/export'         => ['GET' => [AlertController::class, 'export']],
    'alerts/read'           => ['POST' => [AlertController::class, 'markRead']],
    'alerts/unread'         => ['POST' => [AlertController::class, 'markUnread']],
    'alerts/read-all'       => ['POST' => [AlertController::class, 'markAllRead']],
    'alerts/delete'         => ['POST' => [AlertController::class, 'delete']],

    'reports'               => ['GET' => [ReportController::class, 'index']],
    'reports/export'        => ['GET' => [ReportController::class, 'export']],

    'users'                 => ['GET' => [UserController::class, 'index']],
    'users/create'          => ['GET'  => [UserController::class, 'create'],
                                'POST' => [UserController::class, 'store']],
    'users/edit'            => ['GET'  => [UserController::class, 'edit'],
                                'POST' => [UserController::class, 'update']],
    'users/delete'          => ['POST' => [UserController::class, 'delete']],

    'logs'                  => ['GET' => [SystemLogController::class, 'index']],
    'logs/export'           => ['GET' => [SystemLogController::class, 'export']],
    'logs/purge-demo'       => ['POST' => [SystemLogController::class, 'purgeDemoData']],
];

$key = $route === 'dashboard' ? 'dashboard' : $route;

if (!isset($routes[$key])) {
    http_response_code(404);
    $GLOBALS['ERROR_TITLE']   = 'Page not found';
    $GLOBALS['ERROR_MESSAGE'] = 'The page you asked for does not exist.';
    require APP_ROOT . '/views/errors/404.php';
    exit;
}

if (!isset($routes[$key][$method])) {
    http_response_code(405);
    header('Allow: ' . implode(', ', array_keys($routes[$key])));
    $GLOBALS['ERROR_TITLE']   = 'Action not allowed';
    $GLOBALS['ERROR_MESSAGE'] = 'That action cannot be performed this way.';
    require APP_ROOT . '/views/errors/404.php';
    exit;
}

[$class, $action] = $routes[$key][$method];
(new $class())->{$action}();
