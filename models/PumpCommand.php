<?php
/**
 * AgriSense - pump_commands
 *
 * The web application never switches the relay itself. It queues a command
 * here; the ESP32 collects it on its next poll, acts on it and acknowledges.
 * The firmware therefore stays the single authority over the relay, which is
 * what makes the safety rules in requirement 27 enforceable.
 */

class PumpCommand
{
    public const COMMANDS = ['PUMP_ON', 'PUMP_OFF', 'MODE_AUTO', 'MODE_MANUAL'];

    public const LABELS = [
        'PUMP_ON'     => 'Turn pump ON',
        'PUMP_OFF'    => 'Turn pump OFF',
        'MODE_AUTO'   => 'Switch to automatic mode',
        'MODE_MANUAL' => 'Switch to manual mode',
    ];

    /** Queue a command for a device. */
    public static function queue(int $deviceId, string $command, ?int $userId): int
    {
        if (!in_array($command, self::COMMANDS, true)) {
            throw new InvalidArgumentException('Unknown pump command: ' . $command);
        }

        return Database::transaction(function () use ($deviceId, $command, $userId) {
            // A newer instruction supersedes anything still outstanding, so
            // the pump never receives a stale ON after an operator pressed
            // OFF. 'delivered' is included because a command now stays
            // outstanding until it is acknowledged - without this an
            // unconfirmed ON would keep being re-served past the OFF.
            Database::execute(
                "UPDATE pump_commands
                    SET status = 'expired', result_message = 'Superseded by a newer command.'
                  WHERE device_id = ? AND status IN ('pending','delivered')",
                [$deviceId]
            );

            return Database::insert(
                'INSERT INTO pump_commands (device_id, command, requested_by) VALUES (?, ?, ?)',
                [$deviceId, $command, $userId]
            );
        });
    }

    /**
     * Give up on anything the device never confirmed in time.
     *
     * A command is re-served on every poll until it is acknowledged, so
     * reaching this point means the device did not carry it out - not
     * merely that one reply went missing. The wording reflects that.
     */
    public static function expireStale(): int
    {
        return Database::execute(
            "UPDATE pump_commands
                SET status = 'expired',
                    result_message = 'The device did not confirm this command in time.'
              WHERE status IN ('pending','delivered')
                AND created_at < (NOW() - INTERVAL " . PUMP_COMMAND_TTL . " SECOND)"
        );
    }

    /**
     * Hand the oldest outstanding command to the device.
     *
     * The command stays outstanding until the device acknowledges it, so a
     * reply lost in transit is re-served on the next poll instead of being
     * silently dropped. That matters most for PUMP_OFF: the operator is
     * usually pressing it because something is wrong, which is exactly when
     * the link is least reliable.
     *
     * delivered_at records the FIRST time it went out, so the age check in
     * expireStale() still measures from the original queueing.
     *
     * The SELECT takes a row lock, so two concurrent polls - a second board
     * sharing a key, or anything else talking to the API at the same moment
     * - cannot both walk away with the same command.
     */
    public static function claimNext(int $deviceId): ?array
    {
        self::expireStale();

        return Database::transaction(function () use ($deviceId) {
            $cmd = Database::first(
                "SELECT * FROM pump_commands
                  WHERE device_id = ? AND status IN ('pending','delivered')
                  ORDER BY created_at, id
                  LIMIT 1
                  FOR UPDATE",
                [$deviceId]
            );

            if (!$cmd) {
                return null;
            }

            Database::execute(
                "UPDATE pump_commands
                    SET status = 'delivered',
                        delivered_at = COALESCE(delivered_at, NOW())
                  WHERE id = ?",
                [$cmd['id']]
            );

            return $cmd;
        });
    }

    /**
     * The device confirms it applied (or could not apply) the command.
     *
     * Scoped to commands that are still outstanding: a duplicate ack for a
     * command already settled - or superseded - must not resurrect it.
     *
     * @return bool True when this ack actually settled the command.
     */
    public static function acknowledge(int $id, bool $ok, string $message = ''): bool
    {
        return Database::execute(
            "UPDATE pump_commands
                SET status = ?, acknowledged_at = NOW(), result_message = ?
              WHERE id = ? AND status IN ('pending','delivered')",
            [$ok ? 'acknowledged' : 'failed', substr($message, 0, 255) ?: null, $id]
        ) > 0;
    }

    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM pump_commands WHERE id = ?', [$id]);
    }

    /** Status of a command, for the dashboard to poll after a button press. */
    public static function status(int $id): ?array
    {
        return Database::first(
            'SELECT c.id, c.command, c.status, c.result_message, c.created_at,
                    c.delivered_at, c.acknowledged_at, u.name AS requested_by_name
               FROM pump_commands c
               LEFT JOIN users u ON u.id = c.requested_by
              WHERE c.id = ?',
            [$id]
        );
    }

    /** Recent command history for a device. */
    public static function recent(int $deviceId, int $limit = 10): array
    {
        $limit = max(1, min($limit, 100));

        return Database::all(
            "SELECT c.*, u.name AS requested_by_name
               FROM pump_commands c
               LEFT JOIN users u ON u.id = c.requested_by
              WHERE c.device_id = ?
              ORDER BY c.created_at DESC, c.id DESC
              LIMIT {$limit}",
            [$deviceId]
        );
    }

    public static function pendingCount(int $deviceId): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM pump_commands
              WHERE device_id = ? AND status IN ('pending','delivered')",
            [$deviceId]
        );
    }
}
