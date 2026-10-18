<?php
/**
 * API: Submit Lot Result (Alur Per-Lot AQL)
 * Menangani konfirmasi PASSED atau pencatatan defect REJECTED untuk satu lot/label spesifik
 * Payload POST: session_lot_id, action ('passed' | 'rejected'), defects (array)
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';
require_once __DIR__ . '/../../../config/summary_helper.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi login Anda telah berakhir. Silakan login kembali.', 'unauthorized' => true]);
    exit;
}

$currentUser = current_user();
$currentUserId = (int)$currentUser['id'];
$currentUserRole = $currentUser['role'];
$isPrivilegedUser = in_array($currentUserRole, ['admin', 'chief', 'supervisor', 'manager'], true);

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Koneksi database gagal']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metode request tidak valid']);
    exit;
}

$rawInput = file_get_contents('php://input');
$jsonData = json_decode($rawInput, true);

$sessionLotId = (int)($jsonData['session_lot_id'] ?? ($_POST['session_lot_id'] ?? 0));
$sessionIdInput = (int)($jsonData['session_id'] ?? ($_POST['session_id'] ?? 0));
$action       = trim(sanitize($jsonData['action'] ?? ($_POST['action'] ?? '')));
$defects      = $jsonData['defects'] ?? ($_POST['defects'] ?? []);
$physicalNgQtyInput = isset($jsonData['physical_ng_qty']) ? (int)$jsonData['physical_ng_qty'] : (isset($_POST['physical_ng_qty']) ? (int)$_POST['physical_ng_qty'] : 0);

if ($action === 'pass_all') {
    if ($sessionIdInput <= 0 && $sessionLotId > 0) {
        $stmtFindS = $pdo->prepare("SELECT inspection_session_id FROM inspection_session_lots WHERE id = :id");
        $stmtFindS->execute([':id' => $sessionLotId]);
        $sessionIdInput = (int)$stmtFindS->fetchColumn();
    }
    if ($sessionIdInput <= 0) {
        echo json_encode(['success' => false, 'message' => 'Session ID tidak valid untuk Pass All']);
        exit;
    }
} elseif ($sessionLotId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Session Lot ID tidak valid']);
    exit;
}

if (!in_array($action, ['passed', 'rejected', 'reset', 'in_progress', 'pass_all'])) {
    echo json_encode(['success' => false, 'message' => 'Action harus passed, rejected, reset, atau pass_all']);
    exit;
}

try {
    $pdo->beginTransaction();

    if ($action === 'pass_all') {
        $sessionId = $sessionIdInput;
        $stmtSessCheck = $pdo->prepare("SELECT * FROM inspection_sessions WHERE id = :sid FOR UPDATE");
        $stmtSessCheck->execute([':sid' => $sessionId]);
        $sessRow = $stmtSessCheck->fetch(PDO::FETCH_ASSOC);
        if (!$sessRow) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Sesi tidak ditemukan']);
            exit;
        }

        // Ownership check
        $isSessionOwner = empty($sessRow['inspector_id']) || ((int)$sessRow['inspector_id'] === $currentUserId);
        if (!$isSessionOwner && !$isPrivilegedUser) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak untuk memodifikasi sesi inspektur lain.']);
            exit;
        }

        // Tandai seluruh lot yang masih in_progress sebagai passed
        $stmtPassAllLots = $pdo->prepare("
            UPDATE inspection_session_lots
            SET lot_result = 'passed',
                ng_count = 0,
                lot_status = 'ok',
                closed_at = NOW()
            WHERE inspection_session_id = :sid
              AND lot_result = 'in_progress'
              AND (lot_status = 'ok' OR lot_status IS NULL)
        ");
        $stmtPassAllLots->execute([':sid' => $sessionId]);

        $partId = (int)($sessRow['part_id'] ?? 0);
        $lot = [
            'kanban_item_id' => $sessRow['kanban_item_id'] ?? 0,
            'part_id' => $partId
        ];
    } else {
        // 1. Ambil data lot dan sesi terkait
        $stmtLot = $pdo->prepare("
            SELECT isl.*, s.id as session_id, s.part_id, s.kanban_item_id, s.flow_version, s.status as session_status, s.inspector_id
            FROM inspection_session_lots isl
            JOIN inspection_sessions s ON s.id = isl.inspection_session_id
            WHERE isl.id = :id
            FOR UPDATE
        ");
        $stmtLot->execute([':id' => $sessionLotId]);
        $lot = $stmtLot->fetch(PDO::FETCH_ASSOC);

        if (!$lot) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Data lot tidak ditemukan']);
            exit;
        }

        // Ownership check
        $isSessionOwner = empty($lot['inspector_id']) || ((int)$lot['inspector_id'] === $currentUserId);
        if (!$isSessionOwner && !$isPrivilegedUser) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak untuk memodifikasi sesi inspektur lain.']);
            exit;
        }

        $sessionId = (int)$lot['session_id'];
        $partId = (int)($lot['part_id'] ?? 0);
        $rejectNumber = max(1, (int)$lot['reject_number']);

        // 2. Pemrosesan Aksi
        if ($action === 'passed') {
            // Validasi: Cek apakah lot ini merupakan hasil re-inspeksi, sortir, atau box pengganti
            $isReinspLot = (strtolower($lot['lot_status'] ?? '') === 'reinspected')
                || !empty($lot['parent_session_id'])
                || !empty($lot['is_reinspection'])
                || (stripos($lot['remarks'] ?? '', 'Pengganti') !== false)
                || (stripos($lot['remarks'] ?? '', 'Re-inspeksi') !== false)
                || (stripos($lot['remarks'] ?? '', 'Reinspeksi') !== false)
                || (stripos($lot['remarks'] ?? '', 'Sortir') !== false);

            if (!$isReinspLot) {
                $stmtChkSort = $pdo->prepare("SELECT 1 FROM inspection_ng_records WHERE session_lot_id = :lid AND is_sorted = 1 LIMIT 1");
                $stmtChkSort->execute([':lid' => $sessionLotId]);
                if ($stmtChkSort->fetchColumn()) {
                    $isReinspLot = true;
                }
            }
            if (!$isReinspLot) {
                $stmtChkSub = $pdo->prepare("SELECT 1 FROM lot_substitution_log WHERE (ng_session_lot_id = :lid1 OR replacement_session_lot_id = :lid2) LIMIT 1");
                $stmtChkSub->execute([':lid1' => $sessionLotId, ':lid2' => $sessionLotId]);
                if ($stmtChkSub->fetchColumn()) {
                    $isReinspLot = true;
                }
            }

            $newLotStatus = $isReinspLot ? 'reinspected' : 'ok';

            $stmtUpdLot = $pdo->prepare("
                UPDATE inspection_session_lots
                SET lot_result = 'passed',
                    ng_count = 0,
                    lot_status = :new_status,
                    closed_at = NOW()
                WHERE id = :id
            ");
            $stmtUpdLot->execute([':new_status' => $newLotStatus, ':id' => $sessionLotId]);

            // Tandai temuan NG sebelumnya untuk lot ini sebagai disortir (is_sorted = 1, is_cancelled = 0)
            // agar temuan cacat historis dari inspeksi sebelumnya tetap terekam di analitik, dashboard, dan Total NG Pcs.
            $stmtSortNg = $pdo->prepare("
                UPDATE inspection_ng_records
                SET is_sorted = 1,
                    sort_notes = COALESCE(NULLIF(sort_notes, ''), 'Telah disortir & lulus pada re-inspeksi'),
                    sorted_at = COALESCE(sorted_at, NOW()),
                    is_cancelled = 0
                WHERE session_lot_id = :slot_id 
                  AND (is_cancelled IS NULL OR is_cancelled = 0)
            ");
            $stmtSortNg->execute([':slot_id' => $sessionLotId]);
        } elseif ($action === 'rejected') {
            if (!is_array($defects) || empty($defects)) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Harap sertakan minimal satu jenis defect untuk menandai lot REJECTED']);
                exit;
            }

            // Tandai temuan NG sebelumnya untuk lot ini sebagai disortir (is_sorted = 1, is_cancelled = 0)
            // agar temuan cacat dari ronde inspeksi sebelumnya tidak terhapus dari histori & Total NG Pcs
            $stmtSortNg = $pdo->prepare("
                UPDATE inspection_ng_records
                SET is_sorted = 1,
                    sort_notes = COALESCE(NULLIF(sort_notes, ''), 'Temuan NG dari inspeksi sebelumnya'),
                    sorted_at = COALESCE(sorted_at, NOW()),
                    is_cancelled = 0
                WHERE session_lot_id = :slot_id 
                  AND (is_cancelled IS NULL OR is_cancelled = 0)
            ");
            $stmtSortNg->execute([':slot_id' => $sessionLotId]);

            $totalNgQty = 0;
            $stmtInsNg = $pdo->prepare("
                INSERT INTO inspection_ng_records
                    (inspection_session_id, session_lot_id, unit_number, part_id, ref_number, lot_number, defect_type_id, qty_ng, is_cancelled, remark, created_at)
                VALUES
                    (:sid, :slot_id, :unit_no, :pid, :ref, :lot, :dt_id, :qty, 0, :rem, NOW())
            ");

            foreach ($defects as $df) {
                $unitNo = max(1, (int)($df['unit_number'] ?? 1));
                $defectTypeId = (int)($df['defect_type_id'] ?? 0);
                $qtyNg = max(1, (int)($df['qty_ng'] ?? 1));
                $customName = trim(sanitize($df['custom_name'] ?? ''));

                // Pastikan defect_type_id valid di database jika > 0
                if ($defectTypeId > 0) {
                    $stmtChkExist = $pdo->prepare("SELECT id FROM defect_types WHERE id = :id LIMIT 1");
                    $stmtChkExist->execute([':id' => $defectTypeId]);
                    if (!$stmtChkExist->fetchColumn()) {
                        $defectTypeId = 0;
                    }
                }

                // Jika custom defect atau defect_type_id belum terdaftar di tabel master
                if ($defectTypeId === 0) {
                    $cName = !empty($customName) ? $customName : 'Defect Lainnya';
                    $stmtChkDef = $pdo->prepare("SELECT id FROM defect_types WHERE LOWER(name) = LOWER(:nm) LIMIT 1");
                    $stmtChkDef->execute([':nm' => $cName]);
                    $defRow = $stmtChkDef->fetch(PDO::FETCH_ASSOC);
                    if ($defRow) {
                        $defectTypeId = (int)$defRow['id'];
                    } else {
                        $stmtInsDef = $pdo->prepare("INSERT INTO defect_types (name, created_at) VALUES (:nm, NOW())");
                        $stmtInsDef->execute([':nm' => $cName]);
                        $defectTypeId = (int)$pdo->lastInsertId();
                    }
                }

                if ($defectTypeId > 0) {
                    $stmtInsNg->execute([
                        ':sid'     => $sessionId,
                        ':slot_id' => $sessionLotId,
                        ':unit_no' => $unitNo,
                        ':pid'     => $partId ?: null,
                        ':ref'     => $lot['ref_number'] ?: null,
                        ':lot'     => $lot['lot_number'] ?: null,
                        ':dt_id'   => $defectTypeId,
                        ':qty'     => $qtyNg,
                        ':rem'     => !empty($customName) ? "Custom: {$customName}" : null
                    ]);
                    $totalNgQty += $qtyNg;
                }
            }

            if ($totalNgQty <= 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Total kuantitas defect harus lebih dari 0']);
                exit;
            }

            $finalPhysicalNg = ($physicalNgQtyInput > 0) ? min($physicalNgQtyInput, $totalNgQty) : $totalNgQty;

            // Update lot menjadi rejected
            $stmtUpdLot = $pdo->prepare("
                UPDATE inspection_session_lots
                SET lot_result = 'rejected',
                    ng_count = :ng_cnt,
                    lot_status = 'ng_found',
                    closed_at = NOW()
                WHERE id = :id
            ");
            $stmtUpdLot->execute([
                ':ng_cnt' => $finalPhysicalNg,
                ':id'     => $sessionLotId
            ]);
        } elseif ($action === 'reset' || $action === 'in_progress') {
        $stmtUpdLot = $pdo->prepare("
            UPDATE inspection_session_lots
            SET lot_result = 'in_progress',
                ng_count = 0,
                lot_status = 'ok',
                closed_at = NULL
            WHERE id = :id
        ");
        $stmtUpdLot->execute([':id' => $sessionLotId]);

        $stmtDelNg = $pdo->prepare("
            UPDATE inspection_ng_records
            SET is_cancelled = 1,
                cancel_reason = 'Direset operator ke in_progress',
                cancelled_at = NOW()
            WHERE session_lot_id = :slot_id 
              AND (is_cancelled IS NULL OR is_cancelled = 0)
              AND (is_sorted IS NULL OR is_sorted = 0)
        ");
        $stmtDelNg->execute([':slot_id' => $sessionLotId]);
    }
    }

    // 3. Update Agregat di inspection_sessions
    // Hitung samples_checked (total sample_size dari lot yang sudah passed / rejected)
    $stmtAggr = $pdo->prepare("
        SELECT 
            COUNT(*) as total_lots,
            SUM(CASE WHEN lot_result = 'in_progress' AND lot_status != 'replaced' THEN 1 ELSE 0 END) as in_progress_lots,
            SUM(CASE WHEN lot_result = 'rejected' AND lot_status != 'replaced' THEN 1 ELSE 0 END) as rejected_lots,
            SUM(CASE WHEN lot_result = 'passed' AND lot_status != 'replaced' THEN 1 ELSE 0 END) as passed_lots,
            SUM(CASE WHEN lot_result = 'skipped' AND lot_status != 'replaced' THEN 1 ELSE 0 END) as skipped_lots,
            COALESCE(SUM(CASE 
                WHEN (lot_status != 'replaced' AND lot_result IN ('passed', 'rejected')) 
                     OR lot_status IN ('replaced', 'reinspected') 
                THEN sample_size 
                ELSE 0 
            END), 0) as checked_samples,
            COALESCE(SUM(sample_size), 0) as total_samples,
            COALESCE(SUM(CASE WHEN lot_status != 'replaced' THEN ng_count ELSE 0 END), 0) as total_ng,
            COALESCE(SUM(CASE WHEN lot_status != 'replaced' THEN qty ELSE 0 END), 0) as total_scanned_qty
        FROM inspection_session_lots
        WHERE inspection_session_id = :sid
    ");
    $stmtAggr->execute([':sid' => $sessionId]);
    $aggr = $stmtAggr->fetch(PDO::FETCH_ASSOC);

    $inProgressLots = (int)$aggr['in_progress_lots'];
    $rejectedLots   = (int)$aggr['rejected_lots'];
    $checkedSamples = (int)$aggr['checked_samples'];
    $totalSamples   = (int)$aggr['total_samples'];
    $totalNg        = (int)$aggr['total_ng'];

    // Tentukan status sesi baru
    if ($inProgressLots > 0) {
        $newSessionStatus = 'in_progress';
        $closedAtVal = null;
    } else {
        // Semua lot sudah selesai
        if ($rejectedLots > 0) {
            $newSessionStatus = 'rejected';
        } else {
            $newSessionStatus = 'passed';
        }
        $closedAtVal = date('Y-m-d H:i:s');
    }

    $stmtUpdSess = $pdo->prepare("
        UPDATE inspection_sessions
        SET total_scanned_qty = :tot_qty,
            samples_checked = :chk_samples,
            sample_size = :tot_samples,
            ng_count = :ng_cnt,
            status = :st,
            closed_at = CASE WHEN :cl_at IS NOT NULL THEN :cl_at2 ELSE closed_at END
        WHERE id = :sid
    ");
    $stmtUpdSess->execute([
        ':tot_qty'     => (int)$aggr['total_scanned_qty'],
        ':chk_samples' => $checkedSamples,
        ':tot_samples' => $totalSamples,
        ':ng_cnt'      => $totalNg,
        ':st'          => $newSessionStatus,
        ':cl_at'       => $closedAtVal,
        ':cl_at2'      => $closedAtVal,
        ':sid'         => $sessionId
    ]);

    // 4. Sinkronkan ke Daily Summary & Kanban Item & Auto Safety Stock
    if ($newSessionStatus === 'passed') {
        // Linkkan box Safety Stock asal jika box fisik ini berasal dari stok safety stock gudang
        $stmtSessLots = $pdo->prepare("
            SELECT isl.ref_number, isl.lot_number, isl.qty,
                   COALESCE(did.part_code, ki.item_code, mp.part_code) AS part_code
            FROM inspection_session_lots isl
            JOIN inspection_sessions s ON s.id = isl.inspection_session_id
            LEFT JOIN daily_inspection_data did ON did.id = s.did_id
            LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
            LEFT JOIN master_parts mp ON mp.id = s.part_id
            WHERE s.id = :sid 
              AND s.inspection_type = 'kanban'
              AND (isl.lot_status != 'replaced' OR isl.lot_status IS NULL)
        ");
        $stmtSessLots->execute([':sid' => $sessionId]);
        $kPassedLots = $stmtSessLots->fetchAll(PDO::FETCH_ASSOC);

        $stmtFindSS = $pdo->prepare("
            SELECT ss.id AS ss_session_id, ss.status AS ss_status, isl_ss.id AS ss_lot_id, isl_ss.qty AS ss_qty
            FROM inspection_session_lots isl_ss
            JOIN inspection_sessions ss ON ss.id = isl_ss.inspection_session_id
            LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
            LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
            WHERE ss.inspection_type = 'safety_stock'
              AND (ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0)
              AND (UPPER(did.part_code) = UPPER(:pcode) OR UPPER(ki.item_code) = UPPER(:pcode2))
              AND UPPER(isl_ss.ref_number) = UPPER(:ref)
            ORDER BY ss.id DESC
            LIMIT 1
        ");

        $stmtLinkSS = $pdo->prepare("
            UPDATE inspection_sessions 
            SET auto_fulfilled_by_session_id = :ksid,
                status = 'passed',
                reinspection_notes = CASE 
                    WHEN :old_st = 'rejected' THEN CONCAT(COALESCE(reinspection_notes, ''), ' [Re-inspeksi Lulus via Kanban]')
                    ELSE reinspection_notes
                END
            WHERE id = :ssid AND (auto_fulfilled_by_session_id IS NULL OR auto_fulfilled_by_session_id = 0)
        ");

        $stmtUpdSSLot = $pdo->prepare("
            UPDATE inspection_session_lots 
            SET lot_status = 'reinspected',
                lot_result = 'passed',
                remarks = CONCAT(COALESCE(remarks, ''), ' [Digunakan Kanban #', :ksid, ']')
            WHERE id = :lid
        ");

        foreach ($kPassedLots as $kpLot) {
            if (empty($kpLot['ref_number']) || empty($kpLot['part_code'])) continue;
            $stmtFindSS->execute([
                ':pcode'  => $kpLot['part_code'],
                ':pcode2' => $kpLot['part_code'],
                ':ref'    => $kpLot['ref_number']
            ]);
            $ssMatched = $stmtFindSS->fetch(PDO::FETCH_ASSOC);
            if ($ssMatched) {
                $matchedSessId = (int)$ssMatched['ss_session_id'];
                $matchedStatus = $ssMatched['ss_status'] ?? 'passed';
                $matchedLotId  = (int)$ssMatched['ss_lot_id'];

                // Hindari menimpa sesi pecahan sisa (parent_session_id) yang sengaja dibuat sebagai sisa stok
                $stmtChkParent = $pdo->prepare("SELECT parent_session_id, auto_fulfilled_by_session_id FROM inspection_sessions WHERE id = :ssid");
                $stmtChkParent->execute([':ssid' => $matchedSessId]);
                $chkP = $stmtChkParent->fetch(PDO::FETCH_ASSOC);
                if ($chkP && !empty($chkP['parent_session_id'])) {
                    // Ini sesi sisa pecahan baru, jangan ditandai terpakai lagi ke sesi Kanban ini!
                    continue;
                }

                $stmtLinkSS->execute([
                    ':ksid'   => $sessionId,
                    ':old_st' => $matchedStatus,
                    ':ssid'   => $matchedSessId
                ]);

                if ($matchedLotId > 0) {
                    $stmtUpdSSLot->execute([
                        ':ksid' => $sessionId,
                        ':lid'  => $matchedLotId
                    ]);
                }
            }
        }

        if (function_exists('createAutoSafetyStockForSession')) {
            createAutoSafetyStockForSession($pdo, $sessionId);
        }
    }
    if (function_exists('syncDailySummaryForSession')) {
        syncDailySummaryForSession($pdo, $sessionId);
    }
    $kanbanItemId = (int)($lot['kanban_item_id'] ?? 0);
    if ($kanbanItemId > 0 && function_exists('syncKanbanStatus')) {
        syncKanbanStatus($pdo, $kanbanItemId);
    }

    $pdo->commit();

    $respMsg = 'Hasil lot berhasil diproses';
    if ($action === 'pass_all') {
        $respMsg = 'Semua box berhasil ditandai PASSED';
    } elseif ($action === 'passed') {
        $respMsg = 'Lot berhasil ditandai PASSED';
    } elseif ($action === 'rejected') {
        $respMsg = 'Temuan NG tercatat, lot berstatus REJECTED';
    } elseif ($action === 'reset' || $action === 'in_progress') {
        $respMsg = 'Status lot berhasil dikembalikan ke sedang diinspeksi';
    }

    echo json_encode([
        'success'        => true,
        'message'        => $respMsg,
        'session_id'     => $sessionId,
        'session_lot_id' => $sessionLotId,
        'lot_result'     => ($action === 'reset' || $action === 'in_progress') ? 'in_progress' : $action,
        'session_status' => $newSessionStatus
    ]);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan saat memproses hasil lot: ' . $e->getMessage()
    ]);
    exit;
}
