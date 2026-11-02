# Password Reset & Email Notifications

This guide explains how the password reset and email notification system works in Personal Details Saver.

## Overview

The application can send emails for:
- **Password reset requests** - When a user forgets their password
- **Welcome emails** - When a new account is created
- **Future notifications** - Can be extended for other events

## How It Works

### 1. User Forgets Password

User flow:
1. User clicks "Forgot password?" on login page
2. User enters their email address on `/forgot-password.php`
3. System generates a secure reset token (valid for 1 hour)
4. Email is sent with a password reset link
5. User clicks the link in their email
6. User enters a new password on `/reset-password.php`
7. Password is securely updated in the database

### 2. Technical Flow

```
User clicks "Forgot password?"
         ↓
forgot-password.php
         ↓
Auth::createPasswordResetToken($email)
         ↓
Generate secure token + store in `password_reset_tokens` table
         ↓
Mailer::sendPasswordReset()
         ↓
Email sent to user's inbox
         ↓
User clicks reset link with token
         ↓
reset-password.php validates token
         ↓
User enters new password
         ↓
Auth::resetPassword($token, $password)
         ↓
Password updated + token marked as used
         ↓
User redirected to login page
```

## Files

### New Files
- **forgot-password.php** - Email request form
- **reset-password.php** - Password reset form (token validation)
- **includes/mailer.php** - Email sending class
- **sql/migrate_password_reset.sql** - Database migration

### Modified Files
- **includes/auth.php** - Added password reset methods
- **login.php** - Updated "Forgot password?" link
- **register.php** - Send welcome email on registration
- **sql/schema.sql** - Updated schema

## Database Tables

### password_reset_tokens
```sql
id              INT PRIMARY KEY
user_id         INT (FK to users)
token           VARCHAR(255) UNIQUE  -- Secure reset token
expires_at      TIMESTAMP           -- 1 hour expiry
used_at         TIMESTAMP NULL      -- Set when reset is used
created_at      TIMESTAMP
```

## Security Features

1. **Secure Token Generation** - Uses `bin2hex(random_bytes(32))` (64 characters)
2. **Token Expiration** - Tokens expire after 1 hour
3. **Single Use** - Token is marked as used after password reset
4. **Email Validation** - User must have access to registered email
5. **Password Strength** - Enforced requirements (8+ chars, uppercase, lowercase, number)
6. **No Email Enumeration** - Same message shown whether email exists or not

## Configuration

### Email From Address

The application uses the `MAIL_FROM` environment variable, falling back to:
```
noreply@your-domain.com
```

**To customize:**
1. On hosted servers, set via control panel environment variables
2. Or edit `includes/mailer.php` line 11

### Email Settings

Currently uses PHP's built-in `mail()` function, which works with most hosting providers.

**On ezyro hosting:**
- Mail function is enabled by default
- Emails are sent from `noreply@yourdomain.com`
- No additional configuration needed

**If emails don't arrive:**
1. Check `error.log` for mail errors
2. Verify hosting provider allows outbound mail
3. Test with a simple mail() call

## Testing

### Local Testing

```php
// Test password reset email
php -r "
require 'includes/mailer.php';
\$m = new Mailer();
\$m->sendPasswordReset(
    'test@example.com',
    'John Doe',
    'test_token_abc123',
    'http://localhost:8000/reset-password.php?token=test_token_abc123'
);
"
```

### On Hosted Server

1. Create account and go to "Forgot password?"
2. Enter test email address
3. Check email for reset link
4. Click link and reset password

## API Reference

### Auth Class Methods

#### createPasswordResetToken($email)
```php
$result = $auth->createPasswordResetToken('user@example.com');
// Returns:
// ['success' => bool, 'token' => string, 'user' => array, 'message' => string]
```

#### validatePasswordResetToken($token)
```php
$result = $auth->validatePasswordResetToken($token);
// Returns:
// ['success' => bool, 'user' => array|null, 'error' => string]
```

#### resetPassword($token, $newPassword)
```php
$result = $auth->resetPassword($token, 'NewPassword123');
// Returns:
// ['success' => bool, 'errors' => array]
```

### Mailer Class Methods

#### sendPasswordReset($email, $fullName, $token, $url)
```php
$mailer->sendPasswordReset(
    'user@example.com',
    'John Doe',
    'secure_token_value',
    'https://app.com/reset-password.php?token=secure_token_value'
);
// Returns: bool (success status)
```

#### sendWelcome($email, $fullName, $username)
```php
$mailer->sendWelcome(
    'user@example.com',
    'John Doe',
    'johndoe'
);
// Returns: bool (success status)
```

## Extending Email Notifications

To add more email types:

1. **Add method to Mailer class:**
```php
public function sendNotification($toEmail, $subject, $message) {
    $html = $this->buildEmailTemplate($subject, $message);
    return $this->send($toEmail, $subject, $html);
}
```

2. **Call from appropriate handler:**
```php
$mailer->sendNotification(
    $user['email'],
    'Your Notification Subject',
    'Your notification message'
);
```

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Emails not arriving | Check error.log; may need SMTP configuration on some hosts |
| "Invalid reset link" | Token expired (1 hour limit) - request new token |
| "Reset link already used" | Token was already used - request new token |
| Password reset fails | Check password meets requirements (8+ chars, uppercase, lowercase, number) |

## Deployment Checklist

- [ ] Run migration SQL on hosted database
- [ ] Test forgot password flow 
- [ ] Test welcome email on registration
- [ ] Check error.log for mail errors
- [ ] Verify SMTP/mail configuration if needed
- [ ] Test on multiple email providers

---

**Note:** All email timestamps, tokens, and activities are logged for security auditing.
