<?php
/**
 * Authentication Helper Functions
 */

require_once __DIR__ . '/../config/database.php';

class Auth {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Register a new user
     */
    public function register($username, $email, $password, $fullName) {
        // Validate inputs
        $errors = [];

        if (empty($username) || strlen($username) < 3) {
            $errors[] = "Username must be at least 3 characters long";
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email address";
        }

        if (strlen($password) < 8) {
            $errors[] = "Password must be at least 8 characters long";
        }

        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain uppercase, lowercase, and numbers";
        }

        if (empty($fullName)) {
            $errors[] = "Full name is required";
        }

        // Check if username or email already exists
        $existing = $this->db->fetch(
            "SELECT id FROM users WHERE username = ? OR email = ?",
            [$username, $email]
        );

        if ($existing) {
            $errors[] = "Username or email already exists";
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // Hash password and insert user
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $userId = $this->db->insert(
            "INSERT INTO users (username, email, password, full_name) VALUES (?, ?, ?, ?)",
            [$username, $email, $hashedPassword, $fullName]
        );

        // Log activity
        $this->logActivity($userId, 'register', 'User registered successfully');

        return ['success' => true, 'user_id' => $userId];
    }

    /**
     * Login user
     */
    public function login($usernameOrEmail, $password, $rememberMe = false) {
        $user = $this->db->fetch(
            "SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1",
            [$usernameOrEmail, $usernameOrEmail]
        );

        if (!$user || !password_verify($password, $user['password'])) {
            return ['success' => false, 'errors' => ['Invalid credentials']];
        }

        // Start session
        $this->createSession($user['id'], $rememberMe);

        // Log activity
        $this->logActivity($user['id'], 'login', 'User logged in');

        return ['success' => true, 'user' => $user];
    }

    /**
     * Create user session
     */
    private function createSession($userId, $rememberMe = false) {
        $_SESSION['user_id'] = $userId;
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();

        if ($rememberMe) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + REMEMBER_ME_LIFETIME);

