<?php
/**
 * Global application settings / constants.
 * Olympic Day Celebrations 2026 — Sports Quiz Competition
 */

declare(strict_types=1);

// Application
define('APP_NAME', 'Olympic Day Celebrations 2026 — Sports Quiz Competition');
define('APP_SHORT_NAME', 'Olympic Day Quiz 2026');
define('APP_ORG', 'Kerala Olympic Association');

require_once __DIR__ . '/env.php';

// Environment: 'development' or 'production'
define('APP_ENV', cfg(null, 'app_env', 'APP_ENV', 'development'));

// Base URL path (adjust if app is served from a subfolder)
define('BASE_URL', rtrim((string) cfg(null, 'base_url', 'BASE_URL', ''), '/'));

// Timezone — competition is conducted in India
date_default_timezone_set('Asia/Kolkata');

// Cookie/session security flags. Secure cookies only over HTTPS.
$__https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
);
define('COOKIE_SECURE', $__https);

// Theme palette (PROJECT_SPEC.md section 2)
define('COLOR_PRIMARY', '#1A2B49'); // navy
define('COLOR_ACCENT', '#00897B');  // teal
define('COLOR_LIGHT', '#F5F7FA');   // neutral light grey
define('COLOR_TEXT', '#333333');    // text dark grey

// Uploads directory (outside web root where hosting allows).
define('UPLOAD_DIR', dirname(__DIR__) . '/uploads');
