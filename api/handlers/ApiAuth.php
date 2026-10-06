<?php
/**
 * AgriSense - API authentication (requirement 19)
 *
 * Two kinds of caller reach the API:
 *
 *   1. An ESP32, which presents its per-device API key in the X-API-Key
 *      header. It may only touch its own device's data.
 *   2. A signed-in browser session, for the dashboard's fetch() calls.
 *      Anything that changes state additionally needs the CSRF token.
 *
 * An unauthenticated request never gets further than this file.
 */

class ApiAuth
{
    private static ?array $device = null;

    /**
     * Authenticate the calling device and return its row.
     *
     * The key may travel in the X-API-Key header (preferred), in an
     * Authorization: Bearer header, or - for constrained HTTP clients - as
     * an api_key field in the JSON body.
     */
    public static function device(array $body = []): array
    {
        if (self::$device !== null) {
            return self::$device;
        }

        $key = self::extractKey($body);

        if ($key === '') {
            SystemLog::record(null, 'api_unauthorized', 'Device API request with no API key.');
            Response::error('Missing API key. Send it in the X-API-Key header.', 401);
        }

        $device = Device::findByApiKey($key);

        if (!$device) {
            SystemLog::record(null, 'api_unauthorized', 'Device API request with an unrecognised API key.');
            Response::error('Invalid API key.', 401);
        }

        if ($device['status'] !== 'active') {
            SystemLog::record(null, 'api_forbidden',
                'Rejected data from deactivated device ' . $device['device_code'] . '.');
            Response::error('This device is not active. Ask an administrator to enable it.', 403);
        }

        return self::$device = $device;
    }

    /**
     * Pull the key out of the headers or the body.
     *
     * X-DEVICE-KEY is accepted alongside X-API-Key because that is the
     * header esp32/AgriSense.ino sends.
     */
    private static function extractKey(array $body): string
    {
        $key = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_X_DEVICE_KEY'] ?? '';

        if ($key === '') {
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
            if (stripos($auth, 'bearer ') === 0) {
                $key = substr($auth, 7);
            }
        }

        if ($key === '') {
            foreach (['api_key', 'device_key'] as $field) {
                if (isset($body[$field]) && is_string($body[$field])) {
                    $key = $body[$field];
                    break;
                }
            }
        }

        return trim((string) $key);
    }

    /**
     * The device identifier a payload claims.
     *
     * The firmware calls the field `device_id`; the rest of the system
     * calls it `device_code`. Both are accepted so either spelling works.
     */
    public static function claimedIdentifier(array $body)
    {
        foreach (['device_code', 'device_id'] as $field) {
            if (isset($body[$field]) && is_scalar($body[$field]) && (string) $body[$field] !== '') {
                return $body[$field];
            }
        }

        return null;
    }

    /**
     * Confirm the authenticated device is the one named in the payload.
     * Stops a device with a valid key from writing to another device's row.
     */
    public static function assertOwnsDevice(array $device, $identifier): void
    {
        if ($identifier === null || $identifier === '') {
            return;
        }

        $matches = (string) $identifier === (string) $device['device_code']
            || (string) $identifier === (string) $device['id'];

        if (!$matches) {
            SystemLog::record(null, 'api_forbidden', sprintf(
                'Device %s tried to act on "%s".', $device['device_code'], (string) $identifier
            ));
            Response::error('This API key does not belong to the device named in the request.', 403);
        }
    }

    /** Require a signed-in browser session. */
    public static function session(): array
    {
        Auth::startSession();

        $user = Auth::user();
        if (!$user) {
            Response::error('Your session has expired. Please sign in again.', 401);
        }

        // An account still carrying its shipped password is held on the
        // password page. The web routes enforce that through
        // Auth::requireLogin(); the API reaches Auth::user() directly, so
        // without this check the dashboard's fetch() calls would keep
        // working around the gate.
        if (Auth::mustChangePassword()) {
            Response::error('Please set a new password before continuing.', 403);
        }

        return $user;
    }

    /** Require a session and, for writes, a valid CSRF token. */
    public static function sessionWrite(): array
    {
        $user = self::session();

        if (!csrf_valid()) {
            // 403, not a custom code: Apache turns status codes it does not
            // recognise into a 500, which would hide the real problem.
            Response::error('Your session token expired. Please reload the page and try again.', 403);
        }

        return $user;
    }
}
