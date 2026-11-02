# Application Not Opening - Quick Fix Guide

## 🔴 Critical Issue Found & Fixed

**Problem:** `index.php` was trying to use the `url()` function before including the file that defines it.

**Error Result:** "Fatal error: Call to undefined function url()"

**✅ Solution:** Updated `index.php` to include `functions.php`

```php
// BEFORE (Broken)
require_once __DIR__ . '/config/database.php';
header('Location: ' . url('login.php'));  // ❌ url() not defined!

// AFTER (Fixed)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';  // ✅ Add this
header('Location: ' . url('login.php'));  // ✅ Now url() is defined
```

---

## 🚀 Quick Start After Update

### Option 1: Test Locally
```bash
# Start local PHP server
php -S localhost:8000

# Open in browser
http://localhost:8000
```

### Option 2: Upload to ProFreeHost
1. Download the latest code
2. Upload all files to your hosting
3. Update `config/database.php` with your host credentials
4. Visit these diagnostic pages:
   - `https://your-domain.com/test-startup.php` - Check if app loads
   - `https://your-domain.com/debug.php` - Full diagnostic
   - `https://your-domain.com/setup.php` - Create database tables

---

## 🧪 Diagnostic Tools

### 1. **test-startup.php** (NEW - Recommended)
Tests if the application can start:
- ✓ Database config loads
- ✓ Functions available
- ✓ Database connection works
- ✓ url() helper works

**Visit:** `/test-startup.php`

### 2. **debug.php**
Comprehensive system check:
- PHP version and extensions
- Database status
- Table existence
- File permissions

**Visit:** `/debug.php`

### 3. **setup.php**
Creates missing database tables:
- Check which tables exist
- Create missing tables
- View database schema

**Visit:** `/setup.php`

---

## 📋 Troubleshooting Steps

### If Application Still Doesn't Open:

**Step 1: Check the startup test**
```
Visit: https://your-domain.com/test-startup.php
```
- ✓ All tests pass? → See Step 2
- ✗ Some tests fail? → Fix the failures listed

**Step 2: Check database connection**
```
Visit: https://your-domain.com/debug.php
```
- Shows "✓ Database connection successful"? → Go to Step 3
- Shows "✗ Database connection failed"? → Update credentials in `config/database.php`

**Step 3: Create database tables**
```
Visit: https://your-domain.com/setup.php
```
- Click "Create Missing Tables"
- Should show all 13 tables created

**Step 4: Test the application**
```
Visit: https://your-domain.com/
```
Should redirect to login page automatically

---

## 🔑 Key Files Modified

| File | Change | Reason |
|------|--------|--------|
| `index.php` | Added `includes/functions.php` | Was missing function definition |
| `debug.php` | Fixed SQL query | Changed from `SHOW TABLES LIKE ?` to `INFORMATION_SCHEMA` |
| `setup.php` | Fixed SQL query | Changed from `SHOW TABLES LIKE ?` to `INFORMATION_SCHEMA` |

---

## 🛠️ Common Issues & Fixes

### Issue: "Call to undefined function url()"
**Solution:** Ensure `includes/functions.php` is included before using `url()`

### Issue: "Database connection failed"
**Solution:** Check credentials in `config/database.php`
- `DB_HOST`: Usually `localhost` or your host's database server
- `DB_NAME`: Database name created on your hosting
- `DB_USER`: Database username
- `DB_PASS`: Database password

### Issue: "Table doesn't exist"
**Solution:** Run `/setup.php` to create all required tables

### Issue: Still blank page
1. Check browser console (F12 → Console tab)
2. Look for JavaScript errors
3. Visit `/test-startup.php` to check bootstrap
4. Visit `/debug.php` for full diagnostic

---

## 📊 File Structure Check

The application requires these directories:
```
/
├── config/
│   └── database.php        (Database credentials)
├── includes/
│   ├── functions.php       (Helper functions)
│   ├── header.php          (HTML header & nav)
│   ├── footer.php          (HTML footer)
│   ├── auth.php            (Authentication)
│   └── custom_fields_helper.php
├── assets/
│   ├── css/
│   │   ├── style.css
│   │   └── responsive.css
│   └── js/
│       └── app.js
├── uploads/                (For file uploads)
├── sql/
│   ├── schema.sql
│   └── new_tables.sql
└── *.php files             (All page files)
```

All these must exist! If any are missing, the application won't work.

---

## ✅ Verification Checklist

- [ ] Downloaded latest code with fixes
- [ ] Uploaded all files to hosting
- [ ] All directories exist (config, includes, assets, uploads, sql)
- [ ] Updated `config/database.php` with credentials
- [ ] Visited `/test-startup.php` - All tests pass ✓
- [ ] Visited `/debug.php` - Database connected ✓
- [ ] Visited `/setup.php` - Tables created ✓
- [ ] Can access login page at website root
- [ ] Can register new account
- [ ] Can login and access dashboard
- [ ] All menu items open properly
- [ ] Can create/edit personal details

---

## 🎯 What Should Work Now

✓ Application loads on first visit (redirects to login)
✓ Login page displays
✓ Registration works
✓ Dashboard displays after login
✓ All menu items open (Personal Details, Bank Details, etc.)
✓ Can create, edit, and delete records
✓ Custom fields work with AJAX
✓ File uploads work
✓ Works in root: `/`
✓ Works in subdirectories: `/pds/`, `/apps/pds/`, etc.

---

## 📞 Still Having Issues?

1. **Delete contents of `/uploads/` directory** - Clear any corrupted uploads
2. **Re-run `/setup.php`** - Recreate database tables
3. **Check `/test-startup.php`** - See exactly what's failing
4. **Review `/debug.php`** - Full system diagnostic

---

**Last Updated:** February 19, 2026
**Fix Applied:** index.php include order correction + SQL query syntax fix
