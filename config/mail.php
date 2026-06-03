<?php
/**
 * SMTP / mail configuration for PHPMailer.
 *
 * PHPMailer is expected at /includes/PHPMailer/ (composer or manual install).
 * If it is not present, helpers fall back to PHP mail() in development.
 */

declare(strict_types=1);

return [
    'host'       => getenv('SMTP_HOST') ?: 'localhost',
    'port'       => (int) (getenv('SMTP_PORT') ?: 587),
    'username'   => getenv('SMTP_USER') ?: '',
    'password'   => getenv('SMTP_PASS') ?: '',
    'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls', // 'tls' | 'ssl' | ''
    'from_email' => getenv('SMTP_FROM') ?: 'no-reply@olympicday2026.test',
    'from_name'  => 'Olympic Day Quiz 2026',
];
