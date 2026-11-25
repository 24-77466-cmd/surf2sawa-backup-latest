-- Apply once to an existing Surf to Sawa database.
-- Adds email OTP delivery for registration plus login / phone-change email challenges.

ALTER TABLE pending_registrations
    MODIFY COLUMN otp_mode ENUM('development','twilio_verify','email_smtp') NOT NULL;

CREATE TABLE IF NOT EXISTS email_otp_challenges (
    id CHAR(32) NOT NULL PRIMARY KEY,
    user_id INT NOT NULL,
    purpose ENUM('login','phone_change') NOT NULL,
    email VARCHAR(100) NOT NULL,
    payload MEDIUMTEXT DEFAULT NULL,
    otp_hash CHAR(64) NOT NULL,
    otp_mode ENUM('development','smtp') NOT NULL,
    otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    otp_resends TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_sent_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    request_ip VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_otp_user_purpose (user_id, purpose),
    KEY idx_email_otp_expires (expires_at),
    CONSTRAINT email_otp_challenges_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Retain send timestamps independently from expiring challenges for rate limits.
CREATE TABLE IF NOT EXISTS email_otp_send_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL,
    purpose VARCHAR(20) NOT NULL,
    request_ip VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_email_otp_log_email_created (email, created_at),
    KEY idx_email_otp_log_ip_created (request_ip, created_at),
    KEY idx_email_otp_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
