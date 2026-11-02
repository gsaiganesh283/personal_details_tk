<?php
/**
 * Error Handler & Debug Helper
 * Catches and logs errors for blank page debugging
 */

class ApplicationErrorHandler {
    private static $errors = [];
    private static $isDebugging = false;
    
    public static function initialize() {
        // Detect if we're in a test/debug environment
        self::$isDebugging = (
            isset($_GET['_debug']) || 
            in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'])
        );
        
        // Set error handler
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        
        // Shutdown function to catch fatal errors
        register_shutdown_function([self::class, 'handleShutdown']);
    }
    
    public static function handleError($errno, $errstr, $errfile, $errline) {
        $error = [
            'type' => self::getErrorType($errno),
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
            'time' => date('Y-m-d H:i:s'),
            'url' => $_SERVER['REQUEST_URI'] ?? ''
        ];
        
        self::$errors[] = $error;
        self::logError($error);
        
        // Only display error details in debug mode
        if (self::$isDebugging && headers_sent() === false) {
            self::outputDebugError($error);
            return false;
        }
        
        // Log to file in production
        if (!self::$isDebugging) {
            // Continue execution but log the error
        }
        
        return false;
    }
    
    public static function handleException($exception) {
        $error = [
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'time' => date('Y-m-d H:i:s'),
            'url' => $_SERVER['REQUEST_URI'] ?? '',
            'trace' => $exception->getTraceAsString()
        ];
        
        self::$errors[] = $error;
        self::logError($error);

        if (!self::$isDebugging && self::tryRecoverPostRequest($error['message'])) {
            return;
        }
        
        if (self::$isDebugging) {
            self::outputDebugError($error);
        }
    }
    
    public static function handleShutdown() {
        $lastError = error_get_last();
        if ($lastError && in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $error = [
                'type' => self::getErrorType($lastError['type']),
                'message' => $lastError['message'],
                'file' => $lastError['file'],
                'line' => $lastError['line'],
                'time' => date('Y-m-d H:i:s'),
                'url' => $_SERVER['REQUEST_URI'] ?? '',
                'isFatal' => true
            ];
            
            self::$errors[] = $error;
            self::logError($error);

            if (!self::$isDebugging && self::tryRecoverPostRequest('A server error occurred while saving your data.')) {
                return;
            }
            
            if (self::$isDebugging) {
                self::outputDebugError($error);
            }
        }
    }
    
    private static function getErrorType($errno) {
        $types = [
            E_ERROR => 'Error',
            E_WARNING => 'Warning',
            E_PARSE => 'Parse Error',
            E_NOTICE => 'Notice',
            E_CORE_ERROR => 'Core Error',
            E_CORE_WARNING => 'Core Warning',
            E_COMPILE_ERROR => 'Compile Error',
            E_COMPILE_WARNING => 'Compile Warning',
            E_USER_ERROR => 'User Error',
            E_USER_WARNING => 'User Warning',
            E_USER_NOTICE => 'User Notice',
            E_STRICT => 'Strict Notice',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
            E_DEPRECATED => 'Deprecated',
            E_USER_DEPRECATED => 'User Deprecated',
        ];
        return $types[$errno] ?? 'Unknown Error';
    }
    
    private static function logError($error) {
        $logFile = __DIR__ . '/../error.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $message = sprintf(
            "[%s] %s: %s in %s on line %d\nURL: %s\n%s\n---\n",
            $error['time'],
            $error['type'],
            $error['message'],
            $error['file'],
            $error['line'],
            $error['url'],
            isset($error['trace']) ? "Trace: " . $error['trace'] : ''
        );
        
        @file_put_contents($logFile, $message, FILE_APPEND);
    }
    
    private static function outputDebugError($error) {
        echo '<div style="background: #ffebee; border: 2px solid #f44336; padding: 20px; margin: 10px; color: #c62828; font-family: monospace; border-radius: 4px;">';
        echo '<strong style="font-size: 1.2em;">⚠️ ' . htmlspecialchars($error['type']) . '</strong><br>';
        echo '<br><strong>Message:</strong> ' . htmlspecialchars($error['message']) . '<br>';
        echo '<strong>File:</strong> ' . htmlspecialchars($error['file']) . ':' . $error['line'] . '<br>';
        echo '<strong>Time:</strong> ' . htmlspecialchars($error['time']) . '<br>';
        echo '<strong>URL:</strong> ' . htmlspecialchars($error['url']) . '<br>';
        if (isset($error['trace'])) {
            echo '<strong>Trace:</strong><pre style="overflow-x: auto; background: #fff9c4; padding: 10px;">' . htmlspecialchars($error['trace']) . '</pre>';
        }
        echo '</div>';
    }

    private static function tryRecoverPostRequest($errorMessage) {
        if (php_sapi_name() === 'cli') {
            return false;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return false;
        }

        if (self::isAjaxRequest() || self::isApiRequest()) {
            return false;
        }

        $redirectUrl = self::getRecoveryRedirectUrl();

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['flash']['error'] = 'Save failed: ' . $errorMessage;
        }

        if (!headers_sent()) {
            while (ob_get_level()) {
                @ob_end_clean();
            }
            header('Location: ' . $redirectUrl);
            exit;
        }

        $safeUrl = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
        echo '<script>window.location.href=' . json_encode($redirectUrl) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . $safeUrl . '"></noscript>';
        exit;
    }

    private static function isAjaxRequest() {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    private static function isApiRequest() {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if (strpos($script, 'api-') === 0) {
            return true;
        }

        $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
        return strpos($accept, 'application/json') !== false;
    }

    private static function getRecoveryRedirectUrl() {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (!empty($referer)) {
            return $referer;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = strtok($uri, '?');
        return $path ?: '/';
    }
    
    public static function getErrors() {
        return self::$errors;
    }
    
    public static function hasErrors() {
        return !empty(self::$errors);
    }
}

// Initialize the error handler
ApplicationErrorHandler::initialize();
