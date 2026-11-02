-- Savings & Investment Tracker tables (12 manually-maintained categories).
-- These are also auto-created on first use by savings-manage.php --
-- this file is for manual/reference setup only.
--
-- Kept fully separate from the Groww broker integration (see groww_schema.sql)
-- -- these are user-entered records, not live brokerage holdings.

CREATE TABLE IF NOT EXISTS savings_daily (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    date DATE NOT NULL,
    description VARCHAR(255) NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_savings_daily_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS savings_bank_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    bank_name VARCHAR(150) NOT NULL,
    account_type VARCHAR(50) NULL,
    account_no_last4 VARCHAR(10) NULL,
    branch VARCHAR(150) NULL,
    interest_rate DECIMAL(6,3) NULL,
    balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_savings_bank_accounts_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS savings_fixed_deposits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    bank VARCHAR(150) NOT NULL,
    fd_number VARCHAR(100) NULL,
    principal DECIMAL(14,2) NOT NULL DEFAULT 0,
    interest_rate DECIMAL(6,3) NULL,
    start_date DATE NULL,
    maturity_date DATE NULL,
    tenure_months INT NULL,
    maturity_amount DECIMAL(14,2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_savings_fd_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS savings_recurring_deposits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    bank VARCHAR(150) NOT NULL,
    rd_number VARCHAR(100) NULL,
    monthly_installment DECIMAL(14,2) NOT NULL DEFAULT 0,
    interest_rate DECIMAL(6,3) NULL,
    start_date DATE NULL,
    maturity_date DATE NULL,
    months_paid INT NULL,
    total_deposited DECIMAL(14,2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_savings_rd_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Separate from groww_mutual_funds -- these are manually entered, not
-- pulled from the Groww broker API.
CREATE TABLE IF NOT EXISTS savings_mutual_funds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    fund_name VARCHAR(150) NOT NULL,
    category VARCHAR(50) NULL,
    units DECIMAL(14,4) NULL,
    buy_nav DECIMAL(10,4) NULL,
    current_nav DECIMAL(10,4) NULL,
    invested DECIMAL(14,2) NOT NULL DEFAULT 0,
    current_value DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_savings_mf_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS savings_sip_investments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    fund_name VARCHAR(150) NOT NULL,
    sip_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    frequency VARCHAR(50) NULL,
    start_date DATE NULL,
    installments_paid INT NULL,
    total_invested DECIMAL(14,2) NOT NULL DEFAULT 0,
    current_value DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_savings_sip_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS savings_stocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    stock_name VARCHAR(150) NOT NULL,
    ticker VARCHAR(30) NULL,
    quantity DECIMAL(14,4) NULL,
    buy_price DECIMAL(12,2) NULL,
    current_price DECIMAL(12,2) NULL,
    invested DECIMAL(14,2) NOT NULL DEFAULT 0,
    current_value DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_savings_stocks_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS savings_ppf_epf (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    account_type VARCHAR(20) NOT NULL DEFAULT 'ppf',
    account_no VARCHAR(100) NULL,
    opening_balance DECIMAL(14,2) NULL,
    annual_contribution DECIMAL(14,2) NULL,
    interest_rate DECIMAL(6,3) NULL,
    interest_earned DECIMAL(14,2) NULL,
    current_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_savings_ppf_epf_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS savings_gold_metals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    metal VARCHAR(20) NOT NULL DEFAULT 'gold',
    form VARCHAR(50) NULL,
    quantity_g DECIMAL(10,3) NULL,
    buy_rate_per_g DECIMAL(10,2) NULL,
    current_rate_per_g DECIMAL(10,2) NULL,
    invested DECIMAL(14,2) NOT NULL DEFAULT 0,
    current_value DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_savings_gold_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS savings_insurance_policies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    policy_name VARCHAR(150) NOT NULL,
    type VARCHAR(50) NULL,
    policy_no VARCHAR(100) NULL,
    premium DECIMAL(14,2) NULL,
    frequency VARCHAR(50) NULL,
    sum_assured DECIMAL(14,2) NULL,
    maturity_value DECIMAL(14,2) NULL,
    maturity_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_savings_insurance_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS savings_cash_on_hand (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    location VARCHAR(150) NOT NULL,
    notes TEXT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_savings_cash_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tracks where physical/digital records are kept -- not a file-upload
-- module like documents.php, just a registry of reference info.
CREATE TABLE IF NOT EXISTS savings_documents_registry (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category VARCHAR(100) NOT NULL,
    document_name VARCHAR(150) NULL,
    reference_no VARCHAR(100) NULL,
    format VARCHAR(20) NULL,
    location VARCHAR(150) NULL,
    nominee VARCHAR(100) NULL,
    issue_date DATE NULL,
    expiry_date DATE NULL,
    status VARCHAR(50) NULL,
    remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_savings_docs_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
