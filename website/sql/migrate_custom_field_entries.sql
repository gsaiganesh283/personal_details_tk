-- ============================================================
-- Migration Script: Fix custom_field_entries table
-- Run this on your hosted database (ezyro_41197723_psd)
-- ============================================================
-- The custom_field_entries table was created with wrong column
-- names that don't match the PHP code. This script fixes it.
--
-- BEFORE: field_id, linked_id, entry_index, block_id
-- AFTER:  custom_field_id, record_id, block_instance (no block_id)
-- ============================================================

-- Step 1: Drop foreign key constraints (must drop before renaming/dropping columns)
ALTER TABLE `custom_field_entries` DROP FOREIGN KEY `custom_field_entries_ibfk_2`;
ALTER TABLE `custom_field_entries` DROP FOREIGN KEY `custom_field_entries_ibfk_3`;

-- Step 2: Drop the block_id index and column (code doesn't use it)
ALTER TABLE `custom_field_entries` DROP INDEX `block_id`;
ALTER TABLE `custom_field_entries` DROP COLUMN `block_id`;

-- Step 3: Rename columns to match PHP code
ALTER TABLE `custom_field_entries` CHANGE COLUMN `field_id` `custom_field_id` int(11) NOT NULL;
ALTER TABLE `custom_field_entries` CHANGE COLUMN `linked_id` `record_id` int(11) NOT NULL;
ALTER TABLE `custom_field_entries` CHANGE COLUMN `entry_index` `block_instance` int(11) DEFAULT 0;

-- Step 4: Drop old index on field_id (now custom_field_id)
ALTER TABLE `custom_field_entries` DROP INDEX `field_id`;

-- Step 5: Add unique constraint to prevent duplicate entries
ALTER TABLE `custom_field_entries` ADD UNIQUE KEY `uq_field_entry` (`custom_field_id`, `linked_to`, `record_id`, `block_instance`);

-- Step 6: Add index on record_id for faster lookups
ALTER TABLE `custom_field_entries` ADD INDEX `idx_record` (`linked_to`, `record_id`);

-- ============================================================
-- Done! The custom_field_entries table now matches the PHP code.
-- ============================================================
