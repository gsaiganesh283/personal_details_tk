<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

$auth = new Auth();
$mailer = new Mailer();

if ($auth->isLoggedIn()) {
    redirect('/dashboard.php');
}

$errors = [];
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'full_name' => sanitize($_POST['full_name'] ?? ''),
        'username' => sanitize($_POST['username'] ?? ''),
        'email' => sanitize($_POST['email'] ?? ''),
    ];
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match';
    }

    if (!isset($_POST['terms'])) {
        $errors[] = 'You must accept the Terms & Conditions';
    }

    if (empty($errors)) {
        $result = $auth->register($formData['username'], $formData['email'], $password, $formData['full_name']);
        if ($result['success']) {
            // Send welcome email
            $mailer->sendWelcome($formData['email'], $formData['full_name'], $formData['username']);
            
            setFlash('success', 'Account created successfully! Please sign in.');
            redirect('/login.php');
        } else {
            $errors = $result['errors'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#6C63FF">
    <title>Register | Personal Details Saver</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('assets/css/responsive.css'); ?>">
</head>
<body class="auth-page">

<div class="auth-particles"></div>

<div class="auth-container" style="max-width: 520px;">
    <div class="auth-card">
        <div class="auth-header">
            <div class="auth-logo">
                <i class="fas fa-user-plus"></i>
            </div>
            <h1>Create Account</h1>
            <p>Fill in the details to get started</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error animate-slide-in">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo implode('<br>', $errors); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="registerForm">
            <div class="form-group">
                <label>Full Name <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="full_name" placeholder="Enter your full name" 
                           value="<?php echo htmlspecialchars($formData['full_name'] ?? ''); ?>" required>
                    <i class="fas fa-user"></i>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Username <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="text" name="username" placeholder="Choose a username" 
                               value="<?php echo htmlspecialchars($formData['username'] ?? ''); ?>" required>
                        <i class="fas fa-at"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="email" name="email" placeholder="Enter your email" 
                               value="<?php echo htmlspecialchars($formData['email'] ?? ''); ?>" required>
                        <i class="fas fa-envelope"></i>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Password <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" name="password" id="password" placeholder="Create a password" required>
                        <i class="fas fa-lock"></i>
                        <span class="toggle-password" tabindex="0" role="button" aria-label="Toggle password visibility"><i class="fas fa-eye"></i></span>
                    </div>
                    <div class="password-strength">
                        <div class="strength-bar">
                            <div class="strength-segment"></div>
                            <div class="strength-segment"></div>
                            <div class="strength-segment"></div>
                            <div class="strength-segment"></div>
                            <div class="strength-segment"></div>
                        </div>
                        <span class="strength-text"></span>
                    </div>
                </div>

                <div class="form-group">
                    <label>Confirm Password <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" name="confirm_password" placeholder="Confirm your password" required>
                        <i class="fas fa-lock"></i>
                        <span class="toggle-password" tabindex="0" role="button" aria-label="Toggle password visibility"><i class="fas fa-eye"></i></span>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-check">
                    <input type="checkbox" name="terms" required>
                    <span class="checkmark"></span>
                    <span class="form-check-label">I agree to the <a href="#">Terms & Conditions</a> and <a href="#">Privacy Policy</a></span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary btn-full btn-lg">
                <span class="btn-text">Create Account</span>
                <i class="fas fa-arrow-right"></i>
            </button>
        </form>

        <!-- <div class="auth-divider">or sign up with</div>

        <div class="social-btns">
            <a href="<?php echo url('oauth-login.php?provider=google'); ?>" class="btn-social" title="Sign up with Google"><i class="fab fa-google"></i></a>
            <a href="<?php echo url('oauth-login.php?provider=github'); ?>" class="btn-social" title="Sign up with GitHub"><i class="fab fa-github"></i></a>
            <a href="<?php echo url('oauth-login.php?provider=apple'); ?>" class="btn-social" title="Sign up with Apple"><i class="fab fa-apple"></i></a>
        </div> -->

        <div class="auth-footer">
            Already have an account? <a href="<?php echo url('login.php'); ?>">Sign in</a>
        </div>
    </div>
</div>

<script src="<?php echo url('assets/js/app.js'); ?>"></script>
</body>
</html>
