<?php
/**
 * Action Handler: Delete / Cancel Inspection Session
 * Deletes inspection session and associated temporary sample/NG records when an inspection is canceled/not performed
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/../../config/summary_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$sessionId = (int)($_POST['session_id'] ?? 0);
if (!$sessionId) {
    echo json_encode(['success' => false, 'message' => 'Session ID tidak valid']);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Gagal terhubung ke database server']);
    exit;
}

try {
    // Fetch session details
    $stmt = $pdo->prepare("SELECT * FROM inspection_sessions WHERE id = :id");
    $stmt->execute([':id' => $sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode(['success' => false, 'message' => 'Sesi inspeksi tidak ditemukan']);
        exit;
    }

    $sessionDate  = !empty($session['started_at']) ? date('Y-m-d', strtotime($session['started_at'])) : null;
    $kanbanItemId = (int)($session['kanban_item_id'] ?? 0);

    $pdo->beginTransaction();

    // 1. Delete associated NG records
    $stmtDelNg = $pdo->prepare("DELETE FROM inspection_ng_records WHERE inspection_session_id = :sid");
    $stmtDelNg->execute([':sid' => $sessionId]);

    // 2. Delete associated lots
    $stmtDelLots = $pdo->prepare("DELETE FROM inspection_session_lots WHERE inspection_session_id = :sid");
    $stmtDelLots->execute([':sid' => $sessionId]);

    // 3. Delete associated samples
    $stmtDelSamples = $pdo->prepare("DELETE FROM inspection_samples WHERE inspection_session_id = :sid");
    $stmtDelSamples->execute([':sid' => $sessionId]);

    // 4. Delete session
    $stmtDelSess = $pdo->prepare("DELETE FROM inspection_sessions WHERE id = :sid");
    $stmtDelSess->execute([':sid' => $sessionId]);

    $pdo->commit();

    // 5. Sinkronisasi Daily Summary & Kanban Status
    if ($sessionDate && function_exists('syncDailySummaryForDate')) {
        syncDailySummaryForDate($pdo, $sessionDate);
    }
    if ($kanbanItemId > 0 && function_exists('syncKanbanStatus')) {
        syncKanbanStatus($pdo, $kanbanItemId);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Sesi inspeksi berhasil dibatalkan & dihapus.'
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Gagal menghapus sesi inspeksi: ' . $e->getMessage()
    ]);
}
