<?php
/**
 * Regenerate fresh bcrypt password hashes for the seed accounts and print
 * UPDATE statements you can run against your database.
 *
 * Usage:  php database/seeds.php [password]
 *
 * Default seed password is "Password@123" — change it in production.
 */

declare(strict_types=1);

$password = $argv[1] ?? 'Password@123';

$accounts = [
    'admin@olympicday2026.test',
    'association@olympicday2026.test',
    'expert@olympicday2026.test',
    'school1@olympicday2026.test',
    'school2@olympicday2026.test',
];

echo "-- Seed password: {$password}\n";
foreach ($accounts as $email) {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    printf("UPDATE users SET password_hash = '%s' WHERE email = '%s';\n", $hash, $email);
}
