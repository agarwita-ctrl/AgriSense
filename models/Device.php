<?php
/**
 * AgriSense - devices
 *
 * The schema and every query here are keyed on device_id, so adding a
 * second or tenth ESP32 needs no code change (rule 17).
 */

class Device
{
    public const STATUSES = ['active', 'inactive', 'maintenance'];

    /** SQL fragment computing online/offline from last_seen. */
    private const ONLINE_EXPR =
        "(d.last_seen IS NOT NULL AND d.last_seen > (NOW() - INTERVAL " . DEVICE_OFFLINE_AFTER . " SECOND))";

    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT d.*, ' . self::ONLINE_EXPR . ' AS is_online
               FROM devices d WHERE d.id = ?',
            [$id]
        );
    }

    public static function findByCode(string $code): ?array
    {
        return Database::first(
            'SELECT d.*, ' . self::ONLINE_EXPR . ' AS is_online
               FROM devices d WHERE d.device_code = ?',
            [$code]
        );
    }

    /** Shortest key the API will accept. Generated keys are 64 characters. */
    public const MIN_API_KEY_LENGTH = 20;

    /**
     * Hash an API key for storage and lookup.
     *
     * A generated key is 256 bits of `random_bytes`, so there is nothing to
     * brute force and no reuse to protect against: a single SHA-256 is the
     * right primitive here, and unlike bcrypt it can be looked up with an
     * indexed equality instead of a table scan.
     */
    public static function hashApiKey(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * Look a device up by its API key.
     *
     * Only the hash is stored, and only the hash reaches SQL - the key
     * itself is never written to the database, a log or a query. The
     * lookup is an exact match on a utf8mb4_bin column, so it is case
     * sensitive and does not silently ignore trailing whitespace.
     */
    public static function findByApiKey(string $key): ?array
    {
        $key = trim($key);

        if (strlen($key) < self::MIN_API_KEY_LENGTH
            || strlen($key) > 128
            || !preg_match('/^[\x21-\x7E]+$/', $key)) {
            return null;
        }

        return Database::first(
            'SELECT d.*, ' . self::ONLINE_EXPR . ' AS is_online
               FROM devices d WHERE d.api_key_hash = ?
               LIMIT 1',
            [self::hashApiKey($key)]
        );
    }

    /** Every device with its online flag. */
    public static function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT d.*, ' . self::ONLINE_EXPR . ' AS is_online FROM devices d';
        if ($activeOnly) {
            $sql .= " WHERE d.status = 'active'";
        }
        $sql .= ' ORDER BY d.device_name';

        return Database::all($sql);
    }

    /** id => label map for filter dropdowns. */
    public static function options(): array
    {
        $out = [];
        foreach (self::all() as $d) {
            $out[(int) $d['id']] = $d['device_name'] . ' (' . $d['device_code'] . ')';
        }

        return $out;
    }

    /** The device a dashboard should default to: the most recently active one. */
    public static function defaultDevice(): ?array
    {
        return Database::first(
            'SELECT d.*, ' . self::ONLINE_EXPR . ' AS is_online
               FROM devices d
              ORDER BY (d.status = \'active\') DESC, d.last_seen IS NULL, d.last_seen DESC, d.id
              LIMIT 1'
        );
    }

    public static function generateApiKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * @return array<string,string> field => message
     */
    public static function validate(array $data, ?int $id = null): array
    {
        $errors = [];

        $name = trim((string) ($data['device_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['device_name'] = 'Enter a device name of up to 100 characters.';
        }

        $code = trim((string) ($data['device_code'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{3,50}$/', $code)) {
            $errors['device_code'] = 'Use 3 to 50 letters, numbers, dashes or underscores (e.g. ESP32-001).';
        } elseif (self::codeTaken($code, $id)) {
            $errors['device_code'] = 'Another device already uses that code.';
        }

        $mac = strtoupper(trim((string) ($data['mac_address'] ?? '')));
        if ($mac !== '') {
            if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
                $errors['mac_address'] = 'Enter a MAC address in the form A0:B7:65:1C:2D:3E.';
            } elseif (self::macTaken($mac, $id)) {
                $errors['mac_address'] = 'Another device is already registered with that MAC address.';
            }
        }

        if (mb_strlen((string) ($data['location'] ?? '')) > 150) {
            $errors['location'] = 'Keep the location under 150 characters.';
        }
        if (!in_array($data['status'] ?? '', self::STATUSES, true)) {
            $errors['status'] = 'Choose a valid status.';
        }

        if (!in_array((int) ($data['probe_count'] ?? 1), [1, 2], true)) {
            $errors['probe_count'] = 'A device can have one or two soil probes.';
        }

        return $errors;
    }

    private static function codeTaken(string $code, ?int $exceptId): bool
    {
        $sql = 'SELECT COUNT(*) FROM devices WHERE device_code = ?';
        $params = [$code];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        return (int) Database::scalar($sql, $params) > 0;
    }

    private static function macTaken(string $mac, ?int $exceptId): bool
    {
        $sql = 'SELECT COUNT(*) FROM devices WHERE mac_address = ?';
        $params = [$mac];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        return (int) Database::scalar($sql, $params) > 0;
    }

    /**
     * Create a device together with its default irrigation settings.
     *
     * @param string|null $plainKey Receives the generated API key. This is
     *                              the only moment it exists in readable
     *                              form - the caller must show it to the
     *                              operator now or it is gone for good.
     */
    public static function create(array $data, ?int $userId, ?string &$plainKey = null): int
    {
        $plainKey = self::generateApiKey();
        $hash     = self::hashApiKey($plainKey);

        return Database::transaction(function () use ($data, $userId, $hash) {
            $mac = strtoupper(trim((string) ($data['mac_address'] ?? '')));

            $id = Database::insert(
                'INSERT INTO devices
                    (device_name, device_code, api_key_hash, mac_address, location,
                     probe_count, has_dht22, has_mlx90614, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    trim($data['device_name']),
                    trim($data['device_code']),
                    $hash,
                    $mac !== '' ? $mac : null,
                    trim((string) ($data['location'] ?? '')) ?: null,
                    (int) ($data['probe_count'] ?? 1),
                    !empty($data['has_dht22']) ? 1 : 0,
                    !empty($data['has_mlx90614']) ? 1 : 0,
                    $data['status'],
                ]
            );

            // Every device needs a settings row before it can irrigate.
            IrrigationSetting::ensureFor($id, $userId);

            return $id;
        });
    }

    public static function update(int $id, array $data): void
    {
        $mac = strtoupper(trim((string) ($data['mac_address'] ?? '')));

        Database::execute(
            'UPDATE devices
                SET device_name = ?, device_code = ?, mac_address = ?, location = ?,
                    probe_count = ?, has_dht22 = ?, has_mlx90614 = ?, status = ?
              WHERE id = ?',
            [
                trim($data['device_name']),
                trim($data['device_code']),
                $mac !== '' ? $mac : null,
                trim((string) ($data['location'] ?? '')) ?: null,
                (int) ($data['probe_count'] ?? 1),
                !empty($data['has_dht22']) ? 1 : 0,
                !empty($data['has_mlx90614']) ? 1 : 0,
                $data['status'],
                $id,
            ]
        );
    }

    /**
     * Issue a fresh API key. Returns the plaintext, which is the only copy
     * that will ever exist - the database keeps the hash alone.
     */
    public static function rotateApiKey(int $id): string
    {
        $key = self::generateApiKey();
        Database::execute(
            'UPDATE devices SET api_key_hash = ? WHERE id = ?',
            [self::hashApiKey($key), $id]
        );

        return $key;
    }

    public static function delete(int $id): void
    {
        // Readings, logs, alerts and commands cascade away with the device.
        Database::execute('DELETE FROM devices WHERE id = ?', [$id]);
    }

    /** Record that the device just called home. */
    public static function touch(
        int $id,
        ?string $ip = null,
        ?string $firmware = null,
        ?int $rssi = null,
        ?int $uptimeSeconds = null
    ): void {
        Database::execute(
            'UPDATE devices
                SET last_seen = NOW(),
                    ip_address = COALESCE(?, ip_address),
                    firmware_version = COALESCE(?, firmware_version),
                    wifi_rssi = COALESCE(?, wifi_rssi),
                    uptime_seconds = COALESCE(?, uptime_seconds)
              WHERE id = ?',
            [$ip, $firmware, $rssi, $uptimeSeconds, $id]
        );
    }

    public static function isOnline(?array $device): bool
    {
        return $device !== null && (int) ($device['is_online'] ?? 0) === 1;
    }

    /**
     * Raise a device_offline alert for anything that has gone quiet, at most
     * once per device per hour. Called opportunistically on dashboard polls.
     */
    public static function flagOfflineDevices(): void
    {
        $stale = Database::all(
            "SELECT id, device_name FROM devices
              WHERE status = 'active'
                AND last_seen IS NOT NULL
                AND last_seen < (NOW() - INTERVAL " . DEVICE_OFFLINE_AFTER . " SECOND)"
        );

        foreach ($stale as $device) {
            Alert::raiseThrottled(
                (int) $device['id'],
                'device_offline',
                'No data received from ' . $device['device_name'] . ' for over '
                    . fmt_duration(DEVICE_OFFLINE_AFTER) . '.',
                'critical',
                3600
            );
        }
    }
}
