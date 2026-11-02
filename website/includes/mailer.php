<?php
/**
 * Mail Helper Class
 * Handles sending emails for password reset, notifications, etc.
 * 
 * Supports both:
 * - PHP mail() function (noreply@domain)
 * - SMTP via Gmail/other providers (gsaiganesh628@gmail.com)
 */

class Mailer {
    private $fromEmail;
    private $appName;
    private $useSmtp = false;
    private $smtpHost;
    private $smtpPort;
    private $smtpUser;
    private $smtpPass;

    public function __construct() {
        $this->appName = 'Personal Details Saver';

        // Load config from file (works on all hosts, including shared hosts that disable putenv)
        $configFile = __DIR__ . '/../config/email-setup.php';
        $config = [];
        if (file_exists($configFile)) {
            $config = @include $configFile;
            if (!is_array($config)) {
                $config = [];
            }
        }

        // Also check environment variables as fallback
        $smtpHost = $config['smtp_host'] ?? getenv('MAIL_SMTP_HOST');
        $smtpUser = $config['smtp_user'] ?? getenv('MAIL_SMTP_USER');
        $smtpPass = $config['smtp_pass'] ?? getenv('MAIL_SMTP_PASS');

        if (!empty($smtpHost) && !empty($smtpUser) && !empty($smtpPass)) {
            $this->useSmtp = true;
            $this->smtpHost = $smtpHost;
            $this->smtpPort = $config['smtp_port'] ?? getenv('MAIL_SMTP_PORT') ?: 465;
            $this->smtpUser = $smtpUser;
            $this->smtpPass = $smtpPass;
            $this->fromEmail = $config['from'] ?? getenv('MAIL_FROM') ?: $this->smtpUser;
        } else {
            // Fallback to PHP mail() function
            $this->useSmtp = false;
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $host = preg_replace('/:\d+$/', '', $host);  // Remove port
            $this->fromEmail = $config['from'] ?? getenv('MAIL_FROM') ?: ('noreply@' . $host);
        }
    }

    /**
     * Send password reset email
     */
    public function sendPasswordReset($toEmail, $fullName, $resetToken, $resetUrl) {
        $subject = 'Password Reset Request - ' . $this->appName;
        $html = $this->getPasswordResetEmailHtml($fullName, $resetToken, $resetUrl);
        return $this->send($toEmail, $subject, $html);
    }

    /**
     * Send account confirmation email
     */
    public function sendWelcome($toEmail, $fullName, $username) {
        $subject = 'Welcome to ' . $this->appName;

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="background: linear-gradient(135deg, #6C63FF 0%, #5A55E3 100%); padding: 30px; border-radius: 8px 8px 0 0; color: white; text-align: center;">
            <h1 style="margin: 0; font-size: 24px;">Welcome to {$this->appName}!</h1>
        </div>
        
        <div style="background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; border: 1px solid #e5e7eb; border-top: none;">
            <p style="margin-top: 0;">Hi <strong>{$fullName}</strong>,</p>
            
            <p>Your account has been created successfully! You can now sign in with your username and password.</p>
            
            <div style="background: white; padding: 15px; border-radius: 6px; border-left: 4px solid #6C63FF; margin: 20px 0;">
                <p style="margin: 0 0 5px 0;"><strong>Your Username:</strong></p>
                <p style="margin: 0; color: #6C63FF; font-family: monospace;">{$username}</p>
            </div>
            
            <p><strong>What You Can Do:</strong></p>
            <ul style="margin: 0 0 20px 0; padding-left: 20px;">
                <li>Store personal details securely</li>
                <li>Create custom fields for your data</li>
                <li>Upload documents and files</li>
                <li>Connect with Google Drive for cloud backup</li>
                <li>Set up social login (Google, GitHub, Apple)</li>
            </ul>
            
            <p style="margin: 20px 0 0 0; color: #666; font-size: 14px;">
                If you didn't create this account, please ignore this email.
            </p>
        </div>
    </div>
</body>
</html>
HTML;

        return $this->send($toEmail, $subject, $html);
    }

