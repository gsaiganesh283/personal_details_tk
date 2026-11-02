# Deployment Checklist - Blank Page Prevention

## Before You Deploy

- [ ] Database credentials from hosting control panel saved
- [ ] `.htaccess` file included for URL rewriting (if needed)
- [ ] All PHP files have correct file permissions (644 or 777)
- [ ] Uploads directory (uploads/) has write permissions
- [ ] Database has been set up or `setup.php` will be run after deployment

## Deploy These Files

### Core Application Files
- [ ] index.php
- [ ] login.php
- [ ] register.php
- [ ] logout.php
- [ ] dashboard.php

### Detail Pages
- [ ] personal-details.php
- [ ] bank-details.php
- [ ] documents.php
- [ ] aadhaar-details.php
- [ ] pan-details.php
- [ ] other-details.php

### Special Pages
- [ ] custom-fields.php
- [ ] field-blocks.php
- [ ] profile.php
- [ ] setup.php (for database setup)

### Configuration Files
- [ ] config/database.php (UPDATE with YOUR database credentials)

### Include Files
- [ ] includes/header.php
- [ ] includes/footer.php
- [ ] includes/auth.php
- [ ] includes/functions.php
- [ ] includes/custom_fields_helper.php
- [ ] includes/error-handler.php (NEW - for error catching)

### CSS & JavaScript
- [ ] assets/css/style.css
- [ ] assets/css/responsive.css
- [ ] assets/js/app.js

### SQL Files
- [ ] sql/schema.sql
- [ ] sql/new_tables.sql

### New Diagnostic Tools (Important for troubleshooting)
- [ ] health-check.php (NEW - quick health check)
- [ ] blank-page-fixer.php (NEW - detailed diagnostic)
- [ ] diagnostic.php (for detailed debugging)
- [ ] setup.php (for database table creation)

### Documentation
- [ ] README.md
- [ ] BLANK_PAGE_FIX_GUIDE.md (NEW)
- [ ] BLANK_PAGE_FIX_SUMMARY.md (NEW)

### Create These Directories
- [ ] uploads/ (with write permissions)
- [ ] uploads/documents/
- [ ] uploads/custom_fields/

## Deployment Steps

1. **Update Database Credentials**
   ```
   Edit config/database.php with your hosting credentials
   ```

2. **Upload All Files**
   - Use FTP or your hosting control panel
   - Ensure all files are uploaded correctly
   - Check for any 404 errors

3. **Create Directories**
   - Create `uploads` directory
   - Create `uploads/documents` subdirectory
   - Create `uploads/custom_fields` subdirectory
   - Set permissions to 755 or 777

4. **Set Up Database**
   - Option A (Automatic): Visit `setup.php` in browser
   - Option B (Manual): Import `sql/schema.sql` in PhpMyAdmin

5. **Test the Deployment**
   ```
   Visit: your-domain.com/health-check.php
   ```
   Should show all green checks

6. **Create Test Account**
   ```
   Visit: your-domain.com/login.php
   Click: Register
   Create test account
   ```

7. **Test All Pages**
   - Login with test account
   - Click each menu item
   - All should display content without blank pages

## If You See Blank Pages

1. **First**: Visit `health-check.php`
2. **Second**: Read what it says
3. **Third**: Add `?_debug=1` to the blank page URL
4. **Fourth**: Check `error.log` file
5. **Last**: Follow BLANK_PAGE_FIX_GUIDE.md

## Critical Files That Cause Blank Pages

These files MUST be present and correct:

| File | If Missing | If Wrong |
|------|-----------|----------|
| config/database.php | Pages won't load | Database errors |
| includes/header.php | Layout missing | HTML broken |
| includes/footer.php | Page incomplete | Unclosed tags |
| includes/auth.php | Auth fails | Users can't login |
| includes/functions.php | url() undefined | Navigation broken |
| assets/css/style.css | Unstyled page | Looks blank (no styling) |
| assets/js/app.js | JS features fail | Sidebar toggle broken |

## File Permissions

```bash
config/
  database.php          644 (readable by web server)

includes/
  *.php                 644 (readable by web server)

uploads/               755 (writable by web server)
uploads/documents/     755 (writable by web server)
uploads/custom_fields/ 755 (writable by web server)

Other .php files       644 (readable)
```

## Common Mistakes

❌ **Don't do this:**
- Upload files without updating database credentials
- Skip running setup.php without creating tables
- Forget to create uploads directory
- Upload only some files and assume it works
- Upload to wrong directory on hosting
- Change file permissions on critical files

✅ **Do this instead:**
- Update credentials in config/database.php BEFORE uploading
- Run setup.php to ensure tables exist
- Create all required directories with correct permissions
- Upload ALL files in the project
- Upload to root directory (web root)
- Leave file permissions at default (644 for files, 755 for dirs)

## Quick Test Commands (After Deployment)

Visit these in your browser to test:

```
https://your-domain.com/health-check.php
https://your-domain.com/login.php
https://your-domain.com/blank-page-fixer.php
https://your-domain.com/diagnostic.php
```

## Success Indicators

✓ health-check.php shows all green checks  
✓ You can create a login account  
✓ You can log in  
✓ Dashboard loads with content (not blank)  
✓ All menu items show content  
✓ No 404 errors in browser console  
✓ error.log file is empty or has old entries  

## Troubleshooting Quick Links

- Database connection error → Check config/database.php credentials
- Table doesn't exist → Run setup.php
- Functions not found → Check includes files uploaded
- 404 errors → Check base path configuration
- Styling looks broken → Check CSS file paths
- Sidebar doesn't work → Check JavaScript loaded

## Final Check

Before considering deployment complete:

- [ ] Can log in successfully
- [ ] Personal Details page displays content (not blank)
- [ ] Bank Details page displays content
- [ ] Documents page displays content
- [ ] All other pages display content
- [ ] No "Fatal error" messages anywhere
- [ ] Sidebar navigation works on all pages
- [ ] error.log doesn't grow with new errors

## You're Done! 🎉

If all checks pass, your deployment is successful and blank pages should be fixed!

For any remaining issues, use the diagnostic tools and error logs to identify the specific problem.
