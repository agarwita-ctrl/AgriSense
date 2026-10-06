-- =====================================================================
--  AgriSense - Migration: dual soil probe support
--
--  Brings an existing installation in line with the AgriSense.ino
--  hardware configuration:
--
--    * two resistive soil moisture probes instead of one capacitive probe
--    * the device identifier used by the firmware (DEVICE_ID)
--    * thresholds matching the firmware's raw ADC set points
--
--  Safe to run on a database that already holds live readings: it only
--  ADDS columns, and existing rows keep their values.
--
--  Idempotent - every ADD COLUMN is guarded with IF NOT EXISTS, so a
--  second run is a no-op rather than an error that aborts partway and
--  leaves the UPDATE statements below unapplied. (IF NOT EXISTS on
--  ALTER TABLE is MariaDB 10.0+; on MySQL 8 run this once and only once.)
--
--  Run with:
--      mysql -u root -p agrisense < database/migration_dual_probe.sql
--
--  A fresh install does NOT need this - database/agrisense.sql already
--  contains everything below.
-- =====================================================================

USE `agrisense`;

-- ---------------------------------------------------------------------
-- 1. sensor_readings - room for the second probe
--
--    soil_moisture   stays the PRIMARY reading used for irrigation
--                    decisions, charts and thresholds. With two probes
--                    fitted it holds their average, so every existing
--                    query keeps working unchanged.
--    soil_moisture_1 / soil_moisture_2 hold the individual probes, so a
--                    single failing probe or an unevenly watered field is
--                    visible instead of being hidden by the average.
-- ---------------------------------------------------------------------
ALTER TABLE `sensor_readings`
    ADD COLUMN IF NOT EXISTS `soil_moisture_1` DECIMAL(5,2) NULL DEFAULT NULL
        COMMENT 'Probe 1 calibrated percentage, NULL when the read failed'
        AFTER `soil_moisture`,
    ADD COLUMN IF NOT EXISTS `soil_moisture_2` DECIMAL(5,2) NULL DEFAULT NULL
        COMMENT 'Probe 2 calibrated percentage, NULL when not fitted or the read failed'
        AFTER `soil_moisture_1`,
    ADD COLUMN IF NOT EXISTS `soil_raw_2` INT NULL DEFAULT NULL
        COMMENT 'Probe 2 raw ADC value'
        AFTER `soil_raw`;

ALTER TABLE `sensor_readings`
    MODIFY COLUMN `soil_raw` INT NULL DEFAULT NULL
        COMMENT 'Probe 1 raw ADC value';

ALTER TABLE `sensor_readings`
    MODIFY COLUMN `soil_moisture` DECIMAL(5,2) NULL DEFAULT NULL
        COMMENT 'Primary reading used for irrigation decisions - the average of the fitted probes';

-- Existing single-probe rows: the reading they hold came from probe 1.
UPDATE `sensor_readings`
   SET `soil_moisture_1` = `soil_moisture`
 WHERE `soil_moisture_1` IS NULL
   AND `soil_moisture` IS NOT NULL;

-- ---------------------------------------------------------------------
-- 2. devices - how many probes each board actually has
-- ---------------------------------------------------------------------
ALTER TABLE `devices`
    ADD COLUMN IF NOT EXISTS `probe_count` TINYINT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'Number of soil probes fitted (1 or 2)'
        AFTER `location`,
    ADD COLUMN IF NOT EXISTS `has_dht22` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Whether a DHT22 is fitted; when 0 the temperature and humidity cards are hidden'
        AFTER `probe_count`;

-- ---------------------------------------------------------------------
-- 3. Align the primary device with the firmware
--
--    AgriSense.ino identifies itself as DEVICE_ID = "AGRISENSE-ESP32-01"
--    and uses two probes. The API rejects a payload whose identifier does
--    not match the API key's device, so these must agree.
-- ---------------------------------------------------------------------
UPDATE `devices`
   SET `device_code` = 'AGRISENSE-ESP32-01',
       `probe_count` = 2,
       `has_dht22`   = 1
 WHERE `device_code` = 'ESP32-001';

-- ---------------------------------------------------------------------
-- 4. Thresholds matching the firmware's raw ADC set points
--
--    AgriSense.ino and the dashboard both work in percentages, because
--    that is what a farmer can reason about. The sketch's fallbacks are:
--        DEFAULT_MOISTURE_THRESHOLD = 20.0   (pump ON at or below 20%)
--        DEFAULT_TARGET_MOISTURE    = 60.0   (pump OFF at or above 60%)
--        SOIL_RAW_DRY = 3200 (0%)   SOIL_RAW_WET = 1200 (100%)
--
--    Both sides convert raw ADC counts with the same formula, so a figure
--    means the same thing on the board and on screen:
--
--        percent = (3200 - raw) * 100 / (3200 - 1200)
--
--        raw 2800 -> 20%    raw 2000 -> 60%
--
--    Change these in Irrigation Settings at any time; the firmware adopts
--    the new values on its next check-in without a re-flash.
-- ---------------------------------------------------------------------
UPDATE `irrigation_settings` s
  JOIN `devices` d ON d.id = s.device_id
   SET s.`moisture_threshold` = 20.00,
       s.`target_moisture`    = 60.00,
       s.`dry_value`          = 3200,
       s.`wet_value`          = 1200
 WHERE d.`device_code` = 'AGRISENSE-ESP32-01';

-- ---------------------------------------------------------------------
-- 5. Confirmation
-- ---------------------------------------------------------------------
SELECT d.device_code, d.device_name, d.probe_count, d.has_dht22,
       s.moisture_threshold, s.target_moisture, s.dry_value, s.wet_value,
       s.max_pump_runtime, s.reading_interval
  FROM devices d
  LEFT JOIN irrigation_settings s ON s.device_id = d.id;
