# 🔧 Diagnostic Tools - Quick Reference

## Overview

You now have **5 professional diagnostic tools** to troubleshoot any issues. Use them in this order:

---

## 📋 Tools in Order of Use

### 1️⃣ **error-display.php** (START HERE)
**When to use:** Application shows blank page

**What it does:**
- Shows all PHP errors (even hidden ones)
- Attempts to load the application step-by-step
- Shows where it fails
- Displays error log contents

**Access:** `https://your-domain.com/error-display.php`

**Example output:**
```
Loading config... ✓
Loading functions... ✓
Loading auth... ✓
Connecting to database... ✗ ERROR: ...
```

---

### 2️⃣ **diagnostic.php** (DETAILED DIAGNOSIS)
**When to use:** After seeing error-display, need more detailed info

**What it does:**
- Shows server environment info
- Checks all critical files exist
- Tests application bootstrap step-by-step
- Shows PHP extensions and limits
- Provides helpful next steps

**Access:** `https://your-domain.com/diagnostic.php`

**Shows:**
- Server info (OS, PHP version, memory limits)
- File system status (which files missing)
- Bootstrap test results
- Database connection status
- Available PHP extensions

---

### 3️⃣ **debug.php** (SYSTEM STATUS)
**When to use:** Need to check database and tables

**What it does:**
- Full system diagnostic
- Shows database configuration
- Lists which tables exist/missing
- Checks file permissions
- Shows PHP version and extensions

**Access:** `https://your-domain.com/debug.php`

**Shows:**
- PHP configuration ✓
- Database connection ✓
- Which tables exist ✓
- File system status ✓

---

### 4️⃣ **setup.php** (CREATE DATABASE)
**When to use:** Database tables are missing

**What it does:**
- Checks all 13 required tables
- Creates missing tables
- Shows status of each table
- Provides SQL for manual creation if needed

**Access:** `https://your-domain.com/setup.php`

**Options:**
- View tables status
- Click to create missing tables
- Download SQL script

---

### 5️⃣ **test-startup.php** (BOOTSTRAP TEST)
**When to use:** Need to verify application can start

**What it does:**
- Tests database config loads
- Tests all includes load
- Tests functions available
- Tests database connection
- Tests url() helper function

**Access:** `https://your-domain.com/test-startup.php`

**Tests:**
- Database config: ✓/✗
- Functions include: ✓/✗
- Auth include: ✓/✗
- Database connection: ✓/✗
- url() function: ✓/✗

---

## 🎯 Troubleshooting Flow

```
Start
  ↓
[1] Visit /error-display.php
  ├─ Loads successfully? → Done! Try /login.php
  └─ Shows error? ↓
    [2] Visit /diagnostic.php
      ├─ All tests pass? → Database issue (go to step 4)
      └─ Bootstrap fails? → Fix error shown ↓
        [3] Fix the error
           • Add missing file
           • Update credentials
           • Fix permissions
           • Re-upload
        [2] Re-test /diagnostic.php (repeat until pass)
    [4] Visit /setup.php
      ├─ All tables exist? → Try /login.php
      └─ Tables missing? → Click "Create Missing Tables"
    [5] Re-test /diagnostic.php
    [6] Try /login.php
```

---

## 🚨 Common Error Messages & Solutions

### Error: "Call to undefined function url()"
→ **Fix:** Missing `includes/functions.php` include
```php
// Add this to the file:
require_once __DIR__ . '/includes/functions.php';
```

### Error: "Class 'Database' not found"
→ **Fix:** Missing `config/database.php` include
```php
// Add this to the file:
require_once __DIR__ . '/config/database.php';
```

### Error: "SQLSTATE[42000]: Syntax error"
→ **Fix:** SQL query uses old SHOW TABLES syntax
- Update `debug.php` and `setup.php` with latest versions

### Error: "Cannot connect to database"
→ **Fix:** Wrong database credentials
- Check PMA admin settings at hosting
- Update `config/database.php`
- Verify DB_HOST, DB_NAME, DB_USER, DB_PASS

### Error: "File not found"
→ **Fix:** File not uploaded or path is wrong
- Check all files uploaded via FTP
- Verify directory structure matches

---

## 📊 What Each Tool Can Detect

| Issue | error-display | diagnostic | debug | setup | test-startup |
|-------|---|---|---|---|---|
| PHP syntax error | ✓ | ✓ | - | - | - |
| Missing include file | ✓ | ✓ | - | - | ✓ |
| Database connection fail | ✓ | ✓ | ✓ | ✓ | ✓ |
| Wrong credentials | ✓ | ✓ | ✓ | ✓ | ✓ |
| Missing tables | - | - | ✓ | ✓ | - |
| File permissions | - | ✓ | ✓ | - | - |
| PHP version issue | - | ✓ | ✓ | - | - |
| Missing extension | - | ✓ | ✓ | - | - |
| url() function works | - | ✓ | - | - | ✓ |
| Bootstrap loads | ✓ | ✓ | - | - | ✓ |

---

## ✅ Success Checklist

Walk through this to verify everything works:

- [ ] Visit `/error-display.php` → Shows "Application loaded successfully"
- [ ] Visit `/diagnostic.php` → All tests pass with green checkmarks
- [ ] Visit `/debug.php` → Database connected, all tables exist
- [ ] Visit `/setup.php` → All table statuses show ✓
- [ ] Visit `/test-startup.php` → All tests pass (5/5)
- [ ] Visit `/` (root) → Redirects to login.php automatically
- [ ] Visit `/login.php` → See login form
- [ ] Register new account → Works
- [ ] Login → Redirects to dashboard
- [ ] Visit all menu items → All open without errors
- [ ] Create/edit records → All work correctly
- [ ] Upload files → Works
- [ ] Custom fields → AJAX works correctly

---

## 🔒 Security Cleanup

**After you verify everything works, delete these diagnostic files:**

- `error-display.php` - Shows sensitive errors
- `diagnostic.php` - Shows server info
- `debug.php` - Shows database info
- `test-startup.php` - Shows config details

**Keep for production only if needed for ongoing monitoring:**
- `setup.php` - Can be kept to help create new tables if needed
- `php-errors.log` - Delete after resolving issues

---

## 📞 Getting Help

If you still see blank pages after using all tools:

1. **Gather this information:**
   - Screenshot of `/error-display.php`
   - Screenshot of `/diagnostic.php`
   - What error you're seeing

2. **Check common hosting issues:**
   - Database exists at hosting?
   - Database user created?
   - User has SELECT, INSERT, UPDATE, DELETE permissions?
   - PHP version 7.2+?
   - PDO extension enabled?

3. **Contact hosting support with:**
   - Database host name
   - Your database name
   - Username you're using
   - "Can you verify my database is set up correctly?"
   - "Can you confirm PDO and JSON extensions are enabled?"

---

## 📚 Additional Resources

- [BLANK_PAGE_TROUBLESHOOTING.md](BLANK_PAGE_TROUBLESHOOTING.md) - Detailed guide
- [APPLICATION_NOT_OPENING_FIX.md](APPLICATION_NOT_OPENING_FIX.md) - Quick fixes
- [DEBUGGING_GUIDE.md](DEBUGGING_GUIDE.md) - General debugging tips

---

**Last Updated:** February 19, 2026
**Status:** All diagnostic tools ready for use
