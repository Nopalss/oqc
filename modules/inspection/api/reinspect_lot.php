<?php
/**
 * API: Batch Re-inspect Lot / Kanban
 * Endpoint utama untuk menangani sesi REJECTED dalam 4 kombinasi mode:
 *   1. rescan_restart  → Scan ulang semua lot, ulang dari sample #1 (reset counter)
 *   2. rescan_continue → Scan ulang semua lot, lanjut dari sisa sample (continue counter)
 *   3. replace_ng_only → Ganti lot NG saja (dengan QR baru), lot OK dipertahankan
 *   4. replace_all_lots → Ganti seluruh lot dalam kanban dengan QR lot baru
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

// Read POST parameters or JSON body
$rawInput = file_get_contents('php://input');
$jsonBody = json_decode($rawInput, true) ?: [];

$action            = sanitize($_POST['action'] ?? $jsonBody['action'] ?? $jsonBody['mode'] ?? '');
$originalSessionId = (int)($_POST['original_session_id'] ?? $jsonBody['original_session_id'] ?? 0);
$reinspNotes       = sanitize($_POST['reinspection_notes'] ?? $jsonBody['reinspection_notes'] ?? '');

// Normalisasi action name
if ($action === 'rescan')  $action = 'rescan_restart';
if ($action === 'replace') $action = 'replace_ng_only';

$validActions = ['rescan_restart', 'rescan_continue', 'replace_ng_only', 'replace_all_lots'];
if (!in_array($action, $validActions)) {
    echo json_encode(['success' => false, 'message' => 'Mode re-inspeksi tidak valid']);
    exit;
}

if (!$originalSessionId) {
    echo json_encode(['success' => false, 'message' => 'original_session_id wajib diisi']);
    exit;
}

// Replacement lots array (untuk replace_ng_only & replace_all_lots)
$replacementLots = $_POST['replacement_lots'] ?? $jsonBody['replacement_lots'] ?? [];

// Single legacy fallback param support
if (empty($replacementLots) && strpos($action, 'replace') !== false) {
    $singleNgLotId = (int)($_POST['ng_lot_id'] ?? $jsonBody['ng_lot_id'] ?? 0);
    $z1 = strtoupper(trim(sanitize($_POST['new_z1'] ?? $jsonBody['new_z1'] ?? '')));
    $z2 = strtoupper(trim(sanitize($_POST['new_z2'] ?? $jsonBody['new_z2'] ?? '')));
    $z3 = (int)($_POST['new_z3'] ?? $jsonBody['new_z3'] ?? 0);
    $z4 = sanitize($_POST['new_z4'] ?? $jsonBody['new_z4'] ?? '');
    $z5 = sanitize($_POST['new_z5'] ?? $jsonBody['new_z5'] ?? '');
    if ($z2 && $z3 > 0) {
        $replacementLots[] = [
            'ng_lot_id' => $singleNgLotId,
            'new_z1' => $z1,
            'new_z2' => $z2,
            'new_z3' => $z3,
            'new_z4' => $z4,
            'new_z5' => $z5,
        ];
    }
}

try {
    $pdo->beginTransaction();

    // 1. Validasi Sesi Original (Harus REJECTED)
    $stmtSess = $pdo->prepare("
        SELECT s.*, did.part_code, did.part_name, did.cavity
        FROM inspection_sessions s
        JOIN daily_inspection_data did ON did.id = s.did_id
        WHERE s.id = :id
    ");
    $stmtSess->execute([':id' => $originalSessionId]);
    $origSession = $stmtSess->fetch(PDO::FETCH_ASSOC);

    if (!$origSession) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Sesi original tidak ditemukan']);
        exit;
    }
    if ($origSession['status'] !== 'rejected') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Sesi original harus berstatus REJECTED']);
        exit;
    }

    // Fetch semua lot pada sesi original
    $stmtOrigLots = $pdo->prepare("SELECT * FROM inspection_session_lots WHERE inspection_session_id = :sid ORDER BY id ASC");
    $stmtOrigLots->execute([':sid' => $originalSessionId]);
    $origLots = $stmtOrigLots->fetchAll(PDO::FETCH_ASSOC);

    if (empty($origLots)) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Data lot sesi original kosong']);
        exit;
    }

    $partCode     = $origSession['part_code'];
    $partName     = $origSession['part_name'];
    $origKanbanId = $origSession['kanban_item_id'];
    $origPartId   = $origSession['part_id'];
    $origInspType = $origSession['inspection_type'];
    $origCavity   = $origSession['cavity'] ?? '1';

    // 2. Tentukan Data Lot & Qty untuk Sesi Baru
    $newSessionLots = []; // array of lot objects to insert
    $totalReinspQty = 0;

    if ($action === 'rescan_restart' || $action === 'rescan_continue') {
        // Mode Scan Ulang: Sertakan semua lot original
        foreach ($origLots as $lot) {
            $lQty = (int)$lot['qty'];
            $totalReinspQty += $lQty;
            $newSessionLots[] = [
                'ref_number' => $lot['ref_number'],
                'lot_number' => $lot['lot_number'],
                'qty'        => $lQty,
                'remarks'    => $lot['remarks'],
                'scanned_qr' => $lot['scanned_qr_raw'],
                'orig_lot_id'=> $lot['id'],
                'is_replace' => false
            ];
            // Update lot lama -> reinspected
            $pdo->prepare("UPDATE inspection_session_lots SET lot_status = 'reinspected', action_noted_at = NOW() WHERE id = :lid")
                ->execute([':lid' => $lot['id']]);
        }
    } elseif ($action === 'replace_ng_only') {
        // Mode Ganti Lot NG Saja: Ganti lot NG dengan QR baru, pertahankan lot OK
        if (empty($replacementLots)) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Detail lot pengganti wajib diisi']);
            exit;
        }

        // Map replacement lots by ng_lot_id
        $repMap = [];
        foreach ($replacementLots as $rl) {
            if (!empty($rl['ng_lot_id'])) {
                $repMap[(int)$rl['ng_lot_id']] = $rl;
            }
        }

        foreach ($origLots as $lot) {
            $lid = (int)$lot['id'];
            if (isset($repMap[$lid]) || $lot['lot_status'] === 'ng_found') {
                $rl = $repMap[$lid] ?? reset($replacementLots);
                $z1 = strtoupper(trim(sanitize($rl['new_z1'] ?? $partCode)));
                $z2 = strtoupper(trim(sanitize($rl['new_z2'] ?? '')));
                $z3 = (int)($rl['new_z3'] ?? 0);
                $z4 = sanitize($rl['new_z4'] ?? '');
                $z5 = sanitize($rl['new_z5'] ?? '');

                if (!$z2 || $z3 <= 0) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => "Lot pengganti untuk lot {$lot['lot_number']} tidak valid (Z2/Z3 kosong)"]);
                    exit;
                }

                $refNo = $z5 ?: ('REF-REINSP-' . time() . '-' . rand(100, 999));
                $rawQr = 'Z1' . $z1 . '|Z2' . $z2 . '|Z3' . $z3 . ($z5 ? ('|Z5' . $z5) : '');

                $totalReinspQty += $z3;
                $newSessionLots[] = [
                    'ref_number' => $refNo,
                    'lot_number' => $z2,
                    'qty'        => $z3,
                    'remarks'    => $z4,
                    'scanned_qr' => $rawQr,
                    'orig_lot_id'=> $lid,
                    'is_replace' => true
                ];

                // Update lot lama -> ng_quarantine
                $pdo->prepare("UPDATE inspection_session_lots SET lot_status = 'ng_quarantine', action_noted_at = NOW() WHERE id = :lid")
                    ->execute([':lid' => $lid]);
            } else {
                // Lot OK dipertahankan
                $lQty = (int)$lot['qty'];
                $totalReinspQty += $lQty;
                $newSessionLots[] = [
                    'ref_number' => $lot['ref_number'],
                    'lot_number' => $lot['lot_number'],
                    'qty'        => $lQty,
                    'remarks'    => $lot['remarks'],
                    'scanned_qr' => $lot['scanned_qr_raw'],
                    'orig_lot_id'=> $lid,
                    'is_replace' => false
                ];
            }
        }
    } elseif ($action === 'replace_all_lots') {
        // Mode Ganti Semua Lot Dalam Kanban
        if (empty($replacementLots)) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Detail lot pengganti wajib diisi']);
            exit;
        }

        // Karantina semua lot lama
        foreach ($origLots as $lot) {
            $pdo->prepare("UPDATE inspection_session_lots SET lot_status = 'ng_quarantine', action_noted_at = NOW() WHERE id = :lid")
                ->execute([':lid' => $lot['id']]);
        }

        // Masukkan seluruh lot pengganti baru
        foreach ($replacementLots as $rl) {
            $z1 = strtoupper(trim(sanitize($rl['new_z1'] ?? $partCode)));
            $z2 = strtoupper(trim(sanitize($rl['new_z2'] ?? '')));
            $z3 = (int)($rl['new_z3'] ?? 0);
            $z4 = sanitize($rl['new_z4'] ?? '');
            $z5 = sanitize($rl['new_z5'] ?? '');

            if (!$z2 || $z3 <= 0) continue;

            $refNo = $z5 ?: ('REF-REINSP-' . time() . '-' . rand(100, 999));
            $rawQr = 'Z1' . $z1 . '|Z2' . $z2 . '|Z3' . $z3 . ($z5 ? ('|Z5' . $z5) : '');

            $totalReinspQty += $z3;
            $newSessionLots[] = [
                'ref_number' => $refNo,
                'lot_number' => $z2,
                'qty'        => $z3,
                'remarks'    => $z4,
                'scanned_qr' => $rawQr,
                'orig_lot_id'=> null,
                'is_replace' => true
            ];
        }
    }

    if ($totalReinspQty <= 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Total Qty re-inspeksi tidak boleh 0']);
        exit;
    }

    // 3. Auto-create DID untuk lot utama jika belum ada
    $firstLotNum = $newSessionLots[0]['lot_number'] ?? $partCode;
    $stmtDidChk = $pdo->prepare("
        SELECT id FROM daily_inspection_data
        WHERE UPPER(part_code) = UPPER(:pc) AND UPPER(lot_number) = UPPER(:ln)
        ORDER BY id DESC LIMIT 1
    ");
    $stmtDidChk->execute([':pc' => $partCode, ':ln' => $firstLotNum]);
    $didRow = $stmtDidChk->fetch(PDO::FETCH_ASSOC);

    if (!$didRow) {
        $stmtInsDid = $pdo->prepare("
            INSERT INTO daily_inspection_data
              (part_code, part_name, lot_number, cavity, inspecting_date, status_inspect, pic, created_at)
            VALUES (:pc, :pn, :ln, :cav, CURDATE(), 'OK', 'RE_INSPECTION_SCAN', NOW())
        ");
        $stmtInsDid->execute([
            ':pc'  => $partCode,
            ':pn'  => $partName,
            ':ln'  => $firstLotNum,
            ':cav' => $origCavity,
        ]);
        $newDidId = (int)$pdo->lastInsertId();
    } else {
        $newDidId = (int)$didRow['id'];
    }

    // 4. Hitung AQL Baru berdasarkan totalReinspQty
    $aqlLevel = 'G-II';
    if ($origPartId) {
        $stmtPart = $pdo->prepare("SELECT aql_level FROM master_parts WHERE id = :pid LIMIT 1");
        $stmtPart->execute([':pid' => $origPartId]);
        $partRow = $stmtPart->fetch(PDO::FETCH_ASSOC);
        if (!empty($partRow['aql_level'])) $aqlLevel = $partRow['aql_level'];
    }

    $stmtAql = $pdo->prepare("
        SELECT sample_size, accept_number, reject_number, sample_code
        FROM aql_standards
        WHERE inspection_level = :lvl AND :qty BETWEEN qty_min AND qty_max
        LIMIT 1
    ");
    $stmtAql->execute([':lvl' => $aqlLevel, ':qty' => $totalReinspQty]);
    $aqlRow = $stmtAql->fetch(PDO::FETCH_ASSOC);
    if (!$aqlRow) {
        $stmtAqlFb = $pdo->prepare("SELECT sample_size, accept_number, reject_number, sample_code FROM aql_standards WHERE :qty BETWEEN qty_min AND qty_max LIMIT 1");
        $stmtAqlFb->execute([':qty' => $totalReinspQty]);
        $aqlRow = $stmtAqlFb->fetch(PDO::FETCH_ASSOC);
    }
    $sampleSize   = $aqlRow ? (int)$aqlRow['sample_size']   : 32;
    $rejectNumber = $aqlRow ? (int)$aqlRow['reject_number'] : 1;

    // Counter sample awal
    $initSamplesChecked = 0;
    if ($action === 'rescan_continue') {
        // Carry over sampel checked sebelumnya (hanya yang OK)
        $stmtCntOk = $pdo->prepare("SELECT COUNT(*) FROM inspection_samples WHERE inspection_session_id = :sid AND result = 'OK'");
        $stmtCntOk->execute([':sid' => $originalSessionId]);
        $initSamplesChecked = min((int)$stmtCntOk->fetchColumn(), $sampleSize - 1);
    }

    // 5. Buat Sesi Re-Inspeksi Baru
    $stmtInsReinsp = $pdo->prepare("
        INSERT INTO inspection_sessions
          (inspection_type, did_id, kanban_item_id, part_id,
           sample_size, total_scanned_qty, excess_qty, reject_number,
           samples_checked, ng_count, status,
           parent_session_id, is_reinspection, reinspection_type, reinspection_notes,
           inspector_id, started_at)
        VALUES
          (:itype, :did, :kid, :pid,
           :ss, :tqty, 0, :rnum,
           :schecked, 0, 'in_progress',
           :parent, 1, :rtype, :rnotes,
           :insp, NOW())
    ");
    $stmtInsReinsp->execute([
        ':itype'    => $origInspType,
        ':did'      => $newDidId,
        ':kid'      => $origKanbanId,
        ':pid'      => $origPartId,
        ':ss'       => $sampleSize,
        ':tqty'     => $totalReinspQty,
        ':rnum'     => $rejectNumber,
        ':schecked' => $initSamplesChecked,
        ':parent'   => $originalSessionId,
        ':rtype'    => $action,
        ':rnotes'   => $reinspNotes,
        ':insp'     => $_SESSION['user_id'] ?? null,
    ]);
    $reinspSessionId = (int)$pdo->lastInsertId();

    // Carry over OK samples jika rescan_continue
    if ($action === 'rescan_continue' && $initSamplesChecked > 0) {
        $stmtOkSamples = $pdo->prepare("SELECT sample_number, result FROM inspection_samples WHERE inspection_session_id = :sid AND result = 'OK' ORDER BY sample_number ASC LIMIT :limit");
        $stmtOkSamples->bindValue(':sid', $originalSessionId, PDO::PARAM_INT);
        $stmtOkSamples->bindValue(':limit', $initSamplesChecked, PDO::PARAM_INT);
        $stmtOkSamples->execute();
        $okSamples = $stmtOkSamples->fetchAll(PDO::FETCH_ASSOC);

        $stmtInsSp = $pdo->prepare("INSERT INTO inspection_samples (inspection_session_id, sample_number, result, checked_at) VALUES (:sid, :num, 'OK', NOW())");
        foreach ($okSamples as $idx => $sp) {
            $stmtInsSp->execute([
                ':sid' => $reinspSessionId,
                ':num' => ($idx + 1)
            ]);
        }
    }

    // 6. Insert Lots ke Sesi Re-Inspeksi Baru & Log Audit
    $stmtInsLot = $pdo->prepare("
        INSERT INTO inspection_session_lots
          (inspection_session_id, ref_number, lot_number, qty, remarks, scanned_qr_raw, lot_status, created_at)
        VALUES (:sid, :ref, :lot, :qty, :rem, :raw, 'ok', NOW())
    ");

    $stmtInsLog = $pdo->prepare("
        INSERT INTO lot_substitution_log
          (original_session_id, ng_session_lot_id, action_type,
           replacement_session_lot_id, reinspection_session_id,
           actioned_by, notes, created_at)
        VALUES (:orig, :nglot, :atype, :replot, :reinsp, :uid, :notes, NOW())
    ");

    foreach ($newSessionLots as $nl) {
        $stmtInsLot->execute([
            ':sid' => $reinspSessionId,
            ':ref' => $nl['ref_number'],
            ':lot' => $nl['lot_number'],
            ':qty' => $nl['qty'],
            ':rem' => $nl['remarks'],
            ':raw' => $nl['scanned_qr'],
        ]);
        $insertedLotId = (int)$pdo->lastInsertId();

        if ($nl['is_replace'] && !empty($nl['orig_lot_id'])) {
            $pdo->prepare("UPDATE inspection_session_lots SET replaced_by_lot_id = :rby WHERE id = :lid")
                ->execute([':rby' => $insertedLotId, ':lid' => $nl['orig_lot_id']]);
        }

        if (!empty($nl['orig_lot_id'])) {
            $stmtInsLog->execute([
                ':orig'   => $originalSessionId,
                ':nglot'  => $nl['orig_lot_id'],
                ':atype'  => $action,
                ':replot' => $nl['is_replace'] ? $insertedLotId : null,
                ':reinsp' => $reinspSessionId,
                ':uid'    => $_SESSION['user_id'] ?? null,
                ':notes'  => $reinspNotes,
            ]);
        }
    }

    $pdo->commit();

    $actionLabels = [
        'rescan_restart'  => 'Scan Ulang Kanban (Ulang dari Sample #1)',
        'rescan_continue' => 'Scan Ulang Kanban (Lanjut Sisa Sample)',
        'replace_ng_only' => 'Ganti Lot NG Saja',
        'replace_all_lots' => 'Ganti Seluruh Lot Kanban',
    ];
    $labelStr = $actionLabels[$action] ?? 'Re-Inspeksi';

    echo json_encode([
        'success'                 => true,
        'reinspection_session_id' => $reinspSessionId,
        'action_type'             => $action,
        'sample_size'             => $sampleSize,
        'reject_number'           => $rejectNumber,
        'samples_checked'         => $initSamplesChecked,
        'message'                 => "✅ {$labelStr} berhasil! Sesi Re-Inspeksi #{$reinspSessionId} dibuat. Sample: {$sampleSize} pcs, Batas NG: {$rejectNumber}.",
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}
