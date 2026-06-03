<?php
/**
 * CSRF token generation and validation.
 * Tokens are stored per-session and validated on every state-changing request.
 */

declare(strict_types=1);

/** Ensure a CSRF token exists for this session and return it. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Render a hidden CSRF input for inclusion in forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/** Validate a submitted token in constant time. */
function csrf_validate(?string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Enforce CSRF on POST/PUT/DELETE requests. Reads token from POST field
 * `csrf_token` or the `X-CSRF-Token` header (for AJAX). Aborts on failure.
 */
function csrf_check(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        return;
    }
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!csrf_validate($token)) {
        http_response_code(419);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid or expired CSRF token.']);
        } else {
            echo 'Invalid or expired CSRF token. Please reload the page and try again.';
        }
        exit;
    }
}
