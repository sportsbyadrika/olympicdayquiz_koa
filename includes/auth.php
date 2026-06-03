<?php
/**
 * Authentication & authorization.
 * - Secure session bootstrap
 * - Login / logout
 * - Role-based access control: require_login(), require_role()
 * - Current-user helpers and role-profile loading
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/settings.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';

/** Start a hardened session exactly once. */
function auth_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => COOKIE_SECURE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('ODQSESSID');
    session_start();
}

/** Roles and their landing dashboards. */
function role_dashboard(string $role): string
{
    return match ($role) {
        'admin'       => '/admin/index.php',
        'association' => '/association/index.php',
        'expert'      => '/expert/index.php',
        'school'      => '/school/index.php',
        default       => '/public/login.php',
    };
}

/**
 * Attempt login. On success regenerates the session id, stores identity,
 * and returns the user row. Returns null on failure.
 */
function attempt_login(string $email, string $password): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        audit_log('login_failed', 'users', $user['id'] ?? null, 'email=' . $email);
        return null;
    }
    if (($user['status'] ?? 'inactive') !== 'active') {
        audit_log('login_blocked_inactive', 'users', $user['id'], 'email=' . $email);
        return null;
    }

    // Rehash if the algorithm/cost has improved.
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
        $newHash = password_hash($password, PASSWORD_BCRYPT);
        $upd = db()->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
        $upd->execute([':h' => $newHash, ':id' => $user['id']]);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['email']   = $user['email'];
    $_SESSION['role']    = $user['role'];
    $_SESSION['login_at'] = time();

    audit_log('login_success', 'users', $user['id'], 'email=' . $email);
    return $user;
}

/** Log out and destroy the session. */
function logout(): void
{
    audit_log('logout', 'users', $_SESSION['user_id'] ?? null);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** True if a user is logged in. */
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

/** Current user role or null. */
function current_role(): ?string
{
    return $_SESSION['role'] ?? null;
}

/** Current user id or null. */
function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/** Require an authenticated user or redirect to login. */
function require_login(): void
{
    auth_boot();
    if (!is_logged_in()) {
        redirect('/public/login.php');
    }
}

/**
 * Require one of the given roles. Redirects unauthenticated users to login
 * and authenticated-but-wrong-role users to their own dashboard.
 */
function require_role(string ...$roles): void
{
    require_login();
    if (!in_array(current_role(), $roles, true)) {
        // Send the user to their own dashboard rather than leaking a 403 page.
        redirect(role_dashboard(current_role() ?? ''));
    }
}

/** Load the role-specific profile row (association/school/expert) for current user. */
function current_profile(): ?array
{
    $role = current_role();
    $uid  = current_user_id();
    if (!$role || !$uid) {
        return null;
    }
    $table = match ($role) {
        'association' => 'associations',
        'school'      => 'schools',
        'expert'      => 'experts',
        default       => null,
    };
    if ($table === null) {
        return null;
    }
    $stmt = db()->prepare("SELECT * FROM {$table} WHERE user_id = :uid LIMIT 1");
    $stmt->execute([':uid' => $uid]);
    return $stmt->fetch() ?: null;
}