    /**
     * Send a scheduled/custom notification message (from the reminders module
     * or a 'reminder' custom field) to a user's registered email.
     */
    public function sendNotification($toEmail, $fullName, $title, $message) {
        $subject = str_replace(["\r", "\n"], ' ', $title) . ' - ' . $this->appName;
        $safeTitle = htmlspecialchars($title);
        $safeMessage = nl2br(htmlspecialchars($message));
        $greetingName = $fullName !== '' ? htmlspecialchars($fullName) : 'there';

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="background: linear-gradient(135deg, #6C63FF 0%, #5A55E3 100%); padding: 30px; border-radius: 8px 8px 0 0; color: white; text-align: center;">
            <h1 style="margin: 0; font-size: 22px;">{$safeTitle}</h1>
        </div>

        <div style="background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; border: 1px solid #e5e7eb; border-top: none;">
            <p style="margin-top: 0;">Hi <strong>{$greetingName}</strong>,</p>

            <div style="background: white; padding: 16px 18px; border-radius: 6px; border-left: 4px solid #6C63FF; margin: 16px 0;">
                {$safeMessage}
            </div>

            <p style="margin: 20px 0 0 0; color: #666; font-size: 13px;">
                This is a scheduled reminder you set up in {$this->appName}.
            </p>
        </div>
    </div>
</body>
</html>
HTML;

        return $this->send($toEmail, $subject, $html);
    }

    /**
     * Send email via SMTP or PHP mail()
     * If SMTP is configured but fails, falls back to PHP mail()
     */
    private function send($toEmail, $subject, $htmlBody) {
        if (empty($toEmail)) {
            error_log('Mailer: Empty recipient email');
            return false;
        }

        // Try SMTP first if configured
        if ($this->useSmtp) {
            $result = $this->sendViaSMTP($toEmail, $subject, $htmlBody);
            if ($result) {
                return true;
            }
            // SMTP failed - fall back to PHP mail()
            error_log("SMTP failed, falling back to PHP mail() for: $toEmail");
        }

        // Use PHP mail() (default or fallback)
        return $this->sendViaPhpMail($toEmail, $subject, $htmlBody);
    }

    /**
     * Send via PHP mail() function
     */
    private function sendViaPhpMail($toEmail, $subject, $htmlBody) {
        $from = $this->fromEmail;
        // Clean port from host if present (e.g. noreply@localhost:3000 → noreply@localhost)
        $from = preg_replace('/:\d+$/', '', $from);

        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$this->appName} <{$from}>\r\n";
        $headers .= "Reply-To: {$from}\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

        $result = @mail($toEmail, $subject, $htmlBody, $headers);

        if ($result) {
            error_log("Email sent (PHP mail) to: $toEmail | Subject: $subject | From: {$from}");
        } else {
            $lastError = error_get_last();
            $errorMsg = $lastError ? $lastError['message'] : 'unknown error';
            error_log("Email FAILED (PHP mail) to: $toEmail | Subject: $subject | From: {$from} | Error: {$errorMsg}");
        }

