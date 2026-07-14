-- ==========================================================================
-- 0001_seed.sql — minimal seed data for local development
--
-- Creates a single administrator account so a fresh install is usable.
--
--   username: admin
--   password: ChangeMe123!      ← CHANGE THIS IMMEDIATELY after first login
--
-- The hash below is a bcrypt hash of the password above. Generate your own with:
--   php -r "echo password_hash('your-password', PASSWORD_BCRYPT);"
-- ==========================================================================

INSERT INTO `users` (`username`, `email`, `password`, `name`, `role`, `is_active`)
VALUES (
    'admin',
    'admin@dmf.ac.th',
    '$2y$10$dvhnZGZbGmUd3l5yL1M5M.bNywtG29HLjtDiIUjmUhMjCXE6ysiLG',
    'Administrator',
    'super_admin',
    1
)
ON DUPLICATE KEY UPDATE `username` = `username`;
