-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 14, 2026 at 06:58 AM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `agrisense`
--

-- --------------------------------------------------------

--
-- Table structure for table `alerts`
--

CREATE TABLE `alerts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `device_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'NULL for system wide alerts',
  `alert_type` varchar(50) NOT NULL COMMENT 'low_moisture | pump_on | pump_off | device_offline | sensor_failure | wifi_failure | abnormal_reading | ...',
  `message` varchar(255) NOT NULL,
  `severity` enum('info','warning','critical') NOT NULL DEFAULT 'info',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `source` enum('device','demo') NOT NULL DEFAULT 'device',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `alerts`
--

INSERT INTO `alerts` (`id`, `device_id`, `alert_type`, `message`, `severity`, `is_read`, `source`, `created_at`) VALUES
(9, 1, 'pump_on', 'Pump started on Field A - Rice Paddy (automatic) at 7.2% soil moisture.', 'info', 0, 'device', '2026-09-14 11:45:29'),
(10, 1, 'low_moisture', 'Soil moisture at Field A - Rice Paddy is 7.2%, at or below the 20.0% threshold.', 'warning', 0, 'device', '2026-09-14 11:45:29'),
(11, 1, 'pump_off', 'Pump stopped on Field A - Rice Paddy after 40s (Target moisture reached).', 'info', 0, 'device', '2026-09-14 11:46:09'),
(12, 1, 'sensor_failure', 'Field A - Rice Paddy could not read temperature and canopy temperature. The last valid value is still shown.', 'warning', 0, 'device', '2026-09-14 11:49:18'),
(13, 1, 'pump_on', 'Pump started on Field A - Rice Paddy (automatic) at 0.0% soil moisture.', 'info', 0, 'device', '2026-09-14 11:50:38'),
(14, 1, 'probe_mismatch', 'The two soil probes on Field A - Rice Paddy disagree by 31.1 points (31.1% and 0.0%). Check that both are firmly seated.', 'warning', 0, 'device', '2026-09-14 11:50:48'),
(15, 1, 'pump_off', 'Pump stopped on Field A - Rice Paddy after 15s (Target moisture reached).', 'info', 0, 'device', '2026-09-14 11:50:54'),
(16, 1, 'pump_on', 'Pump started on Field A - Rice Paddy (automatic) at 10.2% soil moisture.', 'info', 0, 'device', '2026-09-14 12:01:04'),
(17, 1, 'pump_off', 'Pump stopped on Field A - Rice Paddy after 30s (Target moisture reached).', 'info', 0, 'device', '2026-09-14 12:01:34');

-- --------------------------------------------------------

--
-- Table structure for table `devices`
--

