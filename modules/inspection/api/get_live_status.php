<?php
/**
 * API: Live Status Poller for OQC Kanban Workboard
 * Ultra-lightweight endpoint for multi-laptop real-time synchronization.
 * Designed for high scalability (100M+ rows) using O(1) B-Tree indexed lookups.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$currentUser = current_user();
$currentUserId = (int)$currentUser['id'];

// Release PHP session lock immediately to prevent blocking parallel requests
session_write_close();

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$lastHash = trim($_GET['last_hash'] ?? '');
$rawKanbanIds = trim($_GET['kanban_ids'] ?? '');

$kanbanIds = [];
if (!empty($rawKanbanIds)) {
    foreach (explode(',', $rawKanbanIds) as $idStr) {
        $idVal = (int)trim($idStr);
        if ($idVal > 0) {
            $kanbanIds[] = $idVal;
        }
    }
}

try {
    // 1. Fetch all globally active in-progress sessions (Indexed: idx_sess_status_started)
    // Touches only the active working set (typically 2-10 rows in a real plant)
    $activeStmt = $pdo->query("
        SELECT s.id as session_id, s.kanban_item_id, s.inspector_id, s.status, s.started_at,
               COALESCE(u.name, 'QC Inspector') as inspector_name,
               TIMESTAMPDIFF(MINUTE, s.started_at, NOW()) as duration_minutes
        FROM inspection_sessions s
        LEFT JOIN users u ON u.id = s.inspector_id
        WHERE s.status = 'in_progress'
        ORDER BY s.id DESC
    ");
    $activeSessionsRaw = $activeStmt->fetchAll(PDO::FETCH_ASSOC);

    $activeByKanban = [];
    foreach ($activeSessionsRaw as $act) {
        $kid = (int)$act['kanban_item_id'];
        if ($kid > 0 && !isset($activeByKanban[$kid])) {
            $activeByKanban[$kid] = $act;
        }
    }

    // 2. Fetch latest status & progress only for the currently visible kanban IDs (O(K) point-lookup)
    $kanbanUpdates = [];
    if (!empty($kanbanIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($kanbanIds), '?'));
        $kbSql = "
            SELECT k.id, k.status, k.qty as target_qty,
                   COALESCE((
                       SELECT SUM(
                           CASE 
                               WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                                   COALESCE((SELECT SUM(CASE WHEN isl2.lot_result = 'passed' AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                             FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0)
                               WHEN s2.status = 'passed' THEN
                                   s2.total_scanned_qty
                               ELSE 0
                           END
                       )
                       FROM inspection_sessions s2
                       WHERE s2.kanban_item_id = k.id
                   ), 0) AS total_passed_qty,
                   COUNT(s.id) AS total_sessions_count,
                   MAX(CASE WHEN s.status = 'rejected' OR EXISTS (SELECT 1 FROM inspection_session_lots isl_r WHERE isl_r.inspection_session_id = s.id AND (isl_r.lot_result = 'rejected' OR isl_r.lot_status IN ('rejected', 'ng_found'))) THEN 1 ELSE 0 END) AS has_rejected_session
            FROM kanban_items k
            LEFT JOIN inspection_sessions s ON s.kanban_item_id = k.id
            WHERE k.id IN ($inPlaceholders)
            GROUP BY k.id, k.status, k.qty
        ";
        $kbStmt = $pdo->prepare($kbSql);
        $kbStmt->execute($kanbanIds);
        $kbRows = $kbStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($kbRows as $row) {
            $kid = (int)$row['id'];
            $targetQty = (int)$row['target_qty'];
            $passedQty = (int)$row['total_passed_qty'];
            $totalSessions = (int)$row['total_sessions_count'];
            $hasRejectedSession = ((int)($row['has_rejected_session'] ?? 0) === 1);
            $dbStatus = $row['status'];

            $hasActiveSession = isset($activeByKanban[$kid]);
            $activeInfo = $hasActiveSession ? $activeByKanban[$kid] : null;

            $isOwnSession = false;
            if ($activeInfo && (int)$activeInfo['inspector_id'] === $currentUserId) {
                $isOwnSession = true;
            }

            $progressPct = ($targetQty > 0) ? min(100, round(($passedQty / $targetQty) * 100)) : 0;
            $isFullyPassed = ($targetQty > 0 && $passedQty >= $targetQty);

            // Determine presentation status
            $statusType = 'uninspected';
            $statusLabel = 'Belum Mulai';
            if ($isFullyPassed || $dbStatus === 'completed') {
                $statusType = 'completed';
                $statusLabel = 'Selesai';
            } elseif ($hasActiveSession) {
                $statusType = 'in_progress';
                $statusLabel = 'Sedang Dikerjakan';
            } elseif ($passedQty > 0 || $dbStatus === 'partial') {
                $statusType = 'partial';
                $statusLabel = 'Parsial';
            } elseif ($dbStatus === 'rejected') {
                $statusType = 'rejected';
                $statusLabel = 'Reject';
            }

            $kanbanUpdates[$kid] = [
                'id'                   => $kid,
                'status_type'          => $statusType,
                'status_label'         => $statusLabel,
                'has_active_session'   => $hasActiveSession,
                'active_session_id'    => $activeInfo ? (int)$activeInfo['session_id'] : null,
                'active_inspector_id'  => $activeInfo ? (int)$activeInfo['inspector_id'] : null,
                'active_inspector_name'=> $activeInfo ? $activeInfo['inspector_name'] : null,
                'duration_minutes'     => $activeInfo ? (int)$activeInfo['duration_minutes'] : 0,
                'is_own_session'       => $isOwnSession,
                'passed_qty'           => $passedQty,
                'target_qty'           => $targetQty,
                'progress_pct'         => $progressPct,
                'is_fully_passed'      => $isFullyPassed,
                'has_rejected_session' => $hasRejectedSession,
                'total_sessions'       => $totalSessions
            ];
        }
    }

    // 3. Compute Fast State Hash to minimize network payload
    $stateHash = md5(json_encode([$activeByKanban, $kanbanUpdates]));

    if ($lastHash !== '' && $lastHash === $stateHash) {
        echo json_encode([
            'success'     => true,
            'changed'     => false,
            'hash'        => $stateHash,
            'server_time' => date('H:i:s')
        ]);
        exit;
    }

    echo json_encode([
        'success'         => true,
        'changed'         => true,
        'hash'            => $stateHash,
        'server_time'     => date('H:i:s'),
        'current_user_id' => $currentUserId,
        'active_count'    => count($activeByKanban),
        'active_by_kanban'=> $activeByKanban,
        'kanban_updates'  => $kanbanUpdates
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Query error: ' . $e->getMessage()
    ]);
}
