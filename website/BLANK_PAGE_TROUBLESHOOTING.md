# Blank Page Troubleshooting Guide

## 🔴 All Pages Are Blank

When you visit the hosted website and see a completely blank page, it usually means PHP is executing but all errors are being hidden. This guide will help you find and fix the issue.

---

## 🎯 Step 1: Identify the Real Error (CRITICAL)

### Option A: Use the Diagnostic Tool (Recommended)
```
Visit: https://your-domain.com/diagnostic.php
```
This will show you:
- What files are missing/readable
- If database config loads
- If database connection works
- What the actual error is (not just blank page)

**⬇️ If diagnostic shows errors, scroll to "Common Issues Below" based on what it shows**

---

### Option B: Check Browser Console
1. Open website in browser
2. Press `F12` (Developer Tools)
3. Go to **Console** tab
4. Look for red error messages
5. Screenshot the error

---

### Option C: Check Server Error Log
If hosting provides error logs:
1. Log into hosting control panel (cPanel, etc.)
2. Look for "Error Logs" or "Raw Access Logs"
3. Download and review the errors
4. Search for the most recent errors

---

## 🛠️ Common Issues & Fixes

### Issue #1: "Fatal error: Call to undefined function url()"

**Cause:** `includes/functions.php` is not being included

**Fix:**
Check `index.php` - it MUST have both these lines:
```php
<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';  // ← This line must exist
header('Location: ' . url('login.php'));
exit;
```

**If missing:** Add the line and re-upload.

---

### Issue #2: "Fatal error: Class 'Database' not found"

**Cause:** `config/database.php` is not being included

**Fix:**
1. Ensure `config/database.php` exists on hosting
2. Check that Database class is defined in it:
```php
class Database {
    // ... should exist
}
```

**If missing:** Re-upload the file.

---

### Issue #3: "SQLSTATE[42000]: Syntax error or access violation"

**Cause:** SQL query has errors (old SHOW TABLES LIKE issue)

**Fix:**
1. Update both files:
   - `debug.php`
   - `setup.php`

2. Make sure they use `INFORMATION_SCHEMA`:
```php
// ✓ CORRECT
"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"

// ✗ WRONG
"SHOW TABLES LIKE ?"
```

**If wrong:** Download latest files and re-upload.

---

### Issue #4: "Connection refused" or "Cannot connect to database"

**Cause:** Database credentials are wrong or database doesn't exist

**Fix:**
1. Check `config/database.php` has correct values:
```php
define('DB_HOST', 'your-actual-host');     // NOT localhost!
define('DB_NAME', 'your-actual-db-name');
define('DB_USER', 'your-actual-user');
define('DB_PASS', 'your-actual-password');
```

2. Get correct values from hosting control panel:
   - cPanel → Databases → MySQL
   - Or contact hosting support

3. Update the file with **exact** values
4. Re-upload

---

### Issue #5: "Undefined constant 'DB_HOST'"

**Cause:** `config/database.php` is not being included properly

**Fix:**
1. Ensure line in relevant file has:
```php
require_once __DIR__ . '/config/database.php';
```

2. Check the path is correct:
   - `__DIR__` should point to the root directory
   - `config/database.php` should exist there

**If still failing:** Check if file paths are case-sensitive on hosting (Linux is case-sensitive!) - ensure `config/database.php` matches exactly.

---

### Issue #6: "File not found" or "No such file or directory"

**Cause:** Files weren't uploaded completely or to wrong location

**Fix:**
1. Delete the existing installation
2. Download latest code completely
3. Upload **all** files to hosting:
   ```
   /
   ├── index.php
   ├── login.php
   ├── register.php
   ├── dashboard.php
   ├── ... (all other .php files)
   ├── config/
   │   └── database.php
   ├── includes/
   │   ├── functions.php
   │   ├── header.php
   │   ├── footer.php
   │   ├── auth.php
   │   └── custom_fields_helper.php
   ├── assets/
   │   ├── css/
   │   │   ├── style.css
   │   │   └── responsive.css
   │   └── js/
   │       └── app.js
   ├── sql/
   │   ├── schema.sql
   │   └── new_tables.sql
   ├── uploads/       (create if doesn't exist)
   └── ... (all other files)
   ```

4. Verify via FTP: All files should be there

---

### Issue #7: "Headers already sent" Error

**Cause:** HTML output before PHP `header()` call

