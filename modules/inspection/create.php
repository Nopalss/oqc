<?php
/**
 * Create / Start New Inspection Session Handler
 * Redirects to Unified Full-Screen Workbench (session.php)
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$pdo = getDB();

// Handle Form Submission / AJAX request to initiate session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'start_session') {
    $partCode = strtoupper(trim(sanitize($_POST['part_code'] ?? '')));
    $lotNumber = strtoupper(trim(sanitize($_POST['lot_number'] ?? '')));
    $didId = (int)($_POST['did_id'] ?? 0);
    $kanbanItemId = (int)($_POST['kanban_item_id'] ?? 0);
    $partId = (int)($_POST['part_id'] ?? 0);
    $sampleSize = (int)($_POST['sample_size'] ?? 32);
    $rejectNumber = (int)($_POST['reject_number'] ?? 1);

    $inspectionType = sanitize($_POST['inspection_type'] ?? 'kanban');
    if (!in_array($inspectionType, ['kanban', 'safety_stock'])) {
        $inspectionType = 'kanban';
    }

    if ($didId > 0 && $sampleSize > 0 && $pdo) {
        try {
            // Reuse existing 'in_progress' session if one already exists for this DID
            $stmtChk = $pdo->prepare("SELECT id FROM inspection_sessions WHERE did_id = :did AND status = 'in_progress' ORDER BY id DESC LIMIT 1");
            $stmtChk->execute([':did' => $didId]);
            $existingSession = $stmtChk->fetch(PDO::FETCH_ASSOC);

            if ($existingSession) {
                $sessionId = (int)$existingSession['id'];
            } else {
                $stmtIns = $pdo->prepare("INSERT INTO inspection_sessions 
                    (inspection_type, did_id, kanban_item_id, part_id, sample_size, reject_number, samples_checked, ng_count, status, started_at) 
                    VALUES (:itype, :did, :kanban, :part, :ssize, :rej, 0, 0, 'in_progress', NOW())");
                $stmtIns->execute([
                    ':itype'  => $inspectionType,
                    ':did'    => $didId,
                    ':kanban' => $kanbanItemId ?: null,
                    ':part'   => $partId ?: null,
                    ':ssize'  => $sampleSize,
                    ':rej'    => $rejectNumber
                ]);
                $sessionId = (int)$pdo->lastInsertId();
            }

            header('Content-Type: application/json');
            echo json_encode([
                'success'    => true,
                'session_id' => $sessionId,
                'url'        => base_url('modules/inspection/session.php?id=' . $sessionId)
            ]);
            exit;
        } catch (PDOException $e) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Gagal membuat sesi inspeksi: ' . $e->getMessage()
            ]);
            exit;
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Data DID atau Sample Size tidak valid!'
        ]);
        exit;
    }
}

// Default GET: redirect to unified session workbench with scan overlay active
redirect('modules/inspection/session.php?scan_new=1');
