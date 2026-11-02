-- Additional tables for new menu sections
-- USE personal_details_db;

-- Bank Details table
CREATE TABLE IF NOT EXISTS bank_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    bank_name VARCHAR(150) NOT NULL,
    account_holder VARCHAR(150) NOT NULL,
    account_number VARCHAR(50) NOT NULL,
    account_type ENUM('savings', 'current', 'salary', 'fixed_deposit', 'recurring', 'nri', 'other') DEFAULT 'savings',
    ifsc_code VARCHAR(20) DEFAULT NULL,
    branch_name VARCHAR(150) DEFAULT NULL,
    branch_address TEXT DEFAULT NULL,
    swift_code VARCHAR(20) DEFAULT NULL,
    micr_code VARCHAR(20) DEFAULT NULL,
    upi_id VARCHAR(100) DEFAULT NULL,
    linked_phone VARCHAR(20) DEFAULT NULL,
    is_primary TINYINT(1) DEFAULT 0,
    status ENUM('active', 'closed', 'dormant') DEFAULT 'active',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Documents / PDFs table
CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    document_name VARCHAR(255) NOT NULL,
    document_type ENUM('pdf', 'image', 'certificate', 'id_proof', 'address_proof', 'income_proof', 'education', 'employment', 'insurance', 'legal', 'medical', 'other') DEFAULT 'other',
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT DEFAULT 0 COMMENT 'Size in bytes',
    mime_type VARCHAR(100) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    tags VARCHAR(500) DEFAULT NULL COMMENT 'Comma-separated tags',
    linked_to VARCHAR(50) DEFAULT NULL COMMENT 'e.g. aadhaar, pan, bank',
    linked_id INT DEFAULT NULL,
    is_encrypted TINYINT(1) DEFAULT 0,
    expiry_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Aadhaar Details table
CREATE TABLE IF NOT EXISTS aadhaar_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    holder_name VARCHAR(150) NOT NULL,
    aadhaar_number VARCHAR(14) NOT NULL COMMENT 'Stored as XXXX-XXXX-XXXX',
    date_of_birth DATE DEFAULT NULL,
    gender ENUM('male', 'female', 'other') DEFAULT NULL,
    address TEXT DEFAULT NULL,
    pin_code VARCHAR(10) DEFAULT NULL,
    mobile_linked VARCHAR(20) DEFAULT NULL,
    email_linked VARCHAR(100) DEFAULT NULL,
    enrollment_number VARCHAR(30) DEFAULT NULL,
    vid_number VARCHAR(20) DEFAULT NULL COMMENT 'Virtual ID',
    issue_date DATE DEFAULT NULL,
    is_verified TINYINT(1) DEFAULT 0,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PAN Details table
CREATE TABLE IF NOT EXISTS pan_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    holder_name VARCHAR(150) NOT NULL,
    pan_number VARCHAR(10) NOT NULL COMMENT 'Format: ABCDE1234F',
    father_name VARCHAR(150) DEFAULT NULL,
    date_of_birth DATE DEFAULT NULL,
    category ENUM('individual', 'huf', 'company', 'firm', 'aop', 'trust', 'government', 'other') DEFAULT 'individual',
    mobile_number VARCHAR(20) DEFAULT NULL,
    email VARCHAR(100) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    issue_date DATE DEFAULT NULL,
    is_linked_aadhaar TINYINT(1) DEFAULT 0,
    status ENUM('active', 'inactive', 'surrendered') DEFAULT 'active',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Other Details table (flexible key-value style with categories)
CREATE TABLE IF NOT EXISTS other_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category VARCHAR(100) NOT NULL DEFAULT 'General',
    title VARCHAR(200) NOT NULL,
    detail_type ENUM('text', 'number', 'date', 'url', 'email', 'phone', 'password', 'note') DEFAULT 'text',
    detail_value TEXT NOT NULL,
    is_sensitive TINYINT(1) DEFAULT 0 COMMENT 'Mark as password/sensitive to mask display',
    tags VARCHAR(500) DEFAULT NULL,
    expiry_date DATE DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Custom Field Blocks table
CREATE TABLE IF NOT EXISTS custom_field_blocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    block_name VARCHAR(100) NOT NULL,
    block_label VARCHAR(150) NOT NULL,
    linked_to VARCHAR(50) NOT NULL DEFAULT 'personal_details',
    layout VARCHAR(20) DEFAULT '1-col' COMMENT '1-col, 2-col, 3-col, 4-col, 2-1, 1-2',
    block_order INT DEFAULT 0,
    is_repeatable TINYINT(1) DEFAULT 0,
    max_instances INT DEFAULT 10,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Updated custom_fields table to include block support
-- ALTER TABLE custom_fields ADD COLUMN block_id INT DEFAULT NULL;
-- ALTER TABLE custom_fields ADD COLUMN field_order_in_block INT DEFAULT 0;
-- ALTER TABLE custom_fields ADD FOREIGN KEY (block_id) REFERENCES custom_field_blocks(id) ON DELETE SET NULL;

-- Custom Field Entries (for repeatable blocks and custom field values)
CREATE TABLE IF NOT EXISTS custom_field_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    custom_field_id INT NOT NULL,
    linked_to VARCHAR(50) NOT NULL,
    record_id INT NOT NULL,
    block_instance INT DEFAULT 0,
    field_value TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_field_entry (custom_field_id, linked_to, record_id, block_instance),
    INDEX idx_record (linked_to, record_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- WebAuthn / Fingerprint credentials table
CREATE TABLE IF NOT EXISTS webauthn_credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    credential_id TEXT NOT NULL,
    public_key LONGTEXT NOT NULL,
    sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    device_name VARCHAR(120) NOT NULL DEFAULT 'My Device',
    last_used TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_credential_id (credential_id(255)),
    KEY idx_webauthn_user_id (user_id),
    CONSTRAINT fk_webauthn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;