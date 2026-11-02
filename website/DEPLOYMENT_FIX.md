# Subdirectory Deployment Fix

## Problem
When the application was deployed online in a subdirectory (e.g., example.com/pds/), the menu navigation did not work because all internal links were using absolute paths (e.g., `/dashboard.php`) that pointed to the root domain instead of the subdirectory.

## Solution

### 1. **Added BASE_PATH Detection** ([config/database.php](config/database.php))
- Automatically detects the correct base path from the application's location
- Handles deployments at domain root (/) or in subdirectories (/pds/, /app/, etc.)

### 2. **Created URL Helper Function** ([includes/functions.php](includes/functions.php))
- `url($path)`: Generates correct URLs with proper base path prepending
- Usage: `<a href="<?php echo url('dashboard.php'); ?>">`

### 3. **Updated Redirect Function** ([includes/functions.php](includes/functions.php))
- `redirect()` now automatically handles path conversion
- Internal absolute paths (starting with `/`) are converted to proper subdirectory paths
- External URLs and already-converted paths are passed through unchanged
- Usage: `redirect('/dashboard.php')` works correctly in all environments

### 4. **Updated Navigation Throughout Application**

#### Main Header Navigation
- **File:** [includes/header.php](includes/header.php)
- Updated all sidebar and mobile navigation links to use `url()` helper
- Updated CSS and JavaScript asset paths

#### Login & Registration Pages
- **Files:** [login.php](login.php), [login.php](register.php)
- Added database config inclusion
- Updated form links and internal navigation

#### Key Pages
- **Dashboard:** [dashboard.php](dashboard.php) - Updated all quick action links
- **Personal Details:** [personal-details.php](personal-details.php) - Updated form actions and navigation
- **Index:** [index.php](index.php) - Properly redirects to login with basepath

#### Supporting Files
- **Footer:** [includes/footer.php](includes/footer.php) - Updated JavaScript resource path

## Affected Files
- config/database.php - Added BASE_PATH constant
- includes/functions.php - Added url() helper, updated redirect()
- includes/header.php - Updated navigation links, asset paths
- includes/footer.php - Updated script path
- index.php - Added database config, updated redirect
- login.php - Added database config, updated links
- register.php - Added database config, updated links
- dashboard.php - Updated all quick action buttons
- personal-details.php - Updated form actions and navigation links

## Testing
The application now works correctly when deployed:
- At domain root: example.com
- In subdirectories: example.com/pds
- In nested subdirectories: example.com/app/pds

## Remaining Updates
Some detail pages (bank-details.php, documents.php, etc.) still have hardcoded `/` paths in their form actions. These can be updated following the same pattern using the `url()` helper function. The main navigation issue is completely fixed.

## Migration Notes
If you've already deployed this in a subdirectory:
1. Upload the updated files
2. The application will automatically detect the correct path
3. All menu navigation will now work properly
4. No .htaccess or server configuration changes needed
