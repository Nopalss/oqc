-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Nov 01, 2026 at 10:42 AM
-- Server version: 8.0.30
-- PHP Version: 8.3.36

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `oqc_db2`
--

-- --------------------------------------------------------

--
-- Table structure for table `app_settings`
--

CREATE TABLE `app_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `app_settings`
--

INSERT INTO `app_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('dashboard_target_ppm', '1000', '2026-10-26 03:08:52');

-- --------------------------------------------------------

--
-- Table structure for table `aql_standards`
--

CREATE TABLE `aql_standards` (
  `id` bigint UNSIGNED NOT NULL,
  `qty_min` int NOT NULL,
  `qty_max` int NOT NULL,
  `inspection_level` enum('G-I','G-II','G-III') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'G-II',
  `sample_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sample_size` int NOT NULL,
  `accept_number` int NOT NULL DEFAULT '0',
  `reject_number` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `aql_standards`
--

INSERT INTO `aql_standards` (`id`, `qty_min`, `qty_max`, `inspection_level`, `sample_code`, `sample_size`, `accept_number`, `reject_number`, `created_at`) VALUES
(1, 2, 8, 'G-I', 'A', 2, 0, 1, '2026-08-30 13:35:56'),
(2, 9, 15, 'G-I', 'A', 2, 0, 1, '2026-08-30 13:35:56'),
(3, 16, 25, 'G-I', 'B', 3, 0, 1, '2026-08-30 13:35:56'),
(4, 26, 50, 'G-I', 'C', 5, 0, 1, '2026-08-30 13:35:56'),
(5, 51, 90, 'G-I', 'C', 5, 0, 1, '2026-08-30 13:35:56'),
(6, 91, 150, 'G-I', 'D', 8, 0, 1, '2026-08-30 13:35:56'),
(7, 151, 280, 'G-I', 'E', 13, 0, 1, '2026-08-30 13:35:56'),
(8, 281, 500, 'G-I', 'F', 20, 0, 1, '2026-08-30 13:35:56'),
(9, 501, 1200, 'G-I', 'G', 32, 0, 1, '2026-08-30 13:35:56'),
(10, 1201, 3200, 'G-I', 'H', 50, 0, 1, '2026-08-30 13:35:56'),
(11, 3201, 10000, 'G-I', 'J', 80, 1, 2, '2026-08-30 13:35:56'),
(12, 10001, 35000, 'G-I', 'K', 125, 1, 2, '2026-08-30 13:35:56'),
(13, 35001, 150000, 'G-I', 'L', 200, 2, 3, '2026-08-30 13:35:56'),
(14, 150001, 500000, 'G-I', 'M', 315, 3, 4, '2026-08-30 13:35:56'),
(15, 500001, 99999999, 'G-I', 'N', 500, 5, 6, '2026-08-30 13:35:56'),
(16, 2, 8, 'G-II', 'A', 2, 0, 1, '2026-08-30 13:35:56'),
(17, 9, 15, 'G-II', 'B', 3, 0, 1, '2026-08-30 13:35:56'),
(18, 16, 25, 'G-II', 'C', 5, 0, 1, '2026-08-30 13:35:56'),
(19, 26, 50, 'G-II', 'D', 8, 0, 1, '2026-08-30 13:35:56'),
(20, 51, 90, 'G-II', 'E', 13, 0, 1, '2026-08-30 13:35:56'),
(21, 91, 150, 'G-II', 'F', 20, 0, 1, '2026-08-30 13:35:56'),
(22, 151, 280, 'G-II', 'G', 32, 0, 1, '2026-08-30 13:35:56'),
(23, 281, 500, 'G-II', 'H', 50, 0, 1, '2026-08-30 13:35:56'),
(24, 501, 1200, 'G-II', 'J', 80, 1, 2, '2026-08-30 13:35:56'),
(25, 1201, 3200, 'G-II', 'K', 125, 1, 2, '2026-08-30 13:35:56'),
(26, 3201, 10000, 'G-II', 'L', 200, 2, 3, '2026-08-30 13:35:56'),
(27, 10001, 35000, 'G-II', 'M', 315, 3, 4, '2026-08-30 13:35:56'),
(28, 35001, 150000, 'G-II', 'N', 500, 5, 6, '2026-08-30 13:35:56'),
(29, 150001, 500000, 'G-II', 'P', 800, 7, 8, '2026-08-30 13:35:56'),
(30, 500001, 99999999, 'G-II', 'Q', 1250, 10, 11, '2026-08-30 13:35:56'),
(31, 2, 8, 'G-III', 'B', 3, 0, 1, '2026-08-30 13:35:56'),
(32, 9, 15, 'G-III', 'C', 5, 0, 1, '2026-08-30 13:35:56'),
(33, 16, 25, 'G-III', 'D', 8, 0, 1, '2026-08-30 13:35:56'),
(34, 26, 50, 'G-III', 'E', 13, 0, 1, '2026-08-30 13:35:56'),
(35, 51, 90, 'G-III', 'F', 20, 0, 1, '2026-08-30 13:35:56'),
(36, 91, 150, 'G-III', 'G', 32, 0, 1, '2026-08-30 13:35:56'),
(37, 151, 280, 'G-III', 'H', 50, 0, 1, '2026-08-30 13:35:56'),
(38, 281, 500, 'G-III', 'J', 80, 1, 2, '2026-08-30 13:35:56'),
(39, 501, 1200, 'G-III', 'K', 125, 1, 2, '2026-08-30 13:35:56'),
(40, 1201, 3200, 'G-III', 'L', 200, 2, 3, '2026-08-30 13:35:56'),
(41, 3201, 10000, 'G-III', 'M', 315, 3, 4, '2026-08-30 13:35:56'),
(42, 10001, 35000, 'G-III', 'N', 500, 5, 6, '2026-08-30 13:35:56'),
(43, 35001, 150000, 'G-III', 'P', 800, 7, 8, '2026-08-30 13:35:56'),
(44, 150001, 500000, 'G-III', 'Q', 1250, 10, 11, '2026-08-30 13:35:56'),
(45, 500001, 99999999, 'G-III', 'R', 2000, 14, 15, '2026-08-30 13:35:56');

-- --------------------------------------------------------

--
-- Table structure for table `daily_inspection_data`
--

CREATE TABLE `daily_inspection_data` (
  `id` bigint UNSIGNED NOT NULL,
  `batch_id` bigint UNSIGNED DEFAULT NULL,
  `part_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `part_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lot_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cavity` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `inspecting_date` date NOT NULL,
  `status_inspect` enum('OK','NG') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'OK',
  `pic` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `daily_inspection_data`
--

INSERT INTO `daily_inspection_data` (`id`, `batch_id`, `part_code`, `part_name`, `lot_number`, `cavity`, `inspecting_date`, `status_inspect`, `pic`, `remark`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, '1', 'coba', '1', '1', '2026-11-01', 'OK', 'Admin', '', NULL, '2026-11-01 14:04:24', '2026-11-01 14:04:24'),
(2, 1, '1', 'coba', '2', '1', '2026-11-01', 'OK', 'Admin', '', NULL, '2026-11-01 14:04:24', '2026-11-01 14:04:24');

-- --------------------------------------------------------

--
-- Table structure for table `defect_threshold_targets`
--

CREATE TABLE `defect_threshold_targets` (
  `id` int NOT NULL,
  `target_type` enum('global_defect','specific_defect','global_part','specific_part') NOT NULL,
  `reference_id` int DEFAULT NULL,
  `threshold_val` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_by` int DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `defect_threshold_targets`
--

INSERT INTO `defect_threshold_targets` (`id`, `target_type`, `reference_id`, `threshold_val`, `created_by`, `updated_at`) VALUES
(2, 'specific_part', 249, '22.00', NULL, '2026-10-31 11:49:41'),
(3, 'specific_part', 104, '2.00', NULL, '2026-10-31 11:50:58');

-- --------------------------------------------------------

--
-- Table structure for table `defect_types`
--

CREATE TABLE `defect_types` (
  `id` bigint UNSIGNED NOT NULL,
  `code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `defect_types`
--

INSERT INTO `defect_types` (`id`, `code`, `name`, `created_at`) VALUES
(5, 'LS', 'LOW SIZE ( DIMENSION OUT )', '2026-09-29 15:10:53'),
(6, 'OS', 'OVER SIZE ( DIMENSION OUT )', '2026-09-29 15:10:53'),
(7, 'WC', 'WRONG CONTENT ( WRONG PART )', '2026-09-29 15:10:53'),
(8, 'WL', 'WRONG LABEL', '2026-09-29 15:10:53'),
(9, 'MP', 'MIXPART', '2026-09-29 15:10:53'),
(10, 'MS', 'MISSING SHAPE (BENTUK) / WRONG SHAPE / ABNORMAL SHAPE', '2026-09-29 15:10:53'),
(11, 'PTN', 'PROTECTIVE NG', '2026-09-29 15:10:53'),
(12, 'LKN', 'LOCKING NG ( NOT FUCTION LOCKING )', '2026-09-29 15:10:53'),
(13, 'HNC', 'HOLE NO CENTER', '2026-09-29 15:10:53'),
(14, 'MGC', 'MISSING COMPONENT (ASSY INJ + 2ND)', '2026-09-29 15:10:53'),
(15, 'BR', 'BROKEN (PIN BROKEN), (LIFTER BROKEN)', '2026-09-29 15:10:53'),
(16, 'GL', 'GATE LONG', '2026-09-29 15:10:53'),
(17, 'TM', 'TRIMMING', '2026-09-29 15:10:53'),
(18, 'MGP', 'MISSING PRINT', '2026-09-29 15:10:53'),
(19, 'WCR', 'WRONG CARAGHTER ( PRINT )', '2026-09-29 15:10:53'),
(20, 'MSDM', 'MISSING DOT MARKING ( Tanda Dot,Tanda Material,Tanda Lot tgl,bln,thn )', '2026-09-29 15:10:53'),
(21, 'MMT', 'MIXMATCH', '2026-09-29 15:10:53'),
(22, 'TW', 'TWISTING', '2026-09-29 15:10:53'),
(23, 'BG', 'BENDING', '2026-09-29 15:10:53'),
(24, 'BD', 'BLACK DOT', '2026-09-29 15:10:53'),
(25, 'BB', 'BUBBLE', '2026-09-29 15:10:53'),
(26, 'BC', 'BURRICON', '2026-09-29 15:10:53'),
(27, 'CR', 'CRACK', '2026-09-29 15:10:53'),
(28, 'CT', 'CONTAMINATION', '2026-09-29 15:10:53'),
(29, 'DT', 'DENTED', '2026-09-29 15:10:53'),
(30, 'DC', 'DISCOLOUR (MATERIAL) / FITTING TEST NOT MATCHING COLOUR', '2026-09-29 15:10:53'),
(31, 'FGM', 'FINGER MARK', '2026-09-29 15:10:53'),
(32, 'FL', 'FLASH', '2026-09-29 15:10:53'),
(33, 'FM', 'FLOW MARK', '2026-09-29 15:10:53'),
(34, 'FRM', 'FOREIGN MATERIAL', '2026-09-29 15:10:53'),
(35, 'GM', 'GASMARK', '2026-09-29 15:10:53'),
(36, 'HM', 'HAIR MARK', '2026-09-29 15:10:53'),
(37, 'OL', 'OILY', '2026-09-29 15:10:53'),
(38, 'OM', 'OIL MARK', '2026-09-29 15:10:53'),
(39, 'OC', 'OVER CUT', '2026-09-29 15:10:53'),
(40, 'PL', 'PARTING LINE', '2026-09-29 15:10:53'),
(41, 'PM', 'PIN MARK', '2026-09-29 15:10:53'),
(42, 'RM', 'RIB MARK', '2026-09-29 15:10:53'),
(43, 'SC', 'SCRATCH', '2026-09-29 15:10:53'),
(44, 'SN', 'SHINING ( SHINING EX PROTECTIVE,EX POLYBAG )-INJ,PSRAY,PRINTING', '2026-09-29 15:10:53'),
(45, 'SFC', 'SURFACE NG ( PERMUKAAN/TEXTURE PART NG ) / RUSTY MOLD / ETCHING', '2026-09-29 15:10:53'),
(46, 'SHM', 'SHORT MOLD', '2026-09-29 15:10:53'),
(47, 'SL', 'SILVER', '2026-09-29 15:10:53'),
(48, 'SM', 'SINK MARK', '2026-09-29 15:10:53'),
(49, 'UC', 'UNDER CUT', '2026-09-29 15:10:53'),
(50, 'WL', 'WELD LINE', '2026-09-29 15:10:53'),
(51, 'WD', 'WHITE DOT', '2026-09-29 15:10:53'),
(52, 'WM', 'WHITE MARK ( WHITE STRESS / STRESS MARK )', '2026-09-29 15:10:53'),
(53, 'DCP', 'DISCOLOUR PRINT ( INK )', '2026-09-29 15:10:53'),
(54, 'DPP', 'DISPOSITION PRINT ( UNPOSITION )', '2026-09-29 15:10:53'),
(55, 'FOP', 'PEEL OFF PRINT', '2026-09-29 15:10:53'),
(56, 'FOS', 'PEEL OFF SPRAY', '2026-09-29 15:10:53'),
(57, 'DCS', 'DISCOLOUR SPRAY', '2026-09-29 15:10:53'),
(58, 'AN', 'ASSY NG ( LOCKING NOT MAXIMAL )', '2026-09-29 15:10:53'),
(59, 'GP', 'GAP', '2026-09-29 15:10:53'),
(60, 'SDP', 'SHADOW PRINT', '2026-09-29 15:10:53'),
(61, 'STP', 'STEP', '2026-09-29 15:10:53'),
(62, 'TSN', 'TENSION NG', '2026-09-29 15:10:53'),
(63, 'LBN', 'LABEL NG', '2026-09-29 15:10:53'),
(64, 'MGL', 'MISSING LABEL', '2026-09-29 15:10:53'),
(65, 'PGN', 'PACKING NG (PE-BAG,P.FOAM,PARTISI,BOX),DIRTY POLYBAG, SALAH POSISI PACKING', '2026-09-29 15:10:53'),
(66, 'KB', 'KNOB BENDING', '2026-09-29 15:10:53'),
(67, 'KH', 'KNOB HARD', '2026-09-29 15:10:53'),
(68, 'DP', 'DOUBLE PRINT', '2026-09-29 15:10:53'),
(69, 'DP', 'DIRTY PRINT', '2026-09-29 15:10:53'),
(70, 'SP', 'SMEAR PRINT', '2026-09-29 15:10:53'),
(71, 'D', 'DIRTY', '2026-09-29 15:10:53'),
(72, 'DD', 'DIRTY DUST', '2026-09-29 15:10:53'),
(73, 'BP', 'BOLD PRINT', '2026-09-29 15:10:53'),
(74, 'DG', 'DIRTY GLUE', '2026-09-29 15:10:53'),
(75, 'DI', 'DIRTY INK', '2026-09-29 15:10:53'),
(76, 'DTP', 'DIRTY PROTECTIVE', '2026-09-29 15:10:53'),
(77, 'DTP', 'DIRTY PRINT (SMEAR PRINT)', '2026-09-29 15:10:53'),
(78, 'TP', 'THIN PRINT', '2026-09-29 15:10:53'),
(79, 'SHP', 'SHORT PRINT', '2026-09-29 15:10:53'),
(80, 'STP', 'SLANTING PRINT (MIRING/NO CENTER)', '2026-09-29 15:10:53'),
(81, 'BBP', 'BUBBLE PRINT', '2026-09-29 15:10:53'),
(82, 'BBS', 'BUBBLE SPRAY', '2026-09-29 15:10:53'),
(83, 'DTS', 'DIRTY SPRAY (PAINT)', '2026-09-29 15:10:53'),
(84, 'OS', 'OVER SPRAY (PAINT)', '2026-09-29 15:10:53'),
(85, 'US', 'UNDER SPRAY', '2026-09-29 15:10:53'),
(86, 'SDR', 'SOLDER NG', '2026-09-29 15:10:53'),
(87, 'WTM', 'WATER MARK', '2026-09-29 15:10:53'),
(88, 'MC', 'MIX CAVITY', '2026-09-29 15:10:53'),
(89, 'OVM', 'OVER MARKING ( PENCIL,CRAYON,SPIDOL,DLL )', '2026-09-29 15:10:53'),
(90, 'OVC', 'OVER COMPOUND ( OVER BAHAN FINISHING / 2ND )', '2026-09-29 15:10:53');

-- --------------------------------------------------------

--
-- Table structure for table `did_approval_requests`
--

CREATE TABLE `did_approval_requests` (
  `id` int NOT NULL,
  `inspection_session_id` int DEFAULT NULL,
  `part_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `part_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lot_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `requested_by` int NOT NULL,
  `requested_at` datetime NOT NULL,
  `request_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','approved','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `rejection_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `did_batches`
--

CREATE TABLE `did_batches` (
  `id` bigint UNSIGNED NOT NULL,
  `batch_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inspecting_date` date DEFAULT NULL,
  `import_method` enum('manual','excel_import') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `total_items` int NOT NULL DEFAULT '0',
  `ok_count` int NOT NULL DEFAULT '0',
  `ng_count` int NOT NULL DEFAULT '0',
  `created_by` bigint UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `did_batches`
--

INSERT INTO `did_batches` (`id`, `batch_name`, `inspecting_date`, `import_method`, `total_items`, `ok_count`, `ng_count`, `created_by`, `created_at`) VALUES
(1, 'Manual Entry DID (01 Nov 2026)', '2026-11-01', 'manual', 2, 2, 0, 1, '2026-11-01 14:04:24');

-- --------------------------------------------------------

--
-- Table structure for table `inspection_ng_records`
--

CREATE TABLE `inspection_ng_records` (
  `id` bigint UNSIGNED NOT NULL,
  `inspection_sample_id` bigint UNSIGNED DEFAULT NULL,
  `inspection_session_id` bigint UNSIGNED DEFAULT NULL,
  `session_lot_id` bigint UNSIGNED DEFAULT NULL,
  `unit_number` int DEFAULT '1',
  `part_id` bigint UNSIGNED DEFAULT NULL,
  `ref_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lot_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `defect_type_id` bigint UNSIGNED NOT NULL,
  `qty_ng` int NOT NULL DEFAULT '1',
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_cancelled` tinyint(1) NOT NULL DEFAULT '0',
  `cancel_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_sorted` tinyint(1) NOT NULL DEFAULT '0',
  `sort_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sorted_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inspection_ng_records`
--

INSERT INTO `inspection_ng_records` (`id`, `inspection_sample_id`, `inspection_session_id`, `session_lot_id`, `unit_number`, `part_id`, `ref_number`, `lot_number`, `defect_type_id`, `qty_ng`, `remark`, `is_cancelled`, `cancel_reason`, `cancelled_at`, `cancelled_by`, `is_sorted`, `sort_notes`, `sorted_at`, `created_at`) VALUES
(1, NULL, 13, 17, 1, 385, '1', '2', 23, 1, NULL, 0, NULL, NULL, NULL, 0, NULL, NULL, '2026-11-01 15:56:31'),
(2, NULL, 16, 20, 1, 385, '2', '2', 23, 1, NULL, 0, NULL, NULL, NULL, 0, NULL, NULL, '2026-11-01 16:14:29'),
(3, NULL, 18, 22, 1, 385, '2', '2', 24, 1, NULL, 0, NULL, NULL, NULL, 0, NULL, NULL, '2026-11-01 16:17:12');

-- --------------------------------------------------------

--
-- Table structure for table `inspection_samples`
--

CREATE TABLE `inspection_samples` (
  `id` bigint UNSIGNED NOT NULL,
  `inspection_session_id` bigint UNSIGNED NOT NULL,
  `session_lot_id` bigint UNSIGNED DEFAULT NULL,
  `sample_number` int NOT NULL,
  `result` enum('OK','NG') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `checked_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inspection_sessions`
--

CREATE TABLE `inspection_sessions` (
  `id` bigint UNSIGNED NOT NULL,
  `inspection_type` enum('kanban','safety_stock') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'kanban',
  `flow_version` tinyint NOT NULL DEFAULT '1' COMMENT '1=alur lama, 2=alur baru per-lot',
  `did_id` bigint UNSIGNED NOT NULL,
  `kanban_item_id` bigint UNSIGNED DEFAULT NULL,
  `part_id` bigint UNSIGNED DEFAULT NULL,
  `sample_size` int NOT NULL,
  `total_scanned_qty` int NOT NULL DEFAULT '0',
  `batch_sample_size` int DEFAULT '0',
  `excess_qty` int NOT NULL DEFAULT '0',
  `use_safety_stock_qty` int DEFAULT '0',
  `reject_number` int NOT NULL,
  `samples_checked` int NOT NULL DEFAULT '0',
  `ng_count` int NOT NULL DEFAULT '0',
  `status` enum('in_progress','passed','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress',
  `auto_fulfilled_by_session_id` bigint UNSIGNED DEFAULT NULL,
  `original_ss_session_id` bigint UNSIGNED DEFAULT NULL,
  `parent_session_id` bigint UNSIGNED DEFAULT NULL,
  `is_reinspection` tinyint(1) NOT NULL DEFAULT '0',
  `reinspection_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reinspection_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `inspector_id` bigint UNSIGNED DEFAULT NULL,
  `line_id` bigint UNSIGNED DEFAULT NULL,
  `line_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `started_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `closed_at` datetime DEFAULT NULL,
  `is_approved` tinyint(1) NOT NULL DEFAULT '0',
  `approved_by` bigint UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approval_notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_chief_approved` tinyint(1) NOT NULL DEFAULT '0',
  `chief_approved_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chief_approved_at` datetime DEFAULT NULL,
  `chief_notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inspection_sessions`
--

INSERT INTO `inspection_sessions` (`id`, `inspection_type`, `flow_version`, `did_id`, `kanban_item_id`, `part_id`, `sample_size`, `total_scanned_qty`, `batch_sample_size`, `excess_qty`, `use_safety_stock_qty`, `reject_number`, `samples_checked`, `ng_count`, `status`, `auto_fulfilled_by_session_id`, `original_ss_session_id`, `parent_session_id`, `is_reinspection`, `reinspection_type`, `reinspection_notes`, `inspector_id`, `line_id`, `line_name`, `started_at`, `closed_at`, `is_approved`, `approved_by`, `approved_at`, `approval_notes`, `is_chief_approved`, `chief_approved_by`, `chief_approved_at`, `chief_notes`) VALUES
(1, 'kanban', 2, 1, 1, 385, 40, 200, 32, 138, 0, 1, 40, 0, 'passed', NULL, NULL, NULL, 0, NULL, NULL, 1, 1, 'Line 1', '2026-11-01 14:56:24', '2026-11-01 14:56:27', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL),
(2, 'safety_stock', 1, 2, 7, 385, 1, 38, 0, 0, 0, 1, 1, 0, 'passed', 7, NULL, NULL, 0, NULL, NULL, 1, NULL, NULL, '2026-11-01 14:56:27', '2026-11-01 14:56:27', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL),
(3, 'safety_stock', 1, 1, 8, 385, 1, 100, 0, 0, 0, 1, 1, 0, 'passed', 4, NULL, NULL, 0, NULL, NULL, 1, NULL, NULL, '2026-11-01 14:56:27', '2026-11-01 14:56:27', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL),
(4, 'kanban', 2, 2, 2, 385, 40, 200, 32, 138, 0, 1, 40, 0, 'passed', NULL, NULL, NULL, 0, NULL, NULL, 1, 1, 'Line 1', '2026-11-01 14:57:05', '2026-11-01 14:57:08', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL),
(5, 'safety_stock', 1, 1, 9, 385, 1, 38, 0, 0, 0, 1, 1, 0, 'passed', 7, NULL, NULL, 0, NULL, NULL, 1, NULL, NULL, '2026-11-01 14:57:08', '2026-11-01 14:57:08', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL),
(6, 'safety_stock', 1, 2, 10, 385, 1, 100, 0, 0, 0, 1, 1, 0, 'passed', 7, NULL, NULL, 0, NULL, NULL, 1, NULL, NULL, '2026-11-01 14:57:08', '2026-11-01 14:57:08', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL),
(7, 'kanban', 2, 1, 3, 385, 60, 300, 50, 238, 0, 1, 60, 0, 'passed', NULL, NULL, NULL, 0, NULL, NULL, 1, 1, 'Line 1', '2026-11-01 15:00:39', '2026-11-01 15:00:52', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL),
(8, 'safety_stock', 1, 2, 11, 385, 1, 38, 0, 0, 0, 1, 1, 0, 'passed', 11, NULL, NULL, 0, NULL, NULL, 1, NULL, NULL, '2026-11-01 15:00:52', '2026-11-01 15:00:52', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL),
(9, 'safety_stock', 1, 2, 12, 385, 1, 100, 0, 0, 0, 1, 1, 0, 'passed', 16, NULL, NULL, 0, NULL, NULL, 1, NULL, NULL, '2026-11-01 15:00:52', '2026-11-01 15:00:52', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL),
(10, 'safety_stock', 1, 1, 13, 385, 1, 100, 0, 0, 0, 1, 1, 0, 'passed', NULL, NULL, NULL, 0, NULL, NULL, 1, NULL, NULL, '2026-11-01 15:00:52', '2026-11-01 15:00:52', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL),
(11, 'kanban', 2, 2, 4, 385, 20, 100, 20, 38, 0, 1, 20, 0, 'passed', NULL, NULL, NULL, 0, NULL, NULL, 1, 1, 'Line 1', '2026-11-01 15:54:50', '2026-11-01 15:54:50', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL),
(12, 'safety_stock', 1, 2, 14, 385, 1, 38, 0, 0, 0, 1, 1, 0, 'passed', 13, NULL, NULL, 0, NULL, NULL, 1, NULL, NULL, '2026-11-01 15:54:50', '2026-11-01 15:54:50', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL),
(13, 'kanban', 2, 2, 5, 385, 20, 100, 20, 38, 0, 1, 20, 1, 'rejected', NULL, NULL, NULL, 0, NULL, NULL, 1, 1, 'Line 1', '2026-11-01 15:56:26', '2026-11-01 15:56:31', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL),
(14, 'kanban', 2, 2, 5, 385, 20, 100, 20, 38, 0, 1, 20, 0, 'passed', NULL, NULL, NULL, 0, NULL, NULL, 1, 1, 'Line 1', '2026-11-01 16:13:28', '2026-11-01 16:13:33', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL),
(15, 'safety_stock', 1, 2, 15, 385, 1, 38, 0, 0, 0, 1, 1, 0, 'passed', NULL, NULL, NULL, 0, NULL, NULL, 1, NULL, NULL, '2026-11-01 16:13:33', '2026-11-01 16:13:33', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL),
(16, 'kanban', 2, 2, 6, 385, 20, 100, 20, 38, 0, 1, 20, 1, 'rejected', NULL, NULL, NULL, 0, NULL, NULL, 1, 1, 'Line 1', '2026-11-01 16:14:23', '2026-11-01 16:14:29', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL),
(18, 'kanban', 2, 2, 6, 385, 20, 100, 20, 38, 0, 1, 20, 1, 'rejected', NULL, NULL, NULL, 0, NULL, NULL, 1, 1, 'Line 1', '2026-11-01 16:16:51', '2026-11-01 16:17:12', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `inspection_session_lots`
--

CREATE TABLE `inspection_session_lots` (
  `id` bigint UNSIGNED NOT NULL,
  `inspection_session_id` bigint UNSIGNED NOT NULL,
  `ref_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lot_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `qty` int NOT NULL DEFAULT '0',
  `aql_level` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'G-II',
  `sample_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sample_size` int DEFAULT '0',
  `accept_number` int DEFAULT '0',
  `reject_number` int DEFAULT '1',
  `ng_count` int DEFAULT '0',
  `lot_result` enum('in_progress','passed','rejected','skipped') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'in_progress',
  `closed_at` datetime DEFAULT NULL,
  `scanned_qr_raw` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `remarks` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lot_status` enum('ok','ng_found','ng_quarantine','replaced','reinspected','abolished','action_closed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ok',
  `replaced_by_lot_id` bigint UNSIGNED DEFAULT NULL,
  `action_noted_at` datetime DEFAULT NULL,
  `is_chief_approved` tinyint(1) NOT NULL DEFAULT '0',
  `chief_approved_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chief_approved_at` datetime DEFAULT NULL,
  `chief_notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inspection_session_lots`
--

INSERT INTO `inspection_session_lots` (`id`, `inspection_session_id`, `ref_number`, `lot_number`, `qty`, `aql_level`, `sample_code`, `sample_size`, `accept_number`, `reject_number`, `ng_count`, `lot_result`, `closed_at`, `scanned_qr_raw`, `remarks`, `lot_status`, `replaced_by_lot_id`, `action_noted_at`, `is_chief_approved`, `chief_approved_by`, `chief_approved_at`, `chief_notes`, `created_at`) VALUES
(1, 1, '1', '2', 100, 'G-II', 'F', 20, 0, 1, 0, 'passed', '2026-11-01 14:56:24', 'Z11|Z22|Z3100|Z51', NULL, 'ok', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 14:56:24'),
(2, 1, '1', '1', 100, 'G-II', 'F', 20, 0, 1, 0, 'passed', '2026-11-01 14:56:27', 'Z11|Z21|Z3100|Z51', NULL, 'ok', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 14:56:24'),
(3, 2, '1', '2', 38, 'G-II', NULL, 0, 0, 1, 0, 'passed', '2026-11-01 14:56:27', 'Z11|Z22|Z3100|Z51', 'Sisa Kelebihan Kanban dari Sesi #1 (Pecahan Sisa)', 'ok', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 14:56:27'),
(4, 3, '1', '1', 100, 'G-II', NULL, 0, 0, 1, 0, 'passed', '2026-11-01 14:56:27', 'Z11|Z21|Z3100|Z51', 'Sisa Kelebihan Kanban dari Sesi #1 (Utuh 1 Box)', 'ok', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 14:56:27'),
(5, 4, '1', '1', 100, 'G-II', 'F', 20, 0, 1, 0, 'passed', '2026-11-01 14:57:06', 'Z11|Z21|Z3100|Z51', 'Alokasi Safety Stock Fisik (Sesi #3)', 'ok', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 14:57:05'),
(6, 4, '2', '2', 100, 'G-II', 'F', 20, 0, 1, 0, 'passed', '2026-11-01 14:57:08', 'Z11|Z22|Z3100|Z52', NULL, 'ok', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 14:57:05'),
(7, 5, '1', '1', 38, 'G-II', NULL, 0, 0, 1, 0, 'passed', '2026-11-01 14:57:08', 'Z11|Z21|Z3100|Z51', 'Sisa Kelebihan Kanban dari Sesi #4 (Pecahan Sisa)', 'ok', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 14:57:08'),
(8, 6, '2', '2', 100, 'G-II', NULL, 0, 0, 1, 0, 'passed', '2026-11-01 14:57:08', 'Z11|Z22|Z3100|Z52', 'Sisa Kelebihan Kanban dari Sesi #4 (Utuh 1 Box)', 'ok', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 14:57:08'),
(9, 7, '1', '2', 100, 'G-II', 'F', 20, 0, 1, 0, 'passed', '2026-11-01 15:00:40', 'Z11|Z22|Z3100|Z51', 'Alokasi Safety Stock Fisik (Sesi #2)', 'ok', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 15:00:39'),
(10, 7, '2', '2', 100, 'G-II', 'F', 20, 0, 1, 0, 'passed', '2026-11-01 15:00:42', 'Z11|Z22|Z3100|Z52', 'Alokasi Safety Stock Fisik (Sesi #6)', 'ok', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 15:00:39'),
(11, 7, '1', '1', 100, 'G-II', 'F', 20, 0, 1, 0, 'passed', '2026-11-01 15:00:52', 'Z11|Z21|Z3100|Z51', 'Alokasi Safety Stock Fisik (Sesi #5)', 'ok', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 15:00:39'),
(12, 8, '1', '2', 38, 'G-II', NULL, 0, 0, 1, 0, 'passed', '2026-11-01 15:00:52', 'Z11|Z22|Z3100|Z51', 'Sisa Kelebihan Kanban dari Sesi #7 (Pecahan Sisa)', 'ok', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 15:00:52'),
(13, 9, '2', '2', 100, 'G-II', NULL, 0, 0, 1, 0, 'passed', '2026-11-01 15:00:52', 'Z11|Z22|Z3100|Z52', 'Sisa Kelebihan Kanban dari Sesi #7 (Utuh 1 Box)', 'ok', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 15:00:52'),
(14, 10, '1', '1', 100, 'G-II', NULL, 0, 0, 1, 0, 'passed', '2026-11-01 15:00:52', 'Z11|Z21|Z3100|Z51', 'Sisa Kelebihan Kanban dari Sesi #7 (Utuh 1 Box)', 'ok', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 15:00:52'),
(15, 11, '1', '2', 100, 'G-II', 'F', 20, 0, 1, 0, 'passed', '2026-11-01 15:54:50', 'Z11|Z22|Z3100|Z51', 'Alokasi Safety Stock Fisik (Sesi #8)', 'ok', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 15:54:50'),
(16, 12, '1', '2', 38, 'G-II', NULL, 0, 0, 1, 0, 'passed', '2026-11-01 15:54:50', 'Z11|Z22|Z3100|Z51', 'Sisa Kelebihan Kanban dari Sesi #11 (Pecahan Sisa)', 'ok', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 15:54:50'),
(17, 13, '1', '2', 100, 'G-II', 'F', 20, 0, 1, 1, 'rejected', '2026-11-01 15:56:31', 'Z11|Z22|Z3100|Z51', 'Alokasi Safety Stock Fisik (Sesi #12)', 'ok', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 15:56:26'),
(18, 14, '1', '2', 100, 'G-II', 'F', 20, 0, 1, 0, 'passed', '2026-11-01 16:13:33', 'Z11|Z22|Z3100|Z51', NULL, 'ok', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 16:13:28'),
(19, 15, '1', '2', 38, 'G-II', NULL, 0, 0, 1, 0, 'passed', '2026-11-01 16:13:33', 'Z11|Z22|Z3100|Z51', 'Sisa Kelebihan Kanban dari Sesi #14 (Pecahan Sisa)', 'ok', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 16:13:33'),
(20, 16, '2', '2', 100, 'G-II', 'F', 20, 0, 1, 1, 'rejected', '2026-11-01 16:15:58', 'Z11|Z22|Z3100|Z52', 'Alokasi Safety Stock Fisik (Sesi #9)', 'action_closed', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 16:14:23'),
(22, 18, '2', '2', 100, 'G-II', 'F', 20, 0, 1, 1, 'rejected', '2026-11-01 16:24:25', 'Z11|Z22|Z3100|Z52', NULL, 'action_closed', NULL, NULL, 0, NULL, NULL, NULL, '2026-11-01 16:16:51');

-- --------------------------------------------------------

--
-- Table structure for table `kanban_batches`
--

CREATE TABLE `kanban_batches` (
  `id` bigint UNSIGNED NOT NULL,
  `plan_type` enum('kanban','safety_stock') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'kanban',
  `vendor` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `print_datetime` datetime DEFAULT NULL,
  `import_method` enum('manual','excel_import') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'excel_import',
  `imported_by` bigint UNSIGNED DEFAULT NULL,
  `imported_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `kanban_batches`
--

INSERT INTO `kanban_batches` (`id`, `plan_type`, `vendor`, `document_number`, `print_datetime`, `import_method`, `imported_by`, `imported_at`) VALUES
(1, 'kanban', '228746', 'KANBAN-20261101-145601', '2026-10-30 09:48:42', 'excel_import', 1, '2026-11-01 14:56:10');

-- --------------------------------------------------------

--
-- Table structure for table `kanban_items`
--

CREATE TABLE `kanban_items` (
  `id` bigint UNSIGNED NOT NULL,
  `batch_id` bigint UNSIGNED NOT NULL,
  `plan_type` enum('kanban','safety_stock') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'kanban',
  `kanban_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_description` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `req_date` datetime NOT NULL,
  `qty` int NOT NULL,
  `eta` datetime DEFAULT NULL,
  `str_loc` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supply_area` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `check_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('uninspected','in_progress','partial','completed','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'uninspected',
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `kanban_items`
--

INSERT INTO `kanban_items` (`id`, `batch_id`, `plan_type`, `kanban_no`, `item_code`, `item_description`, `customer`, `req_date`, `qty`, `eta`, `str_loc`, `supply_area`, `check_type`, `status`, `remark`, `created_at`, `updated_at`) VALUES
(1, 1, 'kanban', '1', '1', 'Coba', 'PT. Indonesia Epson Industry', '2026-10-26 13:12:11', 62, '2026-10-26 14:00:00', 'D126', 'D12601', '', 'completed', 'AUTO KANBAN(0004761236)', '2026-11-01 14:56:10', '2026-11-01 14:56:27'),
(2, 1, 'kanban', '2', '1', 'Coba', 'PT. Indonesia Epson Industry', '2026-10-26 13:12:11', 62, '2026-10-26 14:00:00', 'D126', 'D12601', '', 'completed', 'AUTO KANBAN(0004761236)', '2026-11-01 14:56:10', '2026-11-01 14:57:08'),
(3, 1, 'kanban', '3', '1', 'Coba', 'PT. Indonesia Epson Industry', '2026-10-26 13:12:11', 62, '2026-10-26 14:00:00', 'D126', 'D12601', '', 'completed', 'AUTO KANBAN(0004761236)', '2026-11-01 14:56:10', '2026-11-01 15:00:52'),
(4, 1, 'kanban', '4', '1', 'Coba', 'PT. Indonesia Epson Industry', '2026-10-26 13:12:11', 62, '2026-10-26 14:00:00', 'D126', 'D12601', '', 'completed', 'AUTO KANBAN(0004761236)', '2026-11-01 14:56:10', '2026-11-01 15:54:50'),
(5, 1, 'kanban', '5', '1', 'Coba', 'PT. Indonesia Epson Industry', '2026-10-26 13:12:11', 62, '2026-10-26 14:00:00', 'D126', 'D12601', '', 'completed', 'AUTO KANBAN(0004761236)', '2026-11-01 14:56:10', '2026-11-01 16:13:33'),
(6, 1, 'kanban', '6', '1', 'Coba', 'PT. Indonesia Epson Industry', '2026-10-26 13:12:11', 62, '2026-10-26 14:00:00', 'D126', 'D12601', '', 'rejected', 'AUTO KANBAN(0004761236)', '2026-11-01 14:56:10', '2026-11-01 16:17:12'),
(7, 1, 'safety_stock', 'SS-2', '1', 'coba', 'INTERNAL SAFETY STOCK', '2026-11-01 14:56:27', 38, NULL, 'WH-SS-OVERFLOW', 'SAFETY STOCK WAREHOUSE', 'Safety Stock', 'uninspected', 'Auto Safety Stock Sisa Excess Kanban (Lot: 2, Ref: 1, Qty: 38 pcs) (Sisa 38 pcs setelah diambil 62 pcs untuk Kanban) [Ref: AUTO-SS-SESS-1-LOT-0]', '2026-11-01 14:56:27', '2026-11-01 14:56:27'),
(8, 1, 'safety_stock', 'SS-1', '1', 'coba', 'INTERNAL SAFETY STOCK', '2026-11-01 14:56:27', 100, NULL, 'WH-SS-OVERFLOW', 'SAFETY STOCK WAREHOUSE', 'Safety Stock', 'uninspected', 'Auto Safety Stock Sisa Excess Kanban (Lot: 1, Ref: 1, Qty: 100 pcs) (Utuh 1 Box 100 pcs) [Ref: AUTO-SS-SESS-1-LOT-1]', '2026-11-01 14:56:27', '2026-11-01 14:56:27'),
(9, 1, 'safety_stock', 'SS-1', '1', 'coba', 'INTERNAL SAFETY STOCK', '2026-11-01 14:57:08', 38, NULL, 'WH-SS-OVERFLOW', 'SAFETY STOCK WAREHOUSE', 'Safety Stock', 'uninspected', 'Auto Safety Stock Sisa Excess Kanban (Lot: 1, Ref: 1, Qty: 38 pcs) (Sisa 38 pcs setelah diambil 62 pcs untuk Kanban) [Ref: AUTO-SS-SESS-4-LOT-0]', '2026-11-01 14:57:08', '2026-11-01 14:57:08'),
(10, 1, 'safety_stock', 'SS-2', '1', 'coba', 'INTERNAL SAFETY STOCK', '2026-11-01 14:57:08', 100, NULL, 'WH-SS-OVERFLOW', 'SAFETY STOCK WAREHOUSE', 'Safety Stock', 'uninspected', 'Auto Safety Stock Sisa Excess Kanban (Lot: 2, Ref: 2, Qty: 100 pcs) (Utuh 1 Box 100 pcs) [Ref: AUTO-SS-SESS-4-LOT-1]', '2026-11-01 14:57:08', '2026-11-01 14:57:08'),
(11, 1, 'safety_stock', 'SS-2', '1', 'coba', 'INTERNAL SAFETY STOCK', '2026-11-01 15:00:52', 38, NULL, 'WH-SS-OVERFLOW', 'SAFETY STOCK WAREHOUSE', 'Safety Stock', 'uninspected', 'Auto Safety Stock Sisa Excess Kanban (Lot: 2, Ref: 1, Qty: 38 pcs) (Sisa 38 pcs setelah diambil 62 pcs untuk Kanban) [Ref: AUTO-SS-SESS-7-LOT-0]', '2026-11-01 15:00:52', '2026-11-01 15:00:52'),
(12, 1, 'safety_stock', 'SS-2', '1', 'coba', 'INTERNAL SAFETY STOCK', '2026-11-01 15:00:52', 100, NULL, 'WH-SS-OVERFLOW', 'SAFETY STOCK WAREHOUSE', 'Safety Stock', 'uninspected', 'Auto Safety Stock Sisa Excess Kanban (Lot: 2, Ref: 2, Qty: 100 pcs) (Utuh 1 Box 100 pcs) [Ref: AUTO-SS-SESS-7-LOT-1]', '2026-11-01 15:00:52', '2026-11-01 15:00:52'),
(13, 1, 'safety_stock', 'SS-1', '1', 'coba', 'INTERNAL SAFETY STOCK', '2026-11-01 15:00:52', 100, NULL, 'WH-SS-OVERFLOW', 'SAFETY STOCK WAREHOUSE', 'Safety Stock', 'uninspected', 'Auto Safety Stock Sisa Excess Kanban (Lot: 1, Ref: 1, Qty: 100 pcs) (Utuh 1 Box 100 pcs) [Ref: AUTO-SS-SESS-7-LOT-2]', '2026-11-01 15:00:52', '2026-11-01 15:00:52'),
(14, 1, 'safety_stock', 'SS-2', '1', 'coba', 'INTERNAL SAFETY STOCK', '2026-11-01 15:54:50', 38, NULL, 'WH-SS-OVERFLOW', 'SAFETY STOCK WAREHOUSE', 'Safety Stock', 'uninspected', 'Auto Safety Stock Sisa Excess Kanban (Lot: 2, Ref: 1, Qty: 38 pcs) (Sisa 38 pcs setelah diambil 62 pcs untuk Kanban) [Ref: AUTO-SS-SESS-11-LOT-0]', '2026-11-01 15:54:50', '2026-11-01 15:54:50'),
(15, 1, 'safety_stock', 'SS-2', '1', 'coba', 'INTERNAL SAFETY STOCK', '2026-11-01 16:13:33', 38, NULL, 'WH-SS-OVERFLOW', 'SAFETY STOCK WAREHOUSE', 'Safety Stock', 'uninspected', 'Auto Safety Stock Sisa Excess Kanban (Lot: 2, Ref: 1, Qty: 38 pcs) (Sisa 38 pcs setelah diambil 62 pcs untuk Kanban) [Ref: AUTO-SS-SESS-14-LOT-0]', '2026-11-01 16:13:33', '2026-11-01 16:13:33');

-- --------------------------------------------------------

--
-- Table structure for table `lines`
--

CREATE TABLE `lines` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `lines`
--

INSERT INTO `lines` (`id`, `name`, `description`, `status`, `created_at`) VALUES
(1, 'Line 1', 'Didaftarkan dari Workstation Line 1', 'active', '2026-09-21 21:02:53'),
(2, 'Line 2', 'Custom Line', 'active', '2026-09-22 04:23:54');

-- --------------------------------------------------------

--
-- Table structure for table `lot_quarantine_closures`
--

CREATE TABLE `lot_quarantine_closures` (
  `id` bigint UNSIGNED NOT NULL,
  `session_lot_id` bigint UNSIGNED NOT NULL,
  `closure_type` enum('action','abolish') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'action',
  `closed_by` bigint UNSIGNED NOT NULL,
  `closed_at` datetime NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `lot_quarantine_closures`
--

INSERT INTO `lot_quarantine_closures` (`id`, `session_lot_id`, `closure_type`, `closed_by`, `closed_at`, `notes`, `created_at`) VALUES
(2, 20, 'action', 1, '2026-11-01 16:15:58', 'jaja', '2026-11-01 16:15:58'),
(3, 22, 'action', 1, '2026-11-01 16:24:25', 'jjjj', '2026-11-01 16:24:25');

-- --------------------------------------------------------

--
-- Table structure for table `lot_substitution_log`
--

CREATE TABLE `lot_substitution_log` (
  `id` bigint UNSIGNED NOT NULL,
  `original_session_id` bigint UNSIGNED NOT NULL,
  `ng_session_lot_id` bigint UNSIGNED NOT NULL,
  `action_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `replacement_session_lot_id` bigint UNSIGNED DEFAULT NULL,
  `reinspection_session_id` bigint UNSIGNED DEFAULT NULL,
  `actioned_by` bigint UNSIGNED DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `master_customers`
--

CREATE TABLE `master_customers` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `master_customers`
--

INSERT INTO `master_customers` (`id`, `name`, `created_at`, `updated_at`) VALUES
(1, 'PT. Indonesia Epson Industry', '2026-08-17 11:41:54', '2026-08-17 11:50:09'),
(2, 'PT. Muramoto Eletronika Indonesia', '2026-08-17 11:41:54', '2026-08-17 11:50:57'),
(6, 'INTERNAL STOCK', '2026-08-19 04:23:31', '2026-08-19 04:23:31'),
(8, 'INTERNAL SAFETY STOCK', '2026-09-10 19:18:22', '2026-09-10 19:18:22');

-- --------------------------------------------------------

--
-- Table structure for table `master_drawings`
--

CREATE TABLE `master_drawings` (
  `id` bigint UNSIGNED NOT NULL,
  `part_id` bigint UNSIGNED NOT NULL,
  `drawing_2d_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `drawing_3d_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by` bigint UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `master_drawings`
--

INSERT INTO `master_drawings` (`id`, `part_id`, `drawing_2d_path`, `drawing_3d_path`, `uploaded_by`, `created_at`, `updated_at`) VALUES
(2, 67, 'uploads/parts/180634300/1806343-00.pdf', 'uploads/parts/180634300/1806343-00.stp', 1, '2026-08-28 13:50:13', '2026-10-25 21:49:31'),
(3, 51, 'uploads/parts/179537600/179537600-00.pdf', 'uploads/parts/179537600/179537600-00.stp', 1, '2026-08-28 14:34:00', '2026-10-30 08:14:01'),
(4, 112, 'uploads/drawings/2d/2D_part_112_1788360991.pdf', 'uploads/drawings/3d/3D_part_112_1788360991.stp', 1, '2026-09-02 21:56:31', '2026-10-27 14:08:36'),
(5, 1, 'uploads/parts/173592402/173592402-00.pdf', 'uploads/parts/173592402/173592402-00.stp', 1, '2026-09-11 14:21:40', '2026-10-29 10:52:11'),
(6, 306, 'uploads/parts/157529401/157529401.pdf', 'uploads/parts/157529401/157529401.stp', 1, '2026-10-01 05:05:00', '2026-10-25 21:49:30'),
(7, 401, 'uploads/parts/106279000/106279000.pdf', NULL, 9, '2026-10-19 08:48:39', '2026-10-25 21:49:30'),
(8, 369, 'uploads/parts/106279700/106279700.pdf', NULL, 9, '2026-10-19 08:51:36', '2026-10-25 21:49:30'),
(9, 174, 'uploads/parts/186266502/186266502.pdf', 'uploads/parts/186266502/186266502.stp', 9, '2026-10-19 08:59:12', '2026-10-25 21:49:31'),
(10, 175, 'uploads/parts/186281203/186281203.pdf', 'uploads/parts/186281203/186281203.stp', 9, '2026-10-19 09:06:15', '2026-10-25 21:49:31'),
(12, 183, 'uploads/parts/186792201/186792201.pdf', 'uploads/parts/186792201/186792201.stp', 9, '2026-10-19 09:10:28', '2026-10-25 21:49:31'),
(13, 181, 'uploads/parts/186792002/186792002.pdf', 'uploads/parts/186792002/186792002.stp', 9, '2026-10-19 09:11:37', '2026-10-25 21:49:31'),
(14, 182, 'uploads/parts/186792101/186792101.pdf', 'uploads/parts/186792101/186792101.stp', 9, '2026-10-19 09:12:34', '2026-10-25 21:49:31'),
(15, 325, 'uploads/parts/177266100/177266100.pdf', 'uploads/parts/177266100/177266100-00.stp', 9, '2026-10-19 09:13:52', '2026-10-26 16:04:43'),
(16, 177, 'uploads/parts/186286001/186286001.pdf', 'uploads/parts/186286001/186286001.stp', 9, '2026-10-19 09:15:17', '2026-10-25 21:49:31'),
(17, 170, 'uploads/parts/186778701/186778701.pdf', 'uploads/parts/186778701/186778701.stp', 9, '2026-10-19 09:16:28', '2026-10-25 21:49:31'),
(18, 171, 'uploads/parts/186778900/186778900.pdf', 'uploads/parts/186778900/186778900.stp', 9, '2026-10-19 09:17:42', '2026-10-25 21:49:31'),
(19, 250, 'uploads/parts/193599200/193599200.pdf', 'uploads/parts/193599200/193599200.stp', 9, '2026-10-19 09:19:26', '2026-10-25 21:49:31'),
(20, 173, 'uploads/parts/186263702/186263702.pdf', 'uploads/parts/186263702/186263702.stp', 9, '2026-10-19 09:49:36', '2026-10-25 21:49:31'),
(21, 168, 'uploads/parts/186757600/186757600.pdf', 'uploads/parts/186757600/186757600.stp', 9, '2026-10-19 09:50:29', '2026-10-25 21:49:31'),
(22, 249, 'uploads/parts/193599100/193599100.pdf', 'uploads/parts/193599100/193599100.stp', 9, '2026-10-19 09:51:55', '2026-10-25 21:49:31'),
(23, 162, 'uploads/parts/185926400/185926400.pdf', 'uploads/parts/185926400/185926400.stp', 9, '2026-10-19 09:54:00', '2026-10-25 21:49:31'),
(24, 290, 'uploads/parts/166702301/166702301.pdf', NULL, 9, '2026-10-19 09:55:35', '2026-10-25 21:49:30'),
(25, 291, 'uploads/parts/166702401/166702401.pdf', NULL, 9, '2026-10-19 09:56:18', '2026-10-25 21:49:30'),
(26, 36, 'uploads/parts/180047501/180047501.pdf', NULL, 9, '2026-10-19 09:57:42', '2026-10-25 21:49:31'),
(27, 292, 'uploads/parts/169023401/169023401.pdf', NULL, 9, '2026-10-19 09:58:51', '2026-10-25 21:49:30'),
(28, 293, 'uploads/parts/169023501/169023501.pdf', NULL, 9, '2026-10-19 10:00:36', '2026-10-25 21:49:30'),
(29, 368, 'uploads/parts/157571800/157571800.pdf', 'uploads/parts/157571800/157571800.stp', 9, '2026-10-19 15:33:51', '2026-10-25 21:49:30'),
(30, 167, 'uploads/parts/186739302/186739302.pdf', 'uploads/parts/186739302/186739302.stp', 9, '2026-10-19 15:40:06', '2026-10-25 21:49:31'),
(31, 126, 'uploads/parts/182996401/182996401.pdf', 'uploads/parts/182996401/182996401.stp', 9, '2026-10-19 15:45:15', '2026-10-25 21:49:31'),
(32, 159, 'uploads/parts/171792001/171792001.pdf', 'uploads/parts/171792001/171792001.stp', 9, '2026-10-19 15:49:01', '2026-10-25 21:49:30'),
(33, 125, 'uploads/parts/182996301/182996301.pdf', 'uploads/parts/182996301/182996301.stp', 9, '2026-10-19 15:52:17', '2026-10-25 21:49:31'),
(34, 432, 'uploads/parts/191377601/191377601.pdf', 'uploads/parts/191377601/191377601.stp', 9, '2026-10-19 15:54:52', '2026-10-25 21:49:31'),
(35, 35, 'uploads/parts/180047401/180047401.pdf', NULL, 9, '2026-10-19 15:59:01', '2026-10-25 21:49:31'),
(36, 318, 'uploads/parts/169390202/169390202.pdf', 'uploads/parts/169390202/169390202.stp', 9, '2026-10-20 08:41:01', '2026-10-25 21:49:30'),
(37, 207, 'uploads/parts/188889702/188889702.pdf', 'uploads/parts/188889702/188889702.stp', 9, '2026-10-20 08:44:32', '2026-10-25 21:49:31'),
(38, 166, 'uploads/parts/186653403/186653403.pdf', 'uploads/parts/186653403/186653403.stp', 9, '2026-10-20 08:55:09', '2026-10-25 21:49:31'),
(39, 172, 'uploads/parts/186691800/186691800.pdf', 'uploads/parts/186691800/186691800.stp', 9, '2026-10-20 09:02:22', '2026-10-25 21:49:31'),
(40, 176, 'uploads/parts/186281800/186281800.pdf', 'uploads/parts/186281800/186281800.stp', 9, '2026-10-20 09:54:07', '2026-10-25 21:49:31'),
(41, 416, 'uploads/parts/127813800/127813800.pdf', NULL, 9, '2026-10-20 09:58:57', '2026-10-25 21:49:30'),
(42, 428, 'uploads/parts/153786101/153786101.pdf', 'uploads/parts/153786101/153786101-00.stp', 9, '2026-10-20 10:05:02', '2026-10-30 11:13:24'),
(43, 378, 'uploads/parts/161294200/161294200.pdf', 'uploads/parts/161294200/161294200.stp', 9, '2026-10-20 10:09:39', '2026-10-25 21:49:30'),
(44, 316, 'uploads/parts/163219701/163219701.pdf', 'uploads/parts/163219701/163219701.stp', 9, '2026-10-20 10:11:32', '2026-10-25 21:49:30'),
(45, 419, 'uploads/parts/169387500/169387500.pdf', 'uploads/parts/169387500/169387500.stp', 9, '2026-10-20 10:16:08', '2026-10-25 21:49:30'),
(46, 155, 'uploads/parts/185192102/185192102.pdf', 'uploads/parts/185192102/185192102.stp', 9, '2026-10-23 09:39:34', '2026-10-25 21:49:31'),
(47, 160, 'uploads/parts/173336001/1733360-00.pdf', 'uploads/parts/173336001/1733360-00.stp', NULL, '2026-10-25 21:16:00', '2026-10-25 21:49:30'),
(48, 304, 'uploads/parts/174601700/D1746017-01.pdf', 'uploads/parts/174601700/D1746017-01.stp', NULL, '2026-10-25 21:16:00', '2026-10-25 21:49:30'),
(49, 326, 'uploads/parts/174617201/D1746172-01.pdf', 'uploads/parts/174617201/D1746172-01.stp', NULL, '2026-10-25 21:16:00', '2026-10-25 21:49:30'),
(50, 12, 'uploads/parts/176421901/176421901.pdf', 'uploads/parts/176421901/176421901.stp', NULL, '2026-10-25 21:16:00', '2026-10-25 21:49:30'),
(51, 32, 'uploads/parts/176801600/1768016-00.pdf', 'uploads/parts/176801600/1768016-00.stp', NULL, '2026-10-25 21:16:00', '2026-10-25 21:49:30'),
(52, 30, 'uploads/parts/176812503/1768125-00.pdf', 'uploads/parts/176812503/1768125-00.stp', NULL, '2026-10-25 21:16:00', '2026-10-25 21:49:30'),
(53, 436, 'uploads/parts/177266101/1772661-00.pdf', 'uploads/parts/177266101/1772661-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:30'),
(54, 20, 'uploads/parts/179230401/1792304-00.pdf', 'uploads/parts/179230401/1792304-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:30'),
(55, 21, 'uploads/parts/179238202/1792382-00.pdf', 'uploads/parts/179238202/1792382-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:30'),
(56, 15, 'uploads/parts/179239400/1792394-00.pdf', 'uploads/parts/179239400/1792394-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:30'),
(57, 18, 'uploads/parts/179250401/1792504-00.pdf', 'uploads/parts/179250401/1792504-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:30'),
(58, 19, 'uploads/parts/179250501/1792505-00.pdf', 'uploads/parts/179250501/1792505-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:30'),
(59, 24, 'uploads/parts/179251200/1792512-00.pdf', 'uploads/parts/179251200/1792512-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:30'),
(60, 28, 'uploads/parts/179256801/1792568-00.pdf', 'uploads/parts/179256801/1792568-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:30'),
(61, 16, 'uploads/parts/179265900/1792659-00.pdf', 'uploads/parts/179265900/1792659-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:30'),
(62, 22, 'uploads/parts/179270700/1792707-00.pdf', 'uploads/parts/179270700/1792707-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:30'),
(63, 17, 'uploads/parts/179338001/1793380-00.pdf', 'uploads/parts/179338001/1793380-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:30'),
(64, 29, 'uploads/parts/179338200/1793382-00.pdf', 'uploads/parts/179338200/1793382-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 14:07:09'),
(65, 25, 'uploads/parts/179340302/P1793403-00.pdf', 'uploads/parts/179340302/P1793403-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:30'),
(66, 26, 'uploads/parts/179340400/1793404-00.pdf', 'uploads/parts/179340400/1793404-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:30'),
(67, 27, 'uploads/parts/179340602/1793406-00.pdf', 'uploads/parts/179340602/1793406-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:30'),
(68, 34, 'uploads/parts/179840401/1798404-00.pdf', 'uploads/parts/179840401/1798404-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(69, 31, 'uploads/parts/179843203/1798432-00.pdf', 'uploads/parts/179843203/1798432-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(70, 425, 'uploads/parts/180421800/1804218-00.pdf', 'uploads/parts/180421800/1804218-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(71, 65, 'uploads/parts/180641801/1806418-00.pdf', 'uploads/parts/180641801/1806418-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(72, 66, 'uploads/parts/180642201/1806422-00.pdf', 'uploads/parts/180642201/1806422-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(73, 70, 'uploads/parts/180917702/P1809177-00.pdf', 'uploads/parts/180917702/P1809177-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(74, 81, 'uploads/parts/180917802/P1809178-00.pdf', 'uploads/parts/180917802/P1809178-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(75, 69, 'uploads/parts/180917902/P1809179-00.pdf', 'uploads/parts/180917902/P1809179-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(76, 71, 'uploads/parts/180918002/P1809180-00.pdf', 'uploads/parts/180918002/P1809180-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(77, 82, 'uploads/parts/180918102/P1809181-00.pdf', 'uploads/parts/180918102/P1809181-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(78, 80, 'uploads/parts/180918202/P1809182-00.pdf', 'uploads/parts/180918202/P1809182-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(79, 79, 'uploads/parts/180918302/P1809183-00.pdf', 'uploads/parts/180918302/P1809183-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(80, 72, 'uploads/parts/180918402/P1809184-00.pdf', 'uploads/parts/180918402/P1809184-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(81, 83, 'uploads/parts/180918502/P1809185-00.pdf', 'uploads/parts/180918502/P1809185-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(82, 73, 'uploads/parts/180918602/P1809186-00.pdf', 'uploads/parts/180918602/P1809186-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(83, 87, 'uploads/parts/180918702/P1809187-00.pdf', 'uploads/parts/180918702/P1809187-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(84, 76, 'uploads/parts/180918802/P1809188-00.pdf', 'uploads/parts/180918802/P1809188-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(85, 88, 'uploads/parts/180918902/P1809189-00.pdf', 'uploads/parts/180918902/P1809189-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(86, 89, 'uploads/parts/180919002/P1809190-00.pdf', 'uploads/parts/180919002/P1809190-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(87, 75, 'uploads/parts/180919102/P1809191-00.pdf', 'uploads/parts/180919102/P1809191-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(88, 77, 'uploads/parts/180919202/P1809192-00.pdf', 'uploads/parts/180919202/P1809192-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(89, 78, 'uploads/parts/180919302/P1809193-00.pdf', 'uploads/parts/180919302/P1809193-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(90, 90, 'uploads/parts/180919402/P1809194-00.pdf', 'uploads/parts/180919402/P1809194-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(91, 74, 'uploads/parts/180919502/P1809195-00.pdf', 'uploads/parts/180919502/P1809195-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(92, 91, 'uploads/parts/180919602/P1809196-00.pdf', 'uploads/parts/180919602/P1809196-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(93, 84, 'uploads/parts/181010501/P1810105-00.pdf', 'uploads/parts/181010501/P1810105-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(94, 118, 'uploads/parts/183670500/1836705-00.pdf', 'uploads/parts/183670500/1836705-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(95, 142, 'uploads/parts/183834905/P1838349-00.pdf', 'uploads/parts/183834905/P1838349-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(96, 359, 'uploads/parts/184314701/P1843147-00.pdf', 'uploads/parts/184314701/P1843147-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(97, 136, 'uploads/parts/184323801/1843238-00.pdf', 'uploads/parts/184323801/1843238-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 14:03:53'),
(98, 137, 'uploads/parts/184409603/1844096-00.pdf', 'uploads/parts/184409603/1844096-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(99, 138, 'uploads/parts/184458403/1844584-00.pdf', 'uploads/parts/184458403/1844584-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(100, 141, 'uploads/parts/184458901/P1844589-00.pdf', 'uploads/parts/184458901/P1844589-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 13:53:59'),
(101, 140, 'uploads/parts/184494701/1844947-00.pdf', 'uploads/parts/184494701/1844947-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 13:57:51'),
(102, 139, 'uploads/parts/184494903/1844949-00.pdf', 'uploads/parts/184494903/1844949-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(103, 134, 'uploads/parts/184495509/1844955-00.pdf', 'uploads/parts/184495509/1844955-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 14:05:57'),
(104, 135, 'uploads/parts/184495603/1844956-00.pdf', 'uploads/parts/184495603/1844956-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(105, 151, 'uploads/parts/184553705/P1845537-00.pdf', 'uploads/parts/184553705/P1845537-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(106, 152, 'uploads/parts/184553805/P1845538-00.pdf', 'uploads/parts/184553805/P1845538-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(107, 153, 'uploads/parts/184553905/P1845539-00.pdf', 'uploads/parts/184553905/P1845539-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(108, 197, 'uploads/parts/187393603/P1873936-00.pdf', 'uploads/parts/187393603/P1873936-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(109, 199, 'uploads/parts/187460503/P1874605-00.pdf', 'uploads/parts/187460503/P1874605-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(110, 68, 'uploads/parts/188881100/P1888811-00.pdf', 'uploads/parts/188881100/P1888811-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(111, 203, 'uploads/parts/189045902/P1890459-00.pdf', 'uploads/parts/189045902/P1890459-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(112, 230, 'uploads/parts/190119001/P1901190-00.pdf', 'uploads/parts/190119001/P1901190-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(113, 204, 'uploads/parts/190315600/P1903156-00.pdf', 'uploads/parts/190315600/P1903156-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(114, 202, 'uploads/parts/190338801/P1903388-00.pdf', 'uploads/parts/190338801/P1903388-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(115, 302, 'uploads/parts/190664902/P1906649-00.pdf', 'uploads/parts/190664902/P1906649-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(116, 231, 'uploads/parts/190732002/P1907320-00.pdf', 'uploads/parts/190732002/P1907320-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(117, 232, 'uploads/parts/191575100/P1915751-00.pdf', 'uploads/parts/191575100/P1915751-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(118, 245, 'uploads/parts/191758701/P1917587-00.pdf', 'uploads/parts/191758701/P1917587-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(119, 246, 'uploads/parts/191758901/P1917589-00.pdf', 'uploads/parts/191758901/P1917589-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(120, 294, 'uploads/parts/191834301/P1918343-00.pdf', 'uploads/parts/191834301/P1918343-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(121, 295, 'uploads/parts/191834602/P1918346-00.pdf', 'uploads/parts/191834602/P1918346-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(122, 97, 'uploads/parts/192307200/P1923072-00.pdf', 'uploads/parts/192307200/P1923072-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(123, 271, 'uploads/parts/192456700/P1924567-00.pdf', 'uploads/parts/192456700/P1924567-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(124, 273, 'uploads/parts/193050201/P1930502-00.pdf', 'uploads/parts/193050201/P1930502-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(125, 272, 'uploads/parts/193050301/P1930503-00.pdf', 'uploads/parts/193050301/P1930503-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(126, 299, 'uploads/parts/193050401/P1930504-00.pdf', 'uploads/parts/193050401/P1930504-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(127, 247, 'uploads/parts/193272500/P1932725-00.pdf', 'uploads/parts/193272500/P1932725-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(128, 248, 'uploads/parts/193272700/P1932727-00.pdf', 'uploads/parts/193272700/P1932727-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(129, 269, 'uploads/parts/193437400/P1934374-00.pdf', 'uploads/parts/193437400/P1934374-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(130, 296, 'uploads/parts/193437801/P1934378-00.pdf', 'uploads/parts/193437801/P1934378-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(131, 297, 'uploads/parts/193438701/P1934387-00.pdf', 'uploads/parts/193438701/P1934387-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(132, 274, 'uploads/parts/193510600/P1935106-00.pdf', 'uploads/parts/193510600/P1935106.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(133, 298, 'uploads/parts/193578901/P1935789-00.pdf', 'uploads/parts/193578901/P1935789-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(134, 270, 'uploads/parts/193579000/P1935790-00.pdf', 'uploads/parts/193579000/P1935790-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 13:42:07'),
(135, 280, 'uploads/parts/193926600/193926600-00.pdf', 'uploads/parts/193926600/P1939266-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-28 17:09:43'),
(136, 281, 'uploads/parts/193929600/P1939296-00.pdf', 'uploads/parts/193929600/P1939296-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(137, 279, 'uploads/parts/193930200/P1939302-00.pdf', 'uploads/parts/193930200/P1939302-00 (P1890440-00).stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(138, 278, 'uploads/parts/193930300/P1939303-00.pdf', 'uploads/parts/193930300/P1939303-00 (P1890439-00).stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(139, 275, 'uploads/parts/193973700/P1939737-00.pdf', 'uploads/parts/193973700/P1939737-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(140, 358, 'uploads/parts/194775401/P1947754-00.pdf', 'uploads/parts/194775401/P1947754-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 14:13:13'),
(141, 348, 'uploads/parts/194927701/P1949277-00.pdf', 'uploads/parts/194927701/P1949277-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 13:54:33'),
(142, 341, 'uploads/parts/194927801/P1949278-00.pdf', 'uploads/parts/194927801/P1949278-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(143, 342, 'uploads/parts/195060602/P1950606-00.pdf', 'uploads/parts/195060602/P1950606-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(144, 339, 'uploads/parts/195060701/P1950607-00.pdf', 'uploads/parts/195060701/P1950607-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(145, 340, 'uploads/parts/195060801/P1950608-00.pdf', 'uploads/parts/195060801/P1950608-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(146, 337, 'uploads/parts/195646100/P1956461-00.pdf', 'uploads/parts/195646100/P1956461-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 13:46:25'),
(147, 338, 'uploads/parts/195646200/P1956462-00.pdf', 'uploads/parts/195646200/P1956462-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 13:46:53'),
(148, 334, 'uploads/parts/195669100/P1956691-00.pdf', 'uploads/parts/195669100/P1956691-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 14:23:21'),
(149, 328, 'uploads/parts/195679500/P1956795-00.pdf', 'uploads/parts/195679500/P1956795-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 14:27:42'),
(150, 336, 'uploads/parts/195687600/P1956876-00.pdf', 'uploads/parts/195687600/P1956876-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 14:28:16'),
(151, 331, 'uploads/parts/195688400/P1956884-00.pdf', 'uploads/parts/195688400/P1956884-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 14:28:53'),
(152, 347, 'uploads/parts/195717301/P1957173-00.pdf', 'uploads/parts/195717301/P1957173-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 14:29:22'),
(153, 346, 'uploads/parts/195720801/P1957208-00.pdf', 'uploads/parts/195720801/P1957208-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 14:30:04'),
(154, 332, 'uploads/parts/195722700/P1957227-00.pdf', 'uploads/parts/195722700/P1957227-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 14:30:32'),
(155, 335, 'uploads/parts/195723300/P1957233-00.pdf', 'uploads/parts/195723300/P1957233-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 14:30:59'),
(156, 329, 'uploads/parts/195723900/P1957239-00.pdf', 'uploads/parts/195723900/P1957239-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 14:31:26'),
(157, 330, 'uploads/parts/195724000/P1957240-00.pdf', 'uploads/parts/195724000/P1957240-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 14:31:51'),
(158, 333, 'uploads/parts/195724100/P1957241-00.pdf', 'uploads/parts/195724100/P1957241-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-27 14:32:22'),
(159, 327, 'uploads/parts/195733700/195733700-00.pdf', 'uploads/parts/195733700/P1957337-00 (P1915714-00).stp', NULL, '2026-10-25 21:16:01', '2026-10-28 11:51:26'),
(160, 350, 'uploads/parts/196575301/P1965753-00.pdf', 'uploads/parts/196575301/P1965753-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(161, 343, 'uploads/parts/196870501/P1968705-00.pdf', 'uploads/parts/196870501/P1968705-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(162, 344, 'uploads/parts/196870601/P1968706-00.pdf', 'uploads/parts/196870601/P1968706-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(163, 354, 'uploads/parts/196870702/P1968707-00.pdf', 'uploads/parts/196870702/P1968707-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(164, 439, 'uploads/parts/196870703/P1968707-00.pdf', 'uploads/parts/196870703/P1968707-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(165, 355, 'uploads/parts/196870802/P1968708-00.pdf', 'uploads/parts/196870802/P1968708-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(166, 345, 'uploads/parts/196870901/P1968709-00.pdf', 'uploads/parts/196870901/P1968709-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(167, 351, 'uploads/parts/1A4328500/P1A43285-00.pdf', 'uploads/parts/1A4328500/P1A43285-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(168, 352, 'uploads/parts/1A4328600/P1A43286-00.pdf', 'uploads/parts/1A4328600/P1A43286-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(169, 357, 'uploads/parts/1A4558001/P1A45580-00.pdf', 'uploads/parts/1A4558001/P1A45580-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(170, 356, 'uploads/parts/1A4558201/P1A45582-00.pdf', 'uploads/parts/1A4558201/P1A45582-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(171, 353, 'uploads/parts/1A4999200/P1A49992-00.pdf', 'uploads/parts/1A4999200/P1A49992-00.stp', NULL, '2026-10-25 21:16:01', '2026-10-25 21:49:31'),
(172, 85, 'uploads/parts/180774100/180774100-00.pdf', 'uploads/parts/180774100/180774100-00.stp', 9, '2026-10-26 15:37:10', '2026-10-27 13:51:39'),
(173, 380, 'uploads/parts/180322902/180322902-00.pdf', NULL, 9, '2026-10-26 15:49:58', '2026-10-26 15:49:58'),
(174, 379, 'uploads/parts/180323501/180323501-00.pdf', NULL, 9, '2026-10-26 15:50:37', '2026-10-26 15:50:37'),
(175, 179, 'uploads/parts/186816601/186816601-00.pdf', 'uploads/parts/186816601/186816601-00.stp', 9, '2026-10-26 15:51:43', '2026-10-26 15:51:43'),
(176, 95, 'uploads/parts/181916600/181916600-00.pdf', 'uploads/parts/181916600/181916600-00.stp', 9, '2026-10-26 15:55:44', '2026-10-26 15:55:44'),
(177, 103, 'uploads/parts/180678301/180678301-00.pdf', 'uploads/parts/180678301/180678301-00.stp', 9, '2026-10-26 15:58:33', '2026-10-26 15:58:33'),
(178, 46, 'uploads/parts/180055501/180055501-00.pdf', 'uploads/parts/180055501/180055501-00.stp', 9, '2026-10-26 16:02:55', '2026-10-26 16:02:55'),
(179, 104, 'uploads/parts/180674403/180674403-00.pdf', 'uploads/parts/180674403/180674403-00.stp', 9, '2026-10-26 16:06:58', '2026-10-26 16:06:58'),
(180, 102, 'uploads/parts/180671901/180671901-00.pdf', 'uploads/parts/180671901/180671901-00.stp', 9, '2026-10-26 16:15:11', '2026-10-26 16:15:11'),
(181, 33, 'uploads/parts/176799901/176799901-00.pdf', 'uploads/parts/176799901/176799901-00.stp', 9, '2026-10-26 16:18:13', '2026-10-26 16:18:13'),
(182, 324, 'uploads/parts/191716600/191716600-00.pdf', 'uploads/parts/191716600/191716600-1696911.stp', 9, '2026-10-26 16:21:41', '2026-10-30 07:53:42'),
(183, 255, 'uploads/parts/191723200/191723200-00.pdf', 'uploads/parts/191723200/191723200-00.stp', 9, '2026-10-26 16:26:01', '2026-10-26 16:27:45'),
(184, 277, 'uploads/parts/192951000/192951000-00.pdf', 'uploads/parts/192951000/192951000-00.stp', 9, '2026-10-26 16:29:15', '2026-10-26 16:29:15'),
(185, 150, 'uploads/parts/184371801/184371801-00.pdf', 'uploads/parts/184371801/184371801-00.stp', 9, '2026-10-26 16:31:52', '2026-10-26 16:31:52'),
(186, 143, 'uploads/parts/184328905/184328905-00.pdf', 'uploads/parts/184328905/184328905-00.stp', 9, '2026-10-26 16:34:26', '2026-10-26 16:37:27'),
(187, 185, 'uploads/parts/187308400/187308400-00.pdf', 'uploads/parts/187308400/187308400-20220905.stp', 9, '2026-10-26 16:39:08', '2026-10-27 14:04:56'),
(188, 184, 'uploads/parts/187307101/187307101-20221205.pdf', 'uploads/parts/187307101/187307101-20210809.stp', 9, '2026-10-26 16:40:13', '2026-10-27 13:53:33'),
(189, 238, 'uploads/parts/188912801/188912801-00.pdf', 'uploads/parts/188912801/188912801-00.stp', 9, '2026-10-26 16:41:12', '2026-10-26 16:41:12'),
(190, 262, 'uploads/parts/186868700/186868700-00.pdf', 'uploads/parts/186868700/186868700-00.stp', 9, '2026-10-26 16:45:01', '2026-10-26 16:45:01'),
(191, 169, 'uploads/parts/186759802/186759802-00.pdf', 'uploads/parts/186759802/186759802-00.stp', 9, '2026-10-26 16:46:27', '2026-10-26 16:46:27'),
(192, 101, 'uploads/parts/180691502/180691502-00.pdf', 'uploads/parts/180691502/180691502-00.stp', 9, '2026-10-26 16:48:15', '2026-10-26 16:48:15'),
(193, 276, 'uploads/parts/192950900/192950900-00.pdf', 'uploads/parts/192950900/192950900-00.stp', 9, '2026-10-26 16:49:38', '2026-10-29 14:12:52'),
(194, 365, 'uploads/parts/153806800/153806800-00.pdf', 'uploads/parts/153806800/153806800-00.stp', 9, '2026-10-26 16:52:42', '2026-10-30 10:59:43'),
(195, 364, 'uploads/parts/157179601/157179601-00.pdf', 'uploads/parts/157179601/157179601-00.stp', 9, '2026-10-26 16:54:10', '2026-10-26 16:54:10'),
(196, 374, 'uploads/parts/157211601/157211601-00.pdf', 'uploads/parts/157211601/157211601-00.stp', 9, '2026-10-26 16:55:47', '2026-10-30 10:57:25'),
(197, 361, 'uploads/parts/157211701/157211701-00.pdf', 'uploads/parts/157211701/157211701-00.stp', 9, '2026-10-26 16:56:20', '2026-10-30 10:58:20'),
(198, 396, 'uploads/parts/160670100/160670100-1606701.pdf', NULL, 9, '2026-10-26 16:58:05', '2026-10-26 16:58:05'),
(199, 390, 'uploads/parts/160930300/160930300-00.pdf', 'uploads/parts/160930300/160930300-00.stp', 9, '2026-10-26 16:58:59', '2026-10-26 16:58:59'),
(200, 371, 'uploads/parts/160930400/160930400-1609304.pdf', NULL, 9, '2026-10-26 17:00:30', '2026-10-26 17:00:30'),
(201, 391, 'uploads/parts/160931400/160931400-00.pdf', 'uploads/parts/160931400/160931400-00.stp', 9, '2026-10-26 17:01:08', '2026-10-26 17:01:08'),
(202, 370, 'uploads/parts/160932500/160932500-1609325.pdf', NULL, 9, '2026-10-26 17:02:29', '2026-10-26 17:02:29'),
(203, 415, 'uploads/parts/162817100/162817100-1628171.pdf', 'uploads/parts/162817100/162817100-00.stp', 9, '2026-10-26 17:03:34', '2026-10-26 17:03:34'),
(204, 256, 'uploads/parts/161440902/161440902-01.pdf', 'uploads/parts/161440902/161440902-00.stp', 9, '2026-10-26 17:04:42', '2026-10-26 17:04:42'),
(205, 388, 'uploads/parts/160978202/160978202-00.pdf', 'uploads/parts/160978202/160978202-00.stp', 9, '2026-10-26 17:05:52', '2026-10-26 17:05:52'),
(206, 389, 'uploads/parts/160978302/160978302-00.pdf', 'uploads/parts/160978302/160978302-00.stp', 9, '2026-10-26 17:06:40', '2026-10-26 17:06:40'),
(207, 362, 'uploads/parts/169424900/169424900-00.pdf', 'uploads/parts/169424900/169424900-00.stp', 9, '2026-10-26 17:07:51', '2026-10-26 17:07:51'),
(208, 367, 'uploads/parts/169683200/169683200-00.pdf', 'uploads/parts/169683200/169683200-00.stp', 9, '2026-10-26 17:08:38', '2026-10-26 17:08:38'),
(209, 386, 'uploads/parts/169698100/169698100-00.pdf', 'uploads/parts/169698100/169698100-00.stp', 9, '2026-10-26 17:09:59', '2026-10-26 17:09:59'),
(210, 363, 'uploads/parts/170445300/170445300-00.pdf', 'uploads/parts/170445300/170445300-00.stp', 9, '2026-10-26 17:11:33', '2026-10-26 17:11:33'),
(211, 145, 'uploads/parts/184390503/184390503-00.pdf', 'uploads/parts/184390503/184390503-00.stp', 9, '2026-10-27 07:37:51', '2026-10-27 07:37:51'),
(212, 96, 'uploads/parts/180690002/180690002-00.pdf', 'uploads/parts/180690002/180690002-00.stp', 9, '2026-10-27 08:44:10', '2026-10-27 08:48:35'),
(213, 38, 'uploads/parts/179417000/179417000-01.pdf', 'uploads/parts/179417000/179417000-01.stp', 9, '2026-10-27 09:10:09', '2026-10-27 09:12:30'),
(214, 178, 'uploads/parts/186813501/186813501-00.pdf', 'uploads/parts/186813501/186813501-00.stp', 9, '2026-10-27 10:07:10', '2026-10-27 10:07:10'),
(215, 50, 'uploads/parts/179554201/179554201-00.pdf', 'uploads/parts/179554201/179554201-00.stp', 9, '2026-10-27 11:00:54', '2026-10-27 11:00:54'),
(216, 261, 'uploads/parts/189390200/189390200-00.pdf', 'uploads/parts/189390200/189390200-00.stp', 9, '2026-10-27 11:05:32', '2026-10-27 11:14:10'),
(217, 107, 'uploads/parts/180935401/180935401-00.pdf', 'uploads/parts/180935401/180935401-00.stp', 9, '2026-10-27 11:36:43', '2026-10-27 13:03:10'),
(218, 191, 'uploads/parts/187314901/187314901-00.pdf', 'uploads/parts/187314901/187314901-00.stp', 9, '2026-10-27 13:41:32', '2026-10-27 13:41:32'),
(219, 144, 'uploads/parts/184376403/184376403-00.pdf', 'uploads/parts/184376403/184376403-00.stp', 9, '2026-10-27 13:44:59', '2026-10-27 13:44:59'),
(220, 4, 'uploads/parts/173920804/173920804-00.pdf', 'uploads/parts/173920804/173920804-8236.stp', 9, '2026-10-27 13:49:46', '2026-10-27 13:49:46'),
(221, 349, 'uploads/parts/194926501/194926501-00.pdf', 'uploads/parts/194926501/194926501-00.stp', 9, '2026-10-27 13:57:24', '2026-10-27 13:57:24'),
(222, 263, 'uploads/parts/190578900/190578900-20221123.pdf', 'uploads/parts/190578900/190578900-20221123.stp', 9, '2026-10-27 13:59:50', '2026-10-27 13:59:50'),
(223, 154, 'uploads/parts/185192003/185192003-00.pdf', 'uploads/parts/185192003/185192003-00.stp', 9, '2026-10-27 14:01:46', '2026-10-27 14:01:46'),
(224, 201, 'uploads/parts/187805500/187805500-20211004.pdf', 'uploads/parts/187805500/187805500-20211004.stp', 9, '2026-10-27 14:11:00', '2026-10-27 14:11:00'),
(225, 267, 'uploads/parts/193972200/193972200-00.pdf', 'uploads/parts/193972200/193972200-00.stp', 9, '2026-10-27 14:25:30', '2026-10-27 14:25:30'),
(226, 266, 'uploads/parts/193972500/193972500-00.pdf', 'uploads/parts/193972500/193972500-00.stp', 9, '2026-10-27 14:27:07', '2026-10-27 14:27:07'),
(227, 385, 'uploads/parts/1/1-00.pdf', NULL, 1, '2026-10-27 14:51:27', '2026-10-27 14:51:27'),
(228, 8, 'uploads/parts/173514801/173514801-01.pdf', 'uploads/parts/173514801/173514801-00.stp', 9, '2026-10-27 16:33:36', '2026-10-27 16:33:36'),
(229, 92, 'uploads/parts/180681702/180681702-00.pdf', NULL, 9, '2026-10-28 08:18:49', '2026-10-28 08:18:49'),
(230, 148, 'uploads/parts/184432303/184432303-00.pdf', NULL, 9, '2026-10-28 08:22:19', '2026-10-28 08:23:08'),
(231, 94, 'uploads/parts/181907400/181907400-00.pdf', 'uploads/parts/181907400/181907400-00.stp', 9, '2026-10-28 08:27:40', '2026-10-28 14:38:28'),
(232, 98, 'uploads/parts/180706501/180706501-00.pdf', 'uploads/parts/180706501/180706501-00.stp', 9, '2026-10-28 08:30:27', '2026-10-28 08:30:27'),
(233, 186, 'uploads/parts/187308500/187308500-00.pdf', 'uploads/parts/187308500/187308500-20211126.stp', 9, '2026-10-28 08:33:19', '2026-10-28 08:33:19'),
(234, 200, 'uploads/parts/187805302/187805302-20221025.pdf', 'uploads/parts/187805302/187805302-20221025.stp', 9, '2026-10-28 08:35:20', '2026-10-28 08:35:20'),
(235, 165, 'uploads/parts/186717100/186717100-00.pdf', NULL, 9, '2026-10-28 08:41:45', '2026-10-28 08:41:45'),
(236, 195, 'uploads/parts/187385503/187385503-00.pdf', 'uploads/parts/187385503/187385503-00.stp', 9, '2026-10-28 11:29:47', '2026-10-28 11:29:47'),
(237, 236, 'uploads/parts/190747900/190747900-00.pdf', 'uploads/parts/190747900/190747900-00.stp', 9, '2026-10-28 11:32:59', '2026-10-28 11:32:59'),
(238, 48, 'uploads/parts/179922901/179922901-00.pdf', 'uploads/parts/179922901/179922901-00.stp', 9, '2026-10-28 14:21:53', '2026-10-28 14:23:33'),
(239, 111, 'uploads/parts/179922600/179922600-00.pdf', 'uploads/parts/179922600/179922600-00.stp', 9, '2026-10-28 14:25:32', '2026-10-28 14:25:32'),
(240, 47, 'uploads/parts/179899801/179899801-00.pdf', 'uploads/parts/179899801/179899801-00.stp', 9, '2026-10-28 14:27:55', '2026-10-28 14:28:39'),
(241, 100, 'uploads/parts/181010400/181010400-00.pdf', 'uploads/parts/181010400/181010400-00.stp', 9, '2026-10-28 14:34:17', '2026-10-28 14:34:17'),
(242, 305, 'uploads/parts/154176200/154176200-1541762.pdf', 'uploads/parts/154176200/154176200-1541762.stp', 9, '2026-10-29 07:25:59', '2026-10-29 07:25:59'),
(243, 392, 'uploads/parts/160389100/160389100-1603891.pdf', NULL, 9, '2026-10-29 07:30:08', '2026-10-29 07:30:08'),
(244, 308, 'uploads/parts/159973400/159973400-00.pdf', 'uploads/parts/159973400/159973400-01.stp', 9, '2026-10-29 07:32:02', '2026-10-29 07:32:02'),
(245, 309, 'uploads/parts/160668600/160668600-00.pdf', 'uploads/parts/160668600/160668600-00.stp', 9, '2026-10-29 07:33:50', '2026-10-29 07:33:50'),
(246, 300, 'uploads/parts/158909602/158909602-00.pdf', NULL, 9, '2026-10-29 07:36:33', '2026-10-29 07:36:33'),
(247, 307, 'uploads/parts/160400600/160400600-00.pdf', 'uploads/parts/160400600/160400600-00.stp', 9, '2026-10-29 07:37:34', '2026-10-29 07:37:34'),
(248, 310, 'uploads/parts/162973501/162973501-03.pdf', 'uploads/parts/162973501/162973501-01.stp', 9, '2026-10-29 07:39:09', '2026-10-29 07:39:09'),
(249, 311, 'uploads/parts/163164701/163164701-00.pdf', 'uploads/parts/163164701/163164701-00.stp', 9, '2026-10-29 07:40:10', '2026-10-29 07:40:10'),
(250, 383, 'uploads/parts/169679701/169679701-00.pdf', 'uploads/parts/169679701/169679701-00.stp', 9, '2026-10-29 07:41:28', '2026-10-29 07:41:28'),
(251, 9, 'uploads/parts/173514900/173514900-00.pdf', 'uploads/parts/173514900/173514900-4202.stp', 9, '2026-10-29 07:43:39', '2026-10-29 14:50:22'),
(252, 58, 'uploads/parts/180320702/180320702-00.pdf', 'uploads/parts/180320702/180320702-00.stp', 9, '2026-10-29 10:03:12', '2026-10-29 10:03:12'),
(253, 190, 'uploads/parts/187313300/187313300-00.pdf', 'uploads/parts/187313300/187313300-00.stp', 9, '2026-10-29 10:05:10', '2026-10-29 10:05:10'),
(254, 373, 'uploads/parts/169390101/169390101-00.pdf', 'uploads/parts/169390101/169390101-00.stp', 9, '2026-10-29 10:10:57', '2026-10-29 10:10:57'),
(255, 319, 'uploads/parts/169708500/169708500-1697085.pdf', 'uploads/parts/169708500/169708500-1697085.stp', 9, '2026-10-29 10:12:54', '2026-10-29 10:12:54'),
(256, 320, 'uploads/parts/169708601/169708601-1697086.pdf', 'uploads/parts/169708601/169708601-1697086.stp', 9, '2026-10-29 10:14:26', '2026-10-29 10:14:26'),
(257, 323, 'uploads/parts/169691102/169691102-00.pdf', 'uploads/parts/169691102/169691102-1696911.stp', 9, '2026-10-29 10:15:41', '2026-10-29 10:15:41'),
(258, 372, 'uploads/parts/170108801/170108801-1701088.pdf', 'uploads/parts/170108801/170108801-00.stp', 9, '2026-10-29 10:19:07', '2026-10-29 10:19:07'),
(259, 158, 'uploads/parts/171790900/171790900-00.pdf', 'uploads/parts/171790900/171790900-01.stp', 9, '2026-10-29 10:21:05', '2026-10-29 10:21:05'),
(260, 161, 'uploads/parts/173301400/173301400-00.pdf', 'uploads/parts/173301400/173301400-01.stp', 9, '2026-10-29 10:22:33', '2026-10-29 10:23:29'),
(261, 2, 'uploads/parts/173592502/173592502-00.pdf', 'uploads/parts/173592502/173592502-00.stp', 9, '2026-10-29 10:24:40', '2026-10-29 10:24:40'),
(262, 7, 'uploads/parts/173665602/173665602-02.pdf', 'uploads/parts/173665602/173665602-00.stp', 9, '2026-10-29 10:28:12', '2026-10-29 10:28:12'),
(263, 57, 'uploads/parts/180319100/180319100-00.pdf', 'uploads/parts/180319100/180319100-00.stp', 9, '2026-10-29 10:48:38', '2026-10-29 10:48:38'),
(264, 6, 'uploads/parts/173665702/173665702-02.pdf', 'uploads/parts/173665702/173665702-00.stp', 9, '2026-10-29 10:54:38', '2026-10-29 10:54:38'),
(265, 14, 'uploads/parts/176412000/176412000-00.pdf', 'uploads/parts/176412000/176412000-00.stp', 9, '2026-10-29 10:56:49', '2026-10-29 10:58:11'),
(266, 157, 'uploads/parts/173909000/173909000-00.pdf', 'uploads/parts/173909000/173909000-01.stp', 9, '2026-10-29 10:59:48', '2026-10-29 10:59:48'),
(267, 10, 'uploads/parts/176056900/176056900-00.pdf', 'uploads/parts/176056900/176056900-810.stp', 9, '2026-10-29 11:02:37', '2026-10-29 11:02:37'),
(268, 257, 'uploads/parts/176412101/176412101-00.pdf', 'uploads/parts/176412101/176412101-00.stp', 9, '2026-10-29 11:04:20', '2026-10-29 11:04:20'),
(269, 3, 'uploads/parts/173920704/173920704-02.pdf', 'uploads/parts/173920704/173920704-8231.stp', 9, '2026-10-29 11:06:06', '2026-10-29 11:06:06'),
(270, 5, 'uploads/parts/173786101/173786101-00.pdf', 'uploads/parts/173786101/173786101-8212.stp', 9, '2026-10-29 11:07:33', '2026-10-29 11:09:49'),
(271, 13, NULL, 'uploads/parts/176591801/176591801-00.stp', 9, '2026-10-29 11:24:40', '2026-10-29 11:24:40'),
(272, 366, 'uploads/parts/175464800/175464800-00.pdf', 'uploads/parts/175464800/175464800-00.stp', 9, '2026-10-29 11:26:29', '2026-10-29 11:26:29'),
(273, 23, 'uploads/parts/179229601/179229601-00.pdf', 'uploads/parts/179229601/179229601-00.stp', 9, '2026-10-29 11:28:50', '2026-10-29 11:28:50'),
(274, 163, 'uploads/parts/178386501/178386501-00.pdf', 'uploads/parts/178386501/178386501-00.stp', 9, '2026-10-29 11:31:01', '2026-10-29 11:31:01'),
(275, 40, 'uploads/parts/179414400/179414400-00.pdf', 'uploads/parts/179414400/179414400-01.stp', 9, '2026-10-29 11:33:39', '2026-10-29 11:33:39'),
(276, 37, 'uploads/parts/179416600/179416600-00.pdf', 'uploads/parts/179416600/179416600-01.stp', 9, '2026-10-29 11:38:35', '2026-10-29 11:38:35'),
(277, 41, 'uploads/parts/179923301/179923301-00.pdf', 'uploads/parts/179923301/179923301-00.stp', 9, '2026-10-29 13:58:57', '2026-10-29 13:58:57'),
(278, 39, 'uploads/parts/179417300/179417300-00.pdf', 'uploads/parts/179417300/179417300-00.stp', 9, '2026-10-29 14:53:09', '2026-10-29 14:53:09'),
(279, 237, 'uploads/parts/190748000/190748000-00.pdf', 'uploads/parts/190748000/190748000-00.stp', 9, '2026-10-29 16:32:56', '2026-10-29 16:32:56'),
(280, 53, 'uploads/parts/179536100/179536100-00.pdf', 'uploads/parts/179536100/179536100-00.stp', 9, '2026-10-30 08:01:17', '2026-10-30 08:01:17'),
(281, 54, 'uploads/parts/179550901/179550901-00.pdf', 'uploads/parts/179550901/179550901-00.stp', 9, '2026-10-30 08:09:38', '2026-10-30 08:09:38'),
(282, 52, 'uploads/parts/179551302/179551302-00.pdf', 'uploads/parts/179551302/179551302-00.stp', 9, '2026-10-30 08:17:04', '2026-10-30 08:23:43'),
(283, 114, 'uploads/parts/181332800/181332800-00.pdf', 'uploads/parts/181332800/181332800-823.stp', 9, '2026-10-30 08:56:32', '2026-10-30 10:41:07'),
(284, 55, 'uploads/parts/179725001/179725001-00.pdf', 'uploads/parts/179725001/179725001-00.stp', 9, '2026-10-30 09:01:58', '2026-10-30 09:01:58'),
(285, 49, 'uploads/parts/179925001/179925001-00.pdf', 'uploads/parts/179925001/179925001-00.stp', 9, '2026-10-30 09:03:49', '2026-10-30 09:03:49'),
(286, 45, 'uploads/parts/179900400/179900400-00.pdf', 'uploads/parts/179900400/179900400-00.stp', 9, '2026-10-30 09:06:09', '2026-10-30 09:06:09'),
(287, 43, 'uploads/parts/179929700/179929700-00.pdf', 'uploads/parts/179929700/179929700-00.stp', 9, '2026-10-30 09:09:07', '2026-10-30 09:09:07'),
(288, 44, 'uploads/parts/179929800/179929800-00.pdf', 'uploads/parts/179929800/179929800-00.stp', 9, '2026-10-30 09:10:42', '2026-10-30 09:10:42'),
(289, 64, 'uploads/parts/180328600/180328600-00.pdf', 'uploads/parts/180328600/180328600-00.stp', 9, '2026-10-30 09:14:47', '2026-10-30 09:14:47'),
(290, 56, 'uploads/parts/180555800/180555800-01.pdf', 'uploads/parts/180555800/180555800-01.stp', 9, '2026-10-30 09:16:56', '2026-10-30 09:16:56'),
(291, 404, 'uploads/parts/1A5585000/1A5585000-00.pdf', NULL, 9, '2026-10-30 11:27:40', '2026-10-30 11:27:40'),
(292, 403, 'uploads/parts/1A5584900/1A5584900-00.pdf', NULL, 9, '2026-10-30 11:28:35', '2026-10-30 11:28:35'),
(293, 418, 'uploads/parts/192456100/192456100-00.pdf', 'uploads/parts/192456100/192456100-00.stp', 9, '2026-10-30 11:31:38', '2026-10-30 11:31:38'),
(294, 417, 'uploads/parts/193051700/193051700-00.pdf', 'uploads/parts/193051700/193051700-00.stp', 9, '2026-10-30 11:34:05', '2026-10-30 11:34:05');

-- --------------------------------------------------------

--
-- Table structure for table `master_models`
--

CREATE TABLE `master_models` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `master_models`
--

INSERT INTO `master_models` (`id`, `name`, `created_at`, `updated_at`) VALUES
(1, 'EPIC', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(2, 'DANDELION', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(3, 'ASTER', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(4, 'S15 - LIGHT', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(5, 'HOPKINS-3', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(6, 'CARREY/CAHILL', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(7, 'LEGGERO-CX', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(8, 'BAMBOO-2', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(9, 'TAKATSU-8', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(10, 'NEXT', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(11, 'LIFT', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(12, 'AERIS', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(13, 'TAKATSU-4', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(14, 'HOKUSAI-13', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(15, 'HOKUSAI-17', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(16, 'HOKUSAI-13 & 17', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(17, 'GAGA2-MU', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(18, 'GAGA2-FINISHER', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(19, 'LUPINUS', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(20, 'CARREY', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(21, 'NIKE', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(22, 'PUMA', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(23, 'LIFT-2', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(24, 'HIROSHIGE 44D', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(25, 'LIONEL', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(26, 'LIONEL-2', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(27, 'GROW', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(28, 'CARREY/CAHILL/CAVANI', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(29, 'HIROSHIGE 24 & 24D', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(30, 'HIROSHIGE 36D', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(31, 'HIROSHIGE 44DL', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(32, 'HIROSHIGE 36DM', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(33, 'HIROSHIGE 44DM', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(34, 'GLEE', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(35, 'NUSADUA', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(36, 'OTSU', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(37, 'PAPAYA', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(38, 'NIHONBASHI', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(39, 'HIROSHIGE OPT. STACKER', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(40, 'GARDA', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(41, 'OKITSU', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(42, 'NICOLE12-MC', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(43, 'NICOLE12-MS', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(44, 'NICOLE13-MC', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(45, 'NICOLE13-MS', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(46, 'COSTNER/CODY', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(47, 'CLINT/CLAUDE', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(48, 'SUSHI2 FNO/GNO', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(49, 'HOPKINS-4/HOWARD2', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(50, 'HOPKINS-4/HOWARD2, GROW MONO, GLEE FPI', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(51, 'HOPKINS4/HOWARD2', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(52, 'Carrey-FB', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(53, 'RACOON', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(54, 'GAGA-FINISHER-CUBS', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(55, 'HOPKINS4-FQR, GAGA, CUBS', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(56, 'HOPKINS4-FQR,GROW MONO, GAGA, CUBS', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(57, 'GRIT-WS', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(58, 'GLEE FPI', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(59, 'FONTANA', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(60, 'LIONEL3-MBL', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(61, 'LIONEL3-FAL', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(62, 'GROW-FPJ (MONO)', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(63, 'GROW MONO, GROW-FPG', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(64, 'GROW MONO', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(65, 'LIONEL2 TKDN', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(66, 'SUSHI SAC', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(67, 'NUSADUA-MDX', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(68, 'NICOLE-MD', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(69, 'LOUVRE2', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(70, 'FRASER', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(71, 'CINDY FG', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(72, 'CINDY', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(73, 'PLATINI-MG', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(74, 'CD28 Platini-MG', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(75, 'FN 46', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(76, 'DENIRO-SA', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(77, 'PRISM-MH', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(78, 'NAIAD-MD', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(79, 'HOPKINS2-FPR', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(80, 'HOPKINS RIPS2', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(81, 'HOWARD', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(82, 'COSTNER', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(83, 'GRIT', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(84, 'PLATINI6-MG', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(85, 'GARDA-BUNDLE', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(86, 'FUREN', '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(87, 'CRUISE2', '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(88, 'CATE', '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(89, 'GRIT-OPT', '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(90, 'LOUVRE3', '2026-09-29 09:36:59', '2026-09-29 09:36:59');

-- --------------------------------------------------------

--
-- Table structure for table `master_parts`
--

CREATE TABLE `master_parts` (
  `id` bigint UNSIGNED NOT NULL,
  `part_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `part_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint UNSIGNED DEFAULT NULL,
  `aql_level` enum('G-I','G-II','G-III') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'G-II',
  `model` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` enum('manual','auto_generated') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `created_by` bigint UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `master_parts`
--

INSERT INTO `master_parts` (`id`, `part_code`, `part_name`, `model_id`, `aql_level`, `model`, `source`, `created_by`, `created_at`, `updated_at`) VALUES
(1, '173592402', 'GUTTER,INK EJECT', 1, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(2, '173592502', 'GUTTER,INK EJECT,B', 1, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(3, '173920704', 'COVER,SIDE,STAND,INNER,LEFT', 1, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(4, '173920804', 'COVER,SIDE,STAND,INNER,RIGHT', 1, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(5, '173786101', 'COVER,SIDE,STAND,OUTER', 1, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(6, '173665702', 'FLANGE,LEFT', 1, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(7, '173665602', 'FLANGE,RIGHT', 1, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(8, '173514801', 'PAPER GUIDE,UPPER,ROLL', 1, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(9, '173514900', 'PAPER GUIDE,UPPER,ROLL,SUB', 1, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(10, '176056900', 'SPACER,HOLDER', 2, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(11, '176509901', 'COVER,RELEASE LEVER', 3, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(12, '176421901', 'COVER,INK,EJECT', 4, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(13, '176591801', 'COVER,INK,EJECT;B', 4, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(14, '176412000', 'COVER,HOUSING,INK EJECT', 4, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(15, '179239400', 'HOLDER,ROLLER,LD,SUB,ADF', 5, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(16, '179265900', 'HOLDER,FFC,EJ', 5, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(17, '179338001', 'HOLDER,LEVER,LOCK', 5, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(18, '179250401', 'EDGE GUIDE,RIGHT,RASF', 5, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(19, '179250501', 'EDGE GUIDE,LEFT,RASF', 5, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(20, '179230401', 'COVER,SUB,REAR,ADF', 5, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(21, '179238202', 'COVER,ROLLER,LD,ADF', 5, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(22, '179270700', 'LINK,LOCK,RIPS', 5, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(23, '179229601', 'STOPPER,STACKER,ADF', 5, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(24, '179251200', 'COVER,HOPPER,RASF', 5, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(25, '179340302', 'GUIDE,TUBE,UPPER', 5, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(26, '179340400', 'GUIDE,TUBE,MIDDLE', 5, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(27, '179340602', 'GUIDE,TUBE,LOWER', 5, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(28, '179256801', 'HOLDER,FFC,CIS,SC', 5, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(29, '179338200', 'GUIDE,INK PACK,UPPER', 5, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(30, '176812503', 'DOCUMENT,SUPPORT,SUB,ADF', 6, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(31, '179843203', 'DOCUMENT,SUPPORT,SUB,ADF;B', 6, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(32, '176801600', 'COVER,GEAR,FULL', 6, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(33, '176799901', 'HOUSING,RASF,HOME', 6, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(34, '179840401', 'HOUSING,RASF,HOME;B', 6, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(35, '180047401', 'COVER,DAMPER', 7, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(36, '180047501', 'FRAME,VALVE', 7, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(37, '179416600', 'COVER,ABSORBER,RIGHT', 8, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(38, '179417000', 'HOLDER,PAPER', 8, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(39, '179417300', 'LEVER,EDGE GUIDE', 8, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(40, '179414400', 'HOLDER,RPE', 8, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(41, '179923301', 'CASE,MCB;AA', 9, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(42, '189131500', 'PAPER GUIDE,PEELER', 9, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(43, '179929700', 'LEVER,PAPER COVER,REAR;AA', 9, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(44, '179929800', 'LEVER,PAPER COVER,FRONT;AA', 9, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(45, '179900400', 'COVER,SCALE,CR', 9, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(46, '180055501', 'HOLDER,POROUS,PAD', 9, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(47, '179899801', 'COVER,SUB', 9, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(48, '179922901', 'CASE,REAR;AA', 9, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(49, '179925001', 'CASE,MIDDLE,RIGHT;AA', 9, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(50, '179554201', 'LOCK,CR', 10, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(51, '179537600', 'HOLDER,ASF', 10, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(52, '179551302', 'LIGHT GUIDE', 10, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(53, '179536100', 'LOCK,CASSETTE', 10, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(54, '179550901', 'COVER,LIGHT,GUIDE', 10, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(55, '179725001', 'COVER,LIGHT GUIDE', 11, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(56, '180555800', 'COVER,HOUSING,UPPER,SC', 11, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(57, '180319100', 'HOLDER,FFC,PANEL', 12, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(58, '180320702', 'SLIDER,LOCK,STAND', 12, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(59, '180318100', 'STOPPER,COVER,CONECTOR', 12, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(60, '180321002', 'CAP,HOUSING,LOWER,SUPPORT,UPPER', 12, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(61, '180321102', 'CAP,HOUSING,LOWER,SUPPORT,MIDDLE', 12, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(62, '180321202', 'CAP,HOUSING,LOWER,SUPPORT,LOWER', 12, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(63, '180315500', 'LEVER,EDGE GUIDE', 12, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(64, '180328600', 'HOLDER,ENC,ALD', 12, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(65, '180641801', 'CASE,REAR,F;AA', 13, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(66, '180642201', 'CASE,MCB,F;AA', 13, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(67, '180634300', 'COVER,SUB,F', 13, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(68, '188881100', 'PAPER GUIDE,PEELER,F', 13, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(69, '180917902', 'COLOR BLOCK,IH,CQ', 14, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(70, '180917702', 'COLOR BLOCK,IH,AN', 14, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(71, '180918002', 'COLOR BLOCK,IH,DK', 14, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(72, '180918402', 'COLOR BLOCK,IH,HM', 14, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(73, '180918602', 'COLOR BLOCK,IH,JO', 14, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(74, '180919502', 'COLOR BLOCK,IH,SB', 15, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(75, '180919102', 'COLOR BLOCK,IH,OH', 15, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(76, '180918802', 'COLOR BLOCK,IH,LI', 15, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(77, '180919202', 'COLOR BLOCK,IH,PJ', 15, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(78, '180919302', 'COLOR BLOCK,IH,QF', 15, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(79, '180918302', 'COLOR BLOCK,IH,GP', 14, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(80, '180918202', 'COLOR BLOCK,IH,FT', 14, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(81, '180917802', 'COLOR BLOCK,IH,BS', 14, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(82, '180918102', 'COLOR BLOCK,IH,ER', 14, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(83, '180918502', 'COLOR BLOCK,IH,IL', 14, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(84, '181010501', 'HOLDER,POROUS PAD,PAPER GUIDE,LOWER', 14, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(85, '180774100', 'HOLDER,INK,DETECTION', 16, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(86, '186162300', 'TRAY,BD;B', 16, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(87, '180918702', 'COLOR BLOCK,IH,KD', 15, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(88, '180918902', 'COLOR BLOCK,IH,MG', 15, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(89, '180919002', 'COLOR BLOCK,IH,NA', 15, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(90, '180919402', 'COLOR BLOCK,IH,RC', 15, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(91, '180919602', 'COLOR BLOCK,IH,TE', 15, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(92, '180681702', 'STACKER,SUB,REAR', 14, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(93, '180681800', 'STACKER,SUB,FRONT', 14, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(94, '181907400', 'COVER,HARNESS,PS,1ST', 14, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(95, '181916600', 'COVER,HARNESS,PS,2ND', 14, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(96, '180690002', 'GUIDE,TRAY,FRONT,RIGHT', 16, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(97, '192307200', 'GUIDE,TRAY,FRONT,LEFT;C', 16, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(98, '180706501', 'PAPER GUIDE,REAR', 14, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(99, '180690502', 'TRAY,ASSIST', 14, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(100, '181010400', 'HOLDER,HARNESS,MOTOR,PF', 14, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(101, '180691502', 'TRAY,ASSIST', 15, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(102, '180671901', 'FRAME,TOP,RIGHT', 16, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(103, '180678301', 'PAPER GUIDE,REAR', 15, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(104, '180674403', 'COSMETIC PLATE,PANEL', 16, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(105, '180937200', 'HAND GRIP,SUPPORT,MU2', 17, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(106, '180935300', 'COVER,PAPER GUIDE,UPPER,1,FI,MU2', 17, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(107, '180935401', 'COVER,PAPER GUIDE,UPPER,2,FI,MU2', 17, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(108, '181575502', 'TRAY,SUPPORT,REAR LOWER,CD,E,F1', 18, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(109, '181575402', 'TRAY,SUPPORT,FRONT LOWER,CD,E,F1', 18, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(110, '181579001', 'COVER,R,SUPPORT,F1', 18, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(111, '179922600', 'CASE,MIDDLE;AA', 9, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(112, '180937101', 'HAND,GRIP,MU2', 17, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(113, '181578200', 'COVER,HT,F1', 18, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(114, '181332800', 'COVER,CABLE', 19, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(115, '181333000', 'COVER,CABLE,MSRW', 19, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(116, '181703100', 'HOUSING,REAR,SUPPORT,F2', 18, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(117, '183519101', 'COVER,IC,SUB', 20, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(118, '183670500', 'COVER,ROLLER,LD,ADF;B', 5, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(119, '182995200', 'COVER,REAR', 21, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(120, '182955104', 'COVER,CASSETTE', 21, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(121, '183737804', 'COVER,CASSETTE;B', 21, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(122, '183326500', 'COVER,UPPER,PS UNIT', 21, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(123, '182975200', 'FRAME,COVER', 22, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(124, '182962300', 'FRAME,COVER,REAR', 21, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(125, '182996301', 'HOUSING,PANEL,UPPER', 21, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(126, '182996401', 'HOUSING,PANEL,LOWER', 21, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(127, '182994701', 'HOUSING,UPPER,FRONT', 21, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(128, '182954701', 'FRAME,FRONT', 21, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(129, '182958901', 'FRAME,FRONT', 22, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(130, '184469200', 'COVER,UPPER,PS UNIT;B', 21, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(131, '184874201', 'COVER,FFC', 23, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(132, '185101702', 'LIGHT GUIDE', 23, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(133, '184874301', 'COVER,LIGHT GUIDE', 23, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(134, '184495509', 'HOUSING,FRONT,LEFT', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(135, '184495603', 'HOUSING,FRONT,RIGHT', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(136, '184323801', 'PULLEY,MIDDLE,SPACER', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(137, '184409603', 'FLAP,EJ,24', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(138, '184458403', 'COVER,DRAWER,LEFT', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(139, '184494903', 'COVER,JOINT,DRAWER,LEFT', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(140, '184494701', 'COVER,LEVER,DRAWER', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(141, '184458901', 'HOLDER,PANEL,HINGE', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(142, '183834905', 'HOLDER,IH,LOWER,GJ', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(143, '184328905', 'HOLDER,COVER,PRINTER', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(144, '184376403', 'HOUSING,TOP,FRONT,CENTER', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(145, '184390503', 'HOUSING,TOP,FRONT,LEFT', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(146, '184376502', 'HOUSING,TOP,FRONT,RIGHT', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(147, '184432202', 'COVER,LOCK,SPINDLE,HOME', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(148, '184432303', 'COVER,LOCK,SPINDLE,FULL', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(149, '184498305', 'HOLDER,FRONT,RIGHT', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(150, '184371801', 'COVER,FFC,CR,20', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(151, '184553705', 'HOLDER,IH,LOWER,RJ', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(152, '184553805', 'HOLDER,IH,LOWER,GW', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(153, '184553905', 'HOLDER,IH,LOWER,RW', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(154, '185192003', 'HOUSING,REAR,D', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(155, '185192102', 'HOUSING,REAR,E', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(156, '184875100', 'COVER,FRONT,SC', 23, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(157, '173909000', 'COVER,INK EJECT;B', 25, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(158, '171790900', 'COVER,POROUS', 25, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(159, '171792001', 'COVER,CR', 25, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(160, '173336001', 'COVER,HOUSING,INK EJECT', 25, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(161, '173301400', 'HOLDER,CABLE,CSIC,INK EJECT', 25, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(162, '185926400', 'GUIDE,CAP,KEEP,HIR', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(163, '178386501', 'COVER,LD,SUB #2', 20, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(164, '186255000', 'COVER,INK,EJECT;RPSK;B', 26, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(165, '186717100', 'DUCT,HEAD,EXHAUST,FR', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(166, '186653403', 'GUIDE,HEAD,FRONT,HM', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(167, '186739302', 'COVER,CLEANER,LOWER,BU', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(168, '186757600', 'GUIDE,SHAFT,ROLLER,DRIVEN,WEB,BCL', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(169, '186759802', 'PAPER GUIDE,MIDDLE,MPT', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(170, '186778701', 'MOUNTING PLATE,PAPER GUIDE,FRONT,ASF', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(171, '186778900', 'PAPER GUIDE,FRONT,ASF', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(172, '186691800', 'COVER,GEAR,UPPER,DUP', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(173, '186263702', 'HOUSING,BOTTLE HOLDER,ICH', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(174, '186266502', 'HOLDER,BOARD,DETECT,MD', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(175, '186281203', 'HOUSING,MOISTURIZER,LOWER', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(176, '186281800', 'HOLDER,BOARD,ACES,MW', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(177, '186286001', 'FRAME,HOLDER,INK EJECT,IE', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(178, '186813501', 'FLAP,BF', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(179, '186816601', 'COVER,RIGHT,BF', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(180, '186767201', 'STOPPER,CASSETTE,MOVABLE', 28, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(181, '186792002', 'COVER,SENSOR,FRONT,STACKER', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(182, '186792101', 'COVER,MIDDLE,STACKER', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(183, '186792201', 'COVER,SENSOR,REAR,STACKER', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(184, '187307101', 'COVER,STACKER,24', 29, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(185, '187308400', 'COVER,BOTTOM', 29, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(186, '187308500', 'COVER,BOTTOM;C', 29, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(187, '187311900', 'PAPER GUIDE,SUPPORT,12,A', 30, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(188, '187312400', 'GUTTER,INK,EJECT,12', 30, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(189, '187312501', 'GUTTER,INK,EJECT,24', 30, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(190, '187313300', 'COVER,FFC,CR,12', 30, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(191, '187314901', 'PAPER GUIDE,ROLL,ENTRANCE,UPPER,12', 30, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(192, '187315100', 'PAPER GUIDE,ROLL,JUNCTION,12', 30, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(193, '187315200', 'PAPER GUIDE,ROLL,INSIDE,12', 30, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(194, '187304301', 'BASE,PAPER GUIDE,FRONT,SIDE', 29, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(195, '187385503', 'PAPER GUIDE,EJ,LOWER,OIF', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(196, '187385602', 'PAPER GUIDE,EJ,UPPER,OIF', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(197, '187393603', 'TRAY,INK PACK', 31, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(198, '187460004', 'COVER,TOP', 31, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(199, '187460503', 'COVER,DOOR', 31, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(200, '187805302', 'COVER,FRONT,LEFT,SCN,36', 32, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(201, '187805500', 'HOLDER,COVER,PRINTER', 33, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(202, '190338801', 'PAPER SUPPORT,SUB,MPT', 34, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(203, '189045902', 'COVER,PCB,UPPER,ADF', 34, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(204, '190315600', 'CLEAR COVER,I/C', 34, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(205, '190315802', 'DECORATION PLATE,FRONT', 34, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(206, '188892301', 'HOUSING,PANEL', 35, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(207, '188889702', 'HOUSING,UPPER,FRONT', 35, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(208, '188839702', 'HOUSING,REAR,LEFT', 36, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(209, '188839801', 'HOUSING,REAR,RIGHT', 36, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(210, '188842801', 'LIGHT GUIDE,PNL', 36, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(211, '188837502', 'STACKER', 36, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(212, '188842300', 'STOPER,LEVER,LOCK', 36, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(213, '188894903', 'PAPER SUPPORT,MAIN', 37, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(214, '189196501', 'PAPER SUPPORT,SUB', 37, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(215, '189196603', 'PAPER SUPPORT,GUIDE', 37, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(216, '189196701', 'PAPER SUPPORT,COVER', 37, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(217, '189196801', 'PAPER SUPPORT,UPPER', 37, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(218, '189323201', 'COVER,SLOT,EDGE', 38, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(219, '189381401', 'COVER,SLOT,LEFT;B', 38, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(220, '189323105', 'COVER,TOP;B', 38, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(221, '189215702', 'COVER,UPPER,REAR', 39, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(222, '189216102', 'COVER,FRAME,RIGHT', 39, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(223, '189216201', 'COVER,UPPER,CENTER', 39, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(224, '189216302', 'COVER,FRAME,LEFT', 39, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(225, '189442801', 'COVER,UPPER,CENTER,B', 39, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(226, '189450903', 'STAND,POSITIONING', 39, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(227, '189672802', 'PAPER SUPPORT,3RD,LEFT', 40, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(228, '189672702', 'PAPER SUPPORT,3RD,RIGHT', 40, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(229, '189969201', 'COVER,DRIVE,TF', 41, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(230, '190119001', 'COVER,HARNESS,FRONT,PANEL CUT,BU', 34, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(231, '190732002', 'COVER,LEFT,OPT2', 34, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(232, '191575100', 'COVER,TOP;C', 31, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(233, '190747300', 'COVER,CR,REAR;B', 41, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(234, '190747400', 'COVER,SUPPORT ARM,TRAY,RIGHT;B', 41, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(235, '190747500', 'COVER,SUPPORT ARM,TRAY,LEFT;B', 41, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(236, '190747900', 'HOLDER,IH,RIGHT;B', 41, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(237, '190748000', 'HOLDER,IH,LEFT;B', 41, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(238, '188912801', 'HOLDER,FRONT,RIGHT,RECYCLE', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(239, '191353000', 'HOUSING,REAR,LEFT,RABSK', 36, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(240, '191353100', 'HOUSING,REAR,RIGHT,RABSK', 36, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(241, '191352600', 'STACKER,RABSK', 36, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(242, '190038800', 'PAPER GUIDE,ROLL,INSIDE,12,RECYCLE', 30, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(243, '189678304', 'PAPER SUPPORT,2ND,RIGHT', 40, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(244, '189678404', 'PAPER SUPPORT,2ND,LEFT', 40, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(245, '191758701', 'HOUSING,FRONT,LOWER,LEFT', 42, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(246, '191758901', 'HOUSING,FRONT,LOWER,LEFT', 43, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(247, '193272500', 'HOUSING,FRONT,LOWER,LEFT;B', 44, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(248, '193272700', 'HOUSING,FRONT,LOWER,LEFT;B', 45, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(249, '193599100', 'LOCK,TRAY;B', 46, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(250, '193599200', 'LOCK,TRAY;B', 47, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(251, '193635800', 'COVER,UPPER,HINJI,RIGHT;C', 48, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(252, '193635900', 'COVER,UPPER,HINJI,LEFT;C', 48, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(253, '191718900', 'HOUSING,RIGHT,SUB;B', 49, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(254, '191719000', 'HOUSING,LEFT,SUB;B', 49, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(255, '191723200', 'HOLDER,MANUAL;B', 50, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(256, '161440902', 'STOPPER,CR', 51, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(257, '176412101', 'HOLDER,CABLE,CSIC,INK EJECT', 4, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(258, '189391000', 'LEVER,STOPPER,CASSETTE,KP', 52, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(259, '188961900', 'TRAY,SUPPORT,FRONT LOWER,CD,E,F1;B', 53, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(260, '188962000', 'TRAY,SUPPORT,REAR LOWER,CD,E,F1;B', 53, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(261, '189390200', 'LEVER,LOCK,CR,KP', 10, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(262, '186868700', 'HOLDER,CLOTH,UV', 27, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(263, '190578900', 'HOUSING,REAR,SUPPORT,F2,UV', 54, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(264, '191998200', 'COVER,SUB,REAR,ADF;B', 55, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(265, '191999600', 'HOUSING,REAR,LEFT,SC;B', 56, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(266, '193972500', 'HOUSING,REAR,LEFT,SC;C', 57, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(267, '193972200', 'HOUSING,REAR,RIGHT,SC;C', 57, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(268, '192057501', 'GUIDE,STORAGE,STACKER', 39, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(269, '193437400', 'HOUSING,LEFT,SUPPORT', 58, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(270, '193579000', 'PAPER GUIDE,CIS,ADF', 59, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(271, '192456700', 'LENS', 60, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(272, '193050301', 'PAPER GUIDE,UPPER,MIDDLE', 60, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(273, '193050201', 'PAPER GUIDE,UPPER,RIGHT', 60, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(274, '193510600', 'PAPER GUIDE,EJ,ADF', 61, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(275, '193973700', 'COVER,BOTTLE HOLDER,ICH', 62, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(276, '192950900', 'PAPER SUPPORT,LD,SUB,MPT;B', 63, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(277, '192951000', 'SUPPORT,STACKER,TLU;B', 64, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(278, '193930300', 'COVER,FRONT,ADF;C', 58, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(279, '193930200', 'COVER,REAR,ADF;C', 58, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(280, '193926600', 'PAPER SUPPORT,MPT;B', 58, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(281, '193929600', 'DECORATION PLATE,FRONT;B', 58, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(282, '189672602', 'PAPER SUPPORT,1ST,OUTER', 40, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(283, '189732302', 'PAPER SUPPORT,1ST,OUTER;B', 40, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(284, '189672501', 'PAPER SUPPORT,1ST,INNER', 40, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(285, '189673301', 'RACK,EDGE GUIDE', 40, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(286, '189576901', 'LINK,PAPER SUPPORT,RIGHT', 40, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(287, '189577001', 'LINK,PAPER SUPPORT,LEFT', 40, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(288, '191999701', 'HOUSING,REAR,RIGHT,SC;B', 55, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(289, '185947500', 'COVER,INK,EJECT;RPSK', 65, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(290, '166702301', 'COVER,UPPER,HINJI,RIGHT', 66, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(291, '166702401', 'COVER,UPPER,HINJI,LEFT', 66, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(292, '169023401', 'COVER,UPPER,HINJI,RIGHT;B', 66, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(293, '169023501', 'COVER,UPPER,HINJI,LEFT;B', 66, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(294, '191834301', 'HOUSING,PANEL', 67, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(295, '191834602', 'HOUSING,UPPER,FRONT', 67, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(296, '193437801', 'DECORATION PLATE,FRONT', 58, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(297, '193438701', 'CASE,INNER,BOX,UPPER', 58, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(298, '193578901', 'BACKGROUND PLATE,ADF', 59, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(299, '193050401', 'PAPER GUIDE,UPPER,LEFT', 60, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(300, '158909602', 'COVER,HOUSING,UPPER,SC,CA;B', 68, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(301, '189253101', 'COVER,HOUSING,UPPER,SC,CA;G', 68, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(302, '190664902', 'COVER,RIGHT,LOWER,OPT1', 34, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(303, '194673000', 'HOLDER,FRAME,LOWER;C', 39, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(304, '174601700', 'HOLDER,HOUSING,INK EJECT', 69, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(305, '154176200', 'HOUSING,PANEL', 70, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(306, '157529401', 'FRAME,PAPER SUPPORT', 71, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(307, '160400600', 'FRAME,PAPER SUPPORT', 72, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(308, '159973400', 'HOUSING,LOWER,LEFT #1', 73, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(309, '160668600', 'HOUSING,LOWER,LEFT;B #1', 74, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(310, '162973501', 'HOUSING,FRONT,LEFT', 75, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(311, '163164701', 'HOUSING,FRONT,LEFT;B', 75, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(312, '165667200', 'HOUSING,FRONT,LEFT;C', 75, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(313, '165667300', 'HOUSING,FRONT,LEFT;D', 75, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(314, '165667400', 'HOUSING,FRONT,LEFT;E', 75, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(315, '183034400', 'HOUSING,FRONT,LEFT;F', 75, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(316, '163219701', 'HOPPER,RASF', 76, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(317, '166363200', 'COVER,DUPLEX,ASF', 77, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(318, '169390202', 'FRAME,LOWER,LEFT', 78, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(319, '169708500', 'HOUSING,REAR,LEFT,ADF', 79, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(320, '169708601', 'HOUSING,REAR,RIGHT,ADF', 79, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(321, '191718100', 'HOUSING,REAR,LEFT,ADF;B', 80, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(322, '191718200', 'HOUSING,REAR,RIGHT,ADF;B', 80, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(323, '169691102', 'COVER,INNER', 79, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(324, '191716600', 'COVER,INNER;B', 81, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(325, '177266100', 'HOLDER,BUFFER', 82, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(326, '174617201', 'HOUSING,FRONT,RIGHT', 69, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(327, '195733700', 'PAPER SUPPORT,UPPER,MPT;B,PL', 58, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(328, '195679500', 'PAPERGUIDE,PWD,MPT', 83, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(329, '195723900', 'DUCT,HEAD,BASE,LEFT', 83, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(330, '195724000', 'DUCT,HEAD,BASE,RIGHT', 83, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(331, '195688400', 'COVER,PAPER GUIDE,SB,UPPER,EJ', 83, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(332, '195722700', 'COVER,FFC,PANEL', 83, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(333, '195724100', 'DUCT,FAN', 83, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(334, '195669100', 'HOLDER,MPT,BRAKE,RESIST,PF', 83, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(335, '195723300', 'COVER,WIFI', 83, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(336, '195687600', 'COVER,PG,SB,INNER,REAR,EJ', 83, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(337, '195646100', 'HOUSING,LOWER,LEFT', 84, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(338, '195646200', 'HOUSING,LOWER,LEFT;B', 84, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(339, '195060701', 'HOLDER,RECEIPT,2ND', 85, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(340, '195060801', 'HOLDER,RECEIPT,3RD', 85, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:58', '2026-09-29 09:36:58'),
(341, '194927801', 'DOCUMENT SUPPORT,ADF', 86, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(342, '195060602', 'HOLDER,RECEIPT,1ST', 85, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(343, '196870501', 'HOUSING,PANEL,UPPER,FD', 87, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(344, '196870601', 'HOUSING,PANEL,UPPER,FD;B', 87, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(345, '196870901', 'HOUSING,PANEL,MIDDLE', 87, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(346, '195720801', 'CLEAR COVER,I/C', 83, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(347, '195717301', 'HOLDER,HARNESS', 83, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(348, '194927701', 'COVER,LD,ADF', 86, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(349, '194926501', 'HOUSING,PANEL,UPPER', 86, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(350, '196575301', 'STOPPER,CASSETTE,MOVABLE', 88, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(351, '1A4328500', 'COVER,INK EJECT;C', 60, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(352, '1A4328600', 'COVER,INK EJECT;D', 60, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(353, '1A4999200', 'HOUSING,PANEL,UPPER;B', 86, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(354, '196870702', 'HOUSING,PANEL,LOWER,FD', 87, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(355, '196870802', 'HOUSING,PANEL,LOWER,FD;B', 87, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(356, '1A4558201', 'PAPER GUIDE,STOPPER,FRONT,OPT', 89, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(357, '1A4558001', 'HOLDER,EXTEND,OPT', 89, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(358, '194775401', 'COVER,TANK', 90, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(359, '184314701', 'FLAP', 24, 'G-II', NULL, 'manual', 1, '2026-09-29 09:36:59', '2026-09-29 09:36:59'),
(360, '176591500', 'PAPER SUPPORT B ENN7', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:24:37', '2026-10-04 23:24:37'),
(361, '157211701', 'LEVER ASF LEFT', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:24:37', '2026-10-04 23:24:37'),
(362, '169424900', 'SHAFT LD', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:24:37', '2026-10-04 23:24:37'),
(363, '170445300', 'ARM FRONT ADF', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:24:37', '2026-10-04 23:24:37'),
(364, '157179601', 'SLIDER TRANSMISSION RIGHT', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:24:37', '2026-10-04 23:24:37'),
(365, '153806800', 'HOUSING PICK UP LEFT 2ND', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:24:37', '2026-10-04 23:24:37'),
(366, '175464800', 'ROLLER CDR WHITE', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:24:37', '2026-10-04 23:24:37'),
(367, '169683200', 'KNOB LOCK LEVER', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:24:37', '2026-10-04 23:24:37'),
(368, '157571800', 'GUIDE HINGE', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:24:37', '2026-10-04 23:24:37'),
(369, '106279700', 'HOLDER RPE', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:24:37', '2026-10-04 23:24:37'),
(370, '160932500', 'LEVER DOCUMENT GUIDE ADF', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:24:37', '2026-10-04 23:24:37'),
(371, '160930400', 'HOLDER PROPELLER AJ ADF', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:24:37', '2026-10-04 23:24:37'),
(372, '170108801', 'LEVER DOCUMENT SUPPORT ADF B EBCK', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:24:37', '2026-10-04 23:24:37'),
(373, '169390101', 'FRAME LOWER RIGHT', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:24:37', '2026-10-04 23:24:37'),
(374, '157211601', 'LEVER ASF RIGHT', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:24:37', '2026-10-04 23:24:37'),
(375, '190041102', 'PAPER GUIDE,SUPPORT,RIGHT B :CH81:IEI', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:47:57', '2026-10-04 23:47:57'),
(376, '188654502', 'PAPER GUIDE,SUPPORT,LEFT:CH81:IEI', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:47:57', '2026-10-04 23:47:57'),
(377, '1A4211100', 'HOUSING LOWER RIGHT ASSY;B;CL73;IEI', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:47:57', '2026-10-04 23:47:57'),
(378, '161294200', 'COVER,CRCM', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:47:57', '2026-10-04 23:47:57'),
(379, '180323501', 'COVER,CIS,LOWER', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:47:57', '2026-10-04 23:47:57'),
(380, '180322902', 'COVER,CIS,UPPER', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:47:57', '2026-10-04 23:47:57'),
(381, '184314700', 'FLAP', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:47:57', '2026-10-04 23:47:57'),
(382, '192371500', 'PAPER,SUPPORT,WHITE,B271,ASSY;B;IEI', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:47:57', '2026-10-04 23:47:57'),
(383, '169679701', 'GUIDE,HARNESS,REAR', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:47:57', '2026-10-04 23:47:57'),
(384, '192371700', 'PAPER,SUPPORT,BLACK,ASSY;B;IEI', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-04 23:47:57', '2026-10-04 23:47:57'),
(385, '1', 'coba', 46, 'G-II', NULL, 'manual', 1, '2026-10-07 12:06:03', '2026-10-07 12:06:03'),
(386, '169698100', 'COVER INK HOLDER', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:14:49', '2026-10-09 16:14:49'),
(387, '189672601', 'PAPER SUPPORT 1ST OUTER', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:14:49', '2026-10-09 16:14:49'),
(388, '160978202', 'RAIL STACKER RIGHT', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:14:49', '2026-10-09 16:14:49'),
(389, '160978302', 'RAIL STACKER LEFT', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:14:49', '2026-10-09 16:14:49'),
(390, '160930300', 'HOLDER ENCODER SUPPORT LD ADF', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:14:49', '2026-10-09 16:14:49'),
(391, '160931400', 'LINK LOCK COVER ADF', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:14:49', '2026-10-09 16:14:49'),
(392, '160389100', 'ATTACHMENT HOUSING UPPER SC BLACK', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:14:49', '2026-10-09 16:14:49'),
(393, '176423401', 'PAPER SUPPORT EDG2', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:14:49', '2026-10-09 16:14:49'),
(394, '1A5563200', 'COVER HOUSING INK EJECT B', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:14:49', '2026-10-09 16:14:49'),
(395, '1A5563300', 'COVER HOUSING INK EJECT C', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:14:49', '2026-10-09 16:14:49'),
(396, '160670100', 'ATTACHMENT HOUSING UPPER SC B WHITE', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:14:49', '2026-10-09 16:14:49'),
(397, '189196502', 'PAPER SUPPORT SUB (PAPAYA)', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:14:49', '2026-10-09 16:14:49'),
(398, '180744100', 'HOLDER IN DETECTION', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:14:49', '2026-10-09 16:14:49'),
(399, '188894904', 'PAPER SUPPORT MAIN (PAPAYA)', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:15:11', '2026-10-09 16:15:11'),
(400, '176591501', 'PAPER SUPPORT B ENN7', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:15:11', '2026-10-09 16:15:11'),
(401, '106279000', 'COVER RIBBON DRIVE', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:15:11', '2026-10-09 16:15:11'),
(402, '186767200', 'STOPPER CASSETTE MOVABLE', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:15:11', '2026-10-09 16:15:11'),
(403, '1A5584900', 'COVER INK EJECT RPSK C', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:15:11', '2026-10-09 16:15:11'),
(404, '1A5585000', 'COVER INK EJECT D', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:15:11', '2026-10-09 16:15:11'),
(405, '1A5044901', 'CLEAR COVER I/C B (GRIT-FPA)', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:15:11', '2026-10-09 16:15:11'),
(406, '1A5108900', 'CLEAR COVER I/C B (GLEE MECHA)', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:15:11', '2026-10-09 16:15:11'),
(407, '1A5301100', 'COVER LIGHT GUDIE (LIONEL3 MBL)', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:15:11', '2026-10-09 16:15:11'),
(408, '193635801', 'COVER UPPER HINGE RIGHT C #1', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:15:11', '2026-10-09 16:15:11'),
(409, '193635901', 'COVER UPPER HINGE LEFT C #1', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:15:11', '2026-10-09 16:15:11'),
(410, '1896728020', 'PAPER SUPPORT 3RD LEFT', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:15:11', '2026-10-09 16:15:11'),
(411, '189672700', 'PAPER SUPPORT 3RD RIGHT', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:15:11', '2026-10-09 16:15:11'),
(412, '189196901', 'PAPER SUPPORT UPPER (PAPAYA)', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:15:44', '2026-10-09 16:15:44'),
(413, '189196601', 'PAPER SUPPORT GUIDE (PAPAYA)', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:15:44', '2026-10-09 16:15:44'),
(414, '189576902', 'LINK PAPER SUPPORT RIGHT', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:15:44', '2026-10-09 16:15:44'),
(415, '162817100', 'HANDLE CABINET', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:15:44', '2026-10-09 16:15:44'),
(416, '127813800', 'HOLDER CABLE CR - MSRW', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-09 16:15:44', '2026-10-09 16:15:44');
INSERT INTO `master_parts` (`id`, `part_code`, `part_name`, `model_id`, `aql_level`, `model`, `source`, `created_by`, `created_at`, `updated_at`) VALUES
(417, '193051700', 'COVER INK EJECT B (LIONEL 3)', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-12 09:15:58', '2026-10-12 09:15:58'),
(418, '192456100', 'COVER INK EJECT (LIONEL 3)', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-12 09:15:58', '2026-10-12 09:15:58'),
(419, '169387500', 'HOLDER CABLE CSIC INK EJECT (NAIAD)', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-12 09:15:58', '2026-10-12 09:15:58'),
(420, '1A4558200', 'PAPER GUIDE STOPPER FRONT OPT', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-12 09:15:58', '2026-10-12 09:15:58'),
(421, '1A4558000', 'HOLDER EXTEND OPT', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-12 09:15:58', '2026-10-12 09:15:58'),
(422, '1A5959200', 'APAPER SUPPORT LD SUB MPT C', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-12 09:16:39', '2026-10-12 09:16:39'),
(423, '183326501', 'COVER UPPER PS UNIT', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-12 09:17:45', '2026-10-12 09:17:45'),
(424, '188894902', 'PAPER SUPPORT MAIN (PAPAYA)', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-12 09:17:45', '2026-10-12 09:17:45'),
(425, '180421800', 'HOLDER CLOTH', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-12 09:17:45', '2026-10-12 09:17:45'),
(426, '194972801', 'DOCUMENT SUPPORT ADF (FUREN)', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-12 09:17:45', '2026-10-12 09:17:45'),
(427, '170100801', 'LEVER DOCUMENT SUPPORT ADF B EBCK', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-12 09:17:45', '2026-10-12 09:17:45'),
(428, '153786101', 'COVER SIDE STAND OUTER', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-12 09:19:23', '2026-10-12 09:19:23'),
(429, '189732300', 'PAPER SUPPORT 1ST OUTER B (WHITE)', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-12 09:19:23', '2026-10-12 09:19:23'),
(430, '189196602', 'PAPER SUPPORT GUIDE (PAPAYA)', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-12 09:21:25', '2026-10-12 09:21:25'),
(431, '188894901', 'PAPER SUPPORT MAIN (PAPAYA)', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-12 09:21:25', '2026-10-12 09:21:25'),
(432, '191377601', 'TRAY,INK PACK ASSY;CK08:IEI', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-13 09:36:47', '2026-10-13 09:36:47'),
(433, '189717901', 'TRAY INK PACK ASSY;CJ74;IEI', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-14 11:24:54', '2026-10-14 11:24:54'),
(434, '192371600', 'PAPER,SUPPORT,WHITE,ASSY;B;IEI', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-14 15:00:32', '2026-10-14 15:00:32'),
(435, '196428700', 'HOLDER,RECEIPT,ASSY,B;IEI', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-14 15:00:32', '2026-10-14 15:00:32'),
(436, '177266101', 'HOLDER,BUFFER', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-16 10:33:28', '2026-10-16 10:33:28'),
(437, '189438901', 'PAPER SUPPORT ASSY;IEI', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-21 08:10:43', '2026-10-21 08:10:43'),
(438, '196870803', 'HOUSING PENEL LOWER FD B WHITE (CRUICE)', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-22 07:58:09', '2026-10-22 07:58:09'),
(439, '196870703', 'HOUSING PENEL LOWER FD', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-22 07:58:09', '2026-10-22 07:58:09'),
(440, '1A4219900', 'HOUSING LOWER RIGHT ASSY;B;CG17;IEI', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-22 14:23:20', '2026-10-22 14:23:20'),
(441, '1A6677100', 'DUCT,HEAD,ASSY.;2;B,GT', NULL, 'G-II', NULL, 'auto_generated', NULL, '2026-10-27 14:14:49', '2026-10-27 14:14:49');

-- --------------------------------------------------------

--
-- Table structure for table `oqc_daily_defect_summary`
--

CREATE TABLE `oqc_daily_defect_summary` (
  `id` bigint UNSIGNED NOT NULL,
  `summary_date` date NOT NULL,
  `customer` varchar(100) NOT NULL DEFAULT '',
  `part_id` bigint UNSIGNED NOT NULL DEFAULT '0',
  `model_id` bigint UNSIGNED DEFAULT '0',
  `defect_type_id` bigint UNSIGNED NOT NULL DEFAULT '0',
  `lot_count` int NOT NULL DEFAULT '0',
  `qty_ng` int NOT NULL DEFAULT '0',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `oqc_daily_defect_summary`
--

INSERT INTO `oqc_daily_defect_summary` (`id`, `summary_date`, `customer`, `part_id`, `model_id`, `defect_type_id`, `lot_count`, `qty_ng`, `updated_at`) VALUES
(1, '2026-11-01', 'PT. Indonesia Epson Industry', 385, 46, 23, 2, 2, '2026-11-01 16:17:12'),
(2, '2026-11-01', 'PT. Indonesia Epson Industry', 385, 46, 24, 1, 1, '2026-11-01 16:17:12');

-- --------------------------------------------------------

--
-- Table structure for table `oqc_daily_summary`
--

CREATE TABLE `oqc_daily_summary` (
  `id` bigint UNSIGNED NOT NULL,
  `summary_date` date NOT NULL,
  `customer` varchar(100) NOT NULL DEFAULT '',
  `part_id` bigint UNSIGNED NOT NULL DEFAULT '0',
  `model_id` bigint UNSIGNED NOT NULL DEFAULT '0',
  `inspection_type` varchar(50) NOT NULL DEFAULT 'kanban',
  `total_sessions` int NOT NULL DEFAULT '0',
  `passed_sessions` int NOT NULL DEFAULT '0',
  `rejected_sessions` int NOT NULL DEFAULT '0',
  `total_lots` int NOT NULL DEFAULT '0',
  `passed_lots` int NOT NULL DEFAULT '0',
  `rejected_lots` int NOT NULL DEFAULT '0',
  `total_sample_size` int NOT NULL DEFAULT '0',
  `total_sample_size_lot` int DEFAULT '0',
  `total_sample_size_session` int DEFAULT '0',
  `total_ng_samples` int NOT NULL DEFAULT '0',
  `total_ng_pcs` int NOT NULL DEFAULT '0',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `oqc_daily_summary`
--

INSERT INTO `oqc_daily_summary` (`id`, `summary_date`, `customer`, `part_id`, `model_id`, `inspection_type`, `total_sessions`, `passed_sessions`, `rejected_sessions`, `total_lots`, `passed_lots`, `rejected_lots`, `total_sample_size`, `total_sample_size_lot`, `total_sample_size_session`, `total_ng_samples`, `total_ng_pcs`, `updated_at`) VALUES
(1, '2026-11-01', 'PT. Indonesia Epson Industry', 385, 46, 'kanban', 8, 5, 3, 12, 9, 3, 240, 240, 214, 3, 3, '2026-11-01 16:17:12');

-- --------------------------------------------------------

--
-- Table structure for table `rejection_sheet_prints`
--

CREATE TABLE `rejection_sheet_prints` (
  `id` bigint UNSIGNED NOT NULL,
  `inspection_session_id` bigint UNSIGNED NOT NULL,
  `print_type` enum('auto','manual_reprint') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto',
  `printed_by` bigint UNSIGNED DEFAULT NULL,
  `printed_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `treatment_type` enum('SORTING','REWORK','ABOLISH') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'SORTING'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int NOT NULL,
  `role_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `role_name`, `display_name`, `description`, `is_system`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'Administrator', 'Full system administrator with access to all modules and configurations', 1, '2026-09-22 02:22:14', '2026-09-22 02:22:14'),
(4, 'inspektor', 'Inspektur', NULL, 0, '2026-09-22 06:06:23', '2026-10-09 05:53:10'),
(5, 'chief', 'chief', NULL, 0, '2026-10-12 00:53:51', '2026-10-12 00:53:51');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `id` int NOT NULL,
  `role_id` int NOT NULL,
  `menu_key` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `can_view` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`id`, `role_id`, `menu_key`, `can_view`, `created_at`) VALUES
(1, 1, 'dashboard', 1, '2026-09-22 02:22:14'),
(2, 1, 'defect_analysis', 1, '2026-09-22 02:22:14'),
(3, 1, 'inspection', 1, '2026-09-22 02:22:14'),
(4, 1, 'safety_stock', 1, '2026-09-22 02:22:14'),
(5, 1, 'daily_report', 1, '2026-09-22 02:22:14'),
(6, 1, 'did', 1, '2026-09-22 02:22:14'),
(7, 1, 'kanban', 1, '2026-09-22 02:22:14'),
(8, 1, 'master_parts', 1, '2026-09-22 02:22:14'),
(9, 1, 'master_models', 1, '2026-09-22 02:22:14'),
(10, 1, 'master_customers', 1, '2026-09-22 02:22:14'),
(11, 1, 'master_drawings', 1, '2026-09-22 02:22:14'),
(12, 1, 'master_defects', 1, '2026-09-22 02:22:14'),
(13, 1, 'users', 1, '2026-09-22 02:22:14'),
(14, 1, 'roles', 1, '2026-09-22 02:22:14'),
(22, 4, 'dashboard', 1, '2026-10-09 05:53:10'),
(23, 4, 'inspection', 1, '2026-10-09 05:53:10'),
(24, 4, 'safety_stock', 1, '2026-10-09 05:53:10'),
(39, 5, 'dashboard', 1, '2026-10-12 00:53:51'),
(40, 5, 'defect_analysis', 1, '2026-10-12 00:53:51'),
(41, 5, 'inspection', 1, '2026-10-12 00:53:51'),
(42, 5, 'safety_stock', 1, '2026-10-12 00:53:51'),
(43, 5, 'daily_report', 1, '2026-10-12 00:53:51'),
(44, 5, 'did', 1, '2026-10-12 00:53:51'),
(45, 5, 'kanban', 1, '2026-10-12 00:53:51');

-- --------------------------------------------------------

--
-- Table structure for table `standard_oqc`
--

CREATE TABLE `standard_oqc` (
  `id` int NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Prosedur',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name_original` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int UNSIGNED DEFAULT '0',
  `uploaded_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `standard_oqc`
--

INSERT INTO `standard_oqc` (`id`, `title`, `document_number`, `category`, `description`, `file_path`, `file_name_original`, `file_size`, `uploaded_by`, `created_at`, `updated_at`) VALUES
(1, 'Standard AQL', NULL, 'Prosedur', NULL, 'uploads/standards/std_oqc_20261028_231542_55cf4e59.pdf', 'std_aql.pdf', 141893, 1, '2026-10-28 16:01:15', '2026-10-28 16:15:50'),
(5, 'PK-Petunjuk Kerja OQC Digital', NULL, 'Prosedur', NULL, 'uploads/standards/std_oqc_20261029_173431_954fdf0f.pdf', 'PK-OQC Digital.pdf', 1147257, 9, '2026-10-29 10:34:31', '2026-10-29 10:34:31'),
(6, 'File Judgement IEI ( External Appearance Standard Rev.6.0 )', NULL, 'Prosedur', NULL, 'uploads/standards/std_oqc_20261029_173546_b502e809.pdf', 'File Judgment IEI_External Appearance Inspection Standard Rev.6.0 - New 2026.10.28.pdf', 763957, 9, '2026-10-29 10:35:46', '2026-10-29 10:35:46'),
(7, 'Flow Chart Abnormality Handling Process OQC', NULL, 'Prosedur', NULL, 'uploads/standards/std_oqc_20261030_071815_6ccf85bf.pdf', 'Flow Chart Abnormality Handling Process OQC.26.pdf', 358999, 9, '2026-10-30 00:18:15', '2026-10-30 00:18:15'),
(8, 'Flow Chart Change Label in OQC', NULL, 'Prosedur', NULL, 'uploads/standards/std_oqc_20261030_071843_1a2e1361.pdf', 'Flow Chart Change Label OQC.26.pdf', 89932, 9, '2026-10-30 00:18:43', '2026-10-30 00:18:43'),
(9, 'Flow Chart Naik Level Sampling Inspection OQC', NULL, 'Prosedur', NULL, 'uploads/standards/std_oqc_20261030_071919_3fcc12b2.pdf', 'Flow Chart Naik Level Inspection OQC.26.pdf', 319018, 9, '2026-10-30 00:19:19', '2026-10-30 00:19:19');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`, `updated_at`) VALUES
('target_ppm', '10000', 'Target PPM Maksimal untuk STI Survival Performance Report', '2026-08-26 07:07:48');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'admin',
  `role_id` int UNSIGNED DEFAULT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `username`, `password_hash`, `role`, `role_id`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Admin', 'admin', '$2y$10$2gMe0qIwGrqWF.J.WutVU.250zDHZOv4mHz/AKPnXB88uLEb/Frjq', 'admin', 1, 'active', '2026-08-17 05:54:58', '2026-10-12 06:15:52'),
(6, 'Budi', 'budi', '$2y$10$J5TwR.Fp3V38OD711tylEOugQvGkWjFKJGdhtsuNl.DpxLXZLnrHq', 'inspektor', 4, 'active', '2026-10-09 13:04:32', '2026-10-13 10:01:57'),
(7, 'ayu', 'ayu', '$2y$10$0G7faIz2aKN4aqtoNGgaReDYH0g7TjC/usqz77n7laFcz9hriC96.', 'inspektor', NULL, 'active', '2026-10-12 07:32:44', '2026-10-12 07:32:44'),
(8, 'Okta', 'okta', '$2y$10$5pz/M9CHTkCX7qEYEhAWQOTHDWFRlAgH14l7TTH.oAvKHIL24jf.y', 'chief', 5, 'active', '2026-10-12 07:50:49', '2026-10-13 10:01:34'),
(9, 'Sarwono', 'sarwono', '$2y$10$cO4XYNXQu/igrctOss..muzjS336l6DL.scx/cSjRWmO7x3ZIVFxG', 'admin', 1, 'active', '2026-10-12 09:17:46', '2026-10-13 10:01:03'),
(10, 'Wulan', 'wulan', '$2y$10$KUT5VkDoBsdDVQUfju71qufVokE18X4lsltLBrEpsrEePtu34ivii', 'inspektor', 4, 'active', '2026-10-12 16:46:18', '2026-10-13 10:01:22'),
(11, 'Ridho', 'ridho', '$2y$10$CRALCF25rw4GaURgna8YWejm.JkiPzywolXleHw.loi43uF0/iaY6', 'chief', 5, 'active', '2026-10-13 09:52:31', '2026-10-13 10:01:11'),
(12, 'Tri Setiawan', 'tri', '$2y$10$.WyM9brN6OLEXPiYVh86l.bG/jmURr9hQr6PD5q9eJ3wogw.UJimu', 'admin', 1, 'active', '2026-10-13 10:00:03', '2026-10-13 10:02:23'),
(13, 'anggit', 'anggit', '$2y$10$GlqEluJCV7PZOfueBOaYAu2Ho/CJBF.10BjM9xO58nUs3uDJZmivS', 'inspektor', 4, 'active', '2026-10-14 07:04:16', '2026-10-14 07:04:16'),
(14, 'bahar', 'bahar', '$2y$10$syCFbP8DCSrpfn4t8lVvYebD.JVnFBZZFzcg9vCBoUrZQOjbEJKO.', 'inspektor', 4, 'active', '2026-10-19 08:27:13', '2026-10-19 08:27:13'),
(15, 'line 3', 'line 3', '$2y$10$ItRNaB0HQeLir7qPpQvFrOZTCb6fT48y6J7M5v8RFjdjNj..SGeqC', 'inspektor', 4, 'active', '2026-10-20 08:47:36', '2026-10-20 08:47:36'),
(16, 'fikri', 'fikri', '$2y$10$n.1LwboH46UHT0.LgL/w9evbRiihPxIUoIHNgctJTZ2WPQcP5Ei12', 'inspektor', 4, 'active', '2026-10-23 07:22:37', '2026-10-23 07:22:37'),
(17, 'raja', 'raja', '$2y$10$VPimdIhBcFGZeTYhosBFruFLthwsbXdPpcJ8dcutttbj54zeP9D.m', 'inspektor', 4, 'active', '2026-10-23 07:23:11', '2026-10-23 07:23:11');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `app_settings`
--
ALTER TABLE `app_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `aql_standards`
--
ALTER TABLE `aql_standards`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `daily_inspection_data`
--
ALTER TABLE `daily_inspection_data`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_did_part_code` (`part_code`),
  ADD KEY `idx_did_lot_number` (`lot_number`),
  ADD KEY `fk_did_created_by` (`created_by`),
  ADD KEY `idx_did_part_lot_date` (`part_code`,`lot_number`,`inspecting_date`),
  ADD KEY `idx_did_batch_status` (`batch_id`,`status_inspect`),
  ADD KEY `idx_did_batch_id_asc` (`batch_id`,`id`),
  ADD KEY `idx_did_batch_status_id` (`batch_id`,`status_inspect`,`id`);

--
-- Indexes for table `defect_threshold_targets`
--
ALTER TABLE `defect_threshold_targets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_target` (`target_type`,`reference_id`),
  ADD KEY `idx_lookup` (`target_type`,`reference_id`);

--
-- Indexes for table `defect_types`
--
ALTER TABLE `defect_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_defect_types_name` (`name`),
  ADD KEY `idx_defect_types_code` (`code`);

--
-- Indexes for table `did_approval_requests`
--
ALTER TABLE `did_approval_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_did_req_status` (`status`),
  ADD KEY `idx_did_req_part_lot` (`part_code`,`lot_number`),
  ADD KEY `idx_did_req_status_requested` (`status`,`requested_at` DESC),
  ADD KEY `idx_did_req_lot_number` (`lot_number`),
  ADD KEY `idx_did_req_requested_by` (`requested_by`),
  ADD KEY `idx_did_req_reviewed_by` (`reviewed_by`);

--
-- Indexes for table `did_batches`
--
ALTER TABLE `did_batches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_did_batches_date` (`inspecting_date`),
  ADD KEY `idx_did_batches_date_id` (`inspecting_date` DESC,`id` DESC);

--
-- Indexes for table `inspection_ng_records`
--
ALTER TABLE `inspection_ng_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ng_sample` (`inspection_sample_id`),
  ADD KEY `fk_ng_defect_type` (`defect_type_id`),
  ADD KEY `idx_ngr_cancelled` (`is_cancelled`),
  ADD KEY `idx_ngr_sample_cancelled` (`inspection_sample_id`,`is_cancelled`),
  ADD KEY `idx_ngr_session_part` (`inspection_session_id`,`part_id`,`is_cancelled`),
  ADD KEY `idx_ngr_perf_query` (`part_id`,`defect_type_id`,`is_cancelled`,`created_at`),
  ADD KEY `idx_ng_session_defect` (`inspection_session_id`,`defect_type_id`,`is_cancelled`),
  ADD KEY `idx_ngr_created_at` (`is_cancelled`,`created_at`),
  ADD KEY `idx_ngr_lot_cancelled` (`session_lot_id`,`is_cancelled`);

--
-- Indexes for table `inspection_samples`
--
ALTER TABLE `inspection_samples`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_samples_session` (`inspection_session_id`),
  ADD KEY `idx_samples_checked_at` (`checked_at`),
  ADD KEY `idx_samples_sess_checked` (`inspection_session_id`,`checked_at`);

--
-- Indexes for table `inspection_sessions`
--
ALTER TABLE `inspection_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sessions_did` (`did_id`),
  ADD KEY `fk_sessions_kanban_item` (`kanban_item_id`),
  ADD KEY `fk_sessions_part` (`part_id`),
  ADD KEY `fk_sessions_inspector` (`inspector_id`),
  ADD KEY `idx_sessions_parent` (`parent_session_id`),
  ADD KEY `idx_sessions_is_reinspection` (`is_reinspection`),
  ADD KEY `idx_sess_type_status` (`inspection_type`,`status`),
  ADD KEY `idx_sess_type_kid_status` (`inspection_type`,`kanban_item_id`,`status`),
  ADD KEY `idx_sess_type_did_status` (`inspection_type`,`did_id`,`status`),
  ADD KEY `idx_sess_auto_fulfilled` (`auto_fulfilled_by_session_id`),
  ADD KEY `idx_sess_original_ss` (`original_ss_session_id`),
  ADD KEY `idx_sess_started_at` (`started_at`),
  ADD KEY `idx_sess_status_started` (`status`,`started_at`),
  ADD KEY `idx_sessions_approval` (`is_approved`,`approved_at`),
  ADD KEY `idx_sessions_chief_approval` (`is_chief_approved`,`chief_approved_at`),
  ADD KEY `idx_sess_kid_status_qty` (`kanban_item_id`,`status`,`total_scanned_qty`),
  ADD KEY `idx_ss_perf_lookup` (`inspection_type`,`auto_fulfilled_by_session_id`,`did_id`),
  ADD KEY `idx_ss_perf_part` (`inspection_type`,`part_id`,`auto_fulfilled_by_session_id`),
  ADD KEY `idx_sess_started_approved` (`started_at`,`is_approved`),
  ADD KEY `idx_sess_started_kanban` (`started_at`,`kanban_item_id`);

--
-- Indexes for table `inspection_session_lots`
--
ALTER TABLE `inspection_session_lots`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_session_lots_session` (`inspection_session_id`),
  ADD KEY `idx_session_lots_ref` (`ref_number`),
  ADD KEY `idx_session_lots_lot` (`lot_number`),
  ADD KEY `idx_session_lots_status` (`lot_status`),
  ADD KEY `idx_isl_session_ref` (`inspection_session_id`,`ref_number`),
  ADD KEY `idx_lots_chief_approval` (`is_chief_approved`,`chief_approved_at`),
  ADD KEY `idx_isl_session_status_lot` (`inspection_session_id`,`lot_status`,`lot_number`),
  ADD KEY `idx_isl_perf_ref_lot` (`inspection_session_id`,`ref_number`,`lot_number`),
  ADD KEY `idx_isl_sess_sample_res` (`inspection_session_id`,`sample_size`,`lot_result`),
  ADD KEY `idx_isl_created_at` (`created_at`),
  ADD KEY `idx_isl_quarantine_scope` (`lot_result`,`lot_status`,`inspection_session_id`),
  ADD KEY `idx_isl_lot_ref_session` (`lot_number`,`ref_number`,`inspection_session_id`);

--
-- Indexes for table `kanban_batches`
--
ALTER TABLE `kanban_batches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_kanban_batches_imported_by` (`imported_by`),
  ADD KEY `idx_kbatches_type_imported` (`plan_type`,`imported_at`);

--
-- Indexes for table `kanban_items`
--
ALTER TABLE `kanban_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_kanban_item_code` (`item_code`),
  ADD KEY `idx_kanban_no` (`kanban_no`),
  ADD KEY `fk_kanban_items_batch` (`batch_id`),
  ADD KEY `idx_kanban_customer` (`customer`),
  ADD KEY `idx_kanban_status` (`status`),
  ADD KEY `idx_kanban_status_eta` (`status`,`eta`,`req_date`),
  ADD KEY `idx_kanban_item_status` (`item_code`,`status`),
  ADD KEY `idx_kanban_created_at` (`created_at`),
  ADD KEY `idx_kitems_batch_plantype` (`batch_id`,`plan_type`);

--
-- Indexes for table `lines`
--
ALTER TABLE `lines`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `lot_quarantine_closures`
--
ALTER TABLE `lot_quarantine_closures`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lqc_session_lot` (`session_lot_id`),
  ADD KEY `idx_lqc_closed_by` (`closed_by`),
  ADD KEY `idx_lqc_closure_type` (`closure_type`);

--
-- Indexes for table `lot_substitution_log`
--
ALTER TABLE `lot_substitution_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_subst_orig_session` (`original_session_id`),
  ADD KEY `idx_subst_ng_lot` (`ng_session_lot_id`),
  ADD KEY `idx_subst_reinsp_sess` (`reinspection_session_id`);

--
-- Indexes for table `master_customers`
--
ALTER TABLE `master_customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_customer_name` (`name`);

--
-- Indexes for table `master_drawings`
--
ALTER TABLE `master_drawings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_drawings_part_id` (`part_id`),
  ADD KEY `fk_drawings_uploaded_by` (`uploaded_by`);

--
-- Indexes for table `master_models`
--
ALTER TABLE `master_models`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_master_models_name` (`name`);

--
-- Indexes for table `master_parts`
--
ALTER TABLE `master_parts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_master_parts_code` (`part_code`),
  ADD KEY `fk_parts_created_by` (`created_by`),
  ADD KEY `fk_parts_model` (`model_id`),
  ADD KEY `idx_parts_source_id` (`source`,`id`),
  ADD KEY `idx_parts_name` (`part_name`(50));

--
-- Indexes for table `oqc_daily_defect_summary`
--
ALTER TABLE `oqc_daily_defect_summary`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_defect_date_cust_part_def` (`summary_date`,`customer`,`part_id`,`defect_type_id`),
  ADD KEY `idx_summary_date` (`summary_date`),
  ADD KEY `idx_defect_type` (`defect_type_id`),
  ADD KEY `idx_customer` (`customer`),
  ADD KEY `idx_part_id` (`part_id`),
  ADD KEY `idx_dds_perf` (`summary_date`,`defect_type_id`,`model_id`,`part_id`),
  ADD KEY `idx_dds_part_date` (`part_id`,`summary_date`);

--
-- Indexes for table `oqc_daily_summary`
--
ALTER TABLE `oqc_daily_summary`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_summary_date_cust_part_type` (`summary_date`,`customer`,`part_id`,`inspection_type`),
  ADD KEY `idx_summary_date` (`summary_date`),
  ADD KEY `idx_customer` (`customer`),
  ADD KEY `idx_part_id` (`part_id`),
  ADD KEY `idx_model_id` (`model_id`),
  ADD KEY `idx_ds_part_date` (`part_id`,`summary_date`);

--
-- Indexes for table `rejection_sheet_prints`
--
ALTER TABLE `rejection_sheet_prints`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_prints_session` (`inspection_session_id`),
  ADD KEY `fk_prints_user` (`printed_by`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_role_menu` (`role_id`,`menu_key`);

--
-- Indexes for table `standard_oqc`
--
ALTER TABLE `standard_oqc`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_title` (`title`),
  ADD KEY `idx_category` (`category`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_users_username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `aql_standards`
--
ALTER TABLE `aql_standards`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `daily_inspection_data`
--
ALTER TABLE `daily_inspection_data`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `defect_threshold_targets`
--
ALTER TABLE `defect_threshold_targets`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `defect_types`
--
ALTER TABLE `defect_types`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=91;

--
-- AUTO_INCREMENT for table `did_approval_requests`
--
ALTER TABLE `did_approval_requests`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `did_batches`
--
ALTER TABLE `did_batches`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `inspection_ng_records`
--
ALTER TABLE `inspection_ng_records`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `inspection_samples`
--
ALTER TABLE `inspection_samples`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inspection_sessions`
--
ALTER TABLE `inspection_sessions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `inspection_session_lots`
--
ALTER TABLE `inspection_session_lots`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `kanban_batches`
--
ALTER TABLE `kanban_batches`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `kanban_items`
--
ALTER TABLE `kanban_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `lines`
--
ALTER TABLE `lines`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `lot_quarantine_closures`
--
ALTER TABLE `lot_quarantine_closures`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `lot_substitution_log`
--
ALTER TABLE `lot_substitution_log`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `master_customers`
--
ALTER TABLE `master_customers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `master_drawings`
--
ALTER TABLE `master_drawings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=295;

--
-- AUTO_INCREMENT for table `master_models`
--
ALTER TABLE `master_models`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=91;

--
-- AUTO_INCREMENT for table `master_parts`
--
ALTER TABLE `master_parts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=442;

--
-- AUTO_INCREMENT for table `oqc_daily_defect_summary`
--
ALTER TABLE `oqc_daily_defect_summary`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `oqc_daily_summary`
--
ALTER TABLE `oqc_daily_summary`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `rejection_sheet_prints`
--
ALTER TABLE `rejection_sheet_prints`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `standard_oqc`
--
ALTER TABLE `standard_oqc`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `daily_inspection_data`
--
ALTER TABLE `daily_inspection_data`
  ADD CONSTRAINT `fk_did_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `inspection_ng_records`
--
ALTER TABLE `inspection_ng_records`
  ADD CONSTRAINT `fk_ng_defect_type` FOREIGN KEY (`defect_type_id`) REFERENCES `defect_types` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_ng_sample` FOREIGN KEY (`inspection_sample_id`) REFERENCES `inspection_samples` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inspection_samples`
--
ALTER TABLE `inspection_samples`
  ADD CONSTRAINT `fk_samples_session` FOREIGN KEY (`inspection_session_id`) REFERENCES `inspection_sessions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inspection_sessions`
--
ALTER TABLE `inspection_sessions`
  ADD CONSTRAINT `fk_sessions_inspector` FOREIGN KEY (`inspector_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sessions_kanban_item` FOREIGN KEY (`kanban_item_id`) REFERENCES `kanban_items` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sessions_part` FOREIGN KEY (`part_id`) REFERENCES `master_parts` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `inspection_session_lots`
--
ALTER TABLE `inspection_session_lots`
  ADD CONSTRAINT `fk_session_lots_session` FOREIGN KEY (`inspection_session_id`) REFERENCES `inspection_sessions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `kanban_batches`
--
ALTER TABLE `kanban_batches`
  ADD CONSTRAINT `fk_kanban_batches_imported_by` FOREIGN KEY (`imported_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `kanban_items`
--
ALTER TABLE `kanban_items`
  ADD CONSTRAINT `fk_kanban_items_batch` FOREIGN KEY (`batch_id`) REFERENCES `kanban_batches` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `lot_substitution_log`
--
ALTER TABLE `lot_substitution_log`
  ADD CONSTRAINT `fk_subst_orig_session` FOREIGN KEY (`original_session_id`) REFERENCES `inspection_sessions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `master_parts`
--
ALTER TABLE `master_parts`
  ADD CONSTRAINT `fk_parts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_parts_model` FOREIGN KEY (`model_id`) REFERENCES `master_models` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
