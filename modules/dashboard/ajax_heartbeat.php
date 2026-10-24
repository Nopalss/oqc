<?php
/**
 * Ultra-Lightweight Heartbeat & Version Fingerprint Endpoint for OQC Dashboard
 * Checks for database updates in sub-millisecond time (< 1 ms).
 * Zero-load when no updates occur in the factory line.
 */
@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

// Release PHP session lock immediately so other tabs/requests are not blocked
$hasAccess = can_access('dashboard');
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (!$hasAccess) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['has_update' => false, 'error' => 'Database connection failed']);
    exit;
}

// Client's currently held fingerprint version
$clientVersion    = sanitize($_GET['v'] ?? '');
$selectedCustomer = sanitize($_GET['customer'] ?? '');
$filterType       = sanitize($_GET['filter_type'] ?? '');
$selectedMonth    = isset($_GET['month']) && is_numeric($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$selectedYear     = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Resolve date boundaries
if ($filterType === 'all_years') {
    $startDate = '1970-01-01';
    $endDate   = '2099-12-31';
} elseif ($filterType === 'month_year') {
    $startDate = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
    $endDate   = date('Y-m-t', strtotime($startDate));
} elseif ($filterType === 'custom' || (!empty($_GET['start_date']) && !empty($_GET['end_date']))) {
    $startDate = sanitize($_GET['start_date'] ?? date('Y-m-01'));
    $endDate   = sanitize($_GET['end_date'] ?? date('Y-m-d'));
} else {
    $presetFilter = sanitize($_GET['preset'] ?? 'bulanan');
    switch ($presetFilter) {
        case 'hari_ini':
            $startDate = date('Y-m-d');
            $endDate   = date('Y-m-d');
            break;
        case 'mingguan':
            $startDate = date('Y-m-d', strtotime('monday this week'));
            $endDate   = date('Y-m-d');
            break;
        case 'tahunan':
            $startDate = date('Y-01-01');
            $endDate   = date('Y-m-d');
            break;
        default:
            $startDate = date('Y-m-01');
            $endDate   = date('Y-m-d');
    }
}

$edNext = date('Y-m-d', strtotime($endDate . ' +1 day'));
$p = [':sd' => $startDate, ':ed_next' => $edNext];
$sumCustCond = '';
if ($selectedCustomer !== '') {
    $sumCustCond = " AND customer = :cust ";
    $p[':cust'] = $selectedCustomer;
}

try {
    // 1. Check aggregate summary table
    $stmtSum = $pdo->prepare("SELECT 
        COALESCE(MAX(id), 0) AS max_sum_id,
        COALESCE(MAX(updated_at), '1970-01-01') AS last_sum_update,
        COUNT(id) AS sum_count
    FROM oqc_daily_summary
    WHERE summary_date >= :sd AND summary_date < :ed_next {$sumCustCond}");
    $stmtSum->execute($p);
    $rSum = $stmtSum->fetch(PDO::FETCH_ASSOC);

    // 2. Check live sessions table for today/date range
    $stmtSess = $pdo->prepare("SELECT 
        COALESCE(MAX(id), 0) AS max_sess_id,
        COUNT(id) AS sess_count
    FROM inspection_sessions
    WHERE started_at >= :sd AND started_at < :ed_next");
    $stmtSess->execute([':sd' => $startDate, ':ed_next' => $edNext]);
    $rSess = $stmtSess->fetch(PDO::FETCH_ASSOC);

    // 2b. Check global target PPM setting
    $targetPpmVal = '';
    try {
        $stmtTgt = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'dashboard_target_ppm' LIMIT 1");
        $targetPpmVal = (string)($stmtTgt->fetchColumn() ?: '');
    } catch (Exception $eT) {}

    // 3. Compute concise fingerprint hash
    $rawToken = implode('|', [
        $rSum['max_sum_id'] ?? 0,
        $rSum['last_sum_update'] ?? '',
        $rSum['sum_count'] ?? 0,
        $rSess['max_sess_id'] ?? 0,
        $rSess['sess_count'] ?? 0,
        $startDate,
        $endDate,
        $selectedCustomer,
        $targetPpmVal
    ]);

    $currentVersion = md5($rawToken);
    $hasUpdate = ($clientVersion !== '' && $clientVersion !== $currentVersion);

    echo json_encode([
        'status'      => 'success',
        'has_update'  => $hasUpdate,
        'version'     => $currentVersion,
        'server_time' => date('H:i:s'),
        'date_range'  => "{$startDate} to {$endDate}"
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status'     => 'error',
        'has_update' => false,
        'version'    => $clientVersion,
        'message'    => 'Heartbeat check failed: ' . $e->getMessage()
    ]);
}
