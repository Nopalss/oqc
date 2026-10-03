<?php
/**
 * AJAX Endpoint: Scan & Validate Label (Part Code + Lot Number)
 * Supports explicit Planning selection (kanban_item_id) matching & DID validation
 */
header('Content-Type: application/json');
ob_start();
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
session_write_close();

$partCode = strtoupper(trim(sanitize($_REQUEST['part_code'] ?? '')));
$lotNumber = strtoupper(trim(sanitize($_REQUEST['lot_number'] ?? '')));
$kanbanItemId = (int)($_REQUEST['kanban_item_id'] ?? 0);
$reqInspectionType = sanitize($_REQUEST['inspection_type'] ?? '');

if (empty($partCode) || empty($lotNumber)) {
    if (ob_get_length()) ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Part Code dan Lot Number wajib diisi!'
    ]);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    if (ob_get_length()) ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Gagal terhubung ke database server!'
    ]);
    exit;
}

try {
    $kanbanRow = null;

    // 1. If explicit Planning selected, validate Part Code matching
    if ($kanbanItemId > 0) {
        $stmtSelected = $pdo->prepare("SELECT k.*, b.document_number, b.vendor, b.plan_type as batch_plan_type 
                                       FROM kanban_items k 
                                       JOIN kanban_batches b ON b.id = k.batch_id 
                                       WHERE k.id = :kid LIMIT 1");
        $stmtSelected->execute([':kid' => $kanbanItemId]);
        $kanbanRow = $stmtSelected->fetch(PDO::FETCH_ASSOC);

        if ($kanbanRow) {
            $expectedCode = strtoupper(trim($kanbanRow['item_code']));
            if ($expectedCode !== $partCode) {
                if (ob_get_length()) ob_clean();
                echo json_encode([
                    'success' => false,
                    'error_type' => 'part_mismatch',
                    'message' => "⚠️ PART CODE TIDAK COCOK!\nLabel barcode yang discan adalah Part \"{$partCode}\", sedangkan Planning yang Anda pilih di Step 1 adalah Part \"{$expectedCode}\" ({$kanbanRow['item_description']}). Pastikan Anda menduga label barang yang sesuai!"
                ]);
                exit;
            }
        }
    }

    // Fallback: If no explicit planning selected or not found, match via FIFO by part code
    // JANGAN fallback FIFO untuk Safety Stock — harus pakai kanban_item bertipe safety_stock
    if (!$kanbanRow && $reqInspectionType !== 'safety_stock') {
        $stmtKanban = $pdo->prepare("SELECT k.*, b.document_number, b.vendor, b.plan_type as batch_plan_type 
                                     FROM kanban_items k 
                                     JOIN kanban_batches b ON b.id = k.batch_id 
                                     WHERE UPPER(k.item_code) = :pcode 
                                     ORDER BY CASE WHEN k.plan_type = 'kanban' THEN 1 ELSE 2 END ASC, k.id ASC 
                                     LIMIT 1");
        $stmtKanban->execute([':pcode' => $partCode]);
        $kanbanRow = $stmtKanban->fetch(PDO::FETCH_ASSOC);
    }

    // 2. Validasi DID (Daily Inspection Data) - Memastikan lot sudah melampaui Cek Dimensi
    $stmtDid = $pdo->prepare("SELECT * FROM daily_inspection_data WHERE UPPER(part_code) = :pcode AND UPPER(lot_number) = :lot ORDER BY id DESC LIMIT 1");
    $stmtDid->execute([':pcode' => $partCode, ':lot' => $lotNumber]);
    $didRow = $stmtDid->fetch(PDO::FETCH_ASSOC);

    // Auto-create DID if missing for Safety Stock Warehouse Scan
    if (!$didRow && ($reqInspectionType === 'safety_stock' || ($kanbanRow && ($kanbanRow['plan_type'] ?? '') === 'safety_stock'))) {
        $stmtMP = $pdo->prepare("SELECT part_name FROM master_parts WHERE UPPER(part_code) = :pcode LIMIT 1");
        $stmtMP->execute([':pcode' => $partCode]);
        $officialPartName = $stmtMP->fetchColumn();
        $partNameDid = $officialPartName ?: ($kanbanRow['item_description'] ?? ('Part ' . $partCode));

        $stmtInsDid = $pdo->prepare("INSERT INTO daily_inspection_data (part_code, part_name, lot_number, cavity, inspecting_date, status_inspect, pic, created_at) VALUES (:pcode, :pname, :lot, '1', CURDATE(), 'OK', 'SAFETY_STOCK_SCAN', NOW())");
        $stmtInsDid->execute([':pcode' => $partCode, ':pname' => $partNameDid, ':lot' => $lotNumber]);
        $didIdNew = $pdo->lastInsertId();

        $didRow = [
            'id' => $didIdNew,
            'part_code' => $partCode,
            'part_name' => $partNameDid,
            'lot_number' => $lotNumber,
            'cavity' => '1',
            'inspecting_date' => date('Y-m-d'),
            'status_inspect' => 'OK',
            'pic' => 'SAFETY_STOCK_SCAN'
        ];
    }

    if (!$didRow) {
        // Fetch available DID lot numbers for this part code to assist the user (ANSI SQL & MySQL Strict Mode compatible)
        $stmtAvail = $pdo->prepare("SELECT lot_number, MAX(inspecting_date) AS inspecting_date, status_inspect FROM daily_inspection_data WHERE UPPER(part_code) = :pcode GROUP BY lot_number, status_inspect ORDER BY MAX(id) DESC LIMIT 5");
        $stmtAvail->execute([':pcode' => $partCode]);
        $availableLots = $stmtAvail->fetchAll(PDO::FETCH_ASSOC);

        if (ob_get_length()) ob_clean();
        echo json_encode([
            'success' => false,
            'error_type' => 'did_missing',
            'message' => "Lot Number \"{$lotNumber}\" untuk Part \"{$partCode}\" BELUM tercatat/lolos di Daily Inspection Data (DID). Pastikan barang sudah melalui cek dimensi produksi.",
            'available_lots' => $availableLots
        ]);
        exit;
    }

    if (strtoupper($didRow['status_inspect']) === 'NG') {
        if (ob_get_length()) ob_clean();
        echo json_encode([
            'success' => false,
            'error_type' => 'did_ng',
            'message' => "PERINGATAN: Lot Number \"{$lotNumber}\" tercatat ber-status NG pada Daily Inspection Data (DID). Pengecekan OQC dihentikan."
        ]);
        exit;
    }

    // Fast-path: Quick real-time check per scanned label
    if (isset($_REQUEST['mode']) && $_REQUEST['mode'] === 'check_did_only') {
        $refParam = strtoupper(trim(sanitize($_REQUEST['ref_number'] ?? '')));
        $currSessionId = (int)($_REQUEST['current_session_id'] ?? 0);

        // 1. Cek apakah label ini adalah bagian dari Safety Stock yang tersedia (Hanya jika sedang inspeksi Kanban)
        if ($reqInspectionType !== 'safety_stock' && !empty($refParam)) {
            $stmtSSAvail = $pdo->prepare("
                SELECT isl.id AS lot_id, s.id AS session_id, isl.lot_number, isl.ref_number, COALESCE(isl.qty, s.total_scanned_qty) AS available_qty
                FROM inspection_sessions s
                JOIN inspection_session_lots isl ON isl.inspection_session_id = s.id
                JOIN daily_inspection_data d ON d.id = s.did_id
                WHERE s.inspection_type = 'safety_stock'
                  AND s.status != 'in_progress'
                  AND (s.auto_fulfilled_by_session_id IS NULL OR s.auto_fulfilled_by_session_id = 0)
                  AND (isl.lot_status = 'ok' OR isl.lot_status IS NULL)
                  AND (isl.lot_result IS NULL OR isl.lot_result IN ('passed', 'skipped'))
                  AND isl.qty > 0
                  AND UPPER(d.part_code) = :pcode
                  AND UPPER(isl.ref_number) = :ref
                ORDER BY s.started_at ASC, isl.id ASC
                LIMIT 1
            ");
            $stmtSSAvail->execute([':pcode' => $partCode, ':ref' => $refParam]);
            $ssAvailRow = $stmtSSAvail->fetch(PDO::FETCH_ASSOC);

            // Fallback: cari by lot_number HANYA jika ref_number kosong di barcode
            if (!$ssAvailRow && empty($refParam) && !empty($lotNumber)) {
                $stmtSSAvailLot = $pdo->prepare("
                    SELECT isl.id AS lot_id, s.id AS session_id, isl.lot_number, isl.ref_number, COALESCE(isl.qty, s.total_scanned_qty) AS available_qty
                    FROM inspection_sessions s
                    JOIN inspection_session_lots isl ON isl.inspection_session_id = s.id
                    JOIN daily_inspection_data d ON d.id = s.did_id
                    WHERE s.inspection_type = 'safety_stock'
                      AND s.status != 'in_progress'
                      AND (s.auto_fulfilled_by_session_id IS NULL OR s.auto_fulfilled_by_session_id = 0)
                      AND (isl.lot_status = 'ok' OR isl.lot_status IS NULL)
                      AND (isl.lot_result IS NULL OR isl.lot_result IN ('passed', 'skipped'))
                      AND isl.qty > 0
                      AND UPPER(d.part_code) = :pcode
                      AND UPPER(isl.lot_number) = :lot
                    ORDER BY s.started_at ASC, isl.id ASC
                    LIMIT 1
                ");
                $stmtSSAvailLot->execute([':pcode' => $partCode, ':lot' => $lotNumber]);
                $ssAvailRow = $stmtSSAvailLot->fetch(PDO::FETCH_ASSOC);
            }

            if ($ssAvailRow) {
                if (ob_get_length()) ob_clean();
                echo json_encode([
                    'success' => true,
                    'is_safety_stock' => true,
                    'message' => "Label terdeteksi sebagai Safety Stock Gudang.",
                    'ss_lot' => [
                        'lot_id' => (int)$ssAvailRow['lot_id'],
                        'session_id' => (int)$ssAvailRow['session_id'],
                        'lot_number' => $ssAvailRow['lot_number'],
                        'ref_number' => $ssAvailRow['ref_number'],
                        'available_qty' => (int)$ssAvailRow['available_qty']
                    ]
                ]);
                exit;
            }
        }

        // 2. Cek apakah Ref Number ini sedang aktif diinspeksi pada Sesi Lain (in_progress lock)
        if (!empty($refParam)) {
            $stmtInProg = $pdo->prepare("
                SELECT s.id AS session_id, s.inspection_type, s.line_name, u.name AS inspector_name, s.started_at, isl.lot_number, isl.ref_number, ki.kanban_no
                FROM inspection_session_lots isl
                JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                LEFT JOIN users u ON u.id = s.inspector_id
                LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                WHERE UPPER(isl.ref_number) = :ref
                  AND s.status = 'in_progress'
                  AND (:curr_sid1 = 0 OR s.id != :curr_sid2)
                LIMIT 1
            ");
            $stmtInProg->execute([':ref' => $refParam, ':curr_sid1' => $currSessionId, ':curr_sid2' => $currSessionId]);
            $inProgRow = $stmtInProg->fetch(PDO::FETCH_ASSOC);
            if ($inProgRow) {
                $sessDesc = ($inProgRow['inspection_type'] === 'safety_stock') ? 'Safety Stock Gudang' : ('Kanban ' . ($inProgRow['kanban_no'] ?: ('#' . $inProgRow['session_id'])));
                $timeDesc = !empty($inProgRow['started_at']) ? date('H:i', strtotime($inProgRow['started_at'])) : '-';
                if (ob_get_length()) ob_clean();
                echo json_encode([
                    'success' => false,
                    'error_type' => 'in_progress_duplicate',
                    'message' => "Label Box Ref \"{$refParam}\" saat ini sedang aktif diinspeksi pada Sesi #{$inProgRow['session_id']} [{$sessDesc}] di Line: " . ($inProgRow['line_name'] ?: '-') . " oleh " . ($inProgRow['inspector_name'] ?: 'Inspector') . " sejak pukul {$timeDesc} WIB. Label tidak dapat digunakan bersamaan!",
                    'session' => $inProgRow
                ]);
                exit;
            }

            // 3. Cek apakah Ref Number ini sudah pernah PASSED di Sesi Lain (Anti-Double Data)
            $stmtPassed = $pdo->prepare("
                SELECT s.id AS session_id, s.inspection_type, s.closed_at, u.name AS inspector_name, ki.kanban_no, ki.customer, isl.ref_number, isl.lot_number
                FROM inspection_session_lots isl
                JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                LEFT JOIN users u ON u.id = s.inspector_id
                LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                WHERE UPPER(isl.ref_number) = :ref
                  AND (isl.lot_result = 'passed' OR s.status = 'passed')
                  AND (:curr_sid1 = 0 OR s.id != :curr_sid2)
                ORDER BY s.id DESC
                LIMIT 1
            ");
            $stmtPassed->execute([':ref' => $refParam, ':curr_sid1' => $currSessionId, ':curr_sid2' => $currSessionId]);
            $passedRow = $stmtPassed->fetch(PDO::FETCH_ASSOC);
            if ($passedRow) {
                $closedTime = !empty($passedRow['closed_at']) ? date('d M Y, H:i', strtotime($passedRow['closed_at'])) : '-';
                $contextDesc = ($passedRow['inspection_type'] === 'safety_stock') ? 'Safety Stock Gudang' : ('Kanban ' . ($passedRow['kanban_no'] ?: ('#' . $passedRow['session_id'])) . ' (' . ($passedRow['customer'] ?: '-') . ')');
                if (ob_get_length()) ob_clean();
                echo json_encode([
                    'success' => false,
                    'error_type' => 'already_passed_duplicate',
                    'message' => "Label Box Ref Number \"{$refParam}\" (Lot: {$passedRow['lot_number']}) SUDAH PERNAH diinspeksi & dinyatakan PASSED pada Sesi #{$passedRow['session_id']} [{$contextDesc}] oleh QC " . ($passedRow['inspector_name'] ?: 'Inspector') . " pada {$closedTime}. Label ini tidak dapat digunakan kembali demi mencegah duplikasi data!",
                    'session' => $passedRow
                ]);
                exit;
            }

            // 4. Cek apakah Ref Number ini sebelumnya pernah REJECTED (Safety Stock atau Kanban)
            $stmtRej = $pdo->prepare("
                SELECT s.id AS session_id, s.inspection_type, s.closed_at, u.name AS inspector_name, ki.kanban_no, isl.ref_number, isl.lot_number, isl.remarks
                FROM inspection_session_lots isl
                JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                LEFT JOIN users u ON u.id = s.inspector_id
                LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                WHERE UPPER(isl.ref_number) = :ref
                  AND (isl.lot_result = 'rejected' OR isl.lot_status IN ('ng_found', 'ng_quarantine', 'replaced') OR s.status = 'rejected')
                  AND (:curr_sid1 = 0 OR s.id != :curr_sid2)
                ORDER BY s.id DESC
                LIMIT 1
            ");
            $stmtRej->execute([':ref' => $refParam, ':curr_sid1' => $currSessionId, ':curr_sid2' => $currSessionId]);
            $rejRow = $stmtRej->fetch(PDO::FETCH_ASSOC);
            if ($rejRow) {
                if (ob_get_length()) ob_clean();
                echo json_encode([
                    'success' => true,
                    'was_rejected_before' => true,
                    'message' => "Label sebelumnya tercatat REJECTED.",
                    'rejected_info' => [
                        'session_id' => (int)$rejRow['session_id'],
                        'inspection_type' => $rejRow['inspection_type'],
                        'kanban_no' => $rejRow['kanban_no'] ?: '-',
                        'inspector_name' => $rejRow['inspector_name'] ?: 'Inspector QC',
                        'closed_at' => !empty($rejRow['closed_at']) ? date('d M Y, H:i', strtotime($rejRow['closed_at'])) : '-',
                        'ref_number' => $rejRow['ref_number'],
                        'lot_number' => $rejRow['lot_number'],
                        'remarks' => $rejRow['remarks'] ?: '-'
                    ],
                    'did' => [
                        'id' => $didRow['id'],
                        'inspecting_date' => $didRow['inspecting_date'],
                        'cavity' => $didRow['cavity'],
                        'pic' => $didRow['pic'],
                        'status' => $didRow['status_inspect']
                    ]
                ]);
                exit;
            }
        }

        if (ob_get_length()) ob_clean();
        echo json_encode([
            'success' => true,
            'message' => "Lot Number \"{$lotNumber}\" terverifikasi lolos cek dimensi (DID).",
            'did' => [
                'id' => $didRow['id'],
                'inspecting_date' => $didRow['inspecting_date'],
                'cavity' => $didRow['cavity'],
                'pic' => $didRow['pic'],
                'status' => $didRow['status_inspect']
            ]
        ]);
        exit;
    }

    // 2.B. Validasi Status Pengerjaan Terdahulu di Safety Stock (Auto-Bypass PASSED & Peringatan REJECTED)
    $refParam = strtoupper(trim(sanitize($_REQUEST['ref_number'] ?? '')));
    $scannedRefRaw = $_REQUEST['scanned_ref_numbers'] ?? '[]';
    $scannedRefs = is_string($scannedRefRaw) ? json_decode($scannedRefRaw, true) : (is_array($scannedRefRaw) ? $scannedRefRaw : []);
    if (!is_array($scannedRefs)) $scannedRefs = [];
    if (!empty($refParam)) $scannedRefs[] = $refParam;
    $scannedRefs = array_values(array_unique(array_filter(array_map('strtoupper', array_map('trim', $scannedRefs)))));

    // Validasi lintas sesi untuk seluruh scannedRefs (in_progress lock & already_passed lock)
    if (!empty($scannedRefs)) {
        $inRefPlaceholders = implode(',', array_fill(0, count($scannedRefs), '?'));

        // Cek apakah ada label yang sedang in_progress di sesi lain
        $sqlChkActive = "
            SELECT s.id AS session_id, s.inspection_type, s.line_name, u.name AS inspector_name, isl.ref_number, ki.kanban_no
            FROM inspection_session_lots isl
            JOIN inspection_sessions s ON s.id = isl.inspection_session_id
            LEFT JOIN users u ON u.id = s.inspector_id
            LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
            WHERE UPPER(isl.ref_number) IN ($inRefPlaceholders)
              AND s.status = 'in_progress'
            LIMIT 1
        ";
        $stmtChkActive = $pdo->prepare($sqlChkActive);
        $stmtChkActive->execute($scannedRefs);
        $activeRow = $stmtChkActive->fetch(PDO::FETCH_ASSOC);
        if ($activeRow) {
            $sessDesc = ($activeRow['inspection_type'] === 'safety_stock') ? 'Safety Stock Gudang' : ('Kanban ' . ($activeRow['kanban_no'] ?: ('#' . $activeRow['session_id'])));
            if (ob_get_length()) ob_clean();
            echo json_encode([
                'success' => false,
                'error_type' => 'in_progress_duplicate',
                'message' => "Label Box Ref \"{$activeRow['ref_number']}\" saat ini sedang aktif diinspeksi pada Sesi #{$activeRow['session_id']} [{$sessDesc}] (Line: " . ($activeRow['line_name'] ?: '-') . "). Label tidak dapat digunakan bersamaan!"
            ]);
            exit;
        }

        // Cek apakah ada label yang sudah PASSED di sesi Kanban lain (mencegah double data)
        if ($reqInspectionType !== 'safety_stock') {
            $sqlChkPassed = "
                SELECT s.id AS session_id, s.inspection_type, s.closed_at, u.name AS inspector_name, isl.ref_number, ki.kanban_no, ki.customer
                FROM inspection_session_lots isl
                JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                LEFT JOIN users u ON u.id = s.inspector_id
                LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                WHERE UPPER(isl.ref_number) IN ($inRefPlaceholders)
                  AND (isl.lot_result = 'passed' OR s.status = 'passed')
                  AND s.inspection_type = 'kanban'
                LIMIT 1
            ";
            $stmtChkPassed = $pdo->prepare($sqlChkPassed);
            $stmtChkPassed->execute($scannedRefs);
            $passRow = $stmtChkPassed->fetch(PDO::FETCH_ASSOC);
            if ($passRow) {
                if (ob_get_length()) ob_clean();
                echo json_encode([
                    'success' => false,
                    'error_type' => 'already_passed_duplicate',
                    'message' => "Label Box Ref Number \"{$passRow['ref_number']}\" SUDAH PERNAH diinspeksi & dinyatakan PASSED pada Sesi #{$passRow['session_id']} (Kanban " . ($passRow['kanban_no'] ?: '-') . "). Label ini tidak dapat digunakan kembali untuk menghindari data ganda!"
                ]);
                exit;
            }
        }
    }

    $prevSSRow = null;

    $useSsQty = (int)($_REQUEST['use_safety_stock_qty'] ?? 0);
    if ($reqInspectionType === 'safety_stock') {
        // If inspecting Safety Stock, check if any of the specific Ref Numbers being scanned were ALREADY passed/rejected
        if (!empty($scannedRefs)) {
            $inRefPlaceholders = implode(',', array_fill(0, count($scannedRefs), '?'));
            // B2: Hapus UPPER() - part_code & lot_number selalu uppercase di DB, index bisa dipakai
            // B3: Fix OR JOIN -> pure FK join
            $sqlPrevRef = "
                SELECT ss.*, isl.ref_number AS matched_ref_number,
                       ki.item_code, ki.item_description, ki.customer, ki.id AS kanban_item_id,
                       did.lot_number, did.part_code, did.pic AS did_pic,
                       u.name AS inspector_name
                FROM inspection_sessions ss
                JOIN daily_inspection_data did ON did.id = ss.did_id
                JOIN inspection_session_lots isl ON isl.inspection_session_id = ss.id
                LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
                LEFT JOIN users u ON u.id = ss.inspector_id
                WHERE ss.inspection_type = 'safety_stock'
                  AND did.part_code = ?
                  AND did.lot_number = ?
                  AND isl.ref_number IN ($inRefPlaceholders)
                ORDER BY ss.id DESC LIMIT 1
            ";
            $paramsPrev = array_merge([$partCode, $lotNumber], $scannedRefs);
            $stmtPrevSS = $pdo->prepare($sqlPrevRef);
            $stmtPrevSS->execute($paramsPrev);
            $prevSSRow = $stmtPrevSS->fetch(PDO::FETCH_ASSOC);
        }
    } elseif ($useSsQty > 0) {
        // For Kanban customer inspection, ONLY check Safety Stock bypass IF user explicitly requested Safety Stock deduction
        // B2: Hapus UPPER() - sudah strtoupper() di atas, index composite bisa dipakai
        // B3: Fix OR JOIN -> pure FK join; kondisi 'safety_stock' cukup via inspection_type saja
        $stmtPrevSS = $pdo->prepare("
            SELECT ss.*, 
                   ki.item_code, ki.item_description, ki.customer, ki.id AS kanban_item_id,
                   did.lot_number, did.part_code, did.pic AS did_pic,
                   u.name AS inspector_name
            FROM inspection_sessions ss
            JOIN daily_inspection_data did ON did.id = ss.did_id
            LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
            LEFT JOIN users u ON u.id = ss.inspector_id
            WHERE ss.inspection_type = 'safety_stock'
              AND did.part_code = :pcode
              AND did.lot_number = :lot
            ORDER BY ss.id DESC LIMIT 1
        ");
        $stmtPrevSS->execute([':pcode' => $partCode, ':lot' => $lotNumber]);
        $prevSSRow = $stmtPrevSS->fetch(PDO::FETCH_ASSOC);
    }

    if ($prevSSRow) {
        if ($prevSSRow['status'] === 'passed') {
            $customerName = 'PT Customer';
            if ($kanbanItemId > 0) {
                // Fetch Kanban Item details to get customer name
                $stmtK = $pdo->prepare("SELECT customer FROM kanban_items WHERE id = :kid LIMIT 1");
                $stmtK->execute([':kid' => $kanbanItemId]);
                $kRow = $stmtK->fetch(PDO::FETCH_ASSOC);
                if ($kRow && !empty($kRow['customer'])) {
                    $customerName = $kRow['customer'];
                }

                // Check if session for this kanban_item_id already exists
                $stmtChkK = $pdo->prepare("SELECT id FROM inspection_sessions WHERE kanban_item_id = :kid LIMIT 1");
                $stmtChkK->execute([':kid' => $kanbanItemId]);
                $existKSess = $stmtChkK->fetch(PDO::FETCH_ASSOC);

                if ($existKSess) {
                    $stmtUpdK = $pdo->prepare("UPDATE inspection_sessions SET status = 'passed', did_id = :did, closed_at = COALESCE(closed_at, NOW()) WHERE id = :sid");
                    $stmtUpdK->execute([':did' => $prevSSRow['did_id'], ':sid' => $existKSess['id']]);
                    syncDailySummaryForSession($pdo, (int)$existKSess['id']);
                } else {
                    $stmtInsK = $pdo->prepare("INSERT INTO inspection_sessions 
                        (inspection_type, did_id, kanban_item_id, part_id, inspector_id, sample_size, batch_sample_size, reject_number, samples_checked, ng_count, status, started_at, closed_at) 
                        VALUES ('kanban', :did, :kanban, :part, :inspector, :ssize1, :batch_ssize, 1, :ssize2, 0, 'passed', NOW(), NOW())");
                    $stmtInsK->execute([
                        ':did'         => $prevSSRow['did_id'],
                        ':kanban'      => $kanbanItemId,
                        ':part'        => $prevSSRow['part_id'] ?? null,
                        ':inspector'   => $prevSSRow['inspector_id'] ?? null,
                        ':ssize1'      => (int)($prevSSRow['sample_size'] ?: 32),
                        ':batch_ssize' => (int)($prevSSRow['sample_size'] ?: 32),
                        ':ssize2'      => (int)($prevSSRow['sample_size'] ?: 32)
                    ]);
                    $newKSessId = (int)$pdo->lastInsertId();
                    syncDailySummaryForSession($pdo, $newKSessId);
                }
            }

            echo json_encode([
                'success' => true,
                'already_safety_stock_passed' => true,
                'message' => "Lot Number \"{$lotNumber}\" " . (!empty($prevSSRow['matched_ref_number']) ? "(Ref No: {$prevSSRow['matched_ref_number']}) " : "") . "sudah terdaftar & PASSED (Lolos) di Safety Stock.",
                'session_id' => (int)$prevSSRow['id'],
                'kanban_item_id' => $kanbanItemId ?: (int)($prevSSRow['kanban_item_id'] ?? $prevSSRow['id']),
                'inspector_name' => $prevSSRow['inspector_name'] ?: ($prevSSRow['did_pic'] ?: 'Inspector QC'),
                'customer' => $customerName,
                'matched_ref_number' => $prevSSRow['matched_ref_number'] ?? '',
                'closed_at' => $prevSSRow['closed_at'] ? date('d M Y, H:i', strtotime($prevSSRow['closed_at'])) : '-'
            ]);
            exit;
        } elseif ($prevSSRow['status'] === 'rejected') {
            echo json_encode([
                'success' => true,
                'already_safety_stock_rejected' => true,
                'message' => "⚠️ PERINGATAN: Lot Number \"{$lotNumber}\" " . (!empty($prevSSRow['matched_ref_number']) ? "(Ref No: {$prevSSRow['matched_ref_number']}) " : "") . "sebelumnya tercatat REJECTED (NG) di Safety Stock.",
                'session_id' => (int)$prevSSRow['id'],
                'kanban_item_id' => (int)($prevSSRow['kanban_item_id'] ?? $prevSSRow['id']),
                'inspector_name' => $prevSSRow['inspector_name'] ?: ($prevSSRow['did_pic'] ?: 'Inspector QC'),
                'matched_ref_number' => $prevSSRow['matched_ref_number'] ?? '',
                'closed_at' => $prevSSRow['closed_at'] ? date('d M Y, H:i', strtotime($prevSSRow['closed_at'])) : '-'
            ]);
            exit;
        }
    }

    // Prioritaskan reqInspectionType jika eksplisit 'safety_stock'; jangan biarkan kanbanRow dari order lain mengoverride
    $inspectionType = ($reqInspectionType === 'safety_stock')
        ? 'safety_stock'
        : (($kanbanRow && ($kanbanRow['plan_type'] ?? 'kanban') === 'safety_stock') ? 'safety_stock' : 'kanban');
    $reqTotalScanned = clean_qty($_REQUEST['total_scanned_qty'] ?? 0);
    $totalQty = ($reqTotalScanned > 0) ? $reqTotalScanned : ($kanbanRow ? clean_qty($kanbanRow['qty']) : 0); // Priority to Total Scanned Qty
    // Safety Stock selalu customer INTERNAL — jangan ambil customer dari kanbanRow order lain
    if ($inspectionType === 'safety_stock') {
        $customerName = 'INTERNAL SAFETY STOCK';
    } else {
        $customerName = $kanbanRow ? ($kanbanRow['customer'] ?? 'PT. Indonesia Epson Industry') : 'PT. Indonesia Epson Industry';
    }

    // 3. Fetch Master Part & Master Drawings (2D PDF & 3D STP) to get assigned aql_level
    $stmtPart = $pdo->prepare("SELECT p.id as part_id, p.part_code, p.part_name, p.aql_level, COALESCE(m.name, p.model) as part_model, d.drawing_2d_path, d.drawing_3d_path 
                               FROM master_parts p 
                               LEFT JOIN master_models m ON m.id = p.model_id
                               LEFT JOIN master_drawings d ON d.part_id = p.id 
                               WHERE UPPER(p.part_code) = :pcode LIMIT 1");
    $stmtPart->execute([':pcode' => $partCode]);
    $partRow = $stmtPart->fetch(PDO::FETCH_ASSOC);


    // Auto-create Master Part if not existing
    if (!$partRow) {
        $partName = $didRow['part_name'] ?? ($kanbanRow['item_description'] ?? 'Part ' . $partCode);
        $stmtInsPart = $pdo->prepare("INSERT INTO master_parts (part_code, part_name, aql_level, source, created_at) VALUES (:code, :name, 'G-II', 'auto_generated', NOW())");
        $stmtInsPart->execute([':code' => $partCode, ':name' => $partName]);
        $newPartId = $pdo->lastInsertId();

        $partRow = [
            'part_id' => $newPartId,
            'part_code' => $partCode,
            'part_name' => $partName,
            'aql_level' => 'G-II',
            'drawing_2d_path' => null,
            'drawing_3d_path' => null
        ];
    }

    $aqlLevel = !empty($partRow['aql_level']) ? $partRow['aql_level'] : 'G-II';

    // 4. AQL Sampling Calculation based on Part's assigned AQL Level (G-I, G-II, G-III)
    $physScanQty = max(0, $totalQty - $useSsQty);
    $aqlCalcQty = ($useSsQty > 0 && $reqInspectionType === 'kanban') ? max(1, $physScanQty) : $totalQty;

    $stmtAql = $pdo->prepare("SELECT * FROM aql_standards WHERE inspection_level = :lvl AND :qty BETWEEN qty_min AND qty_max LIMIT 1");
    $stmtAql->execute([':lvl' => $aqlLevel, ':qty' => $aqlCalcQty]);
    $aqlRow = $stmtAql->fetch(PDO::FETCH_ASSOC);

    if (!$aqlRow) {
        // Fallback search without inspection_level if specific row not found
        $stmtAqlFB = $pdo->prepare("SELECT * FROM aql_standards WHERE :qty BETWEEN qty_min AND qty_max LIMIT 1");
        $stmtAqlFB->execute([':qty' => $aqlCalcQty]);
        $aqlRow = $stmtAqlFB->fetch(PDO::FETCH_ASSOC);
    }

    if (!$aqlRow) {
        $aqlRow = ['sample_size' => 50, 'reject_number' => 1, 'sample_code' => 'H', 'accept_number' => 0];
    }

    if (ob_get_length()) ob_clean();

    // Resolve drawing assets from filesystem convention
    $fsDrawings = get_part_drawing_assets($partRow['part_code'], $partRow['part_model'] ?? '');
    $drawing2d = $fsDrawings['drawing_2d_url'] ?? ($partRow['drawing_2d_path'] ? base_url($partRow['drawing_2d_path']) : null);
    $drawing3d = $fsDrawings['drawing_3d_url'] ?? ($partRow['drawing_3d_path'] ? base_url($partRow['drawing_3d_path']) : null);

    echo json_encode([
        'success' => true,
        'did' => [
            'id' => $didRow['id'],
            'inspecting_date' => $didRow['inspecting_date'],
            'cavity' => $didRow['cavity'],
            'pic' => $didRow['pic'],
            'status' => $didRow['status_inspect']
        ],
        'inspection_type' => $inspectionType,
        'kanban' => $kanbanRow ? [
            'id' => $kanbanRow['id'],
            'plan_type' => $kanbanRow['plan_type'] ?? 'kanban',
            'kanban_no' => $kanbanRow['kanban_no'] ?? ($inspectionType === 'safety_stock' ? 'SAFETY-STOCK' : '-'),
            'document_number' => $kanbanRow['document_number'],
            'customer' => $customerName,
            'qty' => (int)$kanbanRow['qty'],
            'req_date' => $kanbanRow['req_date'],
            'eta' => $kanbanRow['eta'],
            'check_type' => $kanbanRow['check_type'] ?? ''
        ] : null,
        'part' => [
            'id' => $partRow['part_id'],
            'part_code' => $partRow['part_code'],
            'part_name' => $partRow['part_name'],
            'aql_level' => $aqlLevel,
            'drawing_2d' => $drawing2d,
            'drawing_3d' => $drawing3d
        ],
        'aql' => [
            'total_qty' => $totalQty,
            'sample_size' => (int)$aqlRow['sample_size'],
            'reject_number' => (int)$aqlRow['reject_number'],
            'sample_code' => $aqlRow['sample_code'] ?? 'H',
            'accept_number' => (int)($aqlRow['accept_number'] ?? 0),
            'standard' => $aqlLevel . ' / AQL 0.4'
        ]
    ]);

} catch (PDOException $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Kesalahan Database: ' . $e->getMessage()
    ]);
}
