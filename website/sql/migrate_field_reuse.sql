-- Migration: Enable Custom Fields Reuse Across Multiple Modules
-- This migration creates a many-to-many relationship for custom fields

-- Create junction table for many-to-many relationship
CREATE TABLE IF NOT EXISTS custom_field_modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    custom_field_id INT NOT NULL,
    module_name VARCHAR(50) NOT NULL,
    field_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (custom_field_id) REFERENCES custom_fields(id) ON DELETE CASCADE,
    UNIQUE KEY unique_field_module (custom_field_id, module_name),
    INDEX idx_module (module_name),
    INDEX idx_field (custom_field_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrate existing linked_to values to the junction table
INSERT INTO custom_field_modules (custom_field_id, module_name, field_order, is_active)
SELECT id, linked_to, field_order, is_active
FROM custom_fields
WHERE linked_to IS NOT NULL AND linked_to != '';

-- Keep the linked_to column for backward compatibility (but it's now primary module)
-- You can later drop this column if you want
ALTER TABLE custom_fields 
    MODIFY linked_to VARCHAR(50) DEFAULT 'personal_details' COMMENT 'Primary module (legacy - now managed via custom_field_modules)';
