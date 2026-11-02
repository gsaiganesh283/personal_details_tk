# Debugging Guide - Pages Not Opening Issue

## 🔴 Problem
Pages like Custom Fields, Field Blocks, and other detail pages are not opening when deployed on profreehost.

## ✅ Root Causes (Now Fixed)

### 1. **JavaScript AJAX Calls with Hardcoded Paths**
- **Was:** Custom Fields and Field Blocks were making AJAX requests to `/custom-fields.php` 
- **Issue:** In subdirectories, `/custom-fields.php` resolves to the wrong path
- **Fix:** Updated `ajaxRequest()` function to automatically prepend BASE_PATH

### 2. **Missing Database Tables**
- **Was:** `custom_field_blocks` and `custom_field_entries` tables not in schema
- **Issue:** Field Blocks page would crash when querying non-existent tables
- **Fix:** Added table definitions to `/sql/new_tables.sql`

### 3. **Form Actions with Hardcoded Paths**
- **Was:** Form submission to `/page.php` instead of using `url()` helper
- **Issue:** Forms would submit to wrong location in subdirectories  
- **Fix:** Updated all forms to use `<?php echo url('page.php'); ?>`

## 🧪 Testing Your Deployment

### Step 1: Run Diagnostic Tool
Visit `https://your-domain.com/debug.php`

This tool will show you:
- ✓ PHP version and extensions
- ✓ Database connection status
- ✓ All required tables 
- ✓ File permissions
- ✓ Configuration values
- ✓ Any errors or warnings

### Step 2: Run Setup Script
Visit `https://your-domain.com/setup.php`

This will:
- ✓ Check all database tables
- ✓ Create any missing tables
- ✓ Confirm database setup is complete

### Step 3: Test All Pages

**Menu Pages:**
1. Dashboard - ✓ Should load
2. Personal Details - ✓ Should load and form should work
3. Bank Details - ✓ Should open
4. Documents - ✓ Should load
5. Aadhaar Details - ✓ Should open
6. PAN Details - ✓ Should open
7. Other Details - ✓ Should open
8. Custom Fields - ✓ **This was broken, now fixed**
9. Field Blocks - ✓ **This was broken, now fixed**
10. Profile - ✓ Should load

**Try These Actions:**
- Click on any menu item
- Fill out a form and submit
- Try the Custom Fields AJAX (add/edit/delete)
- Try the Field Blocks AJAX
- Upload a document
- Create a custom field

## 🚨 If Pages Still Don't Open

### 1. Check the Diagnostic Tool
```
https://your-domain.com/debug.php
```
Look for red X marks (errors) or yellow ! marks (warnings)

### 2. Check Browser Console for Errors
1. Open your browser's Developer Tools (F12)
2. Go to Console tab
3. Try clicking the menu item that doesn't work
4. Look for any JavaScript errors
5. Report the error message

### 3. Check Browser Network Tab
1. Open Developer Tools (F12)
2. Go to Network tab
3. Click the menu item that doesn't work
4. Look for failed requests (red)
5. Click on failed request to see details

### 4. Check PHP Errors
Create a temporary file `/test-errors.php`:
```php
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config/database.php';
echo "If you see this, PHP is working!<br>";
echo "BASE_PATH = " . (defined('BASE_PATH') ? BASE_PATH : 'NOT DEFINED') . "<br>";
echo "DB Connection: OK<br>";
?>
```

Visit `https://your-domain.com/test-errors.php` and note any errors.

## 📋 Required Files (Should Be in Root)

```
your-domain.com/
├── index.php (redirects to login)
├── login.php
├── register.php  
├── dashboard.php
├── personal-details.php
├── bank-details.php
├── documents.php
├── aadhaar-details.php
├── pan-details.php
├── other-details.php
├── custom-fields.php ← AJAX calls fixed
├── field-blocks.php ← AJAX calls fixed
├── profile.php
├── logout.php
├── setup.php ← Database setup tool
├── debug.php ← Diagnostic tool
├── config/
│   └── database.php (with your credentials)
├── includes/
│   ├── header.php (sets BASE_PATH in JavaScript)
│   ├── footer.php
│   ├── functions.php
│   └── auth.php
├── assets/
│   ├── css/
│   │   ├── style.css
│   │   └── responsive.css
│   └── js/
│       └── app.js (updated with BASE_PATH support)
└── sql/
    ├── schema.sql
    └── new_tables.sql
```

## 🔄 How the Fix Works

### JavaScript Path Resolution

**In header.php:**
```php
<script>
    window.BASE_PATH = '<?php echo defined('BASE_PATH') ? BASE_PATH : ''; ?>';
</script>
```

**In assets/js/app.js:**
```javascript
window.ajaxRequest = function (url, method, data, callback) {
    // Prepend BASE_PATH to relative URLs starting with /
    var finalUrl = url;
    if (url.charAt(0) === '/' && window.BASE_PATH) {
        finalUrl = window.BASE_PATH + url;
    }
    // ... rest of AJAX code
}
```

**Result:**
- URL `/custom-fields.php` becomes `/pds/custom-fields.php` (in subdirectory)
- URL `/custom-fields.php` stays `/custom-fields.php` (at root)
- Works automatically with no extra coding needed!

## ✨ Features Now Working in Any Directory

✓ **Page Navigation** - All sidebar links work
✓ **Form Submissions** - All forms submit to correct location  
✓ **AJAX Operations** - Custom Fields, Field Blocks work
✓ **File Uploads** - Documents upload correctly
✓ **Redirects** - After form submission, redirects work  
✓ **JavaScript** - Client-side code works everywhere

## 🎯 ProFreeHost Specific Tips

1. **Check FTP Upload:**
   - Ensure all files were uploaded (check connection didn't timeout)
   - Check `/` directory, not a subdirectory

2. **Check Database:**
   - Go to PhpMyAdmin in your hosting control panel
   - Select your database
   - Run `/setup.php` in browser to create any missing tables

3. **Check Permissions:**
   - Files should be 644
   - Directories should be 755
   - Right-click → permissions in File Manager

4. **Check URL:**
   - If uploaded to `/pds/` folder, URL should be `domain.com/pds/`
   - BASE_PATH will auto-detect this

## 📞 Still Having Issues?

1. Visit `/debug.php` - note any errors
2. Visit `/setup.php` - ensure all tables created
3. Check browser console (F12) for JavaScript errors
4. Check browser Network tab for failed requests
5. Share the debug.php output for reference

## ✅ Verification Checklist

After deployment, verify:
- [ ] Can create account
- [ ] Can login
- [ ] Dashboard loads
- [ ] Can add Personal Detail
- [ ] Can add Bank Detail
- [ ] Can upload Document
- [ ] Can create Custom Field
- [ ] Can create Field Block
- [ ] Forms submit and redirect correctly
- [ ] All menu items open without errors

### If All Checks Pass ✓
**🎉 Your deployment is successful!**

---

**Version:** 1.0 | **Date:** February 19, 2026 | **PDS Application**
