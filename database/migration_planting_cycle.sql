-- =====================================================================
--  AgriSense - Migration: planting cycle window
--
--  Lets a farmer record what is planted, when the cycle started and how
--  many days it lasts. Automatic irrigation runs only inside that window:
--  the server tells the device (`cycle_active` in the settings reply) and
--  the firmware pauses automatic watering outside it. Manual control is
--  never affected.
--
--  Adds three nullable columns to `irrigation_settings` (all NULL = no
--  cycle set, irrigation unrestricted, exactly as before) and a new
--  `cycle_ended` stop reason, used when the device stops a running pump
--  because the window closed.
--
--  Run it ONCE, against the database the application uses:
--    - XAMPP:    select the `agrisense` database, then run this file
--    - Railway:  connect to the `railway` database, then run this file
--  There is deliberately no USE line, so it works on either. It is not
--  idempotent (MySQL 8 has no ADD COLUMN IF NOT EXISTS); a second run stops
--  with "Duplicate column name", which is harmless.
--
--  A fresh install does NOT need this - database/agrisense.sql already
--  contains it.
-- =====================================================================

ALTER TABLE `irrigation_settings`
    ADD COLUMN `crop_name` VARCHAR(80) NULL DEFAULT NULL
        COMMENT 'What is planted in this cycle, free text'
        AFTER `min_cycle_interval`,
    ADD COLUMN `cycle_start` DATE NULL DEFAULT NULL
        COMMENT 'First day of the planting cycle'
        AFTER `crop_name`,
    ADD COLUMN `cycle_days` SMALLINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Length of the planting cycle in days, counting the start day'
        AFTER `cycle_start`;

ALTER TABLE `irrigation_logs`
    MODIFY COLUMN `stop_reason` ENUM(
        'target_reached','manual_stop','max_runtime','sensor_failure',
        'device_reset','reconciled_unknown','cycle_ended'
    ) NULL DEFAULT NULL;
