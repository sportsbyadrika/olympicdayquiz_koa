<?php
/**
 * PDO database connection (MySQL 8.x).
 * All access elsewhere uses prepared statements via this handle.
 */

declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/settings.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = cfg('db', 'host', 'DB_HOST', '127.0.0.1');
    $port = cfg('db', 'port', 'DB_PORT', '3306');
    $name = cfg('db', 'name', 'DB_NAME', 'olympicday_quiz');
    $user = cfg('db', 'user', 'DB_USER', 'root');
    $pass = cfg('db', 'pass', 'DB_PASS', '');

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        http_response_code(500);
        if (APP_ENV === 'development') {
            die('Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES));
        }
        die('A database error occurred. Please try again later.');
    }

    return $pdo;
}
