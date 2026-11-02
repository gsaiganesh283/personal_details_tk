## Social Login Setup Guide

This guide walks you through setting up Google, GitHub, and Apple OAuth sign-in for your Personal Details Saver application.

## Table of Contents
1. [Google OAuth](#google-oauth)
2. [GitHub OAuth](#github-oauth)
3. [Apple Sign In](#apple-sign-in-advanced)
4. [Testing](#testing)
5. [Deployment Notes](#deployment-notes)

---

## Google OAuth

### Prerequisites
- A Google Cloud account
- The app hosted with a valid domain (localhost works for testing)

### Step 1: Create a Google Cloud Project
1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Click **Select a Project** → **New Project**
3. Name it "Personal Details Saver" → **Create**
4. Wait for the project to initialize

### Step 2: Enable OAuth Consent Screen
1. Left menu → **APIs & Services** → **OAuth consent screen**
2. Select **External** → **Create**
3. Fill in the form:
   - **App name**: Personal Details Saver
   - **User support email**: your@email.com
   - **Developer contact**: your@email.com
4. Click **Save & Continue** (skip scopes, agreements)
5. Click **Back to Dashboard**

### Step 3: Create OAuth 2.0 Credentials
1. Left menu → **Credentials**
2. Click **+ Create Credentials** → **OAuth client ID**
3. Choose **Desktop application**
4. Name it "Personal Details Saver Desktop"
5. Click **Create**
6. Copy the **Client ID** and **Client Secret**

### Step 4: Configure in Your App
1. Log in to your Personal Details Saver app
2. Go to **Profile** → **Social Login** tab
3. Paste your Google credentials:
   - **Google Client ID**: (from step 3)
   - **Google Client Secret**: (from step 3)
4. Click **Save OAuth Credentials**
5. Test by going to login page and clicking Google button

---

## GitHub OAuth

### Prerequisites
- A GitHub account

### Step 1: Register OAuth Application
1. Go to [GitHub Settings → Developer settings → OAuth Apps](https://github.com/settings/developers)
2. Click **New OAuth App**
3. Fill in:
   - **Application name**: Personal Details Saver
   - **Homepage URL**: `https://yourdomain.com` (or `http://localhost:8000` for local testing)
   - **Authorization callback URL**: `https://yourdomain.com/oauth-callback.php?provider=github`
4. Click **Register application**

### Step 2: Get Credentials
1. On the OAuth app page:
   - Copy **Client ID**
   - Click **Generate a new client secret** and copy it

### Step 3: Configure in Your App
1. Log in to your Personal Details Saver app
2. Go to **Profile** → **Social Login** tab
3. Paste your GitHub credentials:
   - **GitHub Client ID**: (from step 2)
   - **GitHub Client Secret**: (from step 2)
4. Click **Save OAuth Credentials**
5. Test by going to login page and clicking GitHub button

---

## Apple Sign In (Advanced)

Apple Sign In requires an **Apple Developer Account** ($99/year) and is significantly more complex.

### Prerequisites
- Active Apple Developer Account
- Registered domain with HTTPS
- Private key from Apple Developer console

### Step 1: Create Service ID
1. Go to [Apple Developer Account](https://developer.apple.com/account/)
2. Select **Certificates, Identifiers & Profiles**
3. Under **Identifiers**, click **+**
4. Select **Services IDs** → **Continue**
5. Register ID: `com.yourapp.signin` (example)
6. Enable **Sign in with Apple**
7. Configure domain and return URL
8. Save

### Step 2: Create Private Key
1. Go to **Keys** section
2. Click **+** to create a new key
3. Enable **Sign in with Apple**
4. Download the `.p8` file (save it securely)
5. Note the **Key ID**

### Step 3: Configure in Your App
Apple authentication is complex and requires server-side JWT signing. Due to the complexity, we recommend:
- Using a pre-built library for production
- Or contacting us for implementation support
- Or testing with Google & GitHub only first

If you have an Apple Developer Account and want to proceed:
1. Save the `.p8` private key file securely
2. In Profile → Social Login, enter:
   - **Apple Team ID**: (from your Developer Account)
   - **Apple Service ID**: `com.yourapp.signin` (or your own)
   - **Apple Key ID**: (from the private key)

---

## Testing

### Local Testing
1. Run the app locally: `php -S localhost:8000`
2. For **Google** & **GitHub**: Use `http://localhost:8000` as the app URL
3. Register the callback URLs in the provider's settings

### Hosted Testing
1. Update your provider's callback URLs to your live domain
2. Clear browser cookies/cache
3. Visit login page and test social buttons

### Debug Mode
If social login fails:
1. Check browser console for errors
2. Check `error.log` in the application root
3. Verify callback URLs match exactly in provider settings
4. Verify credentials are entered correctly in Profile → Social Login

---

## Deployment Notes

### Security Best Practices
1. **Never commit `config/oauth-config.php`** to version control
   - Add to `.gitignore`:
     ```
     config/oauth-config.php
     config/.google-drive-tokens
     ```

2. **Use environment variables** for sensitive credentials (recommended for production):
   - Replace plaintext credentials with `getenv()` calls
   - Set environment variables in your hosting control panel

3. **Use HTTPS only** in production for OAuth callbacks

4. **Restrict redirect URIs** to exact URLs only

### After Deployment
1. Re-register OAuth apps on each provider with your live domain
2. Update credentials in Profile → Social Login
3. Test all three providers before announcing to users
4. Monitor `error.log` for OAuth issues

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| "Unknown login provider" error | Check the provider name in URL spelling |
| "Invalid OAuth state" | Clear browser cookies and try again |
| "Could not retrieve profile" | Check credentials are correct in Profile → Social Login |
| Provider login page doesn't redirect | Verify callback URL exactly matches provider settings |
| Works locally but not on live server | Update provider's callback URLs to your live domain |

---

## File Structure

```
config/
  └── oauth-config.php       (↔ Stores OAuth credentials)
oauth-login.php              (Initiates OAuth flow)
oauth-callback.php           (Handles OAuth callback)
includes/auth.php            (handleOAuthLogin + related methods)
login.php / register.php     (Social login buttons)
profile.php                  (OAuth configuration UI)
```

---

## References
- [Google OAuth 2.0 Playground](https://developers.google.com/oauthplayground)
- [GitHub OAuth Documentation](https://docs.github.com/en/developers/apps/building-oauth-apps)
- [Apple Sign In with Web](https://developer.apple.com/sign-in-with-apple/get-started/)
