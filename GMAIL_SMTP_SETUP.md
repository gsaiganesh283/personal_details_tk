# Gmail SMTP Email Configuration Guide

## Overview

The application now supports sending emails via:
1. **PHP mail()** function (default, uses server's mail system)
2. **Gmail SMTP** (reliable, uses your Gmail account)
3. **Other SMTP servers** (Mailgun, SendGrid, etc.)

---

## Setup Gmail SMTP (Recommended)

### Step 1: Enable 2-Step Verification

1. Go to [myaccount.google.com/security](https://myaccount.google.com/security)
2. Click **2-Step Verification**
3. Follow the prompts to enable it

### Step 2: Create App Password

1. Go to [myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords)
2. Select **Mail** and **Windows Computer** (or your device type)
3. Click **Generate**
4. Google will show a 16-character password like: `abcd efgh ijkl mnop`
5. **Copy this password** (you'll need it next)

### Step 3: Configure Your Application

**Option A: Environment Variables (Best for Production)**

Add these to your hosting control panel's environment variables:

```
MAIL_SMTP_HOST=smtp.gmail.com
MAIL_SMTP_PORT=587
MAIL_SMTP_USER=gsaiganesh628@gmail.com
MAIL_SMTP_PASS=abcd efgh ijkl mnop
MAIL_FROM=gsaiganesh628@gmail.com
```

**On ezyro Hosting:**
1. Log in to cPanel
2. Go to **Environment Variables**
3. Add the variables above
4. Save

**Option B: Hardcode (Quick Testing Only)**

Edit `includes/mailer.php`:

```php
// Temporarily (for testing)
putenv('MAIL_SMTP_HOST=smtp.gmail.com');
putenv('MAIL_SMTP_PORT=587');
putenv('MAIL_SMTP_USER=gsaiganesh628@gmail.com');
putenv('MAIL_SMTP_PASS=abcd efgh ijkl mnop');
putenv('MAIL_FROM=gsaiganesh628@gmail.com');
```

### Step 4: Test Email

1. Create a test account or go to "Forgot Password"
2. Enter your email
3. Check your inbox for the reset email
4. Check `error.log` if email doesn't arrive

---

## Using ezyro's Built-in Mail (No Configuration Needed)

If you prefer **not** to use Gmail:

1. The app defaults to **PHP mail()** function
2. ezyro automatically handles outbound mail
3. Emails send from: `noreply@pds.liveblog365.com`

**No additional setup required!**

---

## Troubleshooting

### Email Not Sending

**Check 1: Error Log**
```bash
tail -50 /path/to/error.log
```

Look for SMTP or mail errors.

**Check 2: Gmail App Password**
- Verify the 16-character password is correct
- No spaces in the password when copying
- 2-Step Verification must be enabled

**Check 3: SMTP Settings**
```
Host:  smtp.gmail.com  ✓
Port:  587 (not 465)   ✓
Auth:  Required        ✓
TLS:   Required        ✓
```

**Check 4: Test Simple Script**
```php
<?php
$mailer = new Mailer();
$result = $mailer->sendPasswordReset(
    'your-email@gmail.com',
    'Test User',
    'test_token',
    'http://localhost:8000/test'
);
echo $result ? "Email sent!" : "Email failed!";
?>
```

### "Authentication failed"
- Wrong Gmail password / app password
- Ensure 2-Step Verification is ON
- Create a NEW app password (old ones expire)

### "Could not connect to SMTP"
- Firewall blocking port 587
- SMTP host address is wrong
- Network issue

### Emails ending up in Spam
- Add SPF/DKIM records (ask hosting provider)
- Use domain email instead: `noreply@yourdomain.com`
- Gmail adds trust automatically

---

## Email System Flow

```
User clicks "Forgot Password"
        ↓
forgot-password.php
        ↓
Mailer class checks: Is SMTP configured?
        ↓
YES                          NO
 ↓                            ↓
Send via Gmail SMTP    Send via PHP mail()
 ↓                            ↓
SMTP Connection              Server's mail system
+TLS Auth                     (ezyro mail)
Error handling               Automatic
```

---

## Sending Emails Programmatically

```php
require_once 'includes/mailer.php';

$mailer = new Mailer();

// Send password reset
$mailer->sendPasswordReset(
    'user@example.com',
    'John Doe',
    'token_value',
    'https://yourapp.com/reset-password.php?token=token_value'
);

// Send welcome email
$mailer->sendWelcome(
    'user@example.com',
    'John Doe',
    'johndoe'
);
```

---

## Environment Variables Summary

| Variable | Example | Required |
|----------|---------|----------|
| `MAIL_SMTP_HOST` | `smtp.gmail.com` | Only for SMTP |
| `MAIL_SMTP_PORT` | `587` | Only for SMTP |
| `MAIL_SMTP_USER` | `gsaiganesh628@gmail.com` | Only for SMTP |
| `MAIL_SMTP_PASS` | `abcd efgh ijkl mnop` | Only for SMTP |
| `MAIL_FROM` | `gsaiganesh628@gmail.com` | Optional |

**Leave empty to use PHP mail() (default)**

---

## Free Alternatives to Gmail

If you don't want to use Gmail:

| Service | Free Tier | Setup Difficulty |
|---------|-----------|-----------------|
| **ezyro Built-in** | Unlimited | None ✓ |
| **Mailgun** | 5,000/month | Medium |
| **SendGrid** | 100/day | Medium |
| **Zoho Mail** | Free domain email | Easy |

For this app, **ezyro's PHP mail()** is the simplest!

---

## Security Notes

⚠️ **Never commit SMTP password to git!**

- Keep passwords in environment variables
- Use `.gitignore` for any config files
- Regenerate app passwords if exposed
- Use OAuth scopes when possible

---

**Questions?** Check `error.log` for detailed error messages.
