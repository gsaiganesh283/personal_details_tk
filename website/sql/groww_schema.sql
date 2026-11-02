-- Groww investment dashboard tables.
-- These are also auto-created on first use by ensureGrowwSchema() in
-- includes/groww-helper.php — this file is for manual/reference setup only.

CREATE TABLE IF NOT EXISTS groww_credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    auth_method ENUM('access_token','api_secret','totp') NOT NULL DEFAULT 'access_token',
    api_key_enc TEXT NULL,
    api_secret_enc TEXT NULL,
    totp_secret_enc TEXT NULL,
    access_token_enc TEXT NULL,
    token_expires_at DATETIME NULL,
    last_error VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_groww_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS groww_portfolio_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    snapshot_date DATE NOT NULL,
    invested_value DECIMAL(15,2) NOT NULL DEFAULT 0,
    current_value DECIMAL(15,2) NOT NULL DEFAULT 0,
    pnl DECIMAL(15,2) NOT NULL DEFAULT 0,
    pnl_pct DECIMAL(8,3) NOT NULL DEFAULT 0,
    clear_cash DECIMAL(15,2) NOT NULL DEFAULT 0,
    holdings_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_groww_snap (user_id, snapshot_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS groww_watchlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    trading_symbol VARCHAR(50) NOT NULL,
    exchange VARCHAR(10) NOT NULL DEFAULT 'NSE',
    segment VARCHAR(20) NOT NULL DEFAULT 'CASH',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_groww_watch (user_id, trading_symbol, segment),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Manually tracked — Groww's Trading API doesn't expose mutual fund/SIP data.
CREATE TABLE IF NOT EXISTS groww_mutual_funds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    fund_name VARCHAR(150) NOT NULL,
    amc VARCHAR(100) NULL,
    folio_number VARCHAR(50) NULL,
    category VARCHAR(50) NULL,
    units DECIMAL(14,3) NOT NULL DEFAULT 0,
    invested_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    current_value DECIMAL(15,2) NOT NULL DEFAULT 0,
    is_sip TINYINT(1) NOT NULL DEFAULT 0,
    sip_amount DECIMAL(12,2) NULL,
    sip_day TINYINT NULL COMMENT 'Day of month SIP is debited, 1-28',
    notes TEXT NULL,
    updated_on DATE NULL COMMENT 'Date the user last refreshed current_value',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_groww_mf_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
