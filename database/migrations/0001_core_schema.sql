-- ==========================================================================
-- 0001_core_schema.sql — DMF PHP Template core schema
--
-- The minimal, reusable data foundation shared by every DMF PHP application:
-- identities, authentication, and an audit trail. No business/domain tables
-- (they belong to the consuming project's own migrations).
--
-- Engine/charset match the platform standard: InnoDB + utf8mb4_unicode_ci.
-- ==========================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- --------------------------------------------------------------------------
-- users — application accounts + role
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(64)     NOT NULL,
    `email`         VARCHAR(190)    DEFAULT NULL,
    `password`      VARCHAR(255)    NOT NULL,           -- bcrypt/argon hash
    `name`          VARCHAR(190)    NOT NULL,
    `role`          VARCHAR(32)     NOT NULL DEFAULT 'user',
    `avatar`        VARCHAR(255)    DEFAULT NULL,
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `last_login`    DATETIME        DEFAULT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_username` (`username`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- password_resets — token-based password recovery
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     BIGINT UNSIGNED NOT NULL,
    `token_hash`  CHAR(64)        NOT NULL,             -- sha256 of the emailed token
    `expires_at`  DATETIME        NOT NULL,
    `used_at`     DATETIME        DEFAULT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pr_user` (`user_id`),
    KEY `idx_pr_token` (`token_hash`),
    CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- login_attempts — rate-limit / lockout support (reference flagged as unused;
-- shipped enforced-ready here)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`    VARCHAR(64)     NOT NULL,
    `ip_address`  VARCHAR(45)     NOT NULL,
    `successful`  TINYINT(1)      NOT NULL DEFAULT 0,
    `attempted_at` DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_la_username` (`username`),
    KEY `idx_la_ip` (`ip_address`),
    KEY `idx_la_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- audit_logs — generic action trail (who did what, from where)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     BIGINT UNSIGNED DEFAULT NULL,
    `action`      VARCHAR(64)     NOT NULL,
    `entity`      VARCHAR(64)     DEFAULT NULL,
    `entity_id`   BIGINT UNSIGNED DEFAULT NULL,
    `data`        JSON            DEFAULT NULL,
    `ip_address`  VARCHAR(45)     DEFAULT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_user` (`user_id`),
    KEY `idx_audit_action` (`action`),
    KEY `idx_audit_entity` (`entity`, `entity_id`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
