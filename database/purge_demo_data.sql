-- =====================================================================
--  AgriSense - Remove the installer's sample data
--
--  Every row created by the seeder is tagged source = 'demo'. Rows that
--  came from real hardware are tagged source = 'device' and are NOT
--  touched by this script.
--
--  Run it once your ESP32 is sending real readings:
--      mysql -u root -p agrisense < database/purge_demo_data.sql
--
--  The same thing is available in the web interface, for administrators,
--  under System Logs -> "Remove sample data".
-- =====================================================================

USE `agrisense`;

SELECT 'Before' AS stage,
       (SELECT COUNT(*) FROM sensor_readings WHERE source = 'demo') AS demo_readings,
       (SELECT COUNT(*) FROM irrigation_logs WHERE source = 'demo') AS demo_cycles,
       (SELECT COUNT(*) FROM alerts          WHERE source = 'demo') AS demo_alerts;

START TRANSACTION;

DELETE FROM `sensor_readings` WHERE `source` = 'demo';
DELETE FROM `irrigation_logs` WHERE `source` = 'demo';
DELETE FROM `alerts`          WHERE `source` = 'demo';

COMMIT;

SELECT 'After' AS stage,
       (SELECT COUNT(*) FROM sensor_readings) AS readings_left,
       (SELECT COUNT(*) FROM irrigation_logs) AS cycles_left,
       (SELECT COUNT(*) FROM alerts)          AS alerts_left;
