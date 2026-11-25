-- Apply once to an existing Surf to Sawa database.
-- Switches verification from email/SMS OTP to Google Authenticator (TOTP).

ALTER TABLE users
    ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL AFTER phone_verified;

ALTER TABLE pending_registrations
    ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL AFTER otp_hash,
    MODIFY COLUMN otp_mode ENUM('development','twilio_verify','email_smtp','totp') NOT NULL;

-- Brute-force protection for authenticator codes (login / phone change).
CREATE TABLE IF NOT EXISTS totp_attempts (
    user_id INT NOT NULL,
    purpose ENUM('login','phone_change') NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    first_attempt_at DATETIME NOT NULL,
    locked_until DATETIME DEFAULT NULL,
    PRIMARY KEY (user_id, purpose),
    CONSTRAINT totp_attempts_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Remove the replaced email OTP machinery (introduced in 004, no longer used).
DROP TABLE IF EXISTS email_otp_challenges;
DROP TABLE IF EXISTS email_otp_send_log;

-- SMS send log is no longer used now that registration verifies via authenticator.
DROP TABLE IF EXISTS registration_otp_send_log;
