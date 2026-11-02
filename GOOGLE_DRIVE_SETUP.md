# 🚀 Google Drive Integration Setup Guide

## Overview

This Personal Details Saver now supports **Google Drive storage** for large files (PDFs, images, audio, video, etc.). Instead of storing files on your hosting server, they're uploaded to Google Drive while only the metadata stays in the database.

## ✨ Benefits

✅ **Unlimited Storage** - 15GB free (upgradeable to 2TB)  
✅ **Database Stays Small** - Only metadata in database, files in cloud  
✅ **Works on ProfreeHost** - Uses PHP with cURL, no special requirements  
✅ **Automatic Backups** - Google Drive handles backups  
✅ **Accessible Anywhere** - Access files from anywhere  
✅ **Shared Hosting Compatible** - No file size limits on hosting  
✅ **Secure** - Enterprise-grade encryption & security  

## 📋 Quick Start

### Step 1: Run Setup Wizard
```
Visit: https://your-domain.com/setup-google-drive.php
```

The wizard will guide you through:
1. Creating a Google Cloud Project
2. Enabling Google Drive API
3. Getting OAuth credentials
4. Authorizing the application

### Step 2: Follow On-Screen Instructions
The setup page provides complete step-by-step instructions with links to Google Cloud Console.

### Step 3: Start Using Google Drive
After authorization, all file uploads go directly to Google Drive!

---

## 🔐 Detailed Setup Instructions

### Prerequisites
- Google account (Gmail)
- Access to Google Cloud Console
- ProfreeHost account with this application installed

### Step-by-Step Setup

#### 1. **Create Google Cloud Project**