            $this->db->insert(
                "INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)",
                [$userId, $token, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', $expires]
            );

            $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            setcookie('remember_token', $token, time() + REMEMBER_ME_LIFETIME, '/', '', $isHttps, true);
        }
    }

    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
            return true;
        }

        // Check remember me cookie
        if (isset($_COOKIE['remember_token'])) {
            $session = $this->db->fetch(
                "SELECT * FROM user_sessions WHERE session_token = ? AND expires_at > NOW()",
                [$_COOKIE['remember_token']]
            );

            if ($session) {
                $_SESSION['user_id'] = $session['user_id'];
                $_SESSION['logged_in'] = true;
                $_SESSION['last_activity'] = time();
                return true;
            }
        }

        return false;
    }

    /**
     * Get current logged-in user
     */
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }

        return $this->db->fetch(
            "SELECT id, username, email, full_name, avatar, role, created_at FROM users WHERE id = ?",
            [$_SESSION['user_id']]
        );
    }

    /**
     * Logout user
     */
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            $this->logActivity($_SESSION['user_id'], 'logout', 'User logged out');

            // Remove remember me tokens
            $this->db->delete(
                "DELETE FROM user_sessions WHERE user_id = ?",
                [$_SESSION['user_id']]
            );
        }

        // Clear cookies
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', time() - 3600, '/');
        }

        session_destroy();
        session_start();
    }

    /**
     * Update user profile
     */
    public function updateProfile($userId, $data) {
        $fields = [];
        $params = [];

        if (!empty($data['full_name'])) {
            $fields[] = "full_name = ?";
            $params[] = $data['full_name'];
        }

        if (!empty($data['email'])) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'errors' => ['Invalid email']];
            }
            $fields[] = "email = ?";
            $params[] = $data['email'];
        }

        if (!empty($data['avatar'])) {
            $fields[] = "avatar = ?";
            $params[] = $data['avatar'];
        }

        if (empty($fields)) {
            return ['success' => false, 'errors' => ['No fields to update']];
        }

        $params[] = $userId;
        $this->db->update(
            "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?",
            $params
        );

        $this->logActivity($userId, 'profile_update', 'Profile updated');

        return ['success' => true];
    }

    /**
     * Change password
     */
    public function changePassword($userId, $currentPassword, $newPassword) {
        $user = $this->db->fetch("SELECT password FROM users WHERE id = ?", [$userId]);

        if (!password_verify($currentPassword, $user['password'])) {
            return ['success' => false, 'errors' => ['Current password is incorrect']];
        }

        if (strlen($newPassword) < 8) {
            return ['success' => false, 'errors' => ['New password must be at least 8 characters']];
        }

        $hashed = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->db->update("UPDATE users SET password = ? WHERE id = ?", [$hashed, $userId]);

        $this->logActivity($userId, 'password_change', 'Password changed');

        return ['success' => true];
    }

    /**
     * Log activity
     */
    private function logActivity($userId, $action, $description) {
        $this->db->insert(
            "INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
            [$userId, $action, $description, $_SERVER['REMOTE_ADDR'] ?? '']
        );
    }

    /**
     * Get CSRF token
     */
    public static function generateCSRF() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validate CSRF token
     */
    public static function validateCSRF($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    // ──────────────────────────────────────────────
    //  OAuth / Social Login helpers
    // ──────────────────────────────────────────────

    /**
     * Find an existing user by OAuth provider + provider ID
     */
    public function findOAuthUser($provider, $oauthId) {
        return $this->db->fetch(
            "SELECT * FROM users WHERE oauth_provider = ? AND oauth_id = ? AND is_active = 1",
            [$provider, $oauthId]
        );
    }

    /**
     * Find or create a user from OAuth profile data, then log them in.
     *
     * @param string $provider  google|github|apple
     * @param array  $profile   ['id', 'email', 'name', 'avatar']
     * @return array            ['success' => bool, ...]
     */
    public function handleOAuthLogin($provider, $profile) {
        // 1. Try to find user by OAuth provider + ID
        $user = $this->findOAuthUser($provider, $profile['id']);

        if ($user) {
            // Existing OAuth user – log in
            $this->createSession($user['id'], false);
            $this->logActivity($user['id'], 'oauth_login', "Logged in via $provider");
            return ['success' => true, 'user' => $user];
        }

        // 2. Check if an account with the same email already exists
        if (!empty($profile['email'])) {
            $existing = $this->db->fetch(
                "SELECT * FROM users WHERE email = ? AND is_active = 1",
                [$profile['email']]
            );

            if ($existing) {
                // Link this OAuth provider to the existing account
                $this->db->update(
                    "UPDATE users SET oauth_provider = ?, oauth_id = ? WHERE id = ?",
                    [$provider, $profile['id'], $existing['id']]
                );
                $this->createSession($existing['id'], false);
                $this->logActivity($existing['id'], 'oauth_link', "Linked $provider account");
                return ['success' => true, 'user' => $existing];
            }
        }

        // 3. Create a brand-new user
        $username = $this->generateUniqueUsername($profile['name'] ?? $profile['email'] ?? $provider . '_user');

        $userId = $this->db->insert(
            "INSERT INTO users (username, email, password, full_name, avatar, oauth_provider, oauth_id)
             VALUES (?, ?, NULL, ?, ?, ?, ?)",
            [
                $username,
                $profile['email'] ?? '',
                $profile['name'] ?? $username,
                $profile['avatar'] ?? null,
                $provider,
                $profile['id'],
            ]
        );

        $this->createSession($userId, false);
        $this->logActivity($userId, 'oauth_register', "Registered via $provider");

        $newUser = $this->db->fetch("SELECT * FROM users WHERE id = ?", [$userId]);
        return ['success' => true, 'user' => $newUser, 'new_account' => true];
    }

    /**
     * Generate a unique username from a display name.
     */
    private function generateUniqueUsername($name) {
        // Slug-ify the name
        $base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name));
        if (strlen($base) < 3) $base = 'user';
        $username = substr($base, 0, 40);

        $existing = $this->db->fetch("SELECT id FROM users WHERE username = ?", [$username]);
        if (!$existing) return $username;

        // Append random digits until unique
        for ($i = 0; $i < 100; $i++) {
            $candidate = $username . rand(10, 9999);
            $existing = $this->db->fetch("SELECT id FROM users WHERE username = ?", [$candidate]);
            if (!$existing) return $candidate;
        }

        return $username . '_' . bin2hex(random_bytes(4));
    }

    // ──────────────────────────────────────────────
    //  Password Reset
    // ──────────────────────────────────────────────

    /**
     * Create a password reset token for a user
     *
     * @param string $email User's email address
     * @return array        ['success' => bool, 'token' => string, 'user' => array]
     */
    public function createPasswordResetToken($email) {
        $user = $this->db->fetch(
            "SELECT id, email, full_name FROM users WHERE email = ? AND is_active = 1",
            [$email]
        );

        if (!$user) {
            // For security, don't reveal if email exists
            return ['success' => false, 'message' => 'If an account exists with that email, you will receive a reset link.'];
        }

        // Generate secure token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry

        // Clear old tokens for this user
        $this->db->delete(
            "DELETE FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL",
            [$user['id']]
        );

        // Store new token
        $this->db->insert(
            "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)",
            [$user['id'], $token, $expiresAt]
        );

        $this->logActivity($user['id'], 'password_reset_requested', 'Password reset token generated');

        return [
            'success' => true,
            'token' => $token,
            'user' => $user,
            'message' => 'If an account exists with that email, you will receive a reset link.'
        ];
    }

    /**
     * Validate and retrieve password reset token
     *
     * @param string $token Reset token
     * @return array        ['success' => bool, 'user' => array or 'error' => string]
     */
    public function validatePasswordResetToken($token) {
        if (empty($token) || strlen($token) !== 64) {
            return ['success' => false, 'error' => 'Invalid reset link.'];
        }

        $resetRecord = $this->db->fetch(
            "SELECT * FROM password_reset_tokens WHERE token = ? AND used_at IS NULL AND expires_at > NOW()",
            [$token]
        );

        if (!$resetRecord) {
            return ['success' => false, 'error' => 'This reset link has expired or is invalid.'];
        }

        $user = $this->db->fetch(
            "SELECT id, email, full_name FROM users WHERE id = ? AND is_active = 1",
            [$resetRecord['user_id']]
        );

        if (!$user) {
            return ['success' => false, 'error' => 'User account not found.'];
        }

        return ['success' => true, 'user' => $user, 'token_id' => $resetRecord['id']];
    }

    /**
     * Reset password using a valid token
     *
     * @param string $token        Reset token
     * @param string $newPassword  New password
     * @return array               ['success' => bool, 'errors' => array]
     */
    public function resetPassword($token, $newPassword) {
        // Validate token
        $validation = $this->validatePasswordResetToken($token);
        if (!$validation['success']) {
            return ['success' => false, 'errors' => [$validation['error']]];
        }

        // Validate password strength
        $errors = [];
        if (strlen($newPassword) < 8) {
            $errors[] = 'Password must be at least 8 characters long';
        }
        if (!preg_match('/[A-Z]/', $newPassword)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }
        if (!preg_match('/[a-z]/', $newPassword)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }
        if (!preg_match('/[0-9]/', $newPassword)) {
            $errors[] = 'Password must contain at least one number';
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $userId = $validation['user']['id'];
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        // Update password
        $this->db->update(
            "UPDATE users SET password = ? WHERE id = ?",
            [$hashedPassword, $userId]
        );

        // Mark token as used
        $this->db->update(
            "UPDATE password_reset_tokens SET used_at = NOW() WHERE token = ?",
            [$token]
        );

        $this->logActivity($userId, 'password_reset_completed', 'Password reset successfully');

        return ['success' => true];
    }
}
