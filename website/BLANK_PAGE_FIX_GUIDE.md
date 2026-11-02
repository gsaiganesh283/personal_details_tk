# Fixing Blank Pages When Hosted

## Problem
When you deploy the Personal Details Saver to a hosting provider like Ezyro or ProfreeHost, some or all pages appear completely blank, even though the navigation sidebar shows.

## Root Causes

### 1. **Errors Happening Silently**
   - Hosting servers suppress error display by default
   - PHP errors occur but aren't shown to users
   - The page starts rendering (showing sidebar) but fails partway through
   - Result: Blank page with a visible header/sidebar

### 2. **Database Connection Failures**
   - Incorrect database credentials
   - Database doesn't exist
   - Database tables are missing

### 3. **Missing or Incorrect Include Paths**
   - Files weren't uploaded completely
   - File paths are case-sensitive on Linux servers

### 4. **Custom Field Functions Missing**
   - `custom_fields_helper.php` not loaded
   - Database tables for custom fields missing

## How to Fix

### Step 1: Check Error Log (BEST METHOD)

1. Visit your `blank-page-fixer.php` file in browser:
   ```
   https://your-domain.com/blank-page-fixer.php
   ```

2. This will show you:
   - Whether configuration loads correctly
   - Database connection status
   - Missing files
   - Table issues

3. Note any errors and jump to the appropriate section below

### Step 2: Enable Error Viewing for Debugging

Add `?_debug=1` to any page URL to see errors:
```
https://your-domain.com/personal-details.php?_debug=1
```

This will display any PHP errors that occurred.

**⚠️ Warning:** Only use this for debugging. Remove `?_debug=1` after fixing issues.

### Step 3: Check Error Log File

Connect via FTP and check the `error.log` file in your root directory:
1. Open FTP client
2. Navigate to your root directory
3. Look for `error.log` file
4. Download and open it
5. Look for recent entries

### Step 4: Verify Database Configuration

Edit `config/database.php` and ensure these credentials match your hosting:
```php
define('DB_HOST', 'your-actual-host');     // From hosting control panel
define('DB_NAME', 'your-actual-db-name');
define('DB_USER', 'your-actual-user');
define('DB_PASS', 'your-actual-password');
```

To find your database credentials:
1. Log into your hosting control panel (cPanel, etc.)
2. Go to "Databases" or "MySQL"
3. Copy the exact host, database name, username, and password
4. Update `config/database.php`
5. Re-upload the file

### Step 5: Create Missing Tables

If the error log shows missing tables:

1. Visit `setup.php` in your browser:
   ```
   https://your-domain.com/setup.php
   ```

2. It will attempt to create missing tables automatically

3. If that doesn't work, manually run the SQL:
   - Download file `sql/schema.sql`
   - In cPanel → PhpMyAdmin
   - Select your database
   - Go to "Import" tab
   - Upload `sql/schema.sql`
   - Click "Go"

### Step 6: Verify File Uploads

Ensure all these files are on your hosting:
```
✓ index.php
✓ login.php
✓ register.php
✓ dashboard.php
✓ personal-details.php
✓ bank-details.php
✓ documents.php
✓ aadhaar-details.php
✓ pan-details.php
✓ other-details.php
✓ custom-fields.php
✓ field-blocks.php
✓ profile.php
✓ logout.php

✓ config/database.php
✓ includes/header.php
✓ includes/footer.php
✓ includes/auth.php
✓ includes/functions.php
✓ includes/custom_fields_helper.php

✓ assets/css/style.css
✓ assets/css/responsive.css
✓ assets/js/app.js

✓ sql/schema.sql
✓ sql/new_tables.sql
```

If any files are missing, download them from the repository and re-upload.

## Common Issues & Solutions

### Issue: "class 'Database' not found"
**Solution:** Ensure `config/database.php` exists and is properly uploaded

### Issue: "Call to undefined function url()"
**Solution:** Ensure `includes/functions.php` is uploaded

### Issue: "SQLSTATE[HY000] [1045] Access denied"
**Solution:** Check database credentials in `config/database.php`

### Issue: "Table doesn't exist" errors
**Solution:** Run `setup.php` or manually import `sql/schema.sql`

### Issue: "Call to undefined function renderCustomFields()"
**Solution:** Ensure `includes/custom_fields_helper.php` is uploaded

## Testing After Fix

1. Visit `https://your-domain.com/login.php`
2. Register a new account
3. Log in
4. Navigate to each menu item
5. All pages should display content (not blank)

## Additional Debug Tools

Several diagnostic tools are included:

- `blank-page-fixer.php` - Main diagnostic tool
- `diagnostic.php` - Detailed system check
- `page-loading-diagnostic.php` - Step-by-step page loading test
- `error.log` - Error log file (created automatically)

## Getting Help

If you still see blank pages:

1. Run `blank-page-fixer.php`
2. Check the error log at `error.log`
3. Visit a page with `?_debug=1` to see live errors
4. Screenshot any error messages
5. Contact hosting support with the error details

## Notes

- Error logs are automatically created and updated
- Debug mode (`?_debug=1`) is only available when accessed locally or from approved IPs
- Always remove `?_debug=1` from URLs before going live
- Check error logs regularly to catch issues early
