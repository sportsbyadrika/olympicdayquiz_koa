<?php
/**
 * LOCAL CONFIG TEMPLATE
 *
 * 1. Copy this file to  config/local.php  on the server (one time).
 * 2. Fill in your real database + SMTP credentials.
 * 3. config/local.php is gitignored and is NOT deployed by .cpanel.yml,
 *    so it is never overwritten when you pull / deploy through cPanel.
 *
 * Any value left blank falls back to an environment variable, then to a
 * built-in default.
 */

return [
    // Application
    'app_env'  => 'production',   // 'production' or 'development'
    'base_url' => '',             // e.g. '' if served at the domain root

    // Database
    'db' => [
        'host' => 'localhost',
        'port' => '3306',
        'name' => 'olympicd_quiz',
        'user' => 'olympicd_quizuser',
        'pass' => 'CHANGE_ME',
    ],

    // SMTP / mail (PHPMailer)
    'mail' => [
        'host'       => 'mail.yourdomain.com',
        'port'       => '587',
        'username'   => 'no-reply@yourdomain.com',
        'password'   => 'CHANGE_ME',
        'encryption' => 'tls',     // 'tls' | 'ssl' | ''
        'from_email' => 'no-reply@yourdomain.com',
        'from_name'  => 'Olympic Day Quiz 2026',
    ],
];
