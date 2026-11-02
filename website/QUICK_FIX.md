# ⚡ QUICK FIX - Blank Pages on Hosting

## The Problem
Pages show blank when hosted, even though sidebar shows. (Database/PHP errors hidden on hosting)

## The Solution in 5 Minutes

### 1. Upload 2 New Files
```
✓ includes/error-handler.php
✓ health-check.php
✓ blank-page-fixer.php
```

### 2. Update Database Info in config/database.php
```php
// Get these from your hosting control panel
define('DB_HOST', 'YOUR.HOST.HERE');
define('DB_NAME', 'YOUR_DATABASE_NAME');
define('DB_USER', 'YOUR_USERNAME');
define('DB_PASS', 'YOUR_PASSWORD');
```

### 3. Check Health
Visit: `https://your-domain.com/health-check.php`

### 4. Create Tables (if needed)
Visit: `https://your-domain.com/setup.php`

### 5. Test
Login and navigate → All pages should show content

---

## If Still Blank

### Method 1: See the Error
```
Add ?_debug=1 to page:
https://your-domain.com/personal-details.php?_debug=1
```

### Method 2: Check Error Log
```
Download error.log file from your hosting root directory
```

### Method 3: Detailed Diagnosis
```
Visit: https://your-domain.com/blank-page-fixer.php
```

---

## Most Common Causes

| Issue | Fix |
|-------|-----|
| **Database won't connect** | Update credentials in config/database.php |
| **Table doesn't exist** | Run setup.php to create tables |
| **File not found error** | Re-upload the missing file |
| **Function not defined** | Upload includes/ folder again |

---

## Files You MUST Have

```
┌─ config/database.php (UPDATE credentials)
├─ includes/
│  ├─ header.php
│  ├─ footer.php
│  ├─ auth.php
│  ├─ functions.php
│  ├─ custom_fields_helper.php
│  ├─ error-handler.php (NEW)
├─ assets/
│  ├─ css/style.css
│  ├─ js/app.js
├─ *.php (all page files)
├─ health-check.php (NEW)
├─ blank-page-fixer.php (NEW)
└─ uploads/ (directory)
```

---

## Testing Tools

| Tool | URL | Purpose |
|------|-----|---------|
| **Health Check** | `/health-check.php` | Quick status |
| **Blank Page Fixer** | `/blank-page-fixer.php` | Detailed diagnosis |
| **Debug Mode** | `/page.php?_debug=1` | See errors on any page |
| **Setup** | `/setup.php` | Create missing tables |

---

## Success Checklist

- [ ] health-check.php shows all ✓
- [ ] Can create login account
- [ ] Can log in
- [ ] Dashboard shows content (NOT blank)
- [ ] All menu pages show content
- [ ] No error messages

✅ **You're done!**

---

## Emergency Help

### Page shows blank with sidebar visible
→ Database issue or PHP error  
→ Run health-check.php to see what's wrong  
→ Most likely: Need to run setup.php

### Get "access denied" for database
→ Credentials in config/database.php are wrong  
→ Get correct ones from hosting control panel  
→ Update and re-upload file

### Table doesn't exist errors
→ Run setup.php to create tables  
→ Or manually import sql/schema.sql in PhpMyAdmin

### 404 File not found errors
→ File wasn't uploaded  
→ Use FTP to verify file exists  
→ Check file name is spelled correctly (Linux is case-sensitive!)

---

## No More Blank Pages!

The error handling system catches all PHP errors and:
- Logs them to `error.log` 
- Shows them when you add `?_debug=1`
- Makes them visible in health-check.php

So you'll always know what went wrong instead of seeing a blank page!

---

**Next Step:** Visit `health-check.php` after deployment
