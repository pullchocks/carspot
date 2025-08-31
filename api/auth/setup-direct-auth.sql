-- Setup Direct Authentication Support
-- This script ensures the database has the necessary fields for both GTA World OAuth and direct login

-- Ensure password_hash field exists and is nullable (for GTA World users who don't have passwords)
ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NULL;

-- Ensure email field exists and is unique
ALTER TABLE users MODIFY COLUMN email VARCHAR(255) UNIQUE NOT NULL;

-- Add index on email for faster login lookups
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);

-- Add index on password_hash for users who have direct login
CREATE INDEX IF NOT EXISTS idx_users_password_hash ON users(password_hash);

-- Ensure user_wallets table exists for new users
CREATE TABLE IF NOT EXISTS user_wallets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    balance DECIMAL(15,2) DEFAULT 0.00,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Add index on user_id for faster wallet lookups
CREATE INDEX IF NOT EXISTS idx_user_wallets_user_id ON user_wallets(user_id);

-- Update existing users to have a wallet if they don't have one
INSERT IGNORE INTO user_wallets (user_id, balance)
SELECT id, 0.00 FROM users WHERE id NOT IN (SELECT user_id FROM user_wallets);
