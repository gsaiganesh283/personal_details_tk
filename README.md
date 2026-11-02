# Personal Details Saver

A full-featured **PHP web and mobile-responsive application** for saving personal details with custom field management.

## Features

- **User Authentication** — Secure login/register with password hashing (bcrypt), remember me, session management
- **Personal Details Management** — Full CRUD for personal records (name, contact, address, professional info)
- **Custom Fields** — Create, modify, and delete custom fields (text, number, email, date, textarea, select, checkbox, radio, URL, phone, color)
- **Advanced UI** — Dark theme, glassmorphism, smooth animations, particle effects, password strength meter
- **Mobile-First** — Fully responsive with bottom navigation bar for mobile devices
- **Dashboard** — Stats overview, recent activity log, quick actions
- **Profile Management** — Edit profile, change password, activity history
- **Security** — CSRF protection, prepared statements (SQL injection safe), XSS prevention, secure headers

## Tech Stack

- **Backend:** PHP 8+ with PDO (MySQL)
- **Frontend:** Vanilla CSS3 + JavaScript (no frameworks)
- **Database:** MySQL / MariaDB
- **Icons:** Font Awesome 6
- **Fonts:** Inter (Google Fonts)

## Project Structure

```
personal_details_saver/
├── assets/
│   ├── css/
│   │   ├── style.css          # Main stylesheet (1200+ lines)
│   │   └── responsive.css     # Mobile/tablet breakpoints
│   └── js/
│       └── app.js             # Core JavaScript (sidebar, modals, toasts, AJAX, etc.)
├── config/
│   └── database.php           # DB connection & Database class (singleton)
├── includes/
│   ├── auth.php               # Authentication class (login, register, sessions)
│   ├── functions.php          # Utility functions (sanitize, flash, timeAgo, etc.)
│   ├── header.php             # HTML header with sidebar & topbar
│   └── footer.php             # HTML footer with modals & scripts
├── sql/
│   └── schema.sql             # Database schema (6 tables)
├── index.php                  # Entry redirect
├── login.php                  # Login page
├── register.php               # Registration page
├── logout.php                 # Logout handler
├── dashboard.php              # Dashboard with stats & activity
├── personal-details.php       # CRUD for personal records
├── custom-fields.php          # CRUD for custom field definitions
├── profile.php                # User profile & settings
├── .htaccess                  # Apache rewrite & security rules
└── composer.json              # Project metadata
```

## Setup

### 1. Requirements
- PHP 8.0+
- MySQL 5.7+ or MariaDB 10.3+
- Apache (with mod_rewrite) or PHP built-in server

### 2. Database Setup
```bash
# Create the database and tables
mysql -u root -p < sql/schema.sql
```

### 3. Configuration
Edit `config/database.php` and update the credentials:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'personal_details_db');
define('DB_USER', 'root');
define('DB_PASS', 'your_password');
```

### 4. Run the Application
```bash
# Using PHP built-in server
php -S localhost:8000

# Or deploy to Apache/Nginx web server
```

### 5. Access
Open your browser and navigate to `http://localhost:8000`

## 📦 Deployment

### Quick Deployment Guide
- **ProFreeHost & Shared Hosting:** See [PROFREEHOST_DEPLOYMENT.md](PROFREEHOST_DEPLOYMENT.md)
- **Subdirectory Deployment:** See [DEPLOYMENT_FIX.md](DEPLOYMENT_FIX.md)

### Database Setup
After uploading files to your hosting:
1. Create MySQL database and user via hosting control panel
2. Update credentials in `config/database.php`
3. Visit `your-domain.com/setup.php` to create tables automatically
4. Or manually import `/sql/schema.sql` and `/sql/new_tables.sql` via PhpMyAdmin

### Automatic Subdirectory Support
The application works correctly when deployed:
- At domain root: `https://example.com/`
- In subdirectories: `https://example.com/myapp/`
- In nested paths: `https://example.com/apps/myapp/`

**No `.htaccess` or server configuration changes needed!**

## 📸 Screenshots

### Login Page
- Animated floating particles background
- Gradient accent bar
- Password visibility toggle
- Social login buttons
- Responsive design

### Dashboard
- Stats cards with hover animations
- Recent records table
- Activity timeline
- Quick action buttons

### Personal Details
- Card grid view with initials avatars
- Add/Edit form with sectioned layout
- Custom field integration
- Search & pagination
- View detail modal

### Custom Fields
- Table view with drag ordering
- Live field preview
- 11 field types supported
- Toggle active/inactive
- Inline edit/delete

## Mobile Support

The application is fully responsive and works as a mobile web app:
- Bottom tab navigation on mobile
- Touch-optimized buttons and cards
- Safe area padding for notched devices
- PWA-ready meta tags

## License

MIT