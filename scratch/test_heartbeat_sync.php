<?php
/**
 * Test & Verifikasi Keselarasan Date Boundaries & Version Fingerprint
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helper.php';
require_once __DIR__ . '/../config/summary_helper.php';

$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SESSION['menus'] = ['dashboard'];

$pdo = getDB();

echo "=== TEST RESOLUSI TANGGAL SUNDAY ANCHOR ===\n";
$curY = (int)date('Y');
$curM = (int)date('n');

$sundayStart = getWorkCycleMonthStartDate($curY, $curM);
echo "Tahun: {$curY}, Bulan: {$curM}\n";
echo "Hari Minggu Pertama (Sunday Anchor): {$sundayStart} (" . date('l, d F Y', strtotime($sundayStart)) . ")\n";

// Simulasi parameter filter bulanan
$p = [
    ':sd'      => $sundayStart,
    ':ed_next' => date('Y-m-d', strtotime(date('Y-m-d') . ' +1 day'))
];
$sumCustCond = '';
$selectedCustomer = '';
$startDate = $sundayStart;
$endDate   = date('Y-m-d');

// 1. Ambil fingerprint dengan logika index.php
$stmtSumV = $pdo->prepare("SELECT 
    COALESCE(MAX(id), 0) AS max_sum_id,
    COALESCE(MAX(updated_at), '1970-01-01') AS last_sum_update,
    COUNT(id) AS sum_count
FROM oqc_daily_summary
WHERE summary_date >= :sd AND summary_date < :ed_next {$sumCustCond}");
$stmtSumV->execute($p);
$rSumV = $stmtSumV->fetch(PDO::FETCH_ASSOC);

$stmtSessV = $pdo->prepare("SELECT 
    COALESCE(MAX(id), 0) AS max_sess_id,
    COUNT(id) AS sess_count
FROM inspection_sessions
WHERE started_at >= :sd AND started_at < :ed_next");
$stmtSessV->execute([':sd' => $startDate, ':ed_next' => $p[':ed_next']]);
$rSessV = $stmtSessV->fetch(PDO::FETCH_ASSOC);

$stmtTgt = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'dashboard_target_ppm' LIMIT 1");
$dashboardTargetPpm = (float)($stmtTgt ? $stmtTgt->fetchColumn() : 0);

$indexFingerprint = md5(implode('|', [
    $rSumV['max_sum_id'] ?? 0,
    $rSumV['last_sum_update'] ?? '',
    $rSumV['sum_count'] ?? 0,
    $rSessV['max_sess_id'] ?? 0,
    $rSessV['sess_count'] ?? 0,
    $startDate,
    $endDate,
    $selectedCustomer,
    $dashboardTargetPpm
]));

echo "\n=== VERIFIKASI FINGERPRINT HASH ===\n";
echo "Index.php Fingerprint      : {$indexFingerprint}\n";

// 2. Simulasi Heartbeat dengan parameter yang sama
$_GET['preset'] = 'bulanan';
$_GET['v'] = $indexFingerprint;

ob_start();
require __DIR__ . '/../modules/dashboard/ajax_heartbeat.php';
$heartbeatJson = ob_get_clean();
$heartbeatData = json_decode($heartbeatJson, true);

echo "Heartbeat Response Status  : " . ($heartbeatData['status'] ?? 'ERROR') . "\n";
echo "Heartbeat Fingerprint Version: " . ($heartbeatData['version'] ?? 'N/A') . "\n";
echo "Has Update (Harus false)   : " . (($heartbeatData['has_update'] ?? true) ? "TRUE (MISMATCH!)" : "FALSE (IDENTIK!)") . "\n";
echo "Heartbeat Date Range       : " . ($heartbeatData['date_range'] ?? '') . "\n";

if (($heartbeatData['version'] ?? '') === $indexFingerprint && !($heartbeatData['has_update'] ?? true)) {
    echo "\n>>> STATUS: 100% SINKRON & VALID! <<<\n";
} else {
    echo "\n>>> STATUS: ADA KETIDAKSESUAIAN! <<<\n";
}
