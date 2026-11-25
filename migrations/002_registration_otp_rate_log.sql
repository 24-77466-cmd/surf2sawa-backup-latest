CREATE TABLE IF NOT EXISTS registration_otp_send_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    phone_e164 VARCHAR(16) NOT NULL,
    request_ip VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_otp_log_phone_created (phone_e164, created_at),
    KEY idx_otp_log_ip_created (request_ip, created_at),
    KEY idx_otp_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
