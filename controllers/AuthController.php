<?php
/**
 * AgriSense - Sign in, sign out and self-service password change.
 */

class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            redirect('dashboard');
        }

        render('auth/login', [
            'username' => input('username'),
            'error'    => null,
        ], ['title' => 'Sign in', 'bare' => true]);
    }

    public function login(): void
    {
        csrf_guard();

        $username = input('username');
        $result   = Auth::attempt($username, (string) ($_POST['password'] ?? ''));

        if (!$result['ok']) {
            http_response_code(401);
            render('auth/login', [
                'username' => $username,
                'error'    => $result['error'],
            ], ['title' => 'Sign in', 'bare' => true]);

            return;
        }

        $intended = $_SESSION['intended'] ?? 'dashboard';
        unset($_SESSION['intended']);

        flash('success', 'Welcome back, ' . (Auth::user()['name'] ?? '') . '.');
        redirect($intended);
    }

    public function logout(): void
    {
        csrf_guard();
        Auth::logout();
        redirect('login');
    }

    /** The signed-in user's own account page. */
    public function profile(): void
    {
        Auth::requireLogin();

        render('auth/profile', [
            'user'   => Auth::user(),
            'errors' => [],
        ], ['title' => 'My account', 'active' => 'profile']);
    }

    public function updatePassword(): void
    {
        Auth::requireLogin();
        csrf_guard();

        $user    = Auth::user();
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        $errors  = [];

        $hash = (string) Database::scalar('SELECT password FROM users WHERE id = ?', [$user['id']]);
        if (!password_verify($current, $hash)) {
            $errors['current_password'] = 'That is not your current password.';
        }
        if (strlen($new) < 8) {
            $errors['new_password'] = 'Choose a password of at least 8 characters.';
        } elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
            $errors['new_password'] = 'Include at least one letter and one number.';
        } elseif ($new === $current) {
            $errors['new_password'] = 'Choose a password you have not used here before.';
        } elseif ($new !== $confirm) {
            $errors['confirm_password'] = 'The two passwords do not match.';
        }

        if ($errors) {
            render('auth/profile', ['user' => $user, 'errors' => $errors],
                ['title' => 'My account', 'active' => 'profile']);

            return;
        }

        $changedAt = date('Y-m-d H:i:s');

        Database::execute(
            'UPDATE users
                SET password = ?, must_change_password = 0, password_changed_at = ?
              WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), $changedAt, $user['id']]
        );
        SystemLog::record((int) $user['id'], 'password_change', 'Changed their own password.');

        // Keep this session alive but re-key it, and adopt the new stamp so
        // Auth::user() does not read it as one of the sessions being cut.
        // Every other session the account had is now invalid.
        session_regenerate_id(true);
        $_SESSION['pw_stamp'] = $changedAt;

        flash('success', 'Your password has been updated. Any other devices signed in '
            . 'to this account have been signed out.');
        redirect('profile');
    }
}
