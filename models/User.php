<?php
/**
 * AgriSense - users
 */

class User
{
    public const ROLES    = ['admin', 'farmer'];
    public const STATUSES = ['active', 'inactive'];

    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT id, name, username, role, status, can_control_pump, last_login, created_at, updated_at
               FROM users WHERE id = ?',
            [$id]
        );
    }

    /** All users, newest first, for the management table. */
    public static function all(string $search = ''): array
    {
        if ($search !== '') {
            return Database::all(
                'SELECT id, name, username, role, status, can_control_pump, last_login, created_at
                   FROM users
                  WHERE name LIKE ? OR username LIKE ?
                  ORDER BY role, name',
                ['%' . $search . '%', '%' . $search . '%']
            );
        }

        return Database::all(
            'SELECT id, name, username, role, status, can_control_pump, last_login, created_at
               FROM users ORDER BY role, name'
        );
    }

    /** Users who may be listed as pump operators. */
    public static function operators(): array
    {
        return Database::all(
            "SELECT id, name FROM users
              WHERE status = 'active' AND (role = 'admin' OR can_control_pump = 1)
              ORDER BY name"
        );
    }

    public static function usernameTaken(string $username, ?int $exceptId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM users WHERE username = ?';
        $params = [$username];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        return (int) Database::scalar($sql, $params) > 0;
    }

    /**
     * Validate submitted user data.
     *
     * @return array<string,string> field => message, empty when valid
     */
    public static function validate(array $data, ?int $id = null, bool $passwordRequired = true): array
    {
        $errors = [];

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Enter a full name of up to 100 characters.';
        }

        $username = trim((string) ($data['username'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            $errors['username'] = 'Use 3 to 50 letters, numbers, dots, dashes or underscores.';
        } elseif (self::usernameTaken($username, $id)) {
            $errors['username'] = 'That username is already taken.';
        }

        $password = (string) ($data['password'] ?? '');
        if ($passwordRequired || $password !== '') {
            if (strlen($password) < 8) {
                $errors['password'] = 'Passwords must be at least 8 characters long.';
            } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
                $errors['password'] = 'Passwords must contain at least one letter and one number.';
            } elseif ($password !== (string) ($data['password_confirm'] ?? $password)) {
                $errors['password_confirm'] = 'The two passwords do not match.';
            }
        }

        if (!in_array($data['role'] ?? '', self::ROLES, true)) {
            $errors['role'] = 'Choose a valid role.';
        }
        if (!in_array($data['status'] ?? '', self::STATUSES, true)) {
            $errors['status'] = 'Choose a valid status.';
        }

        return $errors;
    }

    /**
     * Create an account. The password was chosen by an administrator, not
     * by the person who will use it, so they are made to replace it the
     * first time they sign in.
     */
    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO users (name, username, password, role, status, can_control_pump,
                                must_change_password)
             VALUES (?, ?, ?, ?, ?, ?, 1)',
            [
                trim($data['name']),
                trim($data['username']),
                password_hash($data['password'], PASSWORD_DEFAULT),
                $data['role'],
                $data['status'],
                !empty($data['can_control_pump']) ? 1 : 0,
            ]
        );
    }

    /**
     * Update a user; the password is only touched when a new one is given.
     *
     * An administrator resetting someone else's password stamps
     * password_changed_at, which signs that account out everywhere, and
     * sets must_change_password so the owner picks their own on the way
     * back in - the administrator should not keep knowing it.
     */
    public static function update(int $id, array $data): void
    {
        $sql = 'UPDATE users SET name = ?, username = ?, role = ?, status = ?, can_control_pump = ?';
        $params = [
            trim($data['name']),
            trim($data['username']),
            $data['role'],
            $data['status'],
            !empty($data['can_control_pump']) ? 1 : 0,
        ];

        if (!empty($data['password'])) {
            $sql .= ', password = ?, must_change_password = 1, password_changed_at = ?';
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
            $params[] = date('Y-m-d H:i:s');
        }

        $sql .= ' WHERE id = ?';
        $params[] = $id;

        Database::execute($sql, $params);
    }

    /** Number of active administrators - used to protect the last admin. */
    public static function activeAdminCount(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'"
        );
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM users WHERE id = ?', [$id]);
    }
}
