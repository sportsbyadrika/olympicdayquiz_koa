<?php
/**
 * SMTP / mail configuration for PHPMailer.
 *
 * Values resolve from config/local.php → environment → defaults (see env.php).
 * PHPMailer is expected at /includes/PHPMailer/ (composer or manual install).
 * If it is not present, helpers fall back to PHP mail() in development.
 */

declare(strict_types=1);

require_once __DIR__ . '/env.php';

return [
    'host'       => cfg('mail', 'host', 'SMTP_HOST', 'localhost'),
    'port'       => (int) cfg('mail', 'port', 'SMTP_PORT', '587'),
    'username'   => cfg('mail', 'username', 'SMTP_USER', ''),
    'password'   => cfg('mail', 'password', 'SMTP_PASS', ''),
    'encryption' => cfg('mail', 'encryption', 'SMTP_ENCRYPTION', 'tls'), // 'tls' | 'ssl' | ''
    'from_email' => cfg('mail', 'from_email', 'SMTP_FROM', 'no-reply@olympicday2026.test'),
    'from_name'  => cfg('mail', 'from_name', 'SMTP_FROM_NAME', 'Olympic Day Quiz 2026'),
];
