<?php
/**
 * User account helpers shared by admin CRUD pages.
 * Creating a role (association / expert / school) always means creating a
 * `users` row plus the matching profile row in a single transaction.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** Does a user with this email already exist? */
function email_exists(string $email, ?int $exceptUserId = null): bool
{
    $sql = 'SELECT id FROM users WHERE email = :email';
    $params = [':email' => $email];
    if ($exceptUserId !== null) {
        $sql .= ' AND id <> :uid';
        $params[':uid'] = $exceptUserId;
    }
    $stmt = db()->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    return (bool) $stmt->fetch();
}

/** Create a users row, returning the new user id. */
function create_user(string $email, string $plainPassword, string $role, string $status = 'active'): int
{
    $stmt = db()->prepare(
        'INSERT INTO users (email, password_hash, role, status)
         VALUES (:email, :hash, :role, :status)'
    );
    $stmt->execute([
        ':email'  => $email,
        ':hash'   => password_hash($plainPassword, PASSWORD_BCRYPT),
        ':role'   => $role,
        ':status' => $status,
    ]);
    return (int) db()->lastInsertId();
}

/** Update a user's login email. Returns false if the email is taken. */
function update_user_email(int $userId, string $email): bool
{
    if (email_exists($email, $userId)) {
        return false;
    }
    db()->prepare('UPDATE users SET email = :e WHERE id = :id')->execute([':e' => $email, ':id' => $userId]);
    return true;
}

/** Update a user's password (bcrypt). */
function set_user_password(int $userId, string $plainPassword): void
{
    $stmt = db()->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
    $stmt->execute([':h' => password_hash($plainPassword, PASSWORD_BCRYPT), ':id' => $userId]);
}

/**
 * Mark exactly one association as the event conductor.
 * Clears the flag on all others inside a transaction.
 */
function set_event_conductor(int $associationId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE associations SET is_event_conductor = 0');
        $stmt = $pdo->prepare('UPDATE associations SET is_event_conductor = 1 WHERE id = :id');
        $stmt->execute([':id' => $associationId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
