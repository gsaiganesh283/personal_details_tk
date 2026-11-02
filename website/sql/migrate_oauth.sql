-- Migration: Add OAuth social login columns to users table
-- Run this on your hosted database via phpMyAdmin

ALTER TABLE users
    ADD COLUMN oauth_provider VARCHAR(20) DEFAULT NULL COMMENT 'google, github, apple' AFTER avatar,
    ADD COLUMN oauth_id VARCHAR(255) DEFAULT NULL COMMENT 'Provider unique user ID' AFTER oauth_provider,
    ADD UNIQUE KEY unique_oauth (oauth_provider, oauth_id);

-- Make password nullable for OAuth-only users
ALTER TABLE users MODIFY password VARCHAR(255) DEFAULT NULL;
