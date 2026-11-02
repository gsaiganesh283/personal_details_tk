<?php
/**
 * WebAuthn loader with fallback for shared hosting.
 *
 * Order:
 * 1) Composer autoload (vendor/autoload.php)
 * 2) Bundled library at includes/webauthn/src
 *
 * Returns bool true when \lbuchs\WebAuthn\WebAuthn is available.
 */

if (!class_exists('\\lbuchs\\WebAuthn\\WebAuthn', false)) {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
}

if (!class_exists('\\lbuchs\\WebAuthn\\WebAuthn', false)) {
    $bundleRoot = __DIR__ . '/webauthn/src';
    $entryPoint = $bundleRoot . '/WebAuthn.php';

    if (is_file($entryPoint)) {
        $previousIncludePath = get_include_path();
        set_include_path($bundleRoot . PATH_SEPARATOR . $previousIncludePath);
        require_once $entryPoint;
        set_include_path($previousIncludePath);
    }
}

return class_exists('\\lbuchs\\WebAuthn\\WebAuthn', false);
