-- Apply once to an existing Surf to Sawa database.
ALTER TABLE users
    ADD COLUMN phone_e164 VARCHAR(16) DEFAULT NULL AFTER email,
    ADD COLUMN phone_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER phone_e164,
    ADD UNIQUE KEY uq_users_phone_e164 (phone_e164);

CREATE TABLE IF NOT EXISTS pending_registrations (
    id CHAR(32) NOT NULL PRIMARY KEY,
    email VARCHAR(100) NOT NULL,
    phone_e164 VARCHAR(16) NOT NULL,
    registration_data MEDIUMTEXT NOT NULL,
    otp_hash CHAR(64) DEFAULT NULL,
    otp_mode ENUM('development','twilio_verify') NOT NULL,
    otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    otp_resends TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_sent_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    request_ip VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pending_registration_email (email),
    KEY idx_pending_phone_sent (phone_e164, last_sent_at),
    KEY idx_pending_ip_sent (request_ip, last_sent_at),
    KEY idx_pending_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
