<?php
/**
 * AgriSense - Application configuration
 *
 * Everything environment specific lives here. Nothing in this file is ever
 * echoed to the browser, and it is never referenced from client side
 * JavaScript (requirement 21).
 */

// ---------------------------------------------------------------------
// Database (XAMPP defaults)
// ---------------------------------------------------------------------
// Behind a TLS-terminating proxy (Railway) PHP sees plain HTTP. Trust the
// forwarded scheme so session cookies get the Secure flag.
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = '443';
}

/** Environment variable, or $default when unset/empty. */
function env_or(string $key, string $default = ''): string
{
    $v = getenv($key);

    return ($v === false || $v === '') ? $default : $v;
}

// Defaults are the XAMPP ones; on Railway set the variables (see .env.example).
// Railway's MySQL plugin provides MYSQLHOST/MYSQLPORT/... automatically.
define('DB_HOST', env_or('DB_HOST', env_or('MYSQLHOST', '127.0.0.1')));
define('DB_PORT', env_or('DB_PORT', env_or('MYSQLPORT', '3306')));
define('DB_NAME', env_or('DB_NAME', env_or('MYSQLDATABASE', 'agrisense')));
define('DB_USER', env_or('DB_USER', env_or('MYSQLUSER', 'root')));
define('DB_PASS', env_or('DB_PASS', env_or('MYSQLPASSWORD', '')));
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------------
// Application
// ---------------------------------------------------------------------
define('APP_NAME', 'AgriSense');
define('APP_TAGLINE', 'IoT-Based Smart Irrigation System');
define('APP_VERSION', '1.0.0');

/**
 * When true, unexpected errors show their message on screen; when false the
 * user sees a generic message and the detail goes to the PHP error log only
 * (requirement 26).
 *
 * Derived rather than hard-coded, so a deployment can never accidentally
 * ship with debugging on: it is enabled only when the site is being served
 * from a local address. Force it either way by editing this line.
 */
define('APP_DEBUG', getenv('APP_DEBUG') !== false && getenv('APP_DEBUG') !== ''
    ? filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN)
    : in_array(
        $_SERVER['SERVER_NAME'] ?? ($_SERVER['SERVER_ADDR'] ?? 'cli'),
        ['localhost', '127.0.0.1', '::1', 'cli'],
        true
    ));

// Local timezone - used for every timestamp the system records.
define('APP_TIMEZONE', env_or('APP_TIMEZONE', 'Asia/Manila'));

// ---------------------------------------------------------------------
// Operational thresholds
// ---------------------------------------------------------------------

/** A device is "offline" when it has not called home for this many seconds. */
define('DEVICE_OFFLINE_AFTER', 120);

/** Queued pump commands older than this are marked expired and never run. */
define('PUMP_COMMAND_TTL', 120);

/** Hard ceiling for max_pump_runtime, in seconds. Protects the pump. */
define('PUMP_RUNTIME_CEILING', 1800);

/** Plausible sensor bounds. Readings outside these raise an abnormal_reading alert. */
define('TEMP_MIN', -10.0);
define('TEMP_MAX', 60.0);
define('HUMIDITY_MIN', 0.0);
define('HUMIDITY_MAX', 100.0);

/**
 * Canopy (leaf) temperature from the MLX90614 infrared thermometer.
 *
 * The band is deliberately wider than TEMP_MIN/TEMP_MAX: this is a surface
 * temperature, not an air temperature. A sunlit leaf sits well above the air
 * around it, and a sensor that has drifted round to face bare soil can read
 * 70 C on a hot afternoon without anything being broken. Using the air bounds
 * here would throw away real readings and raise abnormal_reading alerts all
 * summer.
 */
define('LEAF_TEMP_MIN', -10.0);
define('LEAF_TEMP_MAX', 80.0);

/**
 * Canopy this many degrees above air temperature means the plants are not
 * transpiring freely - the classic sign of water stress, and the basis of the
 * crop water stress index.
 *
 * Must match LEAF_STRESS_DELTA in esp32/AgriSense.ino. The firmware sends its
 * own verdict in `leaf_stress`; this is what the server falls back to when a
 * payload carries the two temperatures but not the flag.
 */
define('LEAF_STRESS_DELTA', 5.0);

// ---------------------------------------------------------------------
// Session / security
// ---------------------------------------------------------------------
define('SESSION_NAME', 'AGRISENSE_SID');

/** Idle timeout in seconds (30 minutes). */
define('SESSION_IDLE_TIMEOUT', 1800);

/** Failed logins allowed from one IP inside LOGIN_LOCKOUT_WINDOW seconds. */
define('LOGIN_MAX_ATTEMPTS', 10);

/**
 * Failed logins allowed against one username inside the same window,
 * counted across every source address. Set higher than the per-IP limit so
 * a shared connection cannot be used to lock a legitimate user out.
 */
define('LOGIN_MAX_ACCOUNT_ATTEMPTS', 10);

define('LOGIN_LOCKOUT_WINDOW', 900);

// ---------------------------------------------------------------------
// Filesystem / URL paths (derived - do not edit)
// ---------------------------------------------------------------------
define('APP_ROOT', dirname(__DIR__));

/**
 * Public base path, e.g. "/AgriSense". Derived from where the application
 * sits inside the document root so the project folder can be renamed.
 */
if (!defined('BASE_PATH')) {
    $docRoot = isset($_SERVER['DOCUMENT_ROOT'])
        ? str_replace('\\', '/', rtrim((string) realpath($_SERVER['DOCUMENT_ROOT']), '/\\'))
        : '';
    $appDir  = str_replace('\\', '/', APP_ROOT);

    if ($docRoot !== '' && str_starts_with($appDir, $docRoot)) {
        $base = rtrim(substr($appDir, strlen($docRoot)), '/');
    } else {
        // CLI, or an unusual vhost layout: fall back to the script directory.
        $base = '';
    }
    define('BASE_PATH', $base);
}

date_default_timezone_set(APP_TIMEZONE);

// Errors are logged, never rendered raw into a response.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