**Fix:**
1. Check no `echo` or HTML before:
```php
<?php  // ← File must start here
// NO HTML BEFORE THIS

require_once '...';
header('Location: ...');  // ← This must work
```

2. Ensure no spaces before `<?php`
3. Ensure no content after `?>` at end of files

---

### Issue #8: Blank Page on Login (No Error)

**Cause:** Likely database tables don't exist

**Fix:**
1. Visit: `https://your-domain.com/setup.php`
2. Click "Create Missing Tables"
3. Should show:
   - ✓ users table
   - ✓ personal_details table
   - ✓ bank_details table
   - ✓ ... (all tables)

4. If any are still marked ✗ (missing), check permissions

---

## 📋 Complete Troubleshooting Checklist

Follow this step-by-step if pages are blank:

### Step 1: Server Diagnostics
- [ ] Visit `/diagnostic.php` → All tests pass? → Go to Step 2
- [ ] Some tests fail? → Note the errors → Fix them → Try again
- [ ] Getting blank page from diagnostic.php itself? → Go to "Advanced Debugging" below

### Step 2: Database Setup
- [ ] Visit `/setup.php` → See all tables listed
- [ ] All tables show ✓? → Go to Step 3
- [ ] Some tables show ✗? → Click "Create Missing Tables" → Wait for completion
- [ ] Still fails? → Check database credentials in config/database.php

### Step 3: Bootstrap Test
- [ ] Visit `/test-startup.php` → All tests pass? → Go to Step 4
- [ ] Some tests fail? → Read error messages → Fix issues → Try again

### Step 4: Application Test
- [ ] Visit `/` (root domain) → Redirects to login.php? ✓ WORKING
- [ ] See login page? → Try register new account
- [ ] Can login? → Dashboard shows? → ALL WORKING ✓

### Step 5: If Still Blank
- [ ] Check browser console (F12 → Console) for JavaScript errors
- [ ] Clear browser cache (Ctrl+Shift+Delete or Cmd+Shift+Delete)
- [ ] Try private/incognito window
- [ ] Check hosting error logs
- [ ] Contact hosting support with screenshots

---

## 🔧 Advanced Debugging

### Enable Detailed Error Output

Create a file called `error-debug.php`:
```php
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/errors.log');

// Try to load the application
try {
    require_once __DIR__ . '/index.php';
} catch (Exception $e) {
    echo "ERROR CAUGHT: " . $e->getMessage();
    echo "<pre>";
    echo $e->getTraceAsString();
    echo "</pre>";
}
?>
```

Visit: `https://your-domain.com/error-debug.php`

This will show you the exact error with full details.

---

### Check PHP Error Log

After accessing the site, check if an `errors.log` file was created:
```
https://your-domain.com/errors.log
```

This contains all PHP errors.

---

## 📞 If Still Stuck

### Information to Gather:
1. Screenshot of `/diagnostic.php` output
2. Error from browser console (F12)
3. Hosting control panel info:
   - Database host
   - Database name
   - Database user
   - PHP version
   - Available extensions

### Then:
1. Create a new GitHub issue with this information
2. Or email/message with the diagnostic output
3. Include exact steps to reproduce the blank page

---

## ✅ Success Indicators

You've solved the issue when:
- [ ] Visit domain → See login page (no errors)
- [ ] Can register new account
- [ ] Can login with account
- [ ] Dashboard displays all info
- [ ] Can click all menu items
- [ ] Can create/edit records
- [ ] No white/blank pages anywhere

---

## 🎯 Quick Reference

| Symptom | Likely Cause | Fix |
|---------|--------------|-----|
| Completely blank page | PHP error hidden | Enable error_log, check error_reporting |
| 500 error | Server error | Check /diagnostic.php and error logs |
| "undefined function url" | Missing include | Add `require_once includes/functions.php` |
| "Database connection failed" | Wrong credentials | Update DB_HOST, DB_NAME, DB_USER, DB_PASS |
| "Table doesn't exist" | Schema missing | Run /setup.php |
| Login fails silently | Auth issue | Check database tables exist |
| Layout broken (no CSS) | Asset paths wrong | Already fixed with BASE_PATH system |
| Menu not working (no JS) | Asset paths wrong | Already fixed with BASE_PATH system |

---

**Last Updated:** February 19, 2026
**Status:** All critical fixes applied
