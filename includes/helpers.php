<?php
/**
 * Shared helper functions: sanitization, escaping, redirects, audit logging,
 * settings access, flash messages, and mail.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/settings.php';
require_once dirname(__DIR__) . '/config/db.php';

/** Escape a value for safe HTML output. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Trim and collapse a scalar input to a clean string. */
function clean(?string $value): string
{
    return trim((string) ($value ?? ''));
}

/** Fetch a trimmed POST value. */
function post(string $key, string $default = ''): string
{
    return isset($_POST[$key]) && is_scalar($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

/** Fetch a trimmed GET value. */
function get(string $key, string $default = ''): string
{
    return isset($_GET[$key]) && is_scalar($_GET[$key]) ? trim((string) $_GET[$key]) : $default;
}

/** Cast a value to a positive integer (or 0). */
function int_val($value): int
{
    return (int) $value > 0 ? (int) $value : 0;
}

/** Validate an email address. */
function is_email(string $value): bool
{
    return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
}

/** Redirect to a path (relative paths are prefixed with BASE_URL) and exit. */
function redirect(string $path): void
{
    if (!preg_match('#^https?://#', $path)) {
        $path = BASE_URL . $path;
    }
    header('Location: ' . $path);
    exit;
}

/** Send a JSON response and exit. */
function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/** Client IP best-effort. */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Write an entry to the audit log. */
function audit_log(string $action, ?string $entity = null, $entityId = null, ?string $details = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_log (user_id, action, entity, entity_id, details, ip, user_agent)
             VALUES (:uid, :action, :entity, :entity_id, :details, :ip, :ua)'
        );
        $stmt->execute([
            ':uid'       => $_SESSION['user_id'] ?? null,
            ':action'    => $action,
            ':entity'    => $entity,
            ':entity_id' => $entityId !== null ? (string) $entityId : null,
            ':details'   => $details !== null ? mb_substr($details, 0, 500) : null,
            ':ip'        => client_ip(),
            ':ua'        => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) {
        // Never let audit logging break the request.
    }
}

/** Read a setting value with a fallback default. */
function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}

/** Set a flash message shown once on the next page render. */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Pull and clear all flash messages. */
function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** Render flash messages as Tailwind alert blocks. */
function render_flashes(): string
{
    $out = '';
    foreach (take_flashes() as $f) {
        $map = [
            'success' => 'bg-green-50 text-green-800 border-green-200',
            'error'   => 'bg-red-50 text-red-800 border-red-200',
            'info'    => 'bg-blue-50 text-blue-800 border-blue-200',
            'warning' => 'bg-amber-50 text-amber-800 border-amber-200',
        ];
        $cls = $map[$f['type']] ?? $map['info'];
        $out .= '<div class="border rounded-lg px-4 py-3 mb-3 text-sm ' . $cls . '">' . e($f['message']) . '</div>';
    }
    return $out;
}

/** Generate a random, human-friendly password. */
function generate_password(int $length = 10): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $special  = '!@#$%&*';
    $out = '';
    for ($i = 0; $i < $length - 1; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    $out .= $special[random_int(0, strlen($special) - 1)];
    return $out;
}

/** Render a small status badge. */
function status_badge(string $status): string
{
    $s = strtolower($status);
    $green  = ['answered', 'submitted', 'accepted', 'active', 'declared', 'qualified'];
    $amber  = ['draft', 'in_progress', 'pending'];
    $grey   = ['unanswered', 'inactive', 'not_started'];
    $red    = ['rejected', 'force_submitted'];

    if (in_array($s, $green, true))      $cls = 'bg-green-100 text-green-800';
    elseif (in_array($s, $amber, true))  $cls = 'bg-amber-100 text-amber-800';
    elseif (in_array($s, $red, true))    $cls = 'bg-red-100 text-red-800';
    else                                 $cls = 'bg-gray-100 text-gray-700';

    $label = ucwords(str_replace('_', ' ', $s));
    return '<span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium ' . $cls . '">' . e($label) . '</span>';
}

/**
 * Send an email. Uses PHPMailer if available, otherwise falls back to mail().
 * Returns true on success.
 */
function send_email(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): bool
{
    $cfg = require dirname(__DIR__) . '/config/mail.php';

    $phpmailer = dirname(__DIR__) . '/includes/PHPMailer/PHPMailer.php';
    if (file_exists($phpmailer)) {
        require_once dirname(__DIR__) . '/includes/PHPMailer/PHPMailer.php';
        require_once dirname(__DIR__) . '/includes/PHPMailer/SMTP.php';
        require_once dirname(__DIR__) . '/includes/PHPMailer/Exception.php';
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $cfg['host'];
            $mail->Port       = $cfg['port'];
            if ($cfg['username'] !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $cfg['username'];
                $mail->Password = $cfg['password'];
            }
            if ($cfg['encryption'] !== '') {
                $mail->SMTPSecure = $cfg['encryption'];
            }
            $mail->setFrom($cfg['from_email'], $cfg['from_name']);
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody !== '' ? $textBody : strip_tags($htmlBody);
            $mail->send();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // Fallback: native mail()
    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-type: text/html; charset=utf-8' . "\r\n";
    $headers .= 'From: ' . $cfg['from_name'] . ' <' . $cfg['from_email'] . '>' . "\r\n";
    return @mail($toEmail, $subject, $htmlBody, $headers);
}
