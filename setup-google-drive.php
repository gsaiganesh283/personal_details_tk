<?php
/**
 * Google Drive Setup & Authorization
 * Guides user through OAuth2 authentication with Google Drive
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if config exists
$configFile = __DIR__ . '/config/google-drive-config.php';
$hasConfig = file_exists($configFile);

?><!DOCTYPE html>
<html>
<head>
    <title>Google Drive Integration Setup</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; }
        .section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #6C63FF; }
        .section h2 { color: #333; margin-top: 0; }
        .step { background: #f9f9f9; padding: 15px; margin: 10px 0; border-left: 3px solid #4ECDC4; }
        .step h3 { margin-top: 0; color: #4ECDC4; }
        code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        .button { background: #6C63FF; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; margin: 5px 0; }
        .button:hover { background: #5A52D5; }
        .success { background: #e8f5e9; border-left-color: #4caf50; }
        .warning { background: #fff3e0; border-left-color: #ff9800; }
        .error { background: #ffebee; border-left-color: #f44336; }
        input { width: 100%; padding: 10px; margin: 5px 0 15px 0; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        label { display: block; font-weight: bold; margin: 15px 0 5px 0; }
        .code-block { background: #f9f9f9; padding: 15px; border-radius: 4px; overflow-x: auto; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔗 Google Drive Integration Setup</h1>
        
        <?php if (!$hasConfig): ?>
        <div class="section warning">
            <h2>⚠️ Step 1: Get Google API Credentials</h2>
            
            <div class="step">
                <h3>1. Create a Google Cloud Project</h3>
                <ol>
                    <li>Go to <a href="https://console.cloud.google.com" target="_blank">Google Cloud Console</a></li>
                    <li>Click "Select a Project" → "New Project"</li>
                    <li>Enter Project Name: <code>PDS Google Drive</code></li>
                    <li>Click "Create"</li>
                </ol>
            </div>
            
            <div class="step">
                <h3>2. Enable Google Drive API</h3>
                <ol>
                    <li>In Cloud Console, go to "APIs & Services" → "Library"</li>
                    <li>Search for <code>Google Drive API</code></li>
                    <li>Click on it and press <code>Enable</code></li>
                </ol>
            </div>
            
            <div class="step">
                <h3>3. Create OAuth 2.0 Credentials</h3>
                <ol>
                    <li>Go to "Credentials" tab</li>
                    <li>Click "Create Credentials" → "OAuth client ID"</li>
                    <li>Choose Application Type: <code>Web Application</code></li>
                    <li>Set Name: <code>Personal Details Saver</code></li>
                    <li>Add Authorized Redirect URI: <code><?php echo 'https://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain.com') . '/setup-google-drive.php?action=callback'; ?></code></li>
                    <li>Click "Create"</li>
                    <li>Download the JSON file</li>
                </ol>
            </div>
            
            <div class="step">
                <h3>4. Enter Your Credentials Below</h3>
                <form method="POST">
                    <label>Client ID:</label>
                    <input type="text" name="client_id" placeholder="From Google Console" required>
                    
                    <label>Client Secret:</label>
                    <input type="password" name="client_secret" placeholder="From Google Console" required>
                    
                    <label>Redirect URI:</label>
                    <input type="text" name="redirect_uri" value="<?php echo 'https://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain.com') . '/setup-google-drive.php?action=callback'; ?>" required>
                    
                    <button type="submit" class="button">Save & Continue</button>
                </form>
            </div>
        </div>
        
        <?php elseif (isset($_GET['action']) && $_GET['action'] === 'authorize'): ?>
        <div class="section">
            <h2>✅ Step 2: Authorize Access</h2>
            <p>Click below to authorize Personal Details Saver to access your Google Drive:</p>
            
            <?php
            require_once __DIR__ . '/includes/google-drive.php';
            try {
                $gdrive = new GoogleDriveIntegration();
                $authUrl = $gdrive->getAuthorizationUrl();
            ?>
                <a href="<?php echo $authUrl; ?>" class="button" style="font-size: 16px;">
                    🔗 Authorize with Google
                </a>
                <p style="color: #666; margin-top: 20px;">
                    You'll be redirected to Google to grant permission. After granting, you'll be redirected back.
                </p>
            <?php
            } catch (Exception $e) {
                echo '<div class="section error">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>
        
        <?php elseif (isset($_GET['action']) && $_GET['action'] === 'callback' && isset($_GET['code'])): ?>
        <div class="section">
            <h2>Processing Authorization...</h2>
            
            <?php
            require_once __DIR__ . '/includes/google-drive.php';
            try {
                $gdrive = new GoogleDriveIntegration();
                if ($gdrive->handleAuthorizationCode($_GET['code'])) {
                    echo '<div class="section success">';
                    echo '<h3>✅ Success!</h3>';
                    echo '<p>Google Drive is now connected!</p>';
                    echo '<p><a href="setup-google-drive.php" class="button">View Status</a></p>';
                    echo '</div>';
                } else {
                    echo '<div class="section error">Failed to authorize. Please try again.</div>';
                }
            } catch (Exception $e) {
                echo '<div class="section error">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>
        
        <?php else: ?>
        <div class="section success">
            <h2>✅ Google Drive Connected!</h2>
            
            <?php
            require_once __DIR__ . '/includes/google-drive.php';
            try {
                $gdrive = new GoogleDriveIntegration();
                if ($gdrive->isConfigured()) {
                    echo '<p><strong>Status:</strong> Connected and Ready</p>';
                    echo '<p>Your documents will now be uploaded to Google Drive instead of local storage.</p>';
                    echo '<h3>Next Steps:</h3>';
                    echo '<ol>';
                    echo '<li>Go to <a href="documents.php">Documents</a> page</li>';
                    echo '<li>Upload a file to test (PDF, image, etc.)</li>';
                    echo '<li>Verify it uploads successfully</li>';
                    echo '<li>Check your Google Drive folder for the file</li>';
                    echo '</ol>';
                    
                    echo '<h3>Benefits:</h3>';
                    echo '<ul>';
                    echo '<li>✅ Unlimited storage (up to 15 GB free)</li>';
                    echo '<li>✅ Automatic backups</li>';
                    echo '<li>✅ Access files from anywhere</li>';
                    echo '<li>✅ Database stays small</li>';
                    echo '<li>✅ Shared hosting compatible</li>';
                    echo '</ul>';
                    
                    echo '<a href="documents.php" class="button">Go to Documents →</a>';
                } else {
                    echo '<p>Configuration incomplete. Please complete the setup above.</p>';
                }
            } catch (Exception $e) {
                echo '<div class="section error">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>
        <?php endif; ?>
        
        <div class="section">
            <h2>❓ FAQ</h2>
            
            <div class="step">
                <h3>Q: Is my data safe?</h3>
                <p>A: Yes! Google Drive provides enterprise-level security, encryption, and backup. Your files are safer there than on shared hosting.</p>
            </div>
            
            <div class="step">
                <h3>Q: How much storage do I get?</h3>
                <p>A: 15 GB free with Google account. Upgrade to 100 GB ($1.99/month) or 2 TB ($9.99/month) if needed.</p>
            </div>
            
            <div class="step">
                <h3>Q: Will this work on ProfreeHost?</h3>
                <p>A: Yes! This uses pure PHP with cURL, which is available on all hosting providers.</p>
            </div>
            
            <div class="step">
                <h3>Q: What file types are supported?</h3>
                <p>A: All types - PDFs, images (JPG, PNG, GIF), audio (MP3, WAV), video (MP4, MOV), documents, etc.</p>
            </div>
            
            <div class="step">
                <h3>Q: Can I download files?</h3>
                <p>A: Yes! Files can be downloaded directly from Google Drive or streamed.</p>
            </div>
            
            <div class="step">
                <h3>Q: What if I lose internet?</h3>
                <p>A: Upload will fail with a clear error message. Retry when connection is back.</p>
            </div>
        </div>
        
        <div class="section" style="background: #f0f7ff; border-left-color: #2196F3;">
            <h2>📞 Support</h2>
            <p>For issues with Google Drive setup:</p>
            <ul>
                <li>Check that cURL is enabled on your hosting (usually is)</li>
                <li>Verify OAuth credentials are correct</li>
                <li>Ensure redirect URI matches exactly</li>
                <li>Check Google Cloud Console for any errors</li>
            </ul>
        </div>
    </div>
</body>
</html>

<?php
// Handle POST request to save config
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasConfig) {
    $clientId = $_POST['client_id'] ?? '';
    $clientSecret = $_POST['client_secret'] ?? '';
    $redirectUri = $_POST['redirect_uri'] ?? '';
    
    if ($clientId && $clientSecret && $redirectUri) {
        $config = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'drive_folder_id' => 'root' // Default to root, can be changed later
        ];
        
        $configContent = '<?php return ' . var_export($config, true) . ';';
        
        if (file_put_contents($configFile, $configContent)) {
            echo '<script>window.location.href = "setup-google-drive.php?action=authorize";</script>';
        }
    }
}
?>