        return $result;
    }

    /**
     * Read a full SMTP response (handles multi-line responses like EHLO)
     * Multi-line responses have '-' after code: "250-SIZE 35882577"
     * Last line has space after code: "250 OK"
     */
    private function smtpReadResponse($smtp) {
        $response = '';
        stream_set_timeout($smtp, 10);
        while (true) {
            $line = fgets($smtp, 4096);
            if ($line === false) break;
            $response .= $line;
            // Last line of response: code followed by space (not dash)
            if (isset($line[3]) && $line[3] === ' ') break;
            if (strlen($line) < 4) break;
        }
        return trim($response);
    }

    /**
     * Send via SMTP (Gmail, Mailgun, etc.)
     * Tries port 465 (direct SSL) first, then port 587 (STARTTLS)
     */
    private function sendViaSMTP($toEmail, $subject, $htmlBody) {
        // Try configured port first, then alternate
        $ports = [];
        if ($this->smtpPort == 465) {
            $ports = [465, 587];
        } else {
            $ports = [587, 465];
        }

        foreach ($ports as $port) {
            error_log("SMTP: Trying {$this->smtpHost}:$port ...");
            $result = $this->doSmtpSend($toEmail, $subject, $htmlBody, $port);
            if ($result) return true;
        }

        return false;
    }

    /**
     * Actual SMTP send on a specific port
     */
    private function doSmtpSend($toEmail, $subject, $htmlBody, $port) {
        $smtp = null;
        try {
            $errno = 0;
            $errstr = '';

            // Port 465 = direct SSL wrapper, Port 587 = STARTTLS
            if ($port == 465) {
                $smtp = @fsockopen('ssl://' . $this->smtpHost, 465, $errno, $errstr, 15);
            } else {
                $smtp = @fsockopen($this->smtpHost, $port, $errno, $errstr, 15);
            }
            
            if (!$smtp) {
                error_log("SMTP Error: Could not connect to {$this->smtpHost}:$port - $errstr ($errno)");
                return false;
            }

            stream_set_timeout($smtp, 15);

            // Read greeting (220)
            $response = $this->smtpReadResponse($smtp);
            if (strpos($response, '220') !== 0) {
                error_log("SMTP: Invalid greeting: $response");
                fclose($smtp);
                return false;
            }

            // For SMTP client submission, use 'localhost' - NOT the sender's email domain.
            // Using gmail.com as EHLO from a non-Google IP looks like impersonation and gets blocked.
            $ehloDomain = 'localhost';

            // EHLO
            fputs($smtp, "EHLO {$ehloDomain}\r\n");
            $response = $this->smtpReadResponse($smtp);
            if (strpos($response, '250') === false) {
                error_log("SMTP: EHLO failed: $response");
                fclose($smtp);
                return false;
            }

            // STARTTLS only needed on port 587 (port 465 is already SSL)
            if ($port != 465) {
                fputs($smtp, "STARTTLS\r\n");
                $response = $this->smtpReadResponse($smtp);
                if (strpos($response, '220') === false) {
                    error_log("SMTP: STARTTLS rejected on port $port: $response");
                    fclose($smtp);
                    return false;
                }
                
                $crypto = stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                if (!$crypto) {
                    error_log("SMTP: TLS negotiation failed on port $port");
                    fclose($smtp);
                    return false;
                }

                // EHLO again after TLS (required by RFC)
                fputs($smtp, "EHLO {$ehloDomain}\r\n");
                $response = $this->smtpReadResponse($smtp);
                if (strpos($response, '250') === false) {
                    error_log("SMTP: EHLO after TLS failed: $response");
                    fclose($smtp);
                    return false;
                }
            }

            // AUTH LOGIN
            fputs($smtp, "AUTH LOGIN\r\n");
            $response = $this->smtpReadResponse($smtp);
            if (strpos($response, '334') === false) {
                error_log("SMTP: AUTH LOGIN rejected: $response");
                fclose($smtp);
                return false;
            }
            
            // Username (base64)
            fputs($smtp, base64_encode($this->smtpUser) . "\r\n");
            $response = $this->smtpReadResponse($smtp);
            if (strpos($response, '334') === false) {
                error_log("SMTP: Username rejected: $response");
                fclose($smtp);
                return false;
            }
            
            // Password (base64)
            fputs($smtp, base64_encode($this->smtpPass) . "\r\n");
            $response = $this->smtpReadResponse($smtp);
            if (strpos($response, '235') === false) {
                error_log("SMTP: Authentication failed: $response");
                fclose($smtp);
                return false;
            }

            // MAIL FROM
            fputs($smtp, "MAIL FROM:<{$this->fromEmail}>\r\n");
            $response = $this->smtpReadResponse($smtp);
            if (strpos($response, '250') === false) {
                error_log("SMTP: MAIL FROM rejected: $response");
                fclose($smtp);
                return false;
            }

            // RCPT TO
            fputs($smtp, "RCPT TO:<{$toEmail}>\r\n");
            $response = $this->smtpReadResponse($smtp);
            if (strpos($response, '250') === false) {
                error_log("SMTP: RCPT TO rejected: $response");
                fclose($smtp);
                return false;
            }

            // DATA
            fputs($smtp, "DATA\r\n");
            $response = $this->smtpReadResponse($smtp);
            if (strpos($response, '354') === false) {
                error_log("SMTP: DATA rejected: $response");
                fclose($smtp);
                return false;
            }

            // Use the app's actual domain for Message-ID (NOT gmail.com - that looks like spoofing)
            $domain = 'pds.liveblog365.com';
            $msgId = uniqid('pds_', true) . '@' . $domain;

            // Encode HTML body as base64 (most reliable for SMTP, avoids 8bit encoding issues)
            $encodedBody = chunk_split(base64_encode($htmlBody), 76, "\r\n");

            // Compose message with proper headers for deliverability
            $message  = "From: {$this->appName} <{$this->fromEmail}>\r\n";
            $message .= "To: {$toEmail}\r\n";
            $message .= "Reply-To: {$this->fromEmail}\r\n";
            $message .= "Subject: {$subject}\r\n";
            $message .= "Date: " . date('r') . "\r\n";
            $message .= "Message-ID: <{$msgId}>\r\n";
            $message .= "MIME-Version: 1.0\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n";
            $message .= "X-Mailer: PersonalDetailsSaver/1.0\r\n";
            $message .= "\r\n";
            $message .= $encodedBody;
            $message .= "\r\n.\r\n";

            // Write in chunks to ensure all data is sent (fputs can do partial writes)
            $totalLen = strlen($message);
            $written = 0;
            while ($written < $totalLen) {
                $chunk = substr($message, $written, 8192);
                $bytes = @fwrite($smtp, $chunk);
                if ($bytes === false || $bytes === 0) {
                    error_log("SMTP: fwrite failed at byte $written of $totalLen");
                    fclose($smtp);
                    return false;
                }
                $written += $bytes;
            }

            $response = $this->smtpReadResponse($smtp);
            if (strpos($response, '250') === false) {
                error_log("SMTP: Message send failed ($written bytes sent): $response");
                fclose($smtp);
                return false;
            }

            // QUIT
            fputs($smtp, "QUIT\r\n");
            @$this->smtpReadResponse($smtp);
            fclose($smtp);

            error_log("Email sent (SMTP port $port) to: $toEmail | Subject: $subject | From: {$this->fromEmail}");
            return true;

        } catch (Exception $e) {
            error_log("SMTP Exception (port $port): " . $e->getMessage());
            if ($smtp && is_resource($smtp)) {
                fclose($smtp);
            }
            return false;
        }
    }

    /**
     * Password Reset Email Template
     */
    private function getPasswordResetEmailHtml($fullName, $resetToken, $resetUrl) {
        $expiryTime = '1 hour';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="background: linear-gradient(135deg, #6C63FF 0%, #5A55E3 100%); padding: 30px; border-radius: 8px 8px 0 0; color: white; text-align: center;">
            <h1 style="margin: 0; font-size: 24px;">Password Reset Request</h1>
        </div>
        
        <div style="background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; border: 1px solid #e5e7eb; border-top: none;">
            <p style="margin-top: 0;">Hi <strong>{$fullName}</strong>,</p>
            
            <p>We received a request to reset your password. If this was you, click the button below to create a new password.</p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="{$resetUrl}" style="background: #6C63FF; color: white; padding: 12px 30px; border-radius: 6px; text-decoration: none; font-weight: 600; display: inline-block;">
                    Reset Your Password
                </a>
            </div>
            
            <p style="color: #666; font-size: 14px;">
                Or copy and paste this link in your browser:<br>
                <code style="background: white; padding: 2px 6px; border-radius: 3px; word-break: break-all;">{$resetUrl}</code>
            </p>
            
            <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 4px; margin: 20px 0; font-size: 14px;">
                <p style="margin: 0;"><strong>Important:</strong> This link will expire in <strong>{$expiryTime}</strong>.</p>
            </div>
            
            <p style="color: #666; font-size: 14px; margin-bottom: 0;">
                If you didn't request a password reset, please ignore this email. Your password will remain unchanged.
            </p>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
