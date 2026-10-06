<?php
/**
 * AgriSense - irrigation_settings (requirement 17)
 *
 * These values drive the firmware. Validation here is the last line of
 * defence before a bad number reaches a real pump, so the bounds are
 * deliberately conservative.
 */

class IrrigationSetting
{
    /** Minimum gap between threshold and target, in percentage points. */
    public const MIN_HYSTERESIS = 5.0;

    public static function forDevice(int $deviceId): ?array
    {
        return Database::first('SELECT * FROM irrigation_settings WHERE device_id = ?', [$deviceId]);
    }

    /** Fetch the settings row, creating it with safe defaults if missing. */
    public static function ensureFor(int $deviceId, ?int $userId = null): array
    {
        $row = self::forDevice($deviceId);
        if ($row) {
            return $row;
        }

        Database::execute(
            'INSERT INTO irrigation_settings (device_id, updated_by) VALUES (?, ?)',
            [$deviceId, $userId]
        );

        return self::forDevice($deviceId) ?? self::defaults($deviceId);
    }

    /** Fallback used only if the row could not be read back. */
    public static function defaults(int $deviceId): array
    {
        return [
            'id'                 => 0,
            'device_id'          => $deviceId,
            'moisture_threshold' => 30.00,
            'target_moisture'    => 60.00,
            'dry_value'          => 3200,
            'wet_value'          => 1200,
            'auto_irrigation'    => 1,
            'max_pump_runtime'   => 300,
            'reading_interval'   => 30,
            'min_cycle_interval' => 60,
            'updated_by'         => null,
            'updated_at'         => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Validate a settings submission.
     *
     * @return array<string,string> field => message, empty when valid
     */
    public static function validate(array $data): array
    {
        $errors = [];

        $threshold = $data['moisture_threshold'] ?? null;
        $target    = $data['target_moisture'] ?? null;

        if (!in_range($threshold, 5, 90)) {
            $errors['moisture_threshold'] = 'The irrigation threshold must be between 5% and 90%.';
        }
        if (!in_range($target, 10, 95)) {
            $errors['target_moisture'] = 'The target moisture must be between 10% and 95%.';
        }

        // Hysteresis gap - without it the pump would chatter on and off
        // around a single set point (requirement 7).
        if (empty($errors['moisture_threshold']) && empty($errors['target_moisture'])) {
            $gap = (float) $target - (float) $threshold;
            if ($gap < self::MIN_HYSTERESIS) {
                $errors['target_moisture'] = sprintf(
                    'The target must sit at least %.0f points above the threshold so the pump does not switch on and off repeatedly.',
                    self::MIN_HYSTERESIS
                );
            }
        }

        // Capacitive sensors read HIGH when dry and LOW when wet.
        $dry = $data['dry_value'] ?? null;
        $wet = $data['wet_value'] ?? null;
        if (!in_range($dry, 0, 4095)) {
            $errors['dry_value'] = 'The dry calibration value must be between 0 and 4095.';
        }
        if (!in_range($wet, 0, 4095)) {
            $errors['wet_value'] = 'The wet calibration value must be between 0 and 4095.';
        }
        if (empty($errors['dry_value']) && empty($errors['wet_value']) && (int) $dry - (int) $wet < 200) {
            $errors['dry_value'] = 'The dry reading must be at least 200 counts above the wet reading. '
                . 'Re-calibrate the sensor in dry air and then in water.';
        }

        if (!in_range($data['max_pump_runtime'] ?? null, 30, PUMP_RUNTIME_CEILING)) {
            $errors['max_pump_runtime'] = 'The maximum pump runtime must be between 30 and '
                . PUMP_RUNTIME_CEILING . ' seconds.';
        }
        if (!in_range($data['reading_interval'] ?? null, 5, 3600)) {
            $errors['reading_interval'] = 'The reading interval must be between 5 and 3600 seconds.';
        }
        if (!in_range($data['min_cycle_interval'] ?? null, 0, 86400)) {
            $errors['min_cycle_interval'] = 'The rest period between cycles must be between 0 and 86400 seconds.';
        }

        return $errors;
    }

    /** Persist validated settings. */
    public static function update(int $deviceId, array $data, ?int $userId): void
    {
        Database::execute(
            'UPDATE irrigation_settings
                SET moisture_threshold = ?, target_moisture = ?, dry_value = ?, wet_value = ?,
                    auto_irrigation = ?, max_pump_runtime = ?, reading_interval = ?,
                    min_cycle_interval = ?, updated_by = ?
              WHERE device_id = ?',
            [
                round((float) $data['moisture_threshold'], 2),
                round((float) $data['target_moisture'], 2),
                (int) $data['dry_value'],
                (int) $data['wet_value'],
                !empty($data['auto_irrigation']) ? 1 : 0,
                (int) $data['max_pump_runtime'],
                (int) $data['reading_interval'],
                (int) $data['min_cycle_interval'],
                $userId,
                $deviceId,
            ]
        );
    }

    /** Flip only the auto/manual flag - used by the mode buttons. */
    public static function setAutoMode(int $deviceId, bool $auto, ?int $userId): void
    {
        Database::execute(
            'UPDATE irrigation_settings SET auto_irrigation = ?, updated_by = ? WHERE device_id = ?',
            [$auto ? 1 : 0, $userId, $deviceId]
        );
    }

    /**
     * Convert a raw ADC reading to a moisture percentage using the stored
     * calibration. Mirrors the mapping in the ESP32 firmware.
     */
    public static function rawToPercent(int $raw, array $settings): float
    {
        $dry = (int) $settings['dry_value'];
        $wet = (int) $settings['wet_value'];
        if ($dry === $wet) {
            return 0.0;
        }

        $percent = ($dry - $raw) * 100.0 / ($dry - $wet);

        return round(max(0.0, min(100.0, $percent)), 2);
    }
}
