-- =====================================================================
--  AgriSense - Migration: store Wi-Fi signal and uptime from heartbeats
--
--  esp32/AgriSense.ino has always sent `rssi` and `uptime_seconds` in
--  every POST /api/device/status heartbeat, but the backend had nowhere
--  to put them and silently discarded both. This adds the two columns
--  and lets DeviceApi::status() persist them like it already does for
--  firmware_version and ip_address.
--
--  Safe to run on a database that already holds live devices: it only
--  ADDS columns: existing rows get NULL until their next heartbeat.
--
--  Idempotent - IF NOT EXISTS guards the ADD COLUMN, so a second run is a
--  no-op rather than an error that aborts partway.
--  (IF NOT EXISTS on ALTER TABLE is MariaDB 10.0+; on MySQL 8 run this
--  once and only once.)
--
--  Run with:
--      mysql -u root -p agrisense < database/migration_device_status.sql
--
--  A fresh install does NOT need this - database/agrisense.sql already
--  contains everything below.
-- =====================================================================

USE `agrisense`;

ALTER TABLE `devices`
    ADD COLUMN IF NOT EXISTS `wifi_rssi` SMALLINT NULL DEFAULT NULL
        COMMENT 'Wi-Fi signal strength in dBm, from the last heartbeat'
        AFTER `firmware_version`;

ALTER TABLE `devices`
    ADD COLUMN IF NOT EXISTS `uptime_seconds` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Seconds since the board booted, from the last heartbeat'
        AFTER `wifi_rssi`;
