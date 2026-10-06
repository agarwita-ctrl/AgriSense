<?php
/**
 * AgriSense - Authentication, sessions and role based access control
 * (requirements 9 and 21)
 */

class Auth
{
    private static ?array $cachedUser = null;
    private static bool $resolved = false;

    // -----------------------------------------------------------------
    // Session lifecycle
    // -----------------------------------------------------------------

    /** Start a hardened session. Called once from the bootstrap. */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');

        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => BASE_PATH === '' ? '/' : BASE_PATH,
            'httponly' => true,       // not readable from JavaScript
            'secure'   => $https,
            'samesite' => 'Lax',      // blocks cross site form posts
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();

        self::enforceIdleTimeout();
    }

    /** Sign out sessions that have been idle past the configured window. */
    private static function enforceIdleTimeout(): void
    {
        if (empty($_SESSION['user_id'])) {
            return;
        }

        $last = $_SESSION['last_activity'] ?? time();
        if (time() - $last > SESSION_IDLE_TIMEOUT) {
            self::logout('session_timeout');
            flash('warning', 'You were signed out after 30 minutes of inactivity.');
            redirect('login');
        }
        $_SESSION['last_activity'] = time();
    }

    // -----------------------------------------------------------------
    // Login / logout
    // -----------------------------------------------------------------

    /**
     * Verify credentials and open a session.
     *
     * @return array{ok:bool,error?:string}
     */
    public static function attempt(string $username, string $password): array
    {
        if ($username === '' || $password === '') {
            return ['ok' => false, 'error' => 'Please enter both your username and password.'];
        }

        if (self::isLockedOut($username)) {
            return [
                'ok'    => false,
                'error' => 'Too many failed sign-in attempts. Please wait 15 minutes and try again.',
            ];
        }

        $user = Database::first(
            'SELECT id, name, username, password, role, status, can_control_pump,
                    must_change_password, password_changed_at
               FROM users
              WHERE username = ?
              LIMIT 1',
            [$username]
        );

        // Always run a hash comparison so a missing username and a wrong
        // password take the same amount of time.
        $hash = $user['password'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $passwordOk = password_verify($password, $hash);

        if (!$user || !$passwordOk) {
            SystemLog::record(null, 'login_failed', self::failureNote($username));

            return ['ok' => false, 'error' => 'Invalid username or password.'];
        }

        if ($user['status'] !== 'active') {
            SystemLog::record((int) $user['id'], 'login_blocked', 'Sign-in blocked - account is inactive.');

            return ['ok' => false, 'error' => 'This account has been deactivated. Please contact the administrator.'];
        }

        // Upgrade the stored hash if PHP's default algorithm has moved on.
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Database::execute(
                'UPDATE users SET password = ? WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), $user['id']]
            );
        }

        // New session id on privilege change - defeats session fixation.
        session_regenerate_id(true);

        $_SESSION['user_id']       = (int) $user['id'];
        $_SESSION['last_activity'] = time();
        $_SESSION['_csrf']         = bin2hex(random_bytes(32));
        // Pinned so a later password change can invalidate this session.
        $_SESSION['pw_stamp']      = (string) ($user['password_changed_at'] ?? '');

        Database::execute('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);
        SystemLog::record((int) $user['id'], 'login', $user['name'] . ' signed in.');

        self::$cachedUser = null;
        self::$resolved   = false;

        return ['ok' => true];
    }

    /** Destroy the session. */
    public static function logout(string $reason = 'logout'): void
    {
        if (!empty($_SESSION['user_id'])) {
            $user = self::user();
            SystemLog::record(
                (int) $_SESSION['user_id'],
                $reason,
                ($user['name'] ?? 'User') . ' signed out.'
            );
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();

        self::$cachedUser = null;
        self::$resolved   = true;
    }

    /**
     * The exact audit line written for a failed sign-in.
     *
     * Fixed format on purpose: the per-account throttle counts these back
     * with an equality match, so the shape of this string is load-bearing.
     */
    private static function failureNote(string $username): string
    {
        return 'Failed sign-in for username "' . $username . '".';
    }

    /**
     * Throttle sign-in attempts on two axes.
     *
     * Per IP stops one machine working through a password list. Per account
     * stops the distributed version of the same attack, which is the shape
     * credential stuffing actually takes - and the target username here is
     * publicly known to be "admin". Either limit alone leaves an open door.
     */
    private static function isLockedOut(string $username): bool
    {
        $byIp = (int) Database::scalar(
            "SELECT COUNT(*) FROM system_logs
              WHERE action = 'login_failed'
                AND ip_address = ?
                AND created_at > (NOW() - INTERVAL ? SECOND)",
            [client_ip(), LOGIN_LOCKOUT_WINDOW]
        );

        if ($byIp >= LOGIN_MAX_ATTEMPTS) {
            return true;
        }

        if ($username === '') {
            return false;
        }

        // Allowed a wider margin than the per-IP limit: a shared office
        // address should not be able to lock a farmer out of their own
        // account by fat-fingering their password.
        $byAccount = (int) Database::scalar(
            "SELECT COUNT(*) FROM system_logs
              WHERE action = 'login_failed'
                AND description = ?
                AND created_at > (NOW() - INTERVAL ? SECOND)",
            [self::failureNote($username), LOGIN_LOCKOUT_WINDOW]
        );

        return $byAccount >= LOGIN_MAX_ACCOUNT_ATTEMPTS;
    }

    // -----------------------------------------------------------------
    // Current user
    // -----------------------------------------------------------------

    /**
     * The signed-in user, re-read from the database once per request so a
     * deactivated or demoted account loses access immediately.
     */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$cachedUser;
        }
        self::$resolved = true;

        if (empty($_SESSION['user_id'])) {
            return self::$cachedUser = null;
        }

        $user = Database::first(
            'SELECT id, name, username, role, status, can_control_pump, last_login,
                    must_change_password, password_changed_at
               FROM users WHERE id = ? LIMIT 1',
            [$_SESSION['user_id']]
        );

        if (!$user || $user['status'] !== 'active') {
            self::logout('session_revoked');

            return self::$cachedUser = null;
        }

        // A password change signs out every other session. Someone who
        // changes their password because they think an account is
        // compromised expects exactly this, and would otherwise leave the
        // intruder's session untouched.
        if ((string) ($user['password_changed_at'] ?? '') !== (string) ($_SESSION['pw_stamp'] ?? '')) {
            self::logout('session_revoked');

            return self::$cachedUser = null;
        }

        return self::$cachedUser = $user;
    }

    /** True when the user is being held on the password page. */
    public static function mustChangePassword(): bool
    {
        $u = self::user();

        return $u !== null && (int) ($u['must_change_password'] ?? 0) === 1;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $u = self::user();

        return $u ? (int) $u['id'] : null;
    }

    public static function isAdmin(): bool
    {
        $u = self::user();

        return $u !== null && $u['role'] === 'admin';
    }

    /** Administrators always may; farmers need the explicit flag. */
    public static function canControlPump(): bool
    {
        $u = self::user();

        return $u !== null && ($u['role'] === 'admin' || (int) $u['can_control_pump'] === 1);
    }

    // -----------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------

    /** Require a signed-in user, remembering where they were headed. */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            if (wants_json()) {
                Response::json(['success' => false, 'error' => 'Your session has expired.'], 401);
            }

            $_SESSION['intended'] = $GLOBALS['CURRENT_ROUTE'] ?? 'dashboard';
            flash('warning', 'Please sign in to continue.');
            redirect('login');
        }

        self::enforcePasswordChange();
    }

    /**
     * Hold an account still carrying its shipped password on the profile
     * page until a new one is set.
     *
     * The default credentials are printed in the README and in the schema,
     * so an installation that never rotates them is open to anyone who has
     * read either. Asking politely in the documentation was not working.
     */
    private static function enforcePasswordChange(): void
    {
        if (!self::mustChangePassword()) {
            return;
        }

        $route = $GLOBALS['CURRENT_ROUTE'] ?? '';
        if ($route === 'profile' || $route === 'logout') {
            return;
        }

        if (wants_json()) {
            Response::json([
                'success' => false,
                'error'   => 'Please set a new password before continuing.',
            ], 403);
        }

        flash('warning', 'This account is still using its default password. '
            . 'Please choose a new one before continuing.');
        redirect('profile');
    }

    /** Require the administrator role. */
    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (self::isAdmin()) {
            return;
        }

        SystemLog::record(self::id(), 'access_denied', 'Attempted to open an administrator-only page.');
        self::deny('This section is restricted to administrators.');
    }

    /** Require permission to operate the pump. */
    public static function requirePumpControl(): void
    {
        self::requireLogin();
        if (self::canControlPump()) {
            return;
        }

        SystemLog::record(self::id(), 'access_denied', 'Attempted to operate the pump without permission.');
        self::deny('You are not authorised to operate the pump. Ask an administrator to enable it for your account.');
    }

    private static function deny(string $message): never
    {
        if (wants_json()) {
            Response::json(['success' => false, 'error' => $message], 403);
        }

        http_response_code(403);
        $GLOBALS['ERROR_TITLE']   = 'Access denied';
        $GLOBALS['ERROR_MESSAGE'] = $message;
        require APP_ROOT . '/views/errors/403.php';
        exit;
    }
}
