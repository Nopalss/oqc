-- ==============================================================================
-- Schema Database MySQL / MariaDB - Sistem OQC PT Surya Technology Industri
-- Berdasarkan PRD_STI.md (12 Tabel Utama)
-- ==============================================================================

CREATE DATABASE IF NOT EXISTS `oqc_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `oqc_db`;

-- Nonaktifkan pengecekan Foreign Key saat pembuatan/penghapusan tabel
SET FOREIGN_KEY_CHECKS = 0;

-- Drop tabel lama jika ada agar tipe data & foreign key terbarui secara bersih
DROP TABLE IF EXISTS `rejection_sheet_prints`;
DROP TABLE IF EXISTS `inspection_ng_records`;
DROP TABLE IF EXISTS `inspection_samples`;
DROP TABLE IF EXISTS `inspection_sessions`;
DROP TABLE IF EXISTS `daily_inspection_data`;
DROP TABLE IF EXISTS `kanban_items`;
DROP TABLE IF EXISTS `kanban_batches`;
DROP TABLE IF EXISTS `master_drawings`;
DROP TABLE IF EXISTS `master_parts`;
DROP TABLE IF EXISTS `defect_types`;
DROP TABLE IF EXISTS `aql_standards`;
DROP TABLE IF EXISTS `users`;

-- ------------------------------------------------------------------------------
-- 1. TABEL USERS (FR-8: User Management & Role Access)
-- ------------------------------------------------------------------------------
CREATE TABLE `users` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `username` VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'qc_inspector', 'supervisor_viewer') NOT NULL DEFAULT 'qc_inspector',
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 1B. TABEL MASTER CUSTOMERS (Master Data Customer / PT Tujuan)
-- ------------------------------------------------------------------------------
CREATE TABLE `master_customers` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_customer_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 1C. TABEL MASTER MODELS (Master Data Model Produk)
-- ------------------------------------------------------------------------------
CREATE TABLE `master_models` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_master_models_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 2. TABEL MASTER PARTS (FR-4: Master Data Part)
-- ------------------------------------------------------------------------------
CREATE TABLE `master_parts` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `part_code` VARCHAR(50) NOT NULL,
  `part_name` VARCHAR(255) NOT NULL,
  `model_id` BIGINT(20) UNSIGNED NULL,
  `aql_level` ENUM('G-I', 'G-II', 'G-III') NOT NULL DEFAULT 'G-II',
  `source` ENUM('manual', 'auto_generated') NOT NULL DEFAULT 'manual',
  `created_by` BIGINT(20) UNSIGNED NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_master_parts_code` (`part_code`),
  KEY `fk_parts_created_by` (`created_by`),
  KEY `fk_parts_model` (`model_id`),
  CONSTRAINT `fk_parts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_parts_model` FOREIGN KEY (`model_id`) REFERENCES `master_models` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 3. TABEL MASTER DRAWINGS (FR-5: Master Data Drawing 2D & 3D)
-- ------------------------------------------------------------------------------
CREATE TABLE `master_drawings` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `part_id` BIGINT(20) UNSIGNED NOT NULL,
  `drawing_2d_path` VARCHAR(500) NULL,
  `drawing_3d_path` VARCHAR(500) NULL,
  `uploaded_by` BIGINT(20) UNSIGNED NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_drawings_part_id` (`part_id`),
  KEY `fk_drawings_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_drawings_part_id` FOREIGN KEY (`part_id`) REFERENCES `master_parts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_drawings_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 4. TABEL AQL STANDARDS (FR-3: Standar Sampling AQL Level G-I, G-II, G-III / AQL 0.4)
-- ------------------------------------------------------------------------------
CREATE TABLE `aql_standards` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `qty_min` INT(11) NOT NULL,
  `qty_max` INT(11) NOT NULL,
  `inspection_level` ENUM('G-I', 'G-II', 'G-III') NOT NULL DEFAULT 'G-II',
  `sample_code` VARCHAR(10) NULL,
  `sample_size` INT(11) NOT NULL,
  `accept_number` INT(11) NOT NULL DEFAULT 0,
  `reject_number` INT(11) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 5. TABEL DEFECT TYPES (FR-3: Master Jenis Cacat / Defect)
