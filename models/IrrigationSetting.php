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
            'crop_name'          => null,
            'cycle_start'        => null,
            'cycle_days'         => null,
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

    // -----------------------------------------------------------------
    // Planting cycle
    // -----------------------------------------------------------------

    /** Longest cycle a farmer can enter, in days (two years). */
    public const CYCLE_MAX_DAYS = 730;

    /**
     * Validate the planting-cycle fields. All three blank means "no cycle",
     * which is valid and leaves irrigation unrestricted.
     *
     * @return array<string,string> field => message, empty when valid
     */
    public static function validateCycle(array $data): array
    {
        $errors = [];

        $crop  = trim((string) ($data['crop_name'] ?? ''));
        $start = trim((string) ($data['cycle_start'] ?? ''));
        $days  = trim((string) ($data['cycle_days'] ?? ''));

        if (mb_strlen($crop) > 80) {
            $errors['crop_name'] = 'The crop name can be at most 80 characters.';
        }

        // Nothing entered: no cycle, nothing more to check.
        if ($start === '' && $days === '') {
            return $errors;
        }

        if ($start === '') {
            $errors['cycle_start'] = 'Enter the day the cycle starts, or clear the length to remove the cycle.';
        } elseif (valid_date($start) === null) {
            $errors['cycle_start'] = 'The start date is not a valid date.';
        } else {
            $limit = new DateTimeImmutable('today');
            $date  = new DateTimeImmutable($start);
            if ($date < $limit->modify('-1 year') || $date > $limit->modify('+1 year')) {
                $errors['cycle_start'] = 'The start date must be within a year of today.';
            }
        }

        if ($days === '') {
            $errors['cycle_days'] = 'Enter how many days the cycle lasts, or clear the start date to remove the cycle.';
        } elseif (!ctype_digit($days) || (int) $days < 1 || (int) $days > self::CYCLE_MAX_DAYS) {
            $errors['cycle_days'] = 'The cycle length must be a whole number of days from 1 to '
                . self::CYCLE_MAX_DAYS . '.';
        }

        return $errors;
    }

    /** Persist the planting cycle. Pass nulls to clear it. */
    public static function updateCycle(
        int $deviceId,
        ?string $crop,
        ?string $start,
        ?int $days,
        ?int $userId
    ): void {
        Database::execute(
            'UPDATE irrigation_settings
                SET crop_name = ?, cycle_start = ?, cycle_days = ?, updated_by = ?
              WHERE device_id = ?',
            [$crop, $start, $days, $userId, $deviceId]
        );
    }

    /**
     * Where the device is in its planting cycle today.
     *
     * The cycle covers `cycle_days` calendar days starting on `cycle_start`
     * (day 1). `active` is the only field the device acts on: true when no
     * cycle is set, or today falls inside it.
     *
     * Dates are compared as plain Y-m-d in the application timezone, so no
     * clock is needed on the ESP32 - the server decides and the device
     * follows on its next check-in.
     *
     * @return array{configured:bool,state:string,active:bool,crop:string,
     *               start:?string,end:?string,days:int,day:int,days_left:int,
     *               days_until:int,progress:float,message:string}
     */
    public static function cycleStatus(array $settings, ?string $today = null): array
    {
        $crop  = trim((string) ($settings['crop_name'] ?? ''));
        $start = valid_date((string) ($settings['cycle_start'] ?? ''));
        $days  = (int) ($settings['cycle_days'] ?? 0);

        $base = [
            'configured' => false, 'state' => 'none', 'active' => true,
            'crop' => $crop, 'start' => null, 'end' => null,
            'days' => 0, 'day' => 0, 'days_left' => 0, 'days_until' => 0,
            'progress' => 0.0,
            'message' => 'No planting cycle is set, so automatic irrigation runs at all times.',
        ];

        if ($start === null || $days < 1) {
            return $base;
        }

        $first = new DateTimeImmutable($start);
        $last  = $first->modify('+' . ($days - 1) . ' days');
        $now   = new DateTimeImmutable($today ?? 'today');
        $label = $crop !== '' ? $crop : 'The planting cycle';

        $base['configured'] = true;
        $base['start']      = $first->format('Y-m-d');
        $base['end']        = $last->format('Y-m-d');
        $base['days']       = $days;

        if ($now < $first) {
            $until = (int) $now->diff($first)->days;
            return array_merge($base, [
                'state'      => 'upcoming',
                'active'     => false,
                'days_until' => $until,
                'message'    => sprintf('%s starts on %s (in %d day%s). Automatic irrigation is paused until then.',
                    $label, $first->format('d M Y'), $until, $until === 1 ? '' : 's'),
            ]);
        }

        if ($now > $last) {
            return array_merge($base, [
                'state'    => 'ended',
                'active'   => false,
                'day'      => $days,
                'progress' => 100.0,
                'message'  => sprintf('%s ended on %s. Automatic irrigation is paused - start a new cycle to resume it.',
                    $label, $last->format('d M Y')),
            ]);
        }

        $day = (int) $first->diff($now)->days + 1;

        return array_merge($base, [
            'state'     => 'active',
            'active'    => true,
            'day'       => $day,
            'days_left' => $days - $day,
            'progress'  => round($day * 100 / $days, 1),
            'message'   => sprintf('%s: day %d of %d (%d day%s left, ends %s).',
                $label, $day, $days, $days - $day, ($days - $day) === 1 ? '' : 's', $last->format('d M Y')),
        ]);
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
