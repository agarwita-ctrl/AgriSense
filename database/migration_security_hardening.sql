-- =====================================================================
--  AgriSense - Migration: security hardening
--
--  Brings an existing installation in line with the hardened application:
--
--    1. Device API keys are stored as a SHA-256 hash, not plaintext.
--    2. Seeded accounts are forced to change their password at next login.
--    3. A password change can invalidate sessions opened before it.
--    4. A reconciled irrigation stop is distinguishable from a real one.
--
--  Safe to run on a database holding live readings - no reading, cycle,
--  alert or log row is touched.
--
--  Run with:
--      mysql -u root -p agrisense < database/migration_security_hardening.sql
--
--  A fresh install does NOT need this - database/agrisense.sql already
--  contains everything below.
--
--  AFTER RUNNING THIS: every device API key that existed before is still
--  accepted (the hashes are backfilled from the old plaintext), but those
--  keys were stored in the clear and must be treated as compromised. Go to
--  Devices -> Edit -> "Generate a new key" for each board and flash the new
--  key. The key is now displayed once, at the moment it is generated, and
--  never again.
-- =====================================================================

USE `agrisense`;

-- ---------------------------------------------------------------------
-- 1. devices - hash the API key
--
--    A 256-bit random key needs no slow KDF: there is nothing to brute
--    force and no password reuse to worry about, so a single SHA-256 is
--    the right primitive. The column is utf8mb4_bin so the comparison is
--    case sensitive and does not ignore trailing spaces, unlike the
--    utf8mb4_unicode_ci default the plaintext column used.
-- ---------------------------------------------------------------------
ALTER TABLE `devices`
    ADD COLUMN `api_key_hash` CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL
        COMMENT 'SHA-256 of the device API key. The key itself is shown once, at generation, and never stored.'
        AFTER `device_code`;

-- Existing keys keep working: hash what is already there.
UPDATE `devices`
   SET `api_key_hash` = SHA2(`api_key`, 256)
 WHERE `api_key_hash` IS NULL
   AND `api_key` IS NOT NULL;

ALTER TABLE `devices`
    MODIFY COLUMN `api_key_hash` CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
        COMMENT 'SHA-256 of the device API key. The key itself is shown once, at generation, and never stored.';

ALTER TABLE `devices`
    ADD UNIQUE KEY `uq_devices_api_key_hash` (`api_key_hash`);

-- The plaintext column is the thing being removed. Drop its unique index
-- first, then the column itself.
ALTER TABLE `devices` DROP INDEX `uq_devices_api_key`;
ALTER TABLE `devices` DROP COLUMN `api_key`;


-- ---------------------------------------------------------------------
-- 2 & 3. users - forced password rotation and session invalidation
-- ---------------------------------------------------------------------
ALTER TABLE `users`
    ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'When 1 the user is held on the password page until they set a new one'
        AFTER `can_control_pump`,
    ADD COLUMN `password_changed_at` DATETIME NULL DEFAULT NULL
        COMMENT 'Sessions opened before this moment are rejected'
        AFTER `must_change_password`;

-- Flag any account still carrying one of the three published seed hashes.
-- An account whose password has already been changed is left alone.
UPDATE `users`
   SET `must_change_password` = 1
 WHERE `password` IN (
     '$2y$10$.Z06D1b4NlrHRaLbRm4bxunfXVdjZWEZsunvT1FxStP2zzUNdIGEy',
     '$2y$10$8a5zxFmUJjON8d07oaqE0ehcKZ4Ivv9Cz9cfWfh1wxj9KXarekPIm'
 );


-- ---------------------------------------------------------------------
-- 4. irrigation_logs - a reconciled stop is not a successful one
--
--    When the device's explicit stop event is lost, the server closes the
--    cycle from the next telemetry poll. It used to record that as
--    'target_reached', which reads as a clean success - exactly wrong when
--    the reason the event went missing was the safety cut-off or a sensor
--    failure taking the network down with it.
-- ---------------------------------------------------------------------
ALTER TABLE `irrigation_logs`
    MODIFY COLUMN `stop_reason`
        ENUM('target_reached','manual_stop','max_runtime','sensor_failure','device_reset','reconciled_unknown')
        NULL DEFAULT NULL;


-- ---------------------------------------------------------------------
-- 5. Confirmation
-- ---------------------------------------------------------------------
SELECT d.id, d.device_code, d.status,
       CONCAT(LEFT(d.api_key_hash, 12), '...') AS api_key_hash
  FROM devices d
 ORDER BY d.id;

SELECT u.id, u.username, u.role, u.must_change_password, u.password_changed_at
  FROM users u
 ORDER BY u.id;
