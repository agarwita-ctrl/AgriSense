-- =====================================================================
--  AgriSense - Migration: MLX90614 infrared thermometer, DHT22 removed
--
--  Brings an existing installation in line with esp32/AgriSense.ino
--  firmware 4.0.0, which changed the hardware on the board:
--
--    * the DHT22 was removed         -> no more humidity, from anywhere
--    * an MLX90614 was fitted        -> air temperature (its ambient
--                                       channel) plus canopy temperature
--                                       (its object channel), which is new
--    * the SIM900A was removed       -> nothing to migrate; the backend
--                                       never touched SMS
--
--  Safe to run on a database that already holds live readings: it only
--  ADDS columns, and existing rows keep their values. Old readings keep
--  their humidity - that history was real when it was recorded, and
--  nothing here deletes it.
--
--  Idempotent - every ADD COLUMN is guarded with IF NOT EXISTS, so a
--  second run is a no-op rather than an error that aborts partway.
--  (IF NOT EXISTS on ALTER TABLE is MariaDB 10.0+; on MySQL 8 run this
--  once and only once.)
--
--  Run with:
--      mysql -u root -p agrisense < database/migration_mlx90614.sql
--
--  A fresh install does NOT need this - database/agrisense.sql already
--  contains everything below.
-- =====================================================================

USE `agrisense`;

-- ---------------------------------------------------------------------
-- 1. sensor_readings - room for the canopy reading
--
--    temperature      keeps its meaning: AIR temperature. It now arrives
--                     from the MLX90614's ambient channel instead of the
--                     DHT22, so every existing chart, threshold and export
--                     keeps working unchanged.
--    leaf_temperature is the new one: the temperature of the plants
--                     themselves, measured without touching them.
--
--    The gap between the two is the useful figure. A well-watered plant
--    transpires and runs at or below air temperature; a water-stressed one
--    closes its stomata and heats up above it.
-- ---------------------------------------------------------------------
ALTER TABLE `sensor_readings`
    ADD COLUMN IF NOT EXISTS `leaf_temperature` DECIMAL(5,2) NULL DEFAULT NULL
        COMMENT 'Canopy surface temperature in Celsius from the MLX90614 object channel, NULL when not fitted or the read failed'
        AFTER `temperature`;

ALTER TABLE `sensor_readings`
    MODIFY COLUMN `temperature` DECIMAL(5,2) NULL DEFAULT NULL
        COMMENT 'Air temperature in Celsius (DHT22, or the MLX90614 ambient channel), NULL when the read failed';

ALTER TABLE `sensor_readings`
    MODIFY COLUMN `humidity` DECIMAL(5,2) NULL DEFAULT NULL
        COMMENT 'Relative humidity %, NULL when the DHT22 read failed or no DHT22 is fitted';

-- ---------------------------------------------------------------------
-- 2. devices - which sensors each board actually carries
--
--    has_dht22 is NOT dropped. Boards on the older firmware still have a
--    DHT22, and the two flags are independent:
--
--      air temperature   expected when has_dht22 OR has_mlx90614
--      humidity          expected when has_dht22
--      canopy temperature expected when has_mlx90614
--
--    A cleared flag hides that card on the dashboard and stops the
--    sensor_failure alerts for it, so a board is never reported as broken
--    for failing to send a measurement it has no sensor for.
-- ---------------------------------------------------------------------
ALTER TABLE `devices`
    ADD COLUMN IF NOT EXISTS `has_mlx90614` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'MLX90614 IR thermometer fitted: supplies air temperature and canopy temperature'
        AFTER `has_dht22`;

ALTER TABLE `devices`
    MODIFY COLUMN `has_dht22` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'DHT22 fitted: supplies air temperature and humidity';

-- ---------------------------------------------------------------------
-- 3. Match the primary board to what is physically fitted
--
--    AGRISENSE-ESP32-01 is the board esp32/AgriSense.ino is flashed to.
--    On firmware 4.0.0 it has an MLX90614 and no DHT22.
--
--    IMPORTANT: this statement is right only for boards you have actually
--    re-flashed and re-wired. Every other device keeps has_dht22 = 1 and
--    has_mlx90614 = 0. If you run several boards, set each one from
--    Devices -> Edit instead of widening the WHERE clause below - claiming
--    a sensor that is not fitted produces a permanently blank card, and
--    denying one that is fitted throws its readings away.
-- ---------------------------------------------------------------------
UPDATE `devices`
   SET `has_dht22`    = 0,
       `has_mlx90614` = 1
 WHERE `device_code` = 'AGRISENSE-ESP32-01';

-- ---------------------------------------------------------------------
-- 4. Confirmation
--
--    Check that each row describes the board on your bench. Anything
--    still showing has_dht22 = 1 will keep expecting humidity from it.
-- ---------------------------------------------------------------------
SELECT d.device_code, d.device_name, d.probe_count,
       d.has_dht22, d.has_mlx90614, d.firmware_version,
       (SELECT COUNT(*) FROM sensor_readings r
         WHERE r.device_id = d.id AND r.leaf_temperature IS NOT NULL) AS canopy_readings
  FROM devices d
 ORDER BY d.id;
