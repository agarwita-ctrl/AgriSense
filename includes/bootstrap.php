<?php
/**
 * AgriSense - Application bootstrap
 *
 * Loads configuration, the data layer and the shared services, installs the
 * global error handlers and starts the session. Every entry point (the web
 * front controller and the API router) includes this file first.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/response.php';

// Models - plain data access classes.
require_once __DIR__ . '/../models/SystemLog.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Alert.php';
require_once __DIR__ . '/../models/IrrigationSetting.php';
require_once __DIR__ . '/../models/IrrigationLog.php';
require_once __DIR__ . '/../models/PumpCommand.php';
require_once __DIR__ . '/../models/SensorReading.php';
require_once __DIR__ . '/../models/Device.php';

// Services.
require_once __DIR__ . '/IrrigationEngine.php';

// Security.
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';

/**
 * Content-Security-Policy.
 *
 * Everything the interface needs is served from this origin - Bootstrap and
 * Chart.js are bundled under /assets/vendor - so scripts are restricted to
 * 'self' plus a per-request nonce that the application's own inline blocks
 * carry. Injected markup has no nonce and will not run.
 *
 * Inline *styles* stay allowed: the views use style="" attributes in a
 * number of places, and a style attribute is a far weaker vector than a
 * script. object-src and frame-ancestors are locked down regardless.
 */
if (!headers_sent()) {
    header(
        "Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self' 'nonce-" . csp_nonce() . "'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; "
        . "font-src 'self'; "
        . "connect-src 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'; "
        . "base-uri 'self'; "
        . "object-src 'none'"
    );
}

/**
 * Turn PHP notices and warnings into exceptions so they are handled by one
 * code path instead of leaking half-rendered pages.
 */
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/**
 * Last-resort handler. Users see a friendly page; the detail goes to the
 * PHP error log. Nothing about the database or the filesystem is exposed
 * unless APP_DEBUG is on (requirement 26).
 */
set_exception_handler(static function (Throwable $e): void {
    error_log('[AgriSense] ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());

    $isDatabase = $e instanceof DatabaseException || $e instanceof PDOException;
    $status     = $isDatabase ? 503 : 500;

    $message = $isDatabase
        ? ($e instanceof DatabaseException
            ? $e->getMessage()
            : 'The system could not complete that database operation.')
        : 'Something went wrong while processing your request.';

    if (function_exists('wants_json') && wants_json()) {
        Response::json([
            'success' => false,
            'error'   => $message,
            'detail'  => APP_DEBUG ? $e->getMessage() : null,
        ], $status);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);

    $GLOBALS['ERROR_TITLE']   = $isDatabase ? 'Service unavailable' : 'Unexpected error';
    $GLOBALS['ERROR_MESSAGE'] = $message;
    $GLOBALS['ERROR_DETAIL']  = APP_DEBUG
        ? $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine()
        : null;

    $page = APP_ROOT . '/views/errors/500.php';
    if (is_file($page)) {
        require $page;
    } else {
        echo '<h1>' . htmlspecialchars($GLOBALS['ERROR_TITLE']) . '</h1><p>'
            . htmlspecialchars($message) . '</p>';
    }
});

/**
 * Render a view inside the standard layout.
 *
 * @param string $view Path under /views without the .php suffix
 * @param array  $data Variables made available to the view
 * @param array  $opts title, active (nav key), bare (skip the layout)
 */
function render(string $view, array $data = [], array $opts = []): void
{
    $file = APP_ROOT . '/views/' . ltrim($view, '/') . '.php';
    if (!is_file($file)) {
        throw new RuntimeException('View not found: ' . $view);
    }

    $pageTitle  = $opts['title']  ?? APP_NAME;
    $activeNav  = $opts['active'] ?? '';
    $bodyClass  = $opts['body_class'] ?? '';
    $bare       = !empty($opts['bare']);

    extract($data, EXTR_SKIP);

    if ($bare) {
        require $file;

        return;
    }

    require APP_ROOT . '/views/layout/header.php';
    require $file;
    require APP_ROOT . '/views/layout/footer.php';
}
