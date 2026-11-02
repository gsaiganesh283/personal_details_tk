-- Two-Factor Authentication (Google Authenticator) migration
-- Adds TOTP columns to the users table

ALTER TABLE users 
    ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL COMMENT 'Base32-encoded TOTP secret for Google Authenticator',
    ADD COLUMN totp_enabled TINYINT(1) DEFAULT 0 COMMENT '1 = 2FA is active for this user';
