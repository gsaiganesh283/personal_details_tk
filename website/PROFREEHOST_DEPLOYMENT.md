# Installation & Deployment Guide for Personal Details Saver

## Quick Start on ProFreeHost (or any shared hosting)

### Step 1: Upload Files

1. Extract the project files
2. Upload all files to your hosting account using FTP or File Manager
3. The structure should look like:
   ```
   your-domain.com/
   ├── config/
   ├── includes/
   ├── assets/
   ├── sql/
   ├── uploads/
   ├── dashboard.php
   ├── login.php
   ├── register.php
   ├── setup.php (NEW - for database setup)
   └── ... other pages
   ```

### Step 2: Create Database

1. **Via ProFreeHost Control Panel:**
   - Go to MySQL Databases section
   - Create a new database (e.g., `personal_details_db`)
   - Create database user with password
   - Give user all privileges on the database

2. **Note the credentials:**
   - Database name
   - Database user
   - Database password
   - Database host (usually `localhost`)

### Step 3: Update Database Configuration

1. Edit `config/database.php`
2. Update the online hosting section with your credentials:

```php
} else {
    // Online hosting (ProFreeHost or other)
    define('DB_HOST', 'your-database-host');      // Usually 'localhost'
    define('DB_NAME', 'your_database_name');
    define('DB_USER', 'your_db_user');
    define('DB_PASS', 'your_db_password');
    define('DB_SOCKET', '');                      // Leave empty for shared hosting
    define('APP_URL', 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
}
```

### Step 4: Create Database Tables

**Option A: Using Setup Script (Easiest)**
1. Visit: `https://your-domain.com/setup.php`
2. Review the database configuration
3. If tables are missing, click "Create Missing Tables"
4. Wait for confirmation message

**Option B: Manual Database Creation**
1. Go to PhpMyAdmin in your hosting control panel
2. Select your database
3. Click "SQL" tab
4. Copy and paste the contents of `/sql/schema.sql`
5. Execute the query
6. Repeat with `/sql/new_tables.sql`

### Step 5: Create Uploads Directory

The application needs write permissions for file uploads:

1. Create directory: `uploads/documents/` (if not auto-created)
2. Set directory permissions to `755` (via FTP or File Manager)
3. The application will create user-specific subdirectories automatically

### Step 6: Test the Installation

1. Visit: `https://your-domain.com/register.php`
2. Create a new account
3. Log in with your credentials
4. Test navigation through all menu pages:
   - ✓ Dashboard
   - ✓ Personal Details
   - ✓ Bank Details
   - ✓ Documents
   - ✓ Aadhaar Details
   - ✓ PAN Details
   - ✓ Other Details
   - ✓ Custom Fields
   - ✓ Field Blocks
   - ✓ Profile

### Common Issues & Solutions

#### Pages Not Opening / Blank Page

**Cause 1:** Missing database tables
- **Solution:** Run the setup script: `your-domain.com/setup.php`

**Cause 2:** Wrong database credentials
- **Solution:** Check `config/database.php` matches your hosting credentials

**Cause 3:** PHP errors not showing
- **Solution:** Enable error reporting temporarily in `config/database.php`:
  ```php
  error_reporting(E_ALL);
  ini_set('display_errors', 1);
  ```
  (Disable after debugging for security)

#### Form Submissions Not Working

**Solution:** This has been fixed! Make sure you have the latest version including:
- Updated `config/database.php` with `BASE_PATH` detection
- Form actions updated to use `url()` helper function
- All files committed after Feb 19, 2026

#### File Upload Errors

**Cause:** `uploads/` directory not writable
- **Solution:** 
  1. Create `uploads/documents/` directory via FTP
  2. Set permissions to `755`
  3. Ensure files can be written

#### 404 Errors on Menu Items (Subdirectory Deployment)

**Solution:** This has been fixed! If you're deploying in a subdirectory (e.g., `domain.com/pds/`):
- The `BASE_PATH` constant auto-detects the correct path
- All links use the `url()` helper function
- Navigation will work automatically

### Performance Optimization

1. **Database Indexes:**
   - The schema automatically creates indexes on foreign keys
   - For large datasets, add indexes on `user_id` in detail tables

2. **Caching:**
   - Currently no caching implemented
   - Consider adding Redis caching for future optimization

3. **Security:**
   - Database credentials are checked on connection
   - SQL injection is prevented with prepared statements
   - User input is sanitized with `htmlspecialchars()`

### Backup & Maintenance

**Regular backups recommended:**

```bash
# Export database (via PhpMyAdmin or command line)
mysqldump -u username -p database_name > backup.sql

# Export uploaded documents (via FTP)
# Backup the entire /uploads/ directory
```

### Troubleshooting Commands

If you have SSH access, run these to diagnose:

```bash
# Check PHP version
php --version

# Check database connection
php -r "require 'config/database.php'; echo 'DB OK';"

# Check file permissions
ls -la uploads/

# Check installed extensions
php -m | grep -i pdo
```

### Support

For issues:

1. Check the setup script for table status
2. Review `config/database.php` configuration
3. Ensure all files are uploaded (no FTP timeouts)
4. Check hosting control panel for resource limits

### Notes for Shared Hosting

- **Max Upload Size:** ProFreeHost limit is typically 2MB (app is set to 5MB, adjust as needed)
- **Execution Time:** May be limited to 30-60 seconds
- **Database Connections:** Limited concurrent connections
- **Directory Permissions:** Must be 644 (files) and 755 (directories)

---

**Last Updated:** February 19, 2026  
**Version:** 1.0.0