CREATE TABLE `devices` (
  `id` int(10) UNSIGNED NOT NULL,
  `device_name` varchar(100) NOT NULL,
  `device_code` varchar(50) NOT NULL COMMENT 'Human readable id used by the firmware, e.g. ESP32-001',
  `api_key_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'SHA-256 of the API key the ESP32 sends in the X-API-Key header',
  `mac_address` varchar(17) DEFAULT NULL,
  `location` varchar(150) DEFAULT NULL,
  `probe_count` tinyint(3) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Number of soil probes fitted (1 or 2)',
  `has_dht22` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'DHT22 fitted: supplies air temperature and humidity',
  `has_mlx90614` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'MLX90614 IR thermometer fitted: supplies air temperature and canopy temperature',
  `ip_address` varchar(45) DEFAULT NULL,
  `firmware_version` varchar(20) DEFAULT NULL,
  `wifi_rssi` smallint(6) DEFAULT NULL COMMENT 'Wi-Fi signal strength in dBm, from the last heartbeat',
  `uptime_seconds` int(10) UNSIGNED DEFAULT NULL COMMENT 'Seconds since the board booted, from the last heartbeat',
  `status` enum('active','inactive','maintenance') NOT NULL DEFAULT 'active',
  `last_seen` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `devices`
--

INSERT INTO `devices` (`id`, `device_name`, `device_code`, `api_key_hash`, `mac_address`, `location`, `probe_count`, `has_dht22`, `has_mlx90614`, `ip_address`, `firmware_version`, `wifi_rssi`, `uptime_seconds`, `status`, `last_seen`, `created_at`, `updated_at`) VALUES
(1, 'Field A - Rice Paddy', 'AGRISENSE-ESP32-01', 'aadf86255e938964c6df6196d009edd321c8efb5f10ca22255a38af6e0c2d11f', 'A0:B7:65:1C:2D:3E', 'Barangay San Isidro - Field A', 2, 0, 1, '192.168.1.19', '4.1.0', NULL, NULL, 'active', '2026-09-14 12:02:18', '2026-09-14 10:54:23', '2026-09-14 12:02:18'),
(2, 'Field B - Rice Seedbed', 'ESP32-002', '9bf11068acf6c0f3f514bcf26927f9501cfd90ed86d7d9c3bf51e8be1e04a524', 'A0:B7:65:1C:2D:4F', 'Barangay San Isidro - Field B', 1, 1, 0, NULL, NULL, NULL, NULL, 'inactive', NULL, '2026-09-14 10:54:23', '2026-09-14 10:54:23');

-- --------------------------------------------------------

--
-- Table structure for table `irrigation_logs`
--

CREATE TABLE `irrigation_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `device_id` int(10) UNSIGNED NOT NULL,
  `trigger_type` enum('automatic','manual') NOT NULL,
  `action` enum('start','stop') NOT NULL DEFAULT 'start',
  `soil_moisture` decimal(5,2) DEFAULT NULL COMMENT 'Moisture reading that opened the cycle',
  `end_moisture` decimal(5,2) DEFAULT NULL COMMENT 'Moisture reading that closed the cycle',
  `threshold` decimal(5,2) DEFAULT NULL COMMENT 'Threshold in force when the cycle opened',
  `stop_reason` enum('target_reached','manual_stop','max_runtime','sensor_failure','device_reset','reconciled_unknown') DEFAULT NULL,
  `started_at` datetime NOT NULL,
  `ended_at` datetime DEFAULT NULL,
  `duration_seconds` int(10) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'User who requested a manual cycle, NULL for automatic',
  `source` enum('device','demo') NOT NULL DEFAULT 'device',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `irrigation_logs`
--

INSERT INTO `irrigation_logs` (`id`, `device_id`, `trigger_type`, `action`, `soil_moisture`, `end_moisture`, `threshold`, `stop_reason`, `started_at`, `ended_at`, `duration_seconds`, `created_by`, `source`, `created_at`) VALUES
(16, 1, 'automatic', 'stop', 7.20, 88.70, 20.00, 'target_reached', '2026-09-14 11:45:29', '2026-09-14 11:46:09', 40, NULL, 'device', '2026-09-14 11:45:29'),
(17, 1, 'automatic', 'stop', 0.00, 74.40, 20.00, 'target_reached', '2026-09-14 11:50:38', '2026-09-14 11:50:53', 15, NULL, 'device', '2026-09-14 11:50:38'),
(18, 1, 'automatic', 'stop', 10.20, 75.00, 20.00, 'target_reached', '2026-09-14 12:01:04', '2026-09-14 12:01:34', 30, NULL, 'device', '2026-09-14 12:01:04');

-- --------------------------------------------------------

--
-- Table structure for table `irrigation_settings`
--

CREATE TABLE `irrigation_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `device_id` int(10) UNSIGNED NOT NULL,
  `moisture_threshold` decimal(5,2) NOT NULL DEFAULT 30.00 COMMENT 'Pump ON at or below this %',
  `target_moisture` decimal(5,2) NOT NULL DEFAULT 60.00 COMMENT 'Pump OFF at or above this %',
  `dry_value` int(11) NOT NULL DEFAULT 3200 COMMENT 'Raw ADC reading in dry air (0% moisture)',
  `wet_value` int(11) NOT NULL DEFAULT 1200 COMMENT 'Raw ADC reading in water (100% moisture)',
  `auto_irrigation` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = AUTO mode, 0 = MANUAL mode',
  `max_pump_runtime` int(10) UNSIGNED NOT NULL DEFAULT 300 COMMENT 'Hard safety cut-off, seconds',
  `reading_interval` int(10) UNSIGNED NOT NULL DEFAULT 30 COMMENT 'Seconds between sensor reads / uploads',
  `min_cycle_interval` int(10) UNSIGNED NOT NULL DEFAULT 600 COMMENT 'Minimum rest between two irrigation cycles, seconds',
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `irrigation_settings`
--

INSERT INTO `irrigation_settings` (`id`, `device_id`, `moisture_threshold`, `target_moisture`, `dry_value`, `wet_value`, `auto_irrigation`, `max_pump_runtime`, `reading_interval`, `min_cycle_interval`, `updated_by`, `updated_at`) VALUES
(1, 1, 20.00, 60.00, 3200, 1200, 1, 300, 30, 60, 1, '2026-09-14 11:30:52'),
(2, 2, 35.00, 65.00, 3150, 1180, 1, 240, 60, 900, 1, '2026-09-14 10:54:23');

-- --------------------------------------------------------

--
-- Table structure for table `pump_commands`
--

CREATE TABLE `pump_commands` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `device_id` int(10) UNSIGNED NOT NULL,
  `command` enum('PUMP_ON','PUMP_OFF','MODE_AUTO','MODE_MANUAL') NOT NULL,
  `status` enum('pending','delivered','acknowledged','expired','failed') NOT NULL DEFAULT 'pending',
  `requested_by` int(10) UNSIGNED DEFAULT NULL,
  `result_message` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `delivered_at` datetime DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sensor_readings`
--

CREATE TABLE `sensor_readings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `device_id` int(10) UNSIGNED NOT NULL,
  `soil_moisture` decimal(5,2) DEFAULT NULL COMMENT 'Primary reading used for irrigation decisions - the average of the fitted probes',
  `soil_moisture_1` decimal(5,2) DEFAULT NULL COMMENT 'Probe 1 calibrated percentage, NULL when the read failed',
  `soil_moisture_2` decimal(5,2) DEFAULT NULL COMMENT 'Probe 2 calibrated percentage, NULL when not fitted or the read failed',
  `soil_raw` int(11) DEFAULT NULL COMMENT 'Probe 1 raw ADC value',
  `soil_raw_2` int(11) DEFAULT NULL COMMENT 'Probe 2 raw ADC value',
  `temperature` decimal(5,2) DEFAULT NULL COMMENT 'Air temperature in Celsius (DHT22, or the MLX90614 ambient channel), NULL when the read failed',
  `leaf_temperature` decimal(5,2) DEFAULT NULL COMMENT 'Canopy surface temperature in Celsius from the MLX90614 object channel, NULL when not fitted or the read failed',
  `humidity` decimal(5,2) DEFAULT NULL COMMENT 'Relative humidity %, NULL when the DHT22 read failed or no DHT22 is fitted',
  `pump_status` enum('ON','OFF') NOT NULL DEFAULT 'OFF',
  `mode` enum('AUTO','MANUAL') NOT NULL DEFAULT 'AUTO',
  `source` enum('device','demo') NOT NULL DEFAULT 'device',
  `recorded_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sensor_readings`
--

INSERT INTO `sensor_readings` (`id`, `device_id`, `soil_moisture`, `soil_moisture_1`, `soil_moisture_2`, `soil_raw`, `soil_raw_2`, `temperature`, `leaf_temperature`, `humidity`, `pump_status`, `mode`, `source`, `recorded_at`) VALUES
(1024, 1, 36.20, 35.60, 36.80, 2489, 2465, 37.90, 35.80, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:23:48'),
(1025, 1, 36.00, 35.50, 36.50, 2490, 2471, 36.90, 35.90, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:24:18'),
(1026, 1, 36.80, 36.30, 37.30, 2475, 2455, 36.90, 34.20, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:24:54'),
(1027, 1, 37.00, 36.30, 37.70, 2475, 2447, 37.10, 36.10, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:25:18'),
(1028, 1, 36.90, 36.40, 37.50, 2473, 2450, 37.80, 37.20, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:25:48'),
(1029, 1, 36.60, 36.00, 37.30, 2481, 2455, 37.10, 36.00, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:26:18'),
(1030, 1, 36.70, 36.10, 37.30, 2479, 2455, 37.40, 36.50, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:26:48'),
(1031, 1, 36.60, 36.00, 37.30, 2481, 2454, 37.80, 36.80, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:27:18'),
(1032, 1, 36.90, 36.30, 37.50, 2474, 2450, 37.90, 35.80, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:27:48'),
(1033, 1, 37.50, 37.10, 37.90, 2458, 2442, 37.60, 35.10, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:28:18'),
(1034, 1, 37.20, 36.60, 37.70, 2468, 2446, 37.60, 34.50, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:28:48'),
(1035, 1, 36.90, 36.40, 37.60, 2473, 2449, 37.40, 34.10, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:29:18'),
(1036, 1, 36.80, 36.20, 37.40, 2477, 2452, 37.00, 33.30, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:29:48'),
(1037, 1, 36.70, 36.10, 37.30, 2478, 2454, 36.50, 32.50, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:30:18'),
(1038, 1, 36.70, 36.30, 37.20, 2475, 2456, 36.20, 32.80, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:30:48'),
(1039, 1, 36.70, 36.20, 37.30, 2476, 2455, 36.00, 32.50, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:31:48'),
(1040, 1, 36.40, 36.00, 36.90, 2481, 2462, 36.00, 33.20, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:32:18'),
(1041, 1, 36.30, 35.80, 36.90, 2484, 2463, 36.10, 33.80, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:32:48'),
(1042, 1, 35.70, 35.30, 36.20, 2495, 2476, 36.20, 33.40, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:33:18'),
(1043, 1, 35.60, 35.20, 36.10, 2497, 2479, 36.20, 34.90, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:33:48'),
(1044, 1, 36.80, 36.30, 37.40, 2475, 2453, 36.80, 36.20, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:34:18'),
(1045, 1, 36.90, 36.40, 37.40, 2473, 2452, 37.30, 36.60, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:34:48'),
(1046, 1, 38.00, 37.50, 38.50, 2451, 2431, 37.80, 36.20, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:35:18'),
(1047, 1, 37.80, 37.40, 38.30, 2453, 2435, 38.00, 36.10, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:35:48'),
(1048, 1, 38.30, 38.00, 38.60, 2441, 2428, 38.20, 36.30, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:36:18'),
(1049, 1, 38.40, 37.80, 38.90, 2444, 2422, 38.30, 37.20, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:36:48'),
(1050, 1, 38.10, 37.70, 38.60, 2447, 2429, 38.70, 37.20, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:37:18'),
(1051, 1, 37.80, 37.40, 38.20, 2452, 2437, 38.90, 36.20, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:37:48'),
(1052, 1, 37.60, 37.20, 38.10, 2456, 2439, 38.90, 35.00, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:38:18'),
(1053, 1, 37.20, 36.90, 37.60, 2463, 2449, 38.50, 33.90, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:38:48'),
(1054, 1, 37.80, 37.50, 38.00, 2450, 2440, 37.70, 32.40, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:39:18'),
(1055, 1, 37.90, 37.40, 38.30, 2452, 2434, 36.70, 32.50, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:39:48'),
(1056, 1, 38.00, 37.70, 38.20, 2446, 2436, 35.40, 33.40, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:40:18'),
(1057, 1, 37.70, 37.40, 38.00, 2453, 2441, 35.90, 35.10, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:40:48'),
(1058, 1, 38.10, 37.80, 38.30, 2444, 2434, 36.60, 34.70, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:41:18'),
(1059, 1, 38.10, 37.90, 38.30, 2442, 2434, 36.90, 33.60, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:41:48'),
(1060, 1, 37.90, 37.70, 38.10, 2447, 2438, 37.00, 32.80, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:42:18'),
(1061, 1, 38.10, 38.10, 38.20, 2439, 2436, 36.20, 33.10, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:42:48'),
(1062, 1, 37.90, 37.90, 38.10, 2443, 2439, 35.90, 33.30, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:43:18'),
(1063, 1, 37.80, 37.50, 38.20, 2451, 2437, 36.50, 33.40, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:43:48'),
(1064, 1, 37.80, 37.60, 38.00, 2449, 2440, 36.90, 33.00, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:44:18'),
(1065, 1, 38.70, 38.50, 39.00, 2431, 2421, 36.60, 32.50, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:44:48'),
(1066, 1, 38.00, 37.80, 38.20, 2444, 2436, 36.80, 33.30, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:45:18'),
(1067, 1, 7.20, 1.60, 12.80, 3169, 2945, 36.80, 33.10, NULL, 'ON', 'AUTO', 'device', '2026-09-14 11:45:29'),
(1068, 1, 4.90, 0.20, 9.60, 3196, 3008, 36.70, 33.30, NULL, 'ON', 'AUTO', 'device', '2026-09-14 11:45:48'),
(1069, 1, 88.70, 85.00, 92.40, 1500, 1353, 36.80, 33.10, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:46:09'),
(1070, 1, 68.10, 64.70, 71.50, 1906, 1771, 36.90, 33.40, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:46:18'),
(1071, 1, 61.20, 57.80, 64.60, 2045, 1908, 37.20, 34.10, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:46:48'),
(1072, 1, 61.00, 57.60, 64.50, 2049, 1910, 36.60, 33.30, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:47:18'),
(1073, 1, 60.80, 57.10, 64.40, 2058, 1912, 36.40, 34.60, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:47:51'),
(1074, 1, 60.60, 57.00, 64.30, 2061, 1914, 36.30, 32.60, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:48:18'),
(1075, 1, 60.50, 56.80, 64.20, 2065, 1916, NULL, NULL, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:49:18'),
(1076, 1, 60.20, 56.50, 63.90, 2070, 1923, NULL, NULL, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:49:48'),
(1077, 1, 60.10, 56.40, 63.80, 2073, 1925, NULL, NULL, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:50:18'),
(1078, 1, 0.00, 0.00, 0.00, NULL, 3411, 36.90, 33.20, NULL, 'ON', 'AUTO', 'device', '2026-09-14 11:50:39'),
(1079, 1, 15.50, 31.10, 0.00, 2579, 3692, 36.90, 33.20, NULL, 'ON', 'AUTO', 'device', '2026-09-14 11:50:48'),
(1080, 1, 74.40, 75.40, 73.50, 1693, 1731, 36.60, 32.00, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:50:54'),
(1081, 1, 62.80, 63.00, 62.70, 1940, 1947, 35.70, 31.80, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:51:18'),
(1082, 1, 62.20, 62.50, 62.00, 1951, 1961, 34.70, 32.40, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:51:48'),
(1083, 1, 61.70, 61.80, 61.60, 1964, 1968, 34.20, 32.70, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:52:18'),
(1084, 1, 61.30, 61.40, 61.20, 1972, 1976, 34.60, 33.00, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:52:48'),
(1085, 1, 61.40, 61.70, 61.20, 1967, 1976, 35.00, 32.80, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:53:18'),
(1086, 1, 61.20, 61.40, 61.10, 1973, 1978, 35.10, 32.50, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:53:48'),
(1087, 1, 61.40, 61.70, 61.20, 1966, 1977, 35.20, 31.70, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:54:18'),
(1088, 1, 61.30, 61.30, 61.30, 1975, 1974, 35.40, 32.90, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:54:48'),
(1089, 1, 61.30, 61.30, 61.30, 1975, 1975, 35.60, 33.30, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:55:18'),
(1090, 1, 61.20, 61.30, 61.00, 1974, 1980, 35.60, 32.30, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:55:48'),
(1091, 1, 61.10, 61.30, 60.90, 1974, 1982, 35.60, 32.90, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:56:18'),
(1092, 1, 61.10, 61.30, 60.90, 1974, 1983, 35.70, 33.10, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:56:48'),
(1093, 1, 61.20, 61.50, 61.00, 1971, 1981, 36.10, 32.80, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:57:18'),
(1094, 1, 61.10, 61.30, 60.90, 1975, 1983, 36.00, 32.80, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:57:48'),
(1095, 1, 61.00, 61.20, 60.90, 1976, 1983, 35.80, 32.20, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:58:18'),
(1096, 1, 61.40, 61.60, 61.20, 1969, 1977, 35.50, 31.90, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:58:48'),
(1097, 1, 64.00, 60.20, 67.70, 1996, 1846, 35.60, 32.10, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:59:18'),
(1098, 1, 63.10, 59.10, 67.20, 2018, 1857, 35.60, 31.60, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 11:59:48'),
(1099, 1, 62.70, 58.60, 66.80, 2028, 1865, 35.40, 32.30, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 12:00:18'),
(1100, 1, 25.20, 31.90, 18.50, 2562, 2831, 35.00, 31.90, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 12:00:48'),
(1101, 1, 10.20, 20.30, 0.00, 2794, 3550, 35.20, 32.50, NULL, 'ON', 'AUTO', 'device', '2026-09-14 12:01:04'),
(1102, 1, 8.60, 17.20, 0.00, 2857, 3786, 35.20, 32.10, NULL, 'ON', 'AUTO', 'device', '2026-09-14 12:01:18'),
(1103, 1, 75.00, 74.10, 75.90, 1718, 1682, 35.20, 32.90, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 12:01:35'),
(1104, 1, 96.10, 99.50, 92.80, 1211, 1344, 35.30, 31.70, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 12:01:48'),
(1105, 1, 54.20, 52.90, 55.50, 2142, 2091, 35.40, 32.60, NULL, 'OFF', 'AUTO', 'device', '2026-09-14 12:02:18');

-- --------------------------------------------------------

--
-- Table structure for table `system_logs`
--

CREATE TABLE `system_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_logs`
--

INSERT INTO `system_logs` (`id`, `user_id`, `action`, `description`, `ip_address`, `created_at`) VALUES
(1, 1, 'login', 'Administrator signed in.', '127.0.0.1', '2026-09-14 07:54:24'),
(2, 1, 'settings_update', 'Updated irrigation settings for AGRISENSE-ESP32-01 (threshold 20%, target 60%).', '127.0.0.1', '2026-09-14 07:54:24'),
(3, 2, 'login', 'Farmer signed in.', '127.0.0.1', '2026-09-14 08:54:24'),
(4, 2, 'pump_control', 'Queued manual command PUMP_ON for AGRISENSE-ESP32-01.', '127.0.0.1', '2026-09-14 08:54:24'),
(5, 2, 'pump_control', 'Queued manual command PUMP_OFF for AGRISENSE-ESP32-01.', '127.0.0.1', '2026-09-14 09:54:24'),
(6, 1, 'device_create', 'Registered device ESP32-002 (Field B - Rice Seedbed).', '127.0.0.1', '2026-09-13 10:54:24'),
(7, NULL, 'login_failed', 'Failed sign-in for username \"admin\".', '::1', '2026-09-14 10:54:53'),
(8, 1, 'login', 'System Administrator signed in.', '::1', '2026-09-14 10:55:04'),
(9, 1, 'password_change', 'Changed their own password.', '::1', '2026-09-14 10:55:35'),
(10, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:00:53'),
(11, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:00:53'),
(12, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:01:04'),
(13, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:01:18'),
(14, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:01:18'),
(15, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:01:33'),
(16, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:01:49'),
(17, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:01:49'),
(18, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:02:04'),
(19, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:02:18'),
(20, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:02:19'),
(21, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:02:34'),
(22, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:02:49'),
(23, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:02:49'),
(24, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:03:04'),
(25, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:03:19'),
(26, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:03:19'),
(27, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:03:34'),
(28, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:03:48'),
(29, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:03:49'),
(30, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:04:04'),
(31, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:04:21'),
(32, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:04:22'),
(33, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:04:33'),
(34, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:04:48'),
(35, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:04:49'),
(36, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:05:04'),
(37, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:05:18'),
(38, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:05:19'),
(39, 1, 'purge_demo_data', 'Removed sample data: 672 readings, 13 irrigation cycles, 8 alerts.', '::1', '2026-09-14 11:06:12'),
(40, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:06:15'),
(41, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:06:30'),
(42, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:06:30'),
(43, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:06:45'),
(44, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:07:00'),
(45, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:07:00'),
(46, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:07:36'),
(47, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:07:39'),
(48, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:08:00'),
(49, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:08:00'),
(50, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:08:21'),
(51, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:08:30'),
(52, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:08:30'),
(53, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:08:48'),
(54, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:09:08'),
(55, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:09:35'),
(56, 1, 'login', 'System Administrator signed in.', '192.168.1.15', '2026-09-14 11:09:36'),
(57, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:09:45'),
(58, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:10:00'),
(59, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:10:00'),
(60, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:10:15'),
(61, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:10:30'),
(62, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:10:30'),
(63, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:10:45'),
(64, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:11:00'),
(65, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:11:00'),
(66, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:11:15'),
(67, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:11:30'),
(68, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:11:31'),
(69, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:11:45'),
(70, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:12:00'),
(71, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:12:00'),
(72, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:12:15'),
(73, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:12:30'),
(74, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:12:30'),
(75, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:12:45'),
(76, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:13:00'),
(77, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:13:00'),
(78, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:13:15'),
(79, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:13:30'),
(80, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:13:30'),
(81, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:13:45'),
(82, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:14:00'),
(83, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:14:00'),
(84, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:14:15'),
(85, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:14:30'),
(86, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:14:30'),
(87, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:14:45'),
(88, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:15:00'),
(89, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:15:30'),
(90, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:15:30'),
(91, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:15:45'),
(92, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:16:00'),
(93, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:16:00'),
(94, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:16:15'),
(95, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:17:04'),
(96, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:17:13'),
(97, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:17:37'),
(98, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:17:37'),
(99, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:18:04'),
(100, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:18:04'),
(101, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:18:34'),
(102, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:18:35'),
(103, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:19:05'),
(104, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:19:05'),
(105, 1, 'device_key_rotate', 'Generated a new API key for AGRISENSE-ESP32-01.', '192.168.1.15', '2026-09-14 11:19:33'),
(106, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:19:34'),
(107, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:19:34'),
(108, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:20:04'),
(109, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:20:34'),
(110, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:20:34'),
(111, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:21:07'),
(112, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:21:07'),
(113, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:21:34'),
(114, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:21:34'),
(115, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:22:04'),
(116, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:22:04'),
(117, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:22:34'),
(118, NULL, 'api_unauthorized', 'Device API request with an unrecognised API key.', '192.168.1.19', '2026-09-14 11:22:34'),
(119, 1, 'logout', 'System Administrator signed out.', '192.168.1.15', '2026-09-14 11:23:49'),
(120, 1, 'login', 'System Administrator signed in.', '192.168.1.15', '2026-09-14 11:24:04'),
(121, 1, 'logout', 'System Administrator signed out.', '192.168.1.15', '2026-09-14 11:27:35'),
(122, 1, 'login', 'System Administrator signed in.', '192.168.1.15', '2026-09-14 11:28:16');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'password_hash() output - never plain text',
  `role` enum('admin','farmer') NOT NULL DEFAULT 'farmer',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `can_control_pump` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Farmers need this flag to operate the pump; admins always may',
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'When 1 the user is held on the password page until they set a new one',
  `password_changed_at` datetime DEFAULT NULL COMMENT 'Sessions opened before this moment are rejected',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `username`, `password`, `role`, `status`, `can_control_pump`, `must_change_password`, `password_changed_at`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'System Administrator', 'admin', '$2y$10$TnFyQlQ.5qcgSL9X9PnKWOf46AZSV7gloiDXhSX.31hLKgLUHTbay', 'admin', 'active', 1, 0, '2026-09-14 10:55:35', '2026-09-14 11:28:16', '2026-09-14 10:54:23', '2026-09-14 11:28:16'),
(2, 'Maria Santos', 'farmer', '$2y$10$8a5zxFmUJjON8d07oaqE0ehcKZ4Ivv9Cz9cfWfh1wxj9KXarekPIm', 'farmer', 'active', 1, 1, NULL, NULL, '2026-09-14 10:54:23', '2026-09-14 10:54:23'),
(3, 'Juan dela Cruz', 'juan', '$2y$10$8a5zxFmUJjON8d07oaqE0ehcKZ4Ivv9Cz9cfWfh1wxj9KXarekPIm', 'farmer', 'active', 0, 1, NULL, NULL, '2026-09-14 10:54:23', '2026-09-14 10:54:23');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `alerts`
--
ALTER TABLE `alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_alerts_device_time` (`device_id`,`created_at`),
  ADD KEY `idx_alerts_unread` (`is_read`,`created_at`),
  ADD KEY `idx_alerts_severity` (`severity`),
  ADD KEY `idx_alerts_type_time` (`alert_type`,`created_at`),
  ADD KEY `idx_alerts_source` (`source`);

--
-- Indexes for table `devices`
--
ALTER TABLE `devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_devices_code` (`device_code`),
  ADD UNIQUE KEY `uq_devices_api_key_hash` (`api_key_hash`),
  ADD UNIQUE KEY `uq_devices_mac` (`mac_address`),
  ADD KEY `idx_devices_status` (`status`),
  ADD KEY `idx_devices_last_seen` (`last_seen`);

--
-- Indexes for table `irrigation_logs`
--
ALTER TABLE `irrigation_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_logs_device_start` (`device_id`,`started_at`),
  ADD KEY `idx_logs_open_cycle` (`device_id`,`ended_at`),
  ADD KEY `idx_logs_trigger` (`trigger_type`),
  ADD KEY `idx_logs_source` (`source`),
  ADD KEY `fk_logs_user` (`created_by`);

--
-- Indexes for table `irrigation_settings`
--
ALTER TABLE `irrigation_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_settings_device` (`device_id`),
  ADD KEY `fk_settings_user` (`updated_by`);

--
-- Indexes for table `pump_commands`
--
ALTER TABLE `pump_commands`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cmd_device_status` (`device_id`,`status`,`created_at`),
  ADD KEY `fk_cmd_user` (`requested_by`);

--
-- Indexes for table `sensor_readings`
--
ALTER TABLE `sensor_readings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_readings_device_time` (`device_id`,`recorded_at`),
  ADD KEY `idx_readings_time` (`recorded_at`),
  ADD KEY `idx_readings_source` (`source`);

--
-- Indexes for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_syslogs_user_time` (`user_id`,`created_at`),
  ADD KEY `idx_syslogs_action` (`action`),
  ADD KEY `idx_syslogs_time` (`created_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD KEY `idx_users_role_status` (`role`,`status`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `alerts`
--
ALTER TABLE `alerts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `devices`
--
ALTER TABLE `devices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `irrigation_logs`
--
ALTER TABLE `irrigation_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `irrigation_settings`
--
ALTER TABLE `irrigation_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pump_commands`
--
ALTER TABLE `pump_commands`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sensor_readings`
--
ALTER TABLE `sensor_readings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1106;

--
-- AUTO_INCREMENT for table `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=123;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `alerts`
--
ALTER TABLE `alerts`
  ADD CONSTRAINT `fk_alerts_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `irrigation_logs`
--
ALTER TABLE `irrigation_logs`
  ADD CONSTRAINT `fk_logs_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_logs_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `irrigation_settings`
--
ALTER TABLE `irrigation_settings`
  ADD CONSTRAINT `fk_settings_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `pump_commands`
--
ALTER TABLE `pump_commands`
  ADD CONSTRAINT `fk_cmd_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cmd_user` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `sensor_readings`
--
ALTER TABLE `sensor_readings`
  ADD CONSTRAINT `fk_readings_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD CONSTRAINT `fk_syslogs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