1. Open [Google Cloud Console](https://console.cloud.google.com)
2. Click project dropdown at top
3. Click "NEW PROJECT"
4. Enter name: `Personal Details Saver`
5. Click "CREATE"
6. Wait for project to be created (1-2 minutes)

![Project Creation](images/cloud-console.jpg)

#### 2. **Enable Google Drive API**

1. In Cloud Console, go to "APIs & Services" → "Library"
2. Search for `"Google Drive API"`
3. Click on the result
4. Click "ENABLE" button
5. Wait for activation (background task)

#### 3. **Create OAuth 2.0 Credentials**

1. Go to "APIs & Services" → "Credentials"
2. Click "CREATE CREDENTIALS" → "OAuth client ID"
3. If prompted, click "CONFIGURE CONSENT SCREEN" first:
   - Choose "External" User Type
   - Fill basic info (app name, etc.)
   - Add your email as test user
   - Save and continue
4. Back in Credentials tab, click "CREATE CREDENTIALS" → "OAuth client ID"
5. Select Application Type: **Web Application**
6. Give it a name: `Personal Details Saver`
7. Under "Authorized Redirect URIs", click "ADD URI"
8. Paste this exact URL:
   ```
   https://your-domain.com/setup-google-drive.php?action=callback
   ```
   (Replace `your-domain.com` with your actual domain)
9. Click "CREATE"
10. Copy the **Client ID** and **Client Secret**

#### 4. **Configure in Application**

1. Open `setup-google-drive.php` in your browser
2. Paste the Client ID and Client Secret from step 3
3. Click "Save & Continue"
4. Click "Authorize with Google"
5. Sign in with your Google account
6. Grant permission when prompted
7. You should see "Success!" message

---

## 📂 How It Works

### File Upload Flow

```
User uploads file
        ↓
File validation (size, type)
        ↓
Upload to Google Drive
        ↓
Store Google Drive ID in database
        ↓
Delete temporary file
        ↓
Success message
```

### File Download Flow

```
User clicks download
        ↓
Get Google Drive ID from database
        ↓
Stream file from Google Drive
        ↓
Browser downloads file
```

---

## 🔧 Configuration Files

### `config/google-drive-config.php`

Created automatically by setup wizard. Contains:

```php
[
    'client_id' => 'YOUR_CLIENT_ID.apps.googleusercontent.com',
    'client_secret' => 'YOUR_CLIENT_SECRET',
    'redirect_uri' => 'https://your-domain.com/setup-google-drive.php?action=callback',
    'drive_folder_id' => 'root' // Or specific folder ID
]
```

### `config/.google-drive-tokens`

Created after authorization. Contains access tokens. **Keep this secure!**

---

## 📊 Storage Structure

### In Database (documents table)
```
id                 - Record ID
user_id            - User who uploaded
document_name      - Display name
file_size          - File size in bytes
mime_type          - File type (pdf, image, etc)
file_path          - "gdrive:FILE_ID_HERE"  ← Stores Google Drive ID
created_at         - Upload timestamp
```

### On Google Drive
```
Personal Details Saver/
    └── document_filename.pdf
    └── photo.jpg
    └── audio.mp3
    └── ...
```

---

## 🔗 Using Google Drive Storage

### Uploading Files

1. Go to **Documents** page
2. Click "Add New Document"
3. Upload file as usual
4. File automatically goes to Google Drive
5. Only metadata stored in database

### Downloading Files

Click download next to any document:
- File is streamed from Google Drive
- No local storage needed
- Works instantly

### Deleting Files

When you delete a document:
1. File deleted from Google Drive
2. Record deleted from database
3. Storage reclaimed automatically

---

## ⚙️ Advanced Configuration

### Using a Specific Google Drive Folder

By default, files go to Google Drive root folder. To use a specific folder:

1. Create folder in Google Drive (e.g., "PDS Documents")
2. Open folder and copy its ID from URL:
   ```
   https://drive.google.com/drive/folders/FOLDER_ID_HERE
   ```
3. Edit `config/google-drive-config.php`:
   ```php
   'drive_folder_id' => 'FOLDER_ID_HERE'
   ```

### Changing Google Account

To re-authorize with different Google account:

1. Delete `config/.google-drive-tokens` file (not the config.php!)
2. Visit `setup-google-drive.php`
3. Click "Authorize with Google"
4. Sign in with new account

### Upgrading Google Storage

Free account: 15 GB  
Convert to paid:
1. Go to [Google One](https://one.google.com)
2. Choose plan:
   - 100 GB = $1.99/month
   - 2 TB = $9.99/month
   - 5 TB = $24.99/month
   - 10 TB = $49.99/month

---

## 🐛 Troubleshooting

### "Google Drive not configured"

**Problem:** Error says Google Drive not set up

**Solution:**
1. Run `setup-google-drive.php`
2. Complete authorization
3. Try again

### "Access Denied" or "401 Unauthorized"

**Problem:** Can't upload files

**Solution:**
1. Google credentials expired
2. Re-authorize: Delete `config/.google-drive-tokens`
3. Visit `setup-google-drive.php`
4. Click "Authorize with Google"

### Files Upload Slowly

**Problem:** Upload seems stuck

**Solution:**
1. Check file size (max 5 GB technically, but set to 5 MB in config)
2. Check internet connection
3. Try smaller file first
4. Larger files take longer

### "cURL error" or Connection Issues

**Problem:** Can't connect to Google

**Solution:**
1. cURL might be disabled on hosting
2. Check with hosting provider
3. Usually works on ProfreeHost

### Files Uploaded but Not Visible

**Problem:** Upload succeeded but file missing

**Solution:**
1. Go to your [Google Drive](https://drive.google.com)
2. Check "Personal Details Saver" folder
3. File should be there
4. Can take 1-2 seconds to appear

---

## 📈 Monitoring Storage Usage

### Check Your Google Drive Usage

1. Go to [Google Drive](https://drive.google.com)
2. Click gear icon → "Storage"
3. See how much you're using
4. Upgrade if needed

### Database Size

PDS database is very small (< 1 MB) because files aren't stored there.

### Calculating Costs

```
15 GB free tier:
- Perfect for 5-10 users with moderate documents

100 GB ($1.99/month):
- Great for 20-50 users

2 TB ($9.99/month):
- Enterprise use with heavy documents
```

---

## 🔒 Security & Privacy

### Data Protection

✓ Files encrypted in transit (HTTPS)  
✓ Encrypted at rest on Google Drive  
✓ Only your account can access  
✓ OAuth tokens never exposed  

### Best Practices

1. **Don't share config files** - Keep API credentials private
2. **Use strong passwords** - On your Google account
3. **Enable 2FA** - On Google account for extra security
4. **Regular backups** - Download important files locally
5. **Monitor activity** - Check Google Drive logs regularly

---

## 🚀 Performance Tips

### Upload Speed

- Larger files (>50MB) take longer
- Use for PDFs, images, documents
- Not recommended for videos > 100MB

### Download Speed

- Files stream directly from Google Drive
- Speed depends on your internet
- Same as downloading from Google Drive web interface

### Optimize Database

Since files aren't stored locally:
- Database stays small
- Faster queries
- Less hosting resource usage
- Better performance overall

---

## 🔄 Migration from Local Storage

If you had local storage before, Google Drive:

### Existing Files Behavior
- Old files remain where they are
- New uploads go to Google Drive
- Can manually migrate if needed

### Manual Migration

To move existing local files to Google Drive:

1. Keep using local storage for old files
2. New files go to Google Drive
3. Over time, archive old local files
4. System supports both simultaneously

---

## 📞 Support & Help

### Common Questions

**Q: Can I share files with others?**  
A: Yes! Files are in your Google Drive, you can share normally.

**Q: What if Google shuts down Drive?**  
A: Unlikely, but you can download all files anytime.

**Q: Can multiple users upload?**  
A: Yes! Each user's files go to same Google Drive (owned by configured account).

**Q: Need to use different Google account per user?**  
A: Currently uses one account. Can be extended if needed.

**Q: Can I use Google Workspace (business accounts)?**  
A: Yes! Same setup process.

**Q: Will this slow down my hosting?**  
A: No! Actually faster - no large files on hosting server.

---

## ✅ Verification Checklist

After setup, verify everything works:

- [ ] Run `setup-google-drive.php` successfully
- [ ] Authorized with Google account
- [ ] Uploaded a test document
- [ ] File appears in Google Drive
- [ ] Can download file from Documents page
- [ ] File metadata shows in database
- [ ] Can delete document (removes from Drive)
- [ ] Create another user and have them upload

---

## 🎯 Next Steps

1. **Complete Setup:** Visit `setup-google-drive.php`
2. **Test Upload:** Go to Documents → upload a PDF
3. **Verify:" Check file appears in Google Drive
4. **Share with Users:** Tell them files auto-upload to cloud

---

**You're all set!** Your large files are now safely stored on Google Drive while your database stays lightweight. 🎉
