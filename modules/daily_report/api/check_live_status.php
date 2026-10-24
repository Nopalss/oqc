<?php
/**
 * Lightweight Micro-API: Live Session Status Checker
 * Digunakan oleh daily_report/index.php untuk polling realtime status sesi in_progress.
 * 
 * PT. Surya Technology Industri — Outgoing Quality Control Division
 */

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Release session lock immediately so parallel requests/tabs are not blocked
session_write_close();

$rawIds = $_GET['ids'] ?? '';
if (empty($rawIds)) {
    echo json_encode(['success' => true, 'sessions' => []]);
    exit;
}

// Filter hanya integer ID untuk mencegah SQL injection
$ids = array_filter(array_map('intval', explode(',', $rawIds)), function($id) {
    return $id > 0;
});

if (empty($ids)) {
    echo json_encode(['success' => true, 'sessions' => []]);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    // Query ultra-ringan: Hanya ambil kolom yang esensial untuk update status tampilan
    $stmt = $pdo->prepare("
        SELECT 
            ss.id,
            ss.status,
            ss.started_at,
            ss.closed_at,
            ss.sample_size,
            ss.total_scanned_qty,
            ss.ng_count,
            ss.is_approved,
            (SELECT COUNT(*) FROM inspection_session_lots WHERE inspection_session_id = ss.id) AS lot_count,
            (SELECT COALESCE(SUM(sample_size), 0) FROM inspection_session_lots WHERE inspection_session_id = ss.id) AS effective_sample_sum,
            (SELECT COALESCE(SUM(qty), 0) FROM inspection_session_lots WHERE inspection_session_id = ss.id) AS lot_qty_sum
        FROM inspection_sessions ss
        WHERE ss.id IN ($placeholders)
    ");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $status = strtolower($row['status'] ?? 'in_progress');
        $closedAt = !empty($row['closed_at']) ? date('H:i', strtotime($row['closed_at'])) : null;
        $startedAtTime = !empty($row['started_at']) ? date('H:i', strtotime($row['started_at'])) : '--:--';
        
        $effectiveSample = (int)$row['effective_sample_sum'];
        if ($effectiveSample <= 0) {
            $effectiveSample = (int)$row['sample_size'];
        }

        $effectiveQty = (int)$row['lot_qty_sum'];
        if ($effectiveQty <= 0) {
            $effectiveQty = (int)$row['total_scanned_qty'];
        }

        $results[$id] = [
            'id'               => $id,
            'status'           => $status, // 'in_progress', 'passed', 'rejected'
            'is_in_progress'   => ($status === 'in_progress' || empty($row['closed_at'])),
            'closed_at_time'   => $closedAt,
            'time_text'        => $startedAtTime . ' - ' . ($closedAt ? $closedAt . ' WIB' : 'Sekarang WIB'),
            'effective_sample' => $effectiveSample,
            'effective_qty'    => $effectiveQty,
            'lot_count'        => (int)$row['lot_count'],
            'ng_count'         => (int)$row['ng_count'],
            'is_approved'      => (int)$row['is_approved']
        ];
    }

    echo json_encode([
        'success'   => true,
        'timestamp' => time(),
        'sessions'  => $results
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
