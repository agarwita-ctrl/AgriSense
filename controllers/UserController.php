<?php
/**
 * AgriSense - User management (requirement 9). Administrators only.
 */

class UserController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        render('users/index', [
            'users'  => User::all(input('search')),
            'search' => input('search'),
            'selfId' => Auth::id(),
        ], ['title' => 'Users', 'active' => 'users']);
    }

    public function create(): void
    {
        Auth::requireAdmin();

        render('users/form', [
            'user'   => ['name' => '', 'username' => '', 'role' => 'farmer',
                         'status' => 'active', 'can_control_pump' => 0],
            'errors' => [],
            'isNew'  => true,
        ], ['title' => 'Add user', 'active' => 'users']);
    }

    public function store(): void
    {
        Auth::requireAdmin();
        csrf_guard();

        $data = $this->payload();
        $errors = User::validate($data, null, true);

        if ($errors) {
            render('users/form', ['user' => $data, 'errors' => $errors, 'isNew' => true],
                ['title' => 'Add user', 'active' => 'users']);

            return;
        }

        $id = User::create($data);
        SystemLog::record(Auth::id(), 'user_create',
            sprintf('Created %s account "%s".', $data['role'], $data['username']));

        flash('success', 'User account created.');
        redirect('users');
    }

    public function edit(): void
    {
        Auth::requireAdmin();

        $user = User::find(input_int('id', 0, 1));
        if (!$user) {
            flash('danger', 'That user could not be found.');
            redirect('users');
        }

        render('users/form', ['user' => $user, 'errors' => [], 'isNew' => false],
            ['title' => 'Edit user', 'active' => 'users']);
    }

    public function update(): void
    {
        Auth::requireAdmin();
        csrf_guard();

        $id   = input_int('id', 0, 1);
        $user = User::find($id);
        if (!$user) {
            flash('danger', 'That user could not be found.');
            redirect('users');
        }

        $data = $this->payload();
        // A blank password field means "leave the password alone".
        $errors = User::validate($data, $id, false);

        // Never let the last active administrator lock everyone out.
        if ($user['role'] === 'admin'
            && ($data['role'] !== 'admin' || $data['status'] !== 'active')
            && User::activeAdminCount() <= 1) {
            $errors['role'] = 'This is the only active administrator. Promote another user first.';
        }

        if ($errors) {
            render('users/form', ['user' => array_merge($user, $data), 'errors' => $errors, 'isNew' => false],
                ['title' => 'Edit user', 'active' => 'users']);

            return;
        }

        User::update($id, $data);
        SystemLog::record(Auth::id(), 'user_update',
            sprintf('Updated account "%s" (role %s, status %s).', $data['username'], $data['role'], $data['status']));

        // Setting a password here stamps password_changed_at and raises
        // must_change_password. Neither should apply to an administrator
        // who just reset their own: they chose it themselves, and their
        // current session should survive.
        if (!empty($data['password']) && $id === Auth::id()) {
            $stamp = (string) Database::scalar(
                'SELECT password_changed_at FROM users WHERE id = ?', [$id]
            );
            Database::execute('UPDATE users SET must_change_password = 0 WHERE id = ?', [$id]);
            session_regenerate_id(true);
            $_SESSION['pw_stamp'] = $stamp;
        }

        flash('success', 'User account updated.');
        redirect('users');
    }

    public function delete(): void
    {
        Auth::requireAdmin();
        csrf_guard();

        $id   = input_int('id', 0, 1);
        $user = User::find($id);
        if (!$user) {
            flash('danger', 'That user could not be found.');
            redirect('users');
        }

        if ($id === Auth::id()) {
            flash('danger', 'You cannot delete the account you are signed in with.');
            redirect('users');
        }

        if ($user['role'] === 'admin' && User::activeAdminCount() <= 1) {
            flash('danger', 'This is the only active administrator and cannot be deleted.');
            redirect('users');
        }

        User::delete($id);
        SystemLog::record(Auth::id(), 'user_delete', 'Deleted account "' . $user['username'] . '".');

        flash('success', 'User account deleted.');
        redirect('users');
    }

    /** Collect and normalise the submitted form fields. */
    private function payload(): array
    {
        return [
            'name'             => input('name'),
            'username'         => input('username'),
            'password'         => (string) ($_POST['password'] ?? ''),
            'password_confirm' => (string) ($_POST['password_confirm'] ?? ''),
            'role'             => one_of(input('role'), User::ROLES, 'farmer'),
            'status'           => one_of(input('status'), User::STATUSES, 'active'),
            'can_control_pump' => input('can_control_pump') === '1' ? 1 : 0,
        ];
    }
}
