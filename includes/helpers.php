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

/** Build an absolute URL to an app path (e.g. '/public/login.php'). */
function app_url(string $path = ''): string
{
    $scheme = COOKIE_SECURE ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . BASE_URL . $path;
}

/**
 * Validate an uploaded CSV file. Returns an error message string, or null if OK.
 * Resilient on hosts where the fileinfo extension is unavailable (the MIME
 * sniff is skipped rather than fataling), and tolerant of the various MIME
 * types browsers/Excel report for CSV.
 */
function validate_csv_upload(?array $file, int $maxBytes = 2097152): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'No file uploaded or upload error.';
    }
    if (($file['size'] ?? 0) > $maxBytes) {
        return 'File exceeds the ' . round($maxBytes / 1048576) . ' MB limit.';
    }
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        return 'Please upload a .csv file.';
    }
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = @finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $allowed = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'];
            if ($mime !== false && $mime !== '' && !in_array($mime, $allowed, true)) {
                return "Unexpected file type ({$mime}). Please upload a plain CSV.";
            }
        }
    }
    return null;
}

/** Shared site footer markup (used by footer.php and standalone pages). */
function site_footer_html(): string
{
    return '<footer class="bg-navy text-white/80 mt-8">'
        . '<div class="max-w-7xl mx-auto px-4 py-6 text-sm grid gap-2 sm:grid-cols-3 items-center text-center">'
        . '<div class="sm:text-left">&copy; ' . date('Y') . ' '
        . '<a href="https://keralaolympic.org/olympicday-run2026.php" target="_blank" rel="noopener noreferrer" class="hover:text-white underline-offset-2 hover:underline">' . e(APP_ORG) . '</a>'
        . '</div>'
        . '<div class="font-medium text-white/90">' . e(APP_NAME) . '</div>'
        . '<div class="sm:text-right">'
        . '<a href="https://sportsmis.com" target="_blank" rel="noopener noreferrer" class="hover:text-white underline-offset-2 hover:underline">Software by SportsMIS.com&reg;</a>'
        . '</div>'
        . '</div>'
        . '</footer>';
}

/** Email login credentials (email + new password) to a user. Returns true if sent. */
function email_login_credentials(string $toEmail, string $toName, string $password): bool
{
    $loginUrl = app_url('/public/login.php');
    $subject = 'Your login & password — Olympic Day Quiz 2026';
    $html = '<div style="font-family:Arial,sans-serif;color:#333">'
        . '<h2 style="color:#1A2B49">Olympic Day Celebrations 2026 — Sports Quiz</h2>'
        . '<p>Dear ' . e($toName) . ',</p>'
        . '<p>Your login credentials have been (re)set:</p>'
        . '<table style="border-collapse:collapse">'
        . '<tr><td style="padding:4px 12px 4px 0"><b>Login URL</b></td><td><a href="' . e($loginUrl) . '">' . e($loginUrl) . '</a></td></tr>'
        . '<tr><td style="padding:4px 12px 4px 0"><b>Email</b></td><td>' . e($toEmail) . '</td></tr>'
        . '<tr><td style="padding:4px 12px 4px 0"><b>Password</b></td><td>' . e($password) . '</td></tr>'
        . '</table>'
        . '<p style="color:#888;font-size:12px">For security, please change your password after logging in (My Account).</p>'
        . '</div>';
    return send_email($toEmail, $toName, $subject, $html);
}

/**
 * Best-effort region/location for an IP address using a free geolocation API.
 * Returns a short "City, Region, Country" string, or null when unavailable
 * (private/reserved IPs, no network, or lookup failure). Cached per request.
 */
function ip_region(?string $ip): ?string
{
    static $cache = [];
    $ip = (string) $ip;
    if ($ip === '' || array_key_exists($ip, $cache)) {
        return $cache[$ip] ?? null;
    }
    // Skip private/reserved addresses — no meaningful geo data.
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return $cache[$ip] = null;
    }
    if (!function_exists('curl_init')) {
        return $cache[$ip] = null;
    }
    $ch = curl_init('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,regionName,city');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 2,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!$body) {
        return $cache[$ip] = null;
    }
    $data = json_decode($body, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
        return $cache[$ip] = null;
    }
    $parts = array_filter([$data['city'] ?? '', $data['regionName'] ?? '', $data['country'] ?? '']);
    return $cache[$ip] = $parts ? implode(', ', $parts) : null;
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

/** Build pagination links preserving current query parameters. */
function paginate_links(int $page, int $pages): string
{
    if ($pages <= 1) {
        return '';
    }
    $url = function (int $p): string {
        $qs = http_build_query(array_merge($_GET, ['page' => $p]));
        return '?' . htmlspecialchars($qs, ENT_QUOTES);
    };
    $btn = function (string $href, string $label, bool $active = false, bool $disabled = false): string {
        if ($disabled) {
            return '<span class="px-3 py-2 rounded-lg text-sm bg-white border border-gray-100 text-gray-300">' . $label . '</span>';
        }
        $cls = $active
            ? 'bg-navy text-white'
            : 'bg-white border border-gray-200 text-gray-600 hover:bg-lightgrey';
        return '<a href="' . $href . '" class="px-3 py-2 rounded-lg text-sm ' . $cls . '">' . $label . '</a>';
    };

    // Windowed page range around the current page.
    $window = 2;
    $start = max(1, $page - $window);
    $end = min($pages, $page + $window);

    $out = '<nav class="flex flex-wrap items-center justify-center gap-1 mt-4">';
    $out .= $btn($url(max(1, $page - 1)), '&larr; Prev', false, $page <= 1);
    if ($start > 1) {
        $out .= $btn($url(1), '1');
        if ($start > 2) {
            $out .= '<span class="px-2 text-gray-400">…</span>';
        }
    }
    for ($p = $start; $p <= $end; $p++) {
        $out .= $btn($url($p), (string) $p, $p === $page);
    }
    if ($end < $pages) {
        if ($end < $pages - 1) {
            $out .= '<span class="px-2 text-gray-400">…</span>';
        }
        $out .= $btn($url($pages), (string) $pages);
    }
    $out .= $btn($url(min($pages, $page + 1)), 'Next &rarr;', false, $page >= $pages);
    $out .= '</nav>';
    return $out;
}

/**
 * Render a compact last-login indicator: a green check if the user has logged
 * in (with the date/time as a hover tooltip), or a red cross if never.
 */
function last_login_badge(?string $dt): string
{
    if ($dt) {
        $when = date('d M Y, H:i', strtotime($dt));
        return '<span title="Last login: ' . e($when) . '" class="inline-flex text-green-600 cursor-default">'
            . '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
            . '</span>';
    }
    return '<span title="Never logged in" class="inline-flex text-red-500 cursor-default">'
        . '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
        . '</span>';
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
