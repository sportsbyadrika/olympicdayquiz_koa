<?php
/**
 * AJAX · Online status dashboard data (admin + expert).
 *   ?data=stats                         → summary numbers, hourly login buckets, per-slot rows
 *   ?data=list&metric=<m>[&slot_id=N]   → drill-down list of schools for a metric
 *
 * Metrics: logged_in | not_logged_in | started | completed | in_progress | assigned
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
if (!in_array(current_role(), ['admin', 'expert'], true)) {
    json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
}

$data = get('data', 'stats');

if ($data === 'stats') {
    $pdo = db();

    $total = (int) $pdo->query('SELECT COUNT(*) FROM schools')->fetchColumn();

    $loggedIn = (int) $pdo->query(
        "SELECT COUNT(*) FROM schools s JOIN users u ON u.id = s.user_id
         WHERE EXISTS (SELECT 1 FROM audit_log a WHERE a.user_id = u.id AND a.action = 'login_success')"
    )->fetchColumn();
    $notLoggedIn = $total - $loggedIn;

    $started = (int) $pdo->query('SELECT COUNT(DISTINCT school_id) FROM quiz_sessions')->fetchColumn();
    $completed = (int) $pdo->query(
        "SELECT COUNT(DISTINCT school_id) FROM quiz_sessions WHERE status IN ('submitted','force_submitted')"
    )->fetchColumn();
    $inProgress = (int) $pdo->query(
        "SELECT COUNT(DISTINCT school_id) FROM quiz_sessions WHERE status = 'in_progress'"
    )->fetchColumn();

    // Hourly buckets of each school's LAST login time.
    $bucketRows = $pdo->query(
        "SELECT DATE_FORMAT(ll.last_login, '%Y-%m-%d %H:00') AS bucket, COUNT(*) AS c
         FROM (
            SELECT u.id AS uid, MAX(a.created_at) AS last_login
            FROM users u
            JOIN schools s ON s.user_id = u.id
            JOIN audit_log a ON a.user_id = u.id AND a.action = 'login_success'
            GROUP BY u.id
         ) ll
         GROUP BY bucket ORDER BY bucket"
    )->fetchAll();
    $buckets = [];
    foreach ($bucketRows as $b) {
        $ts = strtotime((string) $b['bucket']);
        $buckets[] = ['label' => date('d M, H:i', $ts), 'count' => (int) $b['c']];
    }

    // Per-slot started / completed (assigned for context).
    $slotRows = $pdo->query(
        "SELECT sl.id, sl.slot_name, r.round_no,
            (SELECT COUNT(*) FROM slot_schools ss WHERE ss.slot_id = sl.id) AS assigned,
            (SELECT COUNT(DISTINCT qs.school_id) FROM quiz_sessions qs WHERE qs.slot_id = sl.id) AS started,
            (SELECT COUNT(DISTINCT qs.school_id) FROM quiz_sessions qs WHERE qs.slot_id = sl.id AND qs.status IN ('submitted','force_submitted')) AS completed
         FROM slots sl JOIN rounds r ON r.id = sl.round_id
         ORDER BY r.round_no, sl.start_time"
    )->fetchAll();
    $slots = array_map(fn($s) => [
        'slot_id'  => (int) $s['id'],
        'name'     => $s['slot_name'],
        'round_no' => (int) $s['round_no'],
        'assigned' => (int) $s['assigned'],
        'started'  => (int) $s['started'],
        'completed' => (int) $s['completed'],
    ], $slotRows);

    json_response([
        'ok' => true,
        'totals' => [
            'schools' => $total, 'logged_in' => $loggedIn, 'not_logged_in' => $notLoggedIn,
            'started' => $started, 'completed' => $completed, 'in_progress' => $inProgress,
        ],
        'buckets' => $buckets,
        'slots' => $slots,
        'server_time' => date('d M Y, H:i:s'),
    ]);
}

// ----- Drill-down lists -----
$metric = get('metric');
$slotId = int_val(get('slot_id'));
$pdo = db();
$title = '';
$headers = [];
$rows = [];

switch ($metric) {
    case 'logged_in':
        $title = 'Schools that have logged in';
        $headers = ['School', 'Code', 'Email', 'Last login'];
        $st = $pdo->query(
            "SELECT s.name, s.code, u.email, MAX(a.created_at) AS last_login
             FROM schools s JOIN users u ON u.id = s.user_id
             JOIN audit_log a ON a.user_id = u.id AND a.action = 'login_success'
             GROUP BY s.id, s.name, s.code, u.email ORDER BY last_login DESC"
        );
        foreach ($st->fetchAll() as $r) {
            $rows[] = [$r['name'], $r['code'], $r['email'], date('d M Y, H:i', strtotime((string) $r['last_login']))];
        }
        break;

    case 'not_logged_in':
        $title = 'Schools that have NOT logged in';
        $headers = ['School', 'Code', 'Email', 'Contact'];
        $st = $pdo->query(
            "SELECT s.name, s.code, u.email, s.contact FROM schools s JOIN users u ON u.id = s.user_id
             WHERE NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.user_id = u.id AND a.action = 'login_success')
             ORDER BY s.name"
        );
        foreach ($st->fetchAll() as $r) {
            $rows[] = [$r['name'], $r['code'], $r['email'], $r['contact'] ?? ''];
        }
        break;

    case 'started':
    case 'completed':
    case 'in_progress':
        $statusSql = $metric === 'completed' ? "AND qs.status IN ('submitted','force_submitted')"
            : ($metric === 'in_progress' ? "AND qs.status = 'in_progress'" : '');
        $title = ['started' => 'Schools that started the exam', 'completed' => 'Schools that completed the exam', 'in_progress' => 'Schools currently taking the exam'][$metric];
        $headers = ['School', 'Code', 'Slot', 'Status', 'Started at'];
        $params = [];
        $slotFilter = '';
        if ($slotId) { $slotFilter = ' AND qs.slot_id = ?'; $params[] = $slotId; }
        $sql = "SELECT s.name, s.code, sl.slot_name, qs.status, qs.start_time
                FROM quiz_sessions qs
                JOIN schools s ON s.id = qs.school_id
                JOIN slots sl ON sl.id = qs.slot_id
                WHERE 1=1 $statusSql $slotFilter
                ORDER BY qs.start_time DESC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $r) {
            $rows[] = [$r['name'], $r['code'], $r['slot_name'], ucwords(str_replace('_', ' ', $r['status'])),
                date('d M Y, H:i', strtotime((string) $r['start_time']))];
        }
        break;

    case 'assigned':
        $title = 'Schools assigned to this slot';
        $headers = ['School', 'Code', 'Status'];
        $st = $pdo->prepare(
            "SELECT s.name, s.code,
                COALESCE((SELECT qs.status FROM quiz_sessions qs WHERE qs.school_id=s.id AND qs.slot_id=ss.slot_id LIMIT 1), 'not started') AS status
             FROM slot_schools ss JOIN schools s ON s.id = ss.school_id
             WHERE ss.slot_id = ? ORDER BY s.name"
        );
        $st->execute([$slotId]);
        foreach ($st->fetchAll() as $r) {
            $rows[] = [$r['name'], $r['code'], ucwords(str_replace('_', ' ', $r['status']))];
        }
        break;

    default:
        json_response(['ok' => false, 'error' => 'Unknown metric.'], 422);
}

json_response(['ok' => true, 'title' => $title, 'headers' => $headers, 'rows' => $rows]);
