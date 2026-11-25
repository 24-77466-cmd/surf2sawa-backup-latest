-- Surf to Sawa (S2S) Database Schema & Initial Data
-- Target: XAMPP MariaDB / MySQL
-- Character Set: utf8mb4, Collation: utf8mb4_unicode_ci

CREATE DATABASE IF NOT EXISTS `surf_to_sawa` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `surf_to_sawa`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `fullname` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `phone_e164` VARCHAR(16) DEFAULT NULL,
  `phone_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `totp_secret` VARCHAR(64) DEFAULT NULL,
  `password` VARCHAR(255) NOT NULL,
  `balance` DECIMAL(10,2) DEFAULT 0.00,
  `role` ENUM('user','admin') DEFAULT 'user',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_users_phone_e164` (`phone_e164`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Plans Table
CREATE TABLE IF NOT EXISTS `plans` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `duration_hours` INT NOT NULL,
  `image` VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Purchases Table
CREATE TABLE IF NOT EXISTS `purchases` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `plan_id` INT DEFAULT NULL,
  `purchased_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME DEFAULT NULL,
  KEY `user_id` (`user_id`),
  KEY `plan_id` (`plan_id`),
  CONSTRAINT `purchases_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchases_ibfk_2` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.1 Demo Top-up Requests (no real payment processing)
CREATE TABLE IF NOT EXISTS `topup_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `reference` VARCHAR(32) NOT NULL UNIQUE,
  `user_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `payment_method` ENUM('gcash','bank') NOT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` DATETIME DEFAULT NULL,
  `reviewed_by` INT DEFAULT NULL,
  KEY `idx_topup_user_created` (`user_id`, `created_at`),
  KEY `idx_topup_status_created` (`status`, `created_at`),
  CONSTRAINT `topup_requests_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `topup_requests_reviewer_fk` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Activity Logs Table
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `admin_id` INT DEFAULT NULL,
  `admin_name` VARCHAR(100) DEFAULT NULL,
  `action` VARCHAR(80) NOT NULL,
  `target` VARCHAR(80) DEFAULT NULL,
  `detail` VARCHAR(255) DEFAULT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_created` (`created_at`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Barangays Table
CREATE TABLE IF NOT EXISTS `barangays` (
  `id` VARCHAR(30) NOT NULL PRIMARY KEY,
  `name` VARCHAR(60) NOT NULL,
  `city` VARCHAR(60) NOT NULL,
  `region` VARCHAR(30) NOT NULL,
  `x` FLOAT NOT NULL,
  `y` FLOAT NOT NULL,
  `zone` VARCHAR(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Cabinets Table
CREATE TABLE IF NOT EXISTS `cabinets` (
  `id` VARCHAR(10) NOT NULL PRIMARY KEY,
  `name` VARCHAR(60) NOT NULL,
  `barangay_id` VARCHAR(30) NOT NULL,
  `x` FLOAT NOT NULL,
  `y` FLOAT NOT NULL,
  `zone` VARCHAR(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Customers Table
CREATE TABLE IF NOT EXISTS `customers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `customer_code` VARCHAR(20) NOT NULL UNIQUE,
  `full_name` VARCHAR(120) NOT NULL,
  `address` VARCHAR(255) NOT NULL,
  `city` VARCHAR(60) NOT NULL,
  `region` VARCHAR(30) NOT NULL,
  `province` VARCHAR(100) DEFAULT NULL,
  `municipality` VARCHAR(100) DEFAULT NULL,
  `psgc_brgy_code` VARCHAR(20) DEFAULT NULL,
  `barangay_id` VARCHAR(30) DEFAULT NULL,
  `cabinet_id` VARCHAR(10) DEFAULT NULL,
  `zone` VARCHAR(20) NOT NULL,
  `request_id` VARCHAR(20) DEFAULT NULL,
  `plan` VARCHAR(60) DEFAULT NULL,
  `status` ENUM('active','pending','unavailable','suspended') DEFAULT 'active',
  `has_issue` TINYINT(1) DEFAULT 0,
  `signal_dbm` INT DEFAULT NULL,
  `x` FLOAT NOT NULL,
  `y` FLOAT NOT NULL,
  `registered_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY `barangay_id` (`barangay_id`),
  KEY `cabinet_id` (`cabinet_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `customers_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customers_ibfk_2` FOREIGN KEY (`cabinet_id`) REFERENCES `cabinets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customers_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pending registrations hold only unverified signup details until authenticator confirmation.
CREATE TABLE IF NOT EXISTS `pending_registrations` (
  `id` CHAR(32) NOT NULL PRIMARY KEY,
  `email` VARCHAR(100) NOT NULL,
  `phone_e164` VARCHAR(16) NOT NULL,
  `registration_data` MEDIUMTEXT NOT NULL,
  `otp_hash` CHAR(64) DEFAULT NULL,
  `totp_secret` VARCHAR(64) DEFAULT NULL,
  `otp_mode` ENUM('development','twilio_verify','email_smtp','totp') NOT NULL,
  `otp_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `otp_resends` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_sent_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `request_ip` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_pending_registration_email` (`email`),
  KEY `idx_pending_phone_sent` (`phone_e164`, `last_sent_at`),
  KEY `idx_pending_ip_sent` (`request_ip`, `last_sent_at`),
  KEY `idx_pending_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Brute-force protection for Google Authenticator codes (login / phone change).
CREATE TABLE IF NOT EXISTS `totp_attempts` (
  `user_id` INT NOT NULL,
  `purpose` ENUM('login','phone_change') NOT NULL,
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `first_attempt_at` DATETIME NOT NULL,
  `locked_until` DATETIME DEFAULT NULL,
  PRIMARY KEY (`user_id`, `purpose`),
  CONSTRAINT `totp_attempts_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Canonical Seed Data
INSERT INTO `users` (`id`, `fullname`, `email`, `password`, `balance`, `role`) VALUES
(1, 'Admin', 'admin@converge.com', '$2y$10$1d0BAHy9mNMg.l4om4IFH.Xhx6Sf1ed07qyFuPyjYgyvyVb56c26O', 9999.00, 'admin'),
(3, 'S2S Administrator', 'admin@s2s.ph', '$2y$10$1d0BAHy9mNMg.l4om4IFH.Xhx6Sf1ed07qyFuPyjYgyvyVb56c26O', 5000.00, 'admin'),
(4, 'Juan Dela Cruz', 'user@s2s.ph', '$2y$10$yxc2NMROPqoOtvyo6B0GL.zbp14/7yrQkw1QJaE.CiifxaLdHGBC2', 250.00, 'user')
ON DUPLICATE KEY UPDATE `password`=VALUES(`password`), `role`=VALUES(`role`);

INSERT INTO `plans` (`id`, `name`, `description`, `price`, `duration_hours`) VALUES
(1, 'Surf 1 Hour', 'Perfect for quick browsing and messaging', 10.00, 1),
(2, 'Surf 3 Hours', 'Great for video streaming, classes & gaming', 25.00, 3),
(3, 'Surf 6 Hours', 'Half-day unlimited fiber access for multiple tasks', 45.00, 6),
(4, 'Surf to Sawa 12H', '12 hours of unlimited high-speed fiber WiFi', 70.00, 12),
(5, 'Surf to Sawa 24H', 'Full 24-hour unlimited high-speed internet', 120.00, 24)
ON DUPLICATE KEY UPDATE `name`=`name`;

INSERT INTO `barangays` (`id`, `name`, `city`, `region`, `x`, `y`, `zone`) VALUES
('baclaran', 'Baclaran', 'Parañaque', 'NCR', 265, 95, 'PQ-A'),
('tambo', 'Tambo', 'Parañaque', 'NCR', 320, 215, 'PQ-B'),
('sandio', 'San Dionisio', 'Parañaque', 'NCR', 262, 315, 'PQ-C'),
('donbosco', 'Don Bosco', 'Parañaque', 'NCR', 425, 175, 'PQ-D'),
('moonwalk', 'Moonwalk', 'Parañaque', 'NCR', 445, 335, 'PQ-E'),
('bfhomes', 'BF Homes', 'Parañaque', 'NCR', 590, 255, 'PQ-F'),
('sunvalley', 'Sun Valley', 'Parañaque', 'NCR', 565, 105, 'PQ-G'),
('talon', 'Talon Uno', 'Las Piñas', 'NCR', 305, 440, 'LP-A'),
('pamplona', 'Pamplona Tres', 'Las Piñas', 'NCR', 525, 450, 'LP-B')
ON DUPLICATE KEY UPDATE `name`=`name`;

INSERT INTO `cabinets` (`id`, `name`, `barangay_id`, `x`, `y`, `zone`) VALUES
('CAB-01', 'Baclaran Cabinet', 'baclaran', 256, 88, 'PQ-A'),
('CAB-02', 'Tambo Cabinet', 'tambo', 329, 222, 'PQ-B'),
('CAB-03', 'San Dionisio Cabinet', 'sandio', 253, 308, 'PQ-C'),
('CAB-04', 'Don Bosco Cabinet', 'donbosco', 434, 182, 'PQ-D'),
('CAB-05', 'Moonwalk Cabinet', 'moonwalk', 436, 328, 'PQ-E'),
('CAB-06', 'BF Homes Cabinet', 'bfhomes', 599, 262, 'PQ-F'),
('CAB-07', 'Sun Valley Cabinet', 'sunvalley', 556, 98, 'PQ-G'),
('CAB-08', 'Talon Cabinet', 'talon', 314, 447, 'LP-A'),
('CAB-09', 'Pamplona Cabinet', 'pamplona', 516, 443, 'LP-B')
ON DUPLICATE KEY UPDATE `name`=`name`;

INSERT INTO `customers` (`customer_code`, `full_name`, `address`, `city`, `region`, `barangay_id`, `cabinet_id`, `zone`, `request_id`, `plan`, `status`, `has_issue`, `signal_dbm`, `x`, `y`, `registered_at`) VALUES
('S2S-10240', 'Maria Santos', '24 Dr. A. Santos Ave', 'Parañaque', 'NCR', 'baclaran', 'CAB-01', 'PQ-A · CAB-01', 'SR-4211', 'Surf 999 · 200 Mbps', 'active', 0, -16, 270, 100, NOW() - INTERVAL 12 DAY),
('S2S-10241', 'Jose Reyes', '77 Quirino Ave', 'Parañaque', 'NCR', 'tambo', 'CAB-02', 'PQ-B · CAB-02', 'SR-4312', 'Surf 699 · 100 Mbps', 'pending', 0, NULL, 325, 220, NOW() - INTERVAL 8 DAY),
('S2S-10242', 'Angela Cruz', '12 Tropical Ave', 'Parañaque', 'NCR', 'bfhomes', 'CAB-06', 'PQ-F · CAB-06', 'SR-4409', 'Surf 1499 · 500 Mbps', 'active', 1, -21, 585, 260, NOW() - INTERVAL 15 DAY),
('S2S-10243', 'Eduardo Ramos', '45 Ninoy Aquino Ave', 'Parañaque', 'NCR', 'donbosco', 'CAB-04', 'PQ-D · CAB-04', 'SR-4501', 'Surf 999 · 200 Mbps', 'active', 0, -17, 430, 180, NOW() - INTERVAL 6 DAY),
('S2S-10244', 'Lourdes Bautista', '88 Moonwalk Access Rd', 'Parañaque', 'NCR', 'moonwalk', 'CAB-05', 'PQ-E · CAB-05', 'SR-4502', 'Surf 699 · 100 Mbps', 'pending', 0, NULL, 440, 330, NOW() - INTERVAL 3 DAY),
('S2S-10245', 'Renato Mendoza', '15 Sun Valley Drive', 'Parañaque', 'NCR', 'sunvalley', 'CAB-07', 'PQ-G · CAB-07', 'SR-4503', 'Surf 1499 · 500 Mbps', 'suspended', 0, NULL, 560, 102, NOW() - INTERVAL 20 DAY),
('S2S-10246', 'Teresa Cruz', '102 Alabang-Zapote Rd', 'Las Piñas', 'NCR', 'talon', 'CAB-08', 'LP-A · CAB-08', 'SR-4504', 'Surf 999 · 200 Mbps', 'active', 0, -18, 310, 442, NOW() - INTERVAL 4 DAY),
('S2S-10247', 'Felipe Del Rosario', '33 Real St, Pamplona', 'Las Piñas', 'NCR', 'pamplona', 'CAB-09', 'LP-B · CAB-09', 'SR-4505', 'Surf 699 · 100 Mbps', 'active', 0, -15, 520, 446, NOW() - INTERVAL 2 DAY),
('S2S-10248', 'Gloria Soriano', 'Coastal Bay Zone 1', 'Parañaque', 'NCR', 'baclaran', 'CAB-01', 'PQ-A · CAB-01', 'SR-4506', NULL, 'unavailable', 0, NULL, 210, 75, NOW() - INTERVAL 1 DAY)
ON DUPLICATE KEY UPDATE `customer_code`=`customer_code`;

SET FOREIGN_KEY_CHECKS = 1;
