-- Apply once to an existing Surf to Sawa database.
-- Adds an optional admin-managed picture to each internet plan.

ALTER TABLE plans
    ADD COLUMN image VARCHAR(255) DEFAULT NULL AFTER duration_hours;