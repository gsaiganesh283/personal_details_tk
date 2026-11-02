-- New tables for the "custom fields on transactions" feature added on the
-- desktop app this session (see desktop-sync-api.php's ensureSavingsTables()
-- and app/services/mysql_sync.py's TABLE_CONFIG). Safe to run as-is
-- (IF NOT EXISTS everywhere) -- no data, structure only.
--
-- Alternative to running this by hand: ensureSavingsTables() already
-- self-creates both tables on the live site's very next request once
-- desktop-sync-api.php is redeployed, same as the savings_loans /
-- savings_credit_cards / savings_lent_money tables did on 2026-08-16.

CREATE TABLE IF NOT EXISTS savings_bank_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    bank_account_id INT NOT NULL,
    date DATE NOT NULL,
    description VARCHAR(255) NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'deposit',
    amount DECIMAL(14,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_account (bank_account_id),
    FOREIGN KEY (bank_account_id) REFERENCES savings_bank_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS savings_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category VARCHAR(50) NOT NULL,
    record_id INT NOT NULL,
    date DATE NOT NULL,
    description VARCHAR(255) NULL,
    type VARCHAR(20) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    units DECIMAL(14,4) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_category_record (category, record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