-- ------------------------------------------------------------------------------
CREATE TABLE `defect_types` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_defect_types_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 6. TABEL KANBAN BATCHES (FR-1: Metadata Header Import/Batch Planning)
-- ------------------------------------------------------------------------------
CREATE TABLE `kanban_batches` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `plan_type` ENUM('kanban', 'safety_stock') NOT NULL DEFAULT 'kanban',
  `vendor` VARCHAR(255) NULL,
  `document_number` VARCHAR(100) NULL,
  `print_datetime` DATETIME NULL,
  `import_method` ENUM('manual', 'excel_import') NOT NULL DEFAULT 'excel_import',
  `imported_by` BIGINT(20) UNSIGNED NULL,
  `imported_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_kanban_batches_imported_by` (`imported_by`),
  CONSTRAINT `fk_kanban_batches_imported_by` FOREIGN KEY (`imported_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 7. TABEL KANBAN ITEMS (FR-1: Detail Baris Item Planning Inspeksi)
-- ------------------------------------------------------------------------------
CREATE TABLE `kanban_items` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` BIGINT(20) UNSIGNED NOT NULL,
  `plan_type` ENUM('kanban', 'safety_stock') NOT NULL DEFAULT 'kanban',
  `kanban_no` VARCHAR(50) NULL,
  `item_code` VARCHAR(50) NOT NULL,
  `item_description` VARCHAR(255) NOT NULL,
  `customer` VARCHAR(255) NULL,
  `req_date` DATETIME NOT NULL,
  `qty` INT(11) NOT NULL,
  `eta` DATETIME NULL,
  `str_loc` VARCHAR(50) NULL,
  `supply_area` VARCHAR(50) NULL,
  `check_type` VARCHAR(50) NULL,
  `remark` TEXT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kanban_item_code` (`item_code`),
  KEY `idx_kanban_no` (`kanban_no`),
  KEY `idx_kanban_plan_type` (`plan_type`),
  KEY `fk_kanban_items_batch` (`batch_id`),
  CONSTRAINT `fk_kanban_items_batch` FOREIGN KEY (`batch_id`) REFERENCES `kanban_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 8. TABEL DAILY INSPECTION DATA / DID (FR-2: Cek Dimensi Lot Produksi)
-- ------------------------------------------------------------------------------
CREATE TABLE `daily_inspection_data` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `part_code` VARCHAR(50) NOT NULL,
  `part_name` VARCHAR(255) NOT NULL,
  `lot_number` VARCHAR(100) NOT NULL,
  `cavity` VARCHAR(20) NOT NULL,
  `inspecting_date` DATE NOT NULL,
  `status_inspect` ENUM('OK', 'NG') NOT NULL DEFAULT 'OK',
  `pic` VARCHAR(150) NOT NULL,
  `remark` TEXT NULL,
  `created_by` BIGINT(20) UNSIGNED NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_did_part_code` (`part_code`),
  KEY `idx_did_lot_number` (`lot_number`),
  KEY `fk_did_created_by` (`created_by`),
  CONSTRAINT `fk_did_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 9. TABEL INSPECTION SESSIONS (FR-3 + FR-6: Sesi Inspeksi OQC & Closing)
-- ------------------------------------------------------------------------------
CREATE TABLE `inspection_sessions` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `inspection_type` ENUM('kanban', 'safety_stock') NOT NULL DEFAULT 'kanban',
  `did_id` BIGINT(20) UNSIGNED NOT NULL,
  `kanban_item_id` BIGINT(20) UNSIGNED NULL,
  `part_id` BIGINT(20) UNSIGNED NULL,
  `sample_size` INT(11) NOT NULL,
  `total_scanned_qty` INT(11) NOT NULL DEFAULT 0,
  `excess_qty` INT(11) NOT NULL DEFAULT 0,
  `reject_number` INT(11) NOT NULL,
  `samples_checked` INT(11) NOT NULL DEFAULT 0,
  `ng_count` INT(11) NOT NULL DEFAULT 0,
  `status` ENUM('in_progress', 'passed', 'rejected') NOT NULL DEFAULT 'in_progress',
  `auto_fulfilled_by_session_id` BIGINT(20) UNSIGNED NULL,
  `inspector_id` BIGINT(20) UNSIGNED NULL,
  `started_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `closed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `fk_sessions_did` (`did_id`),
  KEY `fk_sessions_kanban_item` (`kanban_item_id`),
  KEY `fk_sessions_part` (`part_id`),
  KEY `fk_sessions_inspector` (`inspector_id`),
  CONSTRAINT `fk_sessions_did` FOREIGN KEY (`did_id`) REFERENCES `daily_inspection_data` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sessions_kanban_item` FOREIGN KEY (`kanban_item_id`) REFERENCES `kanban_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sessions_part` FOREIGN KEY (`part_id`) REFERENCES `master_parts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sessions_inspector` FOREIGN KEY (`inspector_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 9B. TABEL INSPECTION SESSION LOTS (Detail Multi-Label QR / Lot No yang Discan per Sesi)
-- ------------------------------------------------------------------------------
CREATE TABLE `inspection_session_lots` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `inspection_session_id` BIGINT(20) UNSIGNED NOT NULL,
  `ref_number` VARCHAR(100) NULL,
  `lot_number` VARCHAR(100) NOT NULL,
  `qty` INT(11) NOT NULL DEFAULT 0,
  `scanned_qr_raw` TEXT NULL,
  `remarks` VARCHAR(255) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_session_lots_session` (`inspection_session_id`),
  KEY `idx_session_lots_ref` (`ref_number`),
  KEY `idx_session_lots_lot` (`lot_number`),
  CONSTRAINT `fk_session_lots_session` FOREIGN KEY (`inspection_session_id`) REFERENCES `inspection_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 10. TABEL INSPECTION SAMPLES (FR-3: Log Per 1 Pcs Sample Inspection)
-- ------------------------------------------------------------------------------
CREATE TABLE `inspection_samples` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `inspection_session_id` BIGINT(20) UNSIGNED NOT NULL,
  `sample_number` INT(11) NOT NULL,
  `result` ENUM('OK', 'NG') NOT NULL,
  `checked_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_samples_session` (`inspection_session_id`),
  CONSTRAINT `fk_samples_session` FOREIGN KEY (`inspection_session_id`) REFERENCES `inspection_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 11. TABEL INSPECTION NG RECORDS (FR-3: Detail Defect Per Sample NG)
-- ------------------------------------------------------------------------------
CREATE TABLE `inspection_ng_records` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `inspection_sample_id` BIGINT(20) UNSIGNED NOT NULL,
  `defect_type_id` BIGINT(20) UNSIGNED NOT NULL,
  `qty_ng` INT(11) NOT NULL DEFAULT 1,
  `remark` TEXT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_ng_sample` (`inspection_sample_id`),
  KEY `fk_ng_defect_type` (`defect_type_id`),
  CONSTRAINT `fk_ng_sample` FOREIGN KEY (`inspection_sample_id`) REFERENCES `inspection_samples` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ng_defect_type` FOREIGN KEY (`defect_type_id`) REFERENCES `defect_types` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 12. TABEL REJECTION SHEET PRINTS (FR-3E: Log Cetak Rejection Sheet)
-- ------------------------------------------------------------------------------
CREATE TABLE `rejection_sheet_prints` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `inspection_session_id` BIGINT(20) UNSIGNED NOT NULL,
  `print_type` ENUM('auto', 'manual_reprint') NOT NULL DEFAULT 'auto',
  `printed_by` BIGINT(20) UNSIGNED NULL,
  `printed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_prints_session` (`inspection_session_id`),
  KEY `fk_prints_user` (`printed_by`),
  CONSTRAINT `fk_prints_session` FOREIGN KEY (`inspection_session_id`) REFERENCES `inspection_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_prints_user` FOREIGN KEY (`printed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aktifkan kembali pengecekan Foreign Key
SET FOREIGN_KEY_CHECKS = 1;


-- ==============================================================================
-- SEED DATA DEFAULT (INITIAL DATA)
-- ==============================================================================

-- 1. Default Users (Bcrypt Password: "password123")
INSERT INTO `users` (`id`, `name`, `username`, `password_hash`, `role`, `status`) VALUES
(1, 'System Administrator', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'active'),
(2, 'QC Inspector Line 1', 'inspector1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'qc_inspector', 'active'),
(3, 'Quality Supervisor', 'supervisor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'supervisor_viewer', 'active');

-- 2. Default Defect Types
INSERT INTO `defect_types` (`id`, `name`) VALUES
(1, 'Scratch / Baret'),
(2, 'Dent / Penyok'),
(3, 'Dimension Out of Spec'),
(4, 'Flash / Burr / Excess Plastic'),
(5, 'Contamination / Black Spot'),
(6, 'Short Mold / Incomplete');

-- 3. Default Standard AQL Levels G-I, G-II, G-III (AQL 0.4) Reference Ranges (45 Master Rows)
INSERT INTO `aql_standards` (`id`, `qty_min`, `qty_max`, `inspection_level`, `sample_code`, `sample_size`, `accept_number`, `reject_number`) VALUES
-- G-I (Longgar / Reduced)
(1, 2, 8, 'G-I', 'A', 2, 0, 1),
(2, 9, 15, 'G-I', 'A', 2, 0, 1),
(3, 16, 25, 'G-I', 'B', 3, 0, 1),
(4, 26, 50, 'G-I', 'C', 5, 0, 1),
(5, 51, 90, 'G-I', 'C', 5, 0, 1),
(6, 91, 150, 'G-I', 'D', 8, 0, 1),
(7, 151, 280, 'G-I', 'E', 13, 0, 1),
(8, 281, 500, 'G-I', 'F', 20, 0, 1),
(9, 501, 1200, 'G-I', 'G', 32, 0, 1),
(10, 1201, 3200, 'G-I', 'H', 50, 0, 1),
(11, 3201, 10000, 'G-I', 'J', 80, 1, 2),
(12, 10001, 35000, 'G-I', 'K', 125, 1, 2),
(13, 35001, 150000, 'G-I', 'L', 200, 2, 3),
(14, 150001, 500000, 'G-I', 'M', 315, 3, 4),
(15, 500001, 99999999, 'G-I', 'N', 500, 5, 6),

-- G-II (Normal / Standar STI)
(16, 2, 8, 'G-II', 'A', 2, 0, 1),
(17, 9, 15, 'G-II', 'B', 3, 0, 1),
(18, 16, 25, 'G-II', 'C', 5, 0, 1),
(19, 26, 50, 'G-II', 'D', 8, 0, 1),
(20, 51, 90, 'G-II', 'E', 13, 0, 1),
(21, 91, 150, 'G-II', 'F', 20, 0, 1),
(22, 151, 280, 'G-II', 'G', 32, 0, 1),
(23, 281, 500, 'G-II', 'H', 50, 0, 1),
(24, 501, 1200, 'G-II', 'J', 80, 1, 2),
(25, 1201, 3200, 'G-II', 'K', 125, 1, 2),
(26, 3201, 10000, 'G-II', 'L', 200, 2, 3),
(27, 10001, 35000, 'G-II', 'M', 315, 3, 4),
(28, 35001, 150000, 'G-II', 'N', 500, 5, 6),
(29, 150001, 500000, 'G-II', 'P', 800, 7, 8),
(30, 500001, 99999999, 'G-II', 'Q', 1250, 10, 11),

-- G-III (Ketat / Tightened)
(31, 2, 8, 'G-III', 'B', 3, 0, 1),
(32, 9, 15, 'G-III', 'C', 5, 0, 1),
(33, 16, 25, 'G-III', 'D', 8, 0, 1),
(34, 26, 50, 'G-III', 'E', 13, 0, 1),
(35, 51, 90, 'G-III', 'F', 20, 0, 1),
(36, 91, 150, 'G-III', 'G', 32, 0, 1),
(37, 151, 280, 'G-III', 'H', 50, 0, 1),
(38, 281, 500, 'G-III', 'J', 80, 1, 2),
(39, 501, 1200, 'G-III', 'K', 125, 1, 2),
(40, 1201, 3200, 'G-III', 'L', 200, 2, 3),
(41, 3201, 10000, 'G-III', 'M', 315, 3, 4),
(42, 10001, 35000, 'G-III', 'N', 500, 5, 6),
(43, 35001, 150000, 'G-III', 'P', 800, 7, 8),
(44, 150001, 500000, 'G-III', 'Q', 1250, 10, 11),
(45, 500001, 99999999, 'G-III', 'R', 2000, 14, 15);

-- 4. Sample Master Part
INSERT INTO `master_parts` (`id`, `part_code`, `part_name`, `source`, `created_by`) VALUES
(1, 'PART-A001', 'Housing Front Plastic Black', 'manual', 1),
(2, 'PART-B002', 'Cover Top Aluminum Alloy', 'manual', 1),
(3, 'PART-C003', 'Bracket Mounting Steel', 'manual', 1);
