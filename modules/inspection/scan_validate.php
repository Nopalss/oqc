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

// Production Security Guard: Require authenticated user
if (!is_logged_in()) {
    if (ob_get_length()) ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi login Anda telah berakhir. Silakan login kembali.', 'unauthorized' => true]);
    exit;
}
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
                                     WHERE k.item_code = :pcode 
                                     ORDER BY CASE WHEN k.plan_type = 'kanban' THEN 1 ELSE 2 END ASC, k.id ASC 
                                     LIMIT 1");
        $stmtKanban->execute([':pcode' => $partCode]);
        $kanbanRow = $stmtKanban->fetch(PDO::FETCH_ASSOC);
    }

    // 1.B. Validasi Master Part - Memastikan Part Code sudah terdaftar di Master Data Part
    $stmtPartCheck = $pdo->prepare("SELECT p.id as part_id, p.part_code, p.part_name, p.aql_level, COALESCE(m.name, p.model) as part_model, d.drawing_2d_path, d.drawing_3d_path 
                                    FROM master_parts p 
                                    LEFT JOIN master_models m ON m.id = p.model_id
                                    LEFT JOIN master_drawings d ON d.part_id = p.id 
                                    WHERE p.part_code = :pcode LIMIT 1");
    $stmtPartCheck->execute([':pcode' => $partCode]);
    $partRow = $stmtPartCheck->fetch(PDO::FETCH_ASSOC);

    if (!$partRow) {
        if (ob_get_length()) ob_clean();
        echo json_encode([
            'success' => false,
            'error_type' => 'master_part_missing',
            'message' => "PERINGATAN: Part Code \"{$partCode}\" BELUM TERDAFTAR di Master Data Part!\nSilakan daftarkan part ini terlebih dahulu melalui menu Master Data Part sebelum melakukan inspeksi OQC."
        ]);
        exit;
    }

    // 2. Validasi DID (Daily Inspection Data) - Memastikan lot sudah melampaui Cek Dimensi
    $stmtDid = $pdo->prepare("SELECT * FROM daily_inspection_data WHERE part_code = :pcode AND lot_number = :lot ORDER BY id DESC LIMIT 1");
    $stmtDid->execute([':pcode' => $partCode, ':lot' => $lotNumber]);
    $didRow = $stmtDid->fetch(PDO::FETCH_ASSOC);


    if (!$didRow) {
        // Fetch available DID lot numbers for this part code to assist the user (ANSI SQL & MySQL Strict Mode compatible)
        $stmtAvail = $pdo->prepare("SELECT lot_number, MAX(inspecting_date) AS inspecting_date, status_inspect FROM daily_inspection_data WHERE part_code = :pcode GROUP BY lot_number, status_inspect ORDER BY MAX(id) DESC LIMIT 5");
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

        // 0. Cek apakah Lot/Label ini pernah di-ABOLISH (Scrap / Dimusnahkan permanen)
        $refAbolishSql = !empty($refParam) ? "AND UPPER(isl.ref_number) = UPPER(:ref_ab)" : "";
        $stmtAbolish = $pdo->prepare("
            SELECT isl.id, isl.lot_number, isl.ref_number, isl.lot_status, lqc.closure_type, lqc.notes, lqc.closed_at, u.name AS closed_by_name
            FROM inspection_session_lots isl
            JOIN inspection_sessions s ON s.id = isl.inspection_session_id
            LEFT JOIN lot_quarantine_closures lqc ON lqc.session_lot_id = isl.id
            LEFT JOIN users u ON u.id = lqc.closed_by
            LEFT JOIN daily_inspection_data did ON did.id = s.did_id
            LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
            LEFT JOIN master_parts mp ON mp.id = s.part_id
            WHERE UPPER(isl.lot_number) = UPPER(:lot_ab)
              AND (UPPER(did.part_code) = UPPER(:pc1) OR UPPER(ki.item_code) = UPPER(:pc2) OR UPPER(mp.part_code) = UPPER(:pc3))
              AND (
                  isl.lot_status = 'abolished'
                  OR lqc.closure_type = 'abolish'
              )
              {$refAbolishSql}
            ORDER BY isl.id DESC
            LIMIT 1
        ");
        $abParams = [
            ':lot_ab' => $lotNumber,
            ':pc1'    => $partCode,
            ':pc2'    => $partCode,
            ':pc3'    => $partCode
        ];
        if (!empty($refParam)) {
            $abParams[':ref_ab'] = $refParam;
        }
        $stmtAbolish->execute($abParams);
        $abolishRow = $stmtAbolish->fetch(PDO::FETCH_ASSOC);

        if ($abolishRow) {
            $abDate = !empty($abolishRow['closed_at']) ? date('d M Y, H:i', strtotime($abolishRow['closed_at'])) . ' WIB' : '-';
            $abNotes = !empty($abolishRow['notes']) ? $abolishRow['notes'] : 'Karantina Dimusnahkan (Scrap)';
            $abUser = !empty($abolishRow['closed_by_name']) ? $abolishRow['closed_by_name'] : 'Admin/Supervisor';
            $abRef = !empty($abolishRow['ref_number']) ? " (Ref #{$abolishRow['ref_number']})" : (!empty($refParam) ? " (Ref #{$refParam})" : "");
            
            if (ob_get_length()) ob_clean();
            echo json_encode([
                'success' => false,
                'error_type' => 'lot_abolished',
                'message' => "⛔ LABEL / LOT TELAH DI-ABOLISH!\nPart \"{$partCode}\" Lot \"{$lotNumber}\"{$abRef} telah diputuskan ABOLISH (Scrap / Dimusnahkan) oleh {$abUser} pada {$abDate} dengan catatan: \"{$abNotes}\". Label ini TIDAK DAPAT digunakan atau diinspeksi kembali!",
                'details' => [
                    'part_code' => $partCode,
                    'lot_number' => $lotNumber,
                    'ref_number' => $abolishRow['ref_number'] ?: $refParam,
                    'closed_at' => $abDate,
                    'closed_by' => $abUser,
                    'notes' => $abNotes
                ]
            ]);
            exit;
        }

        // 1. Cek apakah Ref Number ini sedang aktif diinspeksi pada Sesi Lain (in_progress lock)
        if (empty($reqInspectionType) && $currSessionId > 0) {
            $stmtSessType = $pdo->prepare("SELECT inspection_type FROM inspection_sessions WHERE id = :sid LIMIT 1");
            $stmtSessType->execute([':sid' => $currSessionId]);
            $reqInspectionType = $stmtSessType->fetchColumn() ?: 'kanban';
        }
        if (!empty($refParam)) {
            // In-progress lock: 3-way match (part_code + lot + ref)
            $stmtInProg = $pdo->prepare("
                SELECT s.id AS session_id, s.inspection_type, s.line_name, u.name AS inspector_name, s.started_at, isl.lot_number, isl.ref_number, ki.kanban_no
                FROM inspection_session_lots isl
                JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                LEFT JOIN users u ON u.id = s.inspector_id
                LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                LEFT JOIN master_parts mp ON mp.id = s.part_id
                WHERE isl.ref_number = :ref
                  AND isl.lot_number = :lot
                  AND (UPPER(did.part_code) = UPPER(:pcode1) OR UPPER(ki.item_code) = UPPER(:pcode2) OR UPPER(mp.part_code) = UPPER(:pcode3))
                  AND s.status = 'in_progress'
                  AND (:curr_sid1 = 0 OR s.id != :curr_sid2)
                LIMIT 1
            ");
            $stmtInProg->execute([':ref' => $refParam, ':lot' => $lotNumber, ':pcode1' => $partCode, ':pcode2' => $partCode, ':pcode3' => $partCode, ':curr_sid1' => $currSessionId, ':curr_sid2' => $currSessionId]);
            $inProgRow = $stmtInProg->fetch(PDO::FETCH_ASSOC);
            if ($inProgRow) {
                $sessDesc = ($inProgRow['inspection_type'] === 'safety_stock') ? 'Safety Stock Gudang' : ('Kanban ' . ($inProgRow['kanban_no'] ?: ('#' . $inProgRow['session_id'])));
                $timeDesc = !empty($inProgRow['started_at']) ? date('H:i', strtotime($inProgRow['started_at'])) : '-';
                if (ob_get_length()) ob_clean();
                echo json_encode([
                    'success' => false,
                    'error_type' => 'in_progress_duplicate',
                    'message' => "Label Box Part \"{$partCode}\" / Lot \"{$lotNumber}\" / Ref \"{$refParam}\" saat ini sedang aktif diinspeksi pada Sesi #{$inProgRow['session_id']} [{$sessDesc}] di Line: " . ($inProgRow['line_name'] ?: '-') . " oleh " . ($inProgRow['inspector_name'] ?: 'Inspector') . " sejak pukul {$timeDesc} WIB. Label tidak dapat digunakan bersamaan!",
                    'session' => $inProgRow
                ]);
                exit;
            }
        }

        // 1.5 Evaluasi Record Inspeksi Terakhir (Latest Inspection Record) dengan Strict 3-Way Matching (Part Code + Lot Number + Ref Number)
        $latestRecord = null;
        if (!empty($refParam)) {
            $stmtLatest = $pdo->prepare("
                SELECT 
                    s.id AS session_id, 
                    s.inspection_type, 
                    s.status AS session_status,
                    s.closed_at, 
                    u.name AS inspector_name, 
                    ki.kanban_no, 
                    ki.customer,
                    COALESCE(p.part_code, d.part_code, ki.item_code, :pcode_sel1) AS part_code,
                    COALESCE(p.part_name, d.part_name, ki.item_description, '-') AS part_name,
                    isl.id AS session_lot_id, 
                    isl.ref_number, 
                    isl.lot_number, 
                    isl.qty, 
                    isl.remarks,
                    isl.lot_result,
                    isl.lot_status
                FROM inspection_session_lots isl
                JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                LEFT JOIN users u ON u.id = s.inspector_id
                LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                LEFT JOIN daily_inspection_data d ON d.id = s.did_id
                LEFT JOIN master_parts p ON p.id = s.part_id
                WHERE UPPER(isl.ref_number) = UPPER(:ref)
                  AND UPPER(isl.lot_number) = UPPER(:lot)
                  AND (UPPER(d.part_code) = UPPER(:pcode1) OR UPPER(ki.item_code) = UPPER(:pcode2) OR UPPER(p.part_code) = UPPER(:pcode3))
                  AND s.status != 'in_progress'
                  AND (:curr_sid1 = 0 OR s.id != :curr_sid2)
                ORDER BY isl.id DESC, s.id DESC
                LIMIT 1
            ");
            $stmtLatest->execute([
                ':pcode_sel1' => $partCode,
                ':ref'        => $refParam,
                ':lot'        => $lotNumber,
                ':pcode1'     => $partCode,
                ':pcode2'     => $partCode,
                ':pcode3'     => $partCode,
                ':curr_sid1'  => $currSessionId,
                ':curr_sid2'  => $currSessionId
            ]);
            $latestRecord = $stmtLatest->fetch(PDO::FETCH_ASSOC);
        } else {
            // Fallback: Jika Ref Number kosong, filter by Lot Number & Part Code
            $stmtLatest = $pdo->prepare("
                SELECT 
                    s.id AS session_id, 
                    s.inspection_type, 
                    s.status AS session_status,
                    s.closed_at, 
                    u.name AS inspector_name, 
                    ki.kanban_no, 
                    ki.customer,
                    COALESCE(p.part_code, d.part_code, ki.item_code, :pcode_sel2) AS part_code,
                    COALESCE(p.part_name, d.part_name, ki.item_description, '-') AS part_name,
                    isl.id AS session_lot_id, 
                    isl.ref_number, 
                    isl.lot_number, 
                    isl.qty, 
                    isl.remarks,
                    isl.lot_result,
                    isl.lot_status
                FROM inspection_session_lots isl
                JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                LEFT JOIN users u ON u.id = s.inspector_id
                LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                LEFT JOIN daily_inspection_data d ON d.id = s.did_id
                LEFT JOIN master_parts p ON p.id = s.part_id
                WHERE UPPER(isl.lot_number) = UPPER(:lot)
                  AND (UPPER(d.part_code) = UPPER(:pcode1) OR UPPER(ki.item_code) = UPPER(:pcode2) OR UPPER(p.part_code) = UPPER(:pcode3))
                  AND s.status != 'in_progress'
                  AND (:curr_sid1 = 0 OR s.id != :curr_sid2)
                ORDER BY isl.id DESC, s.id DESC
                LIMIT 1
            ");
            $stmtLatest->execute([
                ':pcode_sel2' => $partCode,
                ':lot'        => $lotNumber,
                ':pcode1'     => $partCode,
                ':pcode2'     => $partCode,
                ':pcode3'     => $partCode,
                ':curr_sid1'  => $currSessionId,
                ':curr_sid2'  => $currSessionId
            ]);
            $latestRecord = $stmtLatest->fetch(PDO::FETCH_ASSOC);
        }

        $isLatestRejected = false;
        $isLatestPassed = false;

        if ($latestRecord) {
            // Pastikan part_name terisi
            if (empty($latestRecord['part_name']) || $latestRecord['part_name'] === '-') {
                $stmtPn = $pdo->prepare("SELECT part_name FROM master_parts WHERE UPPER(part_code) = UPPER(:pc) LIMIT 1");
                $stmtPn->execute([':pc' => $latestRecord['part_code']]);
                $latestRecord['part_name'] = $stmtPn->fetchColumn() ?: ($didRow['part_name'] ?? '-');
            }

            // Prioritaskan status REJECTED jika lot itu sendiri reject, di-replace, atau ada penanganan karantina
            $isLotRejectedExplicitly = (
                $latestRecord['lot_result'] === 'rejected' ||
                in_array($latestRecord['lot_status'], ['ng_found', 'ng_quarantine', 'replaced', 'rejected'])
            );

            if ($isLotRejectedExplicitly || $latestRecord['session_status'] === 'rejected') {
                $isLatestRejected = true;
            } elseif (!$isLotRejectedExplicitly && (in_array($latestRecord['lot_result'], ['passed', 'skipped']) || $latestRecord['session_status'] === 'passed')) {
                $isLatestPassed = true;
            }
        }

        // 2. Evaluasi Status Terakhir PASSED
        if ($isLatestPassed) {
            $matchedRef = !empty($latestRecord['ref_number']) && $latestRecord['ref_number'] !== '-' ? $latestRecord['ref_number'] : ($refParam ?: $lotNumber);

            // Skenario A: Jika sedang di-scan di Safety Stock Gudang -> TOLAK DUPLIKAT!
            if ($reqInspectionType === 'safety_stock') {
                $sessDesc = ($latestRecord['inspection_type'] === 'safety_stock') ? 'Safety Stock Gudang' : ('Sesi Kanban #' . $latestRecord['session_id']);
                if (ob_get_length()) ob_clean();
                echo json_encode([
                    'success' => false,
                    'error_type' => 'already_passed_duplicate',
                    'message' => "Label Box Ref Number \"{$matchedRef}\" (Part: {$partCode}, Lot: {$lotNumber}) SUDAH PERNAH diinspeksi & dinyatakan PASSED pada {$sessDesc}. Label ini tidak dapat diinspeksi ulang di Safety Stock Gudang!",
                    'session' => $latestRecord,
                    'details' => $latestRecord
                ]);
                exit;
            }

            // Skenario B: Jika di-scan di Kanban
            // Jika inspeksi terakhir adalah Kanban -> Tolak duplikat di Kanban (Kecuali jika masih ada sisa stok di Safety Stock Gudang)
            if ($latestRecord['inspection_type'] !== 'safety_stock') {
                $hasActiveSS = false;
                if (!empty($refParam)) {
                    $stmtSSAvailChk = $pdo->prepare("
                        SELECT 1 FROM inspection_session_lots isl
                        JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                        LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                        LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                        LEFT JOIN master_parts mp ON mp.id = s.part_id
                        WHERE s.inspection_type = 'safety_stock'
                          AND s.status = 'passed'
                          AND (s.auto_fulfilled_by_session_id IS NULL OR s.auto_fulfilled_by_session_id = 0)
                          AND (isl.lot_status IS NULL OR isl.lot_status IN ('ok', 'reinspected', 'passed', ''))
                          AND (isl.lot_result IS NULL OR isl.lot_result IN ('passed', 'skipped'))
                          AND isl.qty > 0
                          AND UPPER(isl.ref_number) = UPPER(:ref)
                          AND UPPER(isl.lot_number) = UPPER(:lot)
                          AND (UPPER(did.part_code) = UPPER(:p1) OR UPPER(ki.item_code) = UPPER(:p2) OR UPPER(mp.part_code) = UPPER(:p3))
                        LIMIT 1
                    ");
                    $stmtSSAvailChk->execute([
                        ':ref' => $refParam,
                        ':lot' => $lotNumber,
                        ':p1'  => $partCode,
                        ':p2'  => $partCode,
                        ':p3'  => $partCode
                    ]);
                    $hasActiveSS = (bool)$stmtSSAvailChk->fetchColumn();
                }

                if (!$hasActiveSS) {
                    $contextDesc = 'Sesi #' . $latestRecord['session_id'] . ($latestRecord['kanban_no'] ? (' (Kanban ' . $latestRecord['kanban_no'] . ')') : '');
                    if (ob_get_length()) ob_clean();
                    echo json_encode([
                        'success' => false,
                        'error_type' => 'already_passed_duplicate',
                        'message' => "Label Box Ref Number \"{$matchedRef}\" (Part: {$partCode}, Lot: {$lotNumber}) SUDAH PERNAH diinspeksi & dinyatakan PASSED pada {$contextDesc}. Label ini tidak dapat digunakan kembali untuk menghindari data ganda!",
                        'session' => $latestRecord,
                        'details' => $latestRecord
                    ]);
                    exit;
                }
            }

            // Cek ketersediaan sisa Safety Stock jika di-scan dari Kanban
            if (!empty($refParam)) {
                $stmtSSAvail = $pdo->prepare("
                    SELECT isl.id AS lot_id, s.id AS session_id, isl.lot_number, isl.ref_number, 
                           COALESCE(isl.qty, s.total_scanned_qty, 0) AS available_qty
                    FROM inspection_session_lots isl
                    JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                    LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                    LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                    LEFT JOIN master_parts mp ON mp.id = s.part_id
                    WHERE s.inspection_type = 'safety_stock'
                      AND s.status = 'passed'
                      AND (s.auto_fulfilled_by_session_id IS NULL OR s.auto_fulfilled_by_session_id = 0)
                      AND (isl.lot_status IS NULL OR isl.lot_status IN ('ok', 'reinspected', 'passed', ''))
                      AND (isl.lot_result IS NULL OR isl.lot_result IN ('passed', 'skipped'))
                      AND isl.qty > 0
                      AND UPPER(isl.ref_number) = UPPER(:ref)
                      AND UPPER(isl.lot_number) = UPPER(:lot)
                      AND (UPPER(did.part_code) = UPPER(:p1) OR UPPER(ki.item_code) = UPPER(:p2) OR UPPER(mp.part_code) = UPPER(:p3))
                    ORDER BY s.id DESC
                    LIMIT 1
                ");
                $stmtSSAvail->execute([
                    ':ref' => $refParam,
                    ':lot' => $lotNumber,
                    ':p1'  => $partCode,
                    ':p2'  => $partCode,
                    ':p3'  => $partCode
                ]);
                $ssAvailRow = $stmtSSAvail->fetch(PDO::FETCH_ASSOC);

                if ($ssAvailRow && (int)$ssAvailRow['available_qty'] > 0) {
                    // Cek juga apakah box Safety Stock ini memiliki riwayat cacat/NG lampau (Eks-NG / Rework)
                    $ssHistRejectedInfo = null;
                    try {
                        $whereSsHist = [
                            "s.id != :curr_sid",
                            "(isl.lot_result = 'rejected' OR isl.lot_status IN ('ng_found', 'ng_quarantine', 'replaced', 'rejected') OR EXISTS (SELECT 1 FROM inspection_ng_records ngr WHERE ngr.session_lot_id = isl.id AND (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)))",
                            "UPPER(isl.lot_number) = UPPER(:lot)",
                            "UPPER(isl.ref_number) = UPPER(:ref)",
                            "(UPPER(did.part_code) = UPPER(:p1) OR UPPER(ki.item_code) = UPPER(:p2) OR UPPER(mp.part_code) = UPPER(:p3))"
                        ];
                        $sqlSsHist = "
                            SELECT isl.id AS session_lot_id, isl.lot_number, isl.ref_number, isl.qty, isl.remarks,
                                   s.id AS session_id, s.inspection_type, s.closed_at, s.started_at,
                                   ki.kanban_no, u.name AS inspector_name
                            FROM inspection_session_lots isl
                            JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                            LEFT JOIN users u ON u.id = s.inspector_id
                            LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                            LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                            LEFT JOIN master_parts mp ON mp.id = s.part_id
                            WHERE " . implode(' AND ', $whereSsHist) . "
                            ORDER BY isl.id DESC, s.id DESC
                            LIMIT 1
                        ";
                        $stmtSsH = $pdo->prepare($sqlSsHist);
                        $stmtSsH->execute([
                            ':curr_sid' => $currSessionId,
                            ':lot'      => $lotNumber,
                            ':ref'      => $refParam,
                            ':p1'       => $partCode,
                            ':p2'       => $partCode,
                            ':p3'       => $partCode
                        ]);
                        $ssHLot = $stmtSsH->fetch(PDO::FETCH_ASSOC);

                        if ($ssHLot) {
                            $ssSlotId = (int)$ssHLot['session_lot_id'];
                            $ssDefects = [];
                            if ($ssSlotId > 0) {
                                $stmtSsNg = $pdo->prepare("
                                    SELECT r.qty_ng, r.remark, COALESCE(dt.name, r.remark, 'Defect Lainnya') AS defect_name, dt.code AS defect_code
                                    FROM inspection_ng_records r
                                    LEFT JOIN defect_types dt ON dt.id = r.defect_type_id
                                    WHERE r.session_lot_id = :slot_id AND (r.is_cancelled IS NULL OR r.is_cancelled = 0)
                                    ORDER BY r.id ASC
                                ");
                                $stmtSsNg->execute([':slot_id' => $ssSlotId]);
                                $ssDefects = $stmtSsNg->fetchAll(PDO::FETCH_ASSOC);
                            }

                            $ssDisp = null;
                            $stmtSsCl = $pdo->prepare("
                                SELECT lqc.*, u.name AS closed_by_name
                                FROM lot_quarantine_closures lqc
                                LEFT JOIN users u ON u.id = lqc.closed_by
                                WHERE lqc.session_lot_id = :slot_id
                                ORDER BY lqc.id DESC LIMIT 1
                            ");
                            $stmtSsCl->execute([':slot_id' => $ssSlotId]);
                            $ssClRow = $stmtSsCl->fetch(PDO::FETCH_ASSOC);
                            if ($ssClRow) {
                                $ssDisp = [
                                    'has_disposition' => true,
                                    'notes'           => $ssClRow['notes'],
                                    'closed_by'       => $ssClRow['closed_by_name'] ?: 'Admin / Supervisor',
                                    'closed_at'       => !empty($ssClRow['closed_at']) ? date('d M Y, H:i', strtotime($ssClRow['closed_at'])) . ' WIB' : '-'
                                ];
                            }

                            $ssHistRejectedInfo = [
                                'session_id'      => (int)$ssHLot['session_id'],
                                'session_lot_id'  => $ssSlotId,
                                'inspection_type' => $ssHLot['inspection_type'],
                                'kanban_no'       => $ssHLot['kanban_no'] ?: '-',
                                'inspector_name'  => $ssHLot['inspector_name'] ?: 'Inspector QC',
                                'closed_at'       => !empty($ssHLot['closed_at']) ? date('d M Y, H:i', strtotime($ssHLot['closed_at'])) : '-',
                                'ref_number'      => $ssHLot['ref_number'],
                                'lot_number'      => $ssHLot['lot_number'],
                                'qty'             => (int)($ssHLot['qty'] ?? 0),
                                'remarks'         => $ssHLot['remarks'] ?: '-',
                                'ng_defects'      => $ssDefects,
                                'disposition'     => $ssDisp
                            ];
                        }
                    } catch (Exception $eSsHist) {}

                    // Deteksi Replenished Box (Box eks-potong yang sudah digenapi kembali oleh Gudang ke Qty aslinya)
                    $scannedBarcodeQty = (int)($_REQUEST['barcode_qty'] ?? 0);
                    $availQty = (int)$ssAvailRow['available_qty'];
                    $isReplenished = false;
                    $replenishInfo = null;

                    if ($scannedBarcodeQty > $availQty) {
                        // Terdeteksi: Barcode fisik memiliki Qty lebih besar dari sisa pecahan di database (misal Barcode 200 pcs vs sisa di DB 100 pcs)
                        // Artinya box telah di-refill / digenapi kembali oleh Gudang!
                        $isReplenished = true;
                        $prevCutQty = $scannedBarcodeQty - $availQty;

                        // Cari info sesi pemotong terdahulu
                        $prevCutKanban = $ssAvailRow['parent_kanban_no'] ?? ($ssAvailRow['kanban_no'] ?? '-');
                        $prevCutRem = $ssAvailRow['remarks'] ?? '';

                        $replenishInfo = [
                            'original_barcode_qty' => $scannedBarcodeQty,
                            'available_in_db'      => $availQty,
                            'previously_taken_qty' => $prevCutQty,
                            'parent_session_id'    => (int)$ssAvailRow['session_id'],
                            'parent_kanban_no'     => $prevCutKanban,
                            'remarks'              => $prevCutRem
                        ];
                    }

                    $ssResponse = [
                        'success'            => true,
                        'is_safety_stock'    => true,
                        'is_replenished_box' => $isReplenished,
                        'message'            => $isReplenished 
                            ? "Box Eks-Potong Terdeteksi: Pernah diambil {$replenishInfo['previously_taken_qty']} pcs, kini telah digenapi kembali ke {$scannedBarcodeQty} pcs oleh Gudang."
                            : "Label terdeteksi sebagai Safety Stock Gudang (Sisa stok: {$availQty} pcs).",
                        'ss_lot'             => [
                            'lot_id'        => (int)$ssAvailRow['lot_id'],
                            'session_id'    => (int)$ssAvailRow['session_id'],
                            'lot_number'    => $ssAvailRow['lot_number'],
                            'ref_number'    => $ssAvailRow['ref_number'],
                            'available_qty' => $isReplenished ? $scannedBarcodeQty : $availQty
                        ]
                    ];

                    if ($isReplenished) {
                        $ssResponse['replenish_info'] = $replenishInfo;
                    }

                    if ($ssHistRejectedInfo !== null) {
                        $ssResponse['was_rejected_before'] = true;
                        $ssResponse['rejected_info']       = $ssHistRejectedInfo;
                    }

                    if (ob_get_length()) ob_clean();
                    echo json_encode($ssResponse);
                    exit;
                } else {
                    // Safety stock terdaftar tapi stoknya sudah habis (0 pcs) -> TOLAK!
                    if (ob_get_length()) ob_clean();
                    echo json_encode([
                        'success'    => false,
                        'error_type' => 'safety_stock_exhausted',
                        'message'    => "⛔ SAFETY STOCK SUDAH HABIS!\nLabel Safety Stock Ref \"{$refParam}\" (Part: {$partCode}, Lot: {$lotNumber}) sudah terpakai seluruhnya di sesi lain (Sisa stok: 0 pcs). Box ini tidak dapat digunakan lagi!"
                    ]);
                    exit;
                }
            }
        }

        // 4.B Cek apakah label ini PERNAH memiliki riwayat NG / Rejected di masa lalu
        // (Berlaku untuk box Fresh, Safety Stock, maupun Re-inspeksi agar user selalu berhati-hati)
        $historicalRejectedInfo = null;
        try {
            $whereHist = [
                "s.id != :curr_sid",
                "(isl.lot_result = 'rejected' OR isl.lot_status IN ('ng_found', 'ng_quarantine', 'replaced', 'rejected') OR EXISTS (SELECT 1 FROM inspection_ng_records ngr WHERE ngr.session_lot_id = isl.id AND (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)))"
            ];
            $paramsHist = [
                ':curr_sid' => $currSessionId,
                ':lot'      => $lotNumber,
                ':p1'       => $partCode,
                ':p2'       => $partCode,
                ':p3'       => $partCode
            ];
            $whereHist[] = "UPPER(isl.lot_number) = UPPER(:lot)";
            $whereHist[] = "(UPPER(did.part_code) = UPPER(:p1) OR UPPER(ki.item_code) = UPPER(:p2) OR UPPER(mp.part_code) = UPPER(:p3))";
            
            if (!empty($refParam)) {
                $whereHist[] = "UPPER(isl.ref_number) = UPPER(:ref)";
                $paramsHist[':ref'] = $refParam;
            }

            $sqlHist = "
                SELECT isl.id AS session_lot_id, isl.lot_number, isl.ref_number, isl.qty, isl.remarks,
                       s.id AS session_id, s.inspection_type, s.closed_at, s.started_at,
                       ki.kanban_no, u.name AS inspector_name
                FROM inspection_session_lots isl
                JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                LEFT JOIN users u ON u.id = s.inspector_id
                LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                LEFT JOIN master_parts mp ON mp.id = s.part_id
                WHERE " . implode(' AND ', $whereHist) . "
                ORDER BY isl.id DESC, s.id DESC
                LIMIT 1
            ";
            $stmtHist = $pdo->prepare($sqlHist);
            $stmtHist->execute($paramsHist);
            $histLot = $stmtHist->fetch(PDO::FETCH_ASSOC);

            if ($histLot) {
                $hSlotId = (int)$histLot['session_lot_id'];
                $hDefects = [];
                if ($hSlotId > 0) {
                    $stmtHNg = $pdo->prepare("
                        SELECT r.qty_ng, r.remark, COALESCE(dt.name, r.remark, 'Defect Lainnya') AS defect_name, dt.code AS defect_code
                        FROM inspection_ng_records r
                        LEFT JOIN defect_types dt ON dt.id = r.defect_type_id
                        WHERE r.session_lot_id = :slot_id AND (r.is_cancelled IS NULL OR r.is_cancelled = 0)
                        ORDER BY r.id ASC
                    ");
                    $stmtHNg->execute([':slot_id' => $hSlotId]);
                    $hDefects = $stmtHNg->fetchAll(PDO::FETCH_ASSOC);
                }

                // Ambil catatan disposisi
                $hDisp = null;
                $stmtHCl = $pdo->prepare("
                    SELECT lqc.*, u.name AS closed_by_name
                    FROM lot_quarantine_closures lqc
                    LEFT JOIN users u ON u.id = lqc.closed_by
                    WHERE lqc.session_lot_id = :slot_id
                    ORDER BY lqc.id DESC LIMIT 1
                ");
                $stmtHCl->execute([':slot_id' => $hSlotId]);
                $hClRow = $stmtHCl->fetch(PDO::FETCH_ASSOC);
                if ($hClRow) {
                    $hDisp = [
                        'has_disposition' => true,
                        'notes'           => $hClRow['notes'],
                        'closed_by'       => $hClRow['closed_by_name'] ?: 'Admin / Supervisor',
                        'closed_at'       => !empty($hClRow['closed_at']) ? date('d M Y, H:i', strtotime($hClRow['closed_at'])) . ' WIB' : '-'
                    ];
                }

                $historicalRejectedInfo = [
                    'session_id'      => (int)$histLot['session_id'],
                    'session_lot_id'  => $hSlotId,
                    'inspection_type' => $histLot['inspection_type'],
                    'kanban_no'       => $histLot['kanban_no'] ?: '-',
                    'inspector_name'  => $histLot['inspector_name'] ?: 'Inspector QC',
                    'closed_at'       => !empty($histLot['closed_at']) ? date('d M Y, H:i', strtotime($histLot['closed_at'])) : '-',
                    'ref_number'      => $histLot['ref_number'],
                    'lot_number'      => $histLot['lot_number'],
                    'qty'             => (int)($histLot['qty'] ?? 0),
                    'remarks'         => $histLot['remarks'] ?: '-',
                    'ng_defects'      => $hDefects,
                    'disposition'     => $hDisp
                ];
            }
        } catch (Exception $eHist) {}

        // 4. Evaluasi Status Terakhir REJECTED -> Tampilkan Modal Peringatan!
        if ($isLatestRejected) {
            $slotId = (int)($latestRecord['session_lot_id'] ?? 0);
            $ngDefects = [];
            if ($slotId > 0) {
                $stmtNg = $pdo->prepare("
                    SELECT 
                        r.qty_ng,
                        r.remark,
                        COALESCE(dt.name, r.remark, 'Defect Lainnya') AS defect_name,
                        dt.code AS defect_code
                    FROM inspection_ng_records r
                    LEFT JOIN defect_types dt ON dt.id = r.defect_type_id
                    WHERE r.session_lot_id = :slot_id
                      AND (r.is_cancelled IS NULL OR r.is_cancelled = 0)
                    ORDER BY r.id ASC
                ");
                $stmtNg->execute([':slot_id' => $slotId]);
                $ngDefects = $stmtNg->fetchAll(PDO::FETCH_ASSOC);
            }

            // Ambil data disposisi / penutupan karantina administratif jika ada
            $dispositionInfo = null;
            if ($slotId > 0) {
                $stmtClosure = $pdo->prepare("
                    SELECT lqc.*, u.name AS closed_by_name
                    FROM lot_quarantine_closures lqc
                    LEFT JOIN users u ON u.id = lqc.closed_by
                    WHERE lqc.session_lot_id = :slot_id
                    ORDER BY lqc.id DESC LIMIT 1
                ");
                $stmtClosure->execute([':slot_id' => $slotId]);
                $closureRow = $stmtClosure->fetch(PDO::FETCH_ASSOC);

                if ($closureRow) {
                    $dispositionInfo = [
                        'has_disposition' => true,
                        'notes'           => $closureRow['notes'],
                        'closed_by'       => $closureRow['closed_by_name'] ?: 'Admin / Supervisor',
                        'closed_at'       => !empty($closureRow['closed_at']) ? date('d M Y, H:i', strtotime($closureRow['closed_at'])) . ' WIB' : '-'
                    ];
                } elseif (!empty($latestRecord['chief_notes'])) {
                    $dispositionInfo = [
                        'has_disposition' => true,
                        'notes'           => $latestRecord['chief_notes'],
                        'closed_by'       => $latestRecord['chief_name'] ?: 'Chief QC / Supervisor',
                        'closed_at'       => !empty($latestRecord['closed_at']) ? date('d M Y, H:i', strtotime($latestRecord['closed_at'])) . ' WIB' : '-'
                    ];
                }
            }

            // Fallback: Jika slotId tidak langsung cocok, cari berdasarkan Part Code + Lot Number (+ Ref jika ada)
            if (!$dispositionInfo) {
                $refFilterSql = !empty($latestRecord['ref_number']) ? "AND UPPER(isl2.ref_number) = UPPER(:ref2)" : "";
                $stmtClosureFallback = $pdo->prepare("
                    SELECT lqc.*, u.name AS closed_by_name
                    FROM lot_quarantine_closures lqc
                    JOIN inspection_session_lots isl2 ON isl2.id = lqc.session_lot_id
                    JOIN inspection_sessions s2 ON s2.id = isl2.inspection_session_id
                    LEFT JOIN daily_inspection_data did2 ON did2.id = s2.did_id
                    LEFT JOIN kanban_items ki2 ON ki2.id = s2.kanban_item_id
                    LEFT JOIN master_parts mp2 ON mp2.id = s2.part_id
                    LEFT JOIN users u ON u.id = lqc.closed_by
                    WHERE UPPER(isl2.lot_number) = UPPER(:lot2)
                      AND (UPPER(did2.part_code) = UPPER(:p1) OR UPPER(ki2.item_code) = UPPER(:p2) OR UPPER(mp2.part_code) = UPPER(:p3))
                      {$refFilterSql}
                    ORDER BY lqc.id DESC LIMIT 1
                ");
                $paramsFallback = [
                    ':lot2' => $lotNumber,
                    ':p1'   => $partCode,
                    ':p2'   => $partCode,
                    ':p3'   => $partCode
                ];
                if (!empty($latestRecord['ref_number'])) {
                    $paramsFallback[':ref2'] = $latestRecord['ref_number'];
                }
                $stmtClosureFallback->execute($paramsFallback);
                $fbClosureRow = $stmtClosureFallback->fetch(PDO::FETCH_ASSOC);
                if ($fbClosureRow) {
                    $dispositionInfo = [
                        'has_disposition' => true,
                        'notes'           => $fbClosureRow['notes'],
                        'closed_by'       => $fbClosureRow['closed_by_name'] ?: 'Admin / Supervisor',
                        'closed_at'       => !empty($fbClosureRow['closed_at']) ? date('d M Y, H:i', strtotime($fbClosureRow['closed_at'])) . ' WIB' : '-'
                    ];
                }
            }

            if (ob_get_length()) ob_clean();
            echo json_encode([
                'success' => true,
                'was_rejected_before' => true,
                'message' => "Label sebelumnya tercatat REJECTED.",
                'rejected_info' => [
                    'session_id' => (int)$latestRecord['session_id'],
                    'session_lot_id' => $slotId,
                    'inspection_type' => $latestRecord['inspection_type'],
                    'kanban_no' => $latestRecord['kanban_no'] ?: '-',
                    'inspector_name' => $latestRecord['inspector_name'] ?: 'Inspector QC',
                    'closed_at' => !empty($latestRecord['closed_at']) ? date('d M Y, H:i', strtotime($latestRecord['closed_at'])) : '-',
                    'ref_number' => $latestRecord['ref_number'],
                    'lot_number' => $latestRecord['lot_number'],
                    'qty' => (int)($latestRecord['qty'] ?? 0),
                    'remarks' => $latestRecord['remarks'] ?: '-',
                    'ng_defects' => $ngDefects,
                    'disposition' => $dispositionInfo
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

        // Jika tidak berstatus latest rejected, namun PERNAH memiliki riwayat NG di masa lalu (misal eks-NG yang sudah PASSED di Safety Stock)
        $respPayload = [
            'success' => true,
            'message' => "Lot Number \"{$lotNumber}\" terverifikasi lolos cek dimensi (DID).",
            'did' => [
                'id' => $didRow['id'],
                'inspecting_date' => $didRow['inspecting_date'],
                'cavity' => $didRow['cavity'],
                'pic' => $didRow['pic'],
                'status' => $didRow['status_inspect']
            ]
        ];

        if ($historicalRejectedInfo !== null) {
            $respPayload['was_rejected_before'] = true;
            $respPayload['rejected_info'] = $historicalRejectedInfo;
        }

        if (ob_get_length()) ob_clean();
        echo json_encode($respPayload);
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
    // 3-way match: Part Code + Lot Number + Ref Number
    if (!empty($scannedRefs)) {
        $inRefPlaceholders = implode(',', array_fill(0, count($scannedRefs), '?'));
        $partLotCond = "
              AND UPPER(isl.lot_number) = ?
              AND (UPPER(did.part_code) = ? OR UPPER(ki.item_code) = ? OR UPPER(mp.part_code) = ?)
        ";
        $partLotParams = [$lotNumber, $partCode, $partCode, $partCode];

        // Cek apakah ada label yang berstatus ABOLISHED
        $sqlChkAbolish = "
            SELECT isl.ref_number, isl.lot_number, isl.lot_status, lqc.closure_type, lqc.notes, lqc.closed_at, u.name AS closed_by_name
            FROM inspection_session_lots isl
            JOIN inspection_sessions s ON s.id = isl.inspection_session_id
            LEFT JOIN lot_quarantine_closures lqc ON lqc.session_lot_id = isl.id
            LEFT JOIN users u ON u.id = lqc.closed_by
            LEFT JOIN daily_inspection_data did ON did.id = s.did_id
            LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
            LEFT JOIN master_parts mp ON mp.id = s.part_id
            WHERE UPPER(isl.ref_number) IN ($inRefPlaceholders)
              $partLotCond
              AND (
                  isl.lot_status = 'abolished'
                  OR lqc.closure_type = 'abolish'
              )
            ORDER BY isl.id DESC
            LIMIT 1
        ";
        $stmtChkAbolish = $pdo->prepare($sqlChkAbolish);
        $stmtChkAbolish->execute(array_merge($scannedRefs, $partLotParams));
        $abMultiRow = $stmtChkAbolish->fetch(PDO::FETCH_ASSOC);
        if ($abMultiRow) {
            $abDate = !empty($abMultiRow['closed_at']) ? date('d M Y, H:i', strtotime($abMultiRow['closed_at'])) . ' WIB' : '-';
            $abNotes = !empty($abMultiRow['notes']) ? $abMultiRow['notes'] : 'Karantina Dimusnahkan (Scrap)';
            $abUser = !empty($abMultiRow['closed_by_name']) ? $abMultiRow['closed_by_name'] : 'Admin/Supervisor';
            if (ob_get_length()) ob_clean();
            echo json_encode([
                'success' => false,
                'error_type' => 'lot_abolished',
                'message' => "⛔ LABEL / LOT TELAH DI-ABOLISH!\nPart \"{$partCode}\" Lot \"{$lotNumber}\" (Ref #{$abMultiRow['ref_number']}) telah diputuskan ABOLISH (Scrap / Dimusnahkan) oleh {$abUser} pada {$abDate} dengan catatan: \"{$abNotes}\". Label ini TIDAK DAPAT digunakan atau diinspeksi kembali!"
            ]);
            exit;
        }

        // Cek apakah ada label yang sedang in_progress di sesi lain
        $sqlChkActive = "
            SELECT s.id AS session_id, s.inspection_type, s.line_name, u.name AS inspector_name, isl.ref_number, ki.kanban_no
            FROM inspection_session_lots isl
            JOIN inspection_sessions s ON s.id = isl.inspection_session_id
            LEFT JOIN users u ON u.id = s.inspector_id
            LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
            LEFT JOIN daily_inspection_data did ON did.id = s.did_id
            LEFT JOIN master_parts mp ON mp.id = s.part_id
            WHERE UPPER(isl.ref_number) IN ($inRefPlaceholders)
              $partLotCond
              AND s.status = 'in_progress'
            LIMIT 1
        ";
        $stmtChkActive = $pdo->prepare($sqlChkActive);
        $stmtChkActive->execute(array_merge($scannedRefs, $partLotParams));
        $activeRow = $stmtChkActive->fetch(PDO::FETCH_ASSOC);
        if ($activeRow) {
            $sessDesc = ($activeRow['inspection_type'] === 'safety_stock') ? 'Safety Stock Gudang' : ('Kanban ' . ($activeRow['kanban_no'] ?: ('#' . $activeRow['session_id'])));
            if (ob_get_length()) ob_clean();
            echo json_encode([
                'success' => false,
                'error_type' => 'in_progress_duplicate',
                'message' => "Label Box Part \"{$partCode}\" / Lot \"{$lotNumber}\" / Ref \"{$activeRow['ref_number']}\" saat ini sedang aktif diinspeksi pada Sesi #{$activeRow['session_id']} [{$sessDesc}] (Line: " . ($activeRow['line_name'] ?: '-') . "). Label tidak dapat digunakan bersamaan!"
            ]);
            exit;
        }

        // Cek apakah label (Part + Lot + Ref) sudah PASSED sebelumnya (mencegah double data)
        // Safety Stock: tolak jika passed di sesi manapun. Kanban: tolak hanya jika passed di Kanban.
        // Pengecualian: Box yang masih memiliki sisa saldo aktif di Safety Stock Gudang BOLEH digunakan di Kanban!
        $refsToCheckPassed = [];
        if ($reqInspectionType !== 'safety_stock') {
            foreach ($scannedRefs as $sr) {
                $stmtSSAct = $pdo->prepare("
                    SELECT 1 FROM inspection_session_lots isl
                    JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                    LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                    LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                    LEFT JOIN master_parts mp ON mp.id = s.part_id
                    WHERE s.inspection_type = 'safety_stock'
                      AND s.status = 'passed'
                      AND (s.auto_fulfilled_by_session_id IS NULL OR s.auto_fulfilled_by_session_id = 0)
                      AND (isl.lot_status IS NULL OR isl.lot_status IN ('ok', 'reinspected', 'passed', ''))
                      AND (isl.lot_result IS NULL OR isl.lot_result IN ('passed', 'skipped'))
                      AND isl.qty > 0
                      AND UPPER(isl.ref_number) = UPPER(:ref)
                      AND UPPER(isl.lot_number) = UPPER(:lot)
                      AND (UPPER(did.part_code) = UPPER(:p1) OR UPPER(ki.item_code) = UPPER(:p2) OR UPPER(mp.part_code) = UPPER(:p3))
                    LIMIT 1
                ");
                $stmtSSAct->execute([
                    ':ref' => $sr,
                    ':lot' => $lotNumber,
                    ':p1'  => $partCode,
                    ':p2'  => $partCode,
                    ':p3'  => $partCode
                ]);
                if (!$stmtSSAct->fetchColumn()) {
                    $refsToCheckPassed[] = $sr;
                }
            }
        } else {
            $refsToCheckPassed = $scannedRefs;
        }

        if (!empty($refsToCheckPassed)) {
            $inRefPassPlaceholders = implode(',', array_fill(0, count($refsToCheckPassed), '?'));
            $typeCond = ($reqInspectionType === 'safety_stock') ? '' : "AND s.inspection_type = 'kanban'";
            $sqlChkPassed = "
                SELECT s.id AS session_id, s.inspection_type, s.closed_at, u.name AS inspector_name, isl.ref_number, ki.kanban_no, ki.customer
                FROM inspection_session_lots isl
                JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                LEFT JOIN users u ON u.id = s.inspector_id
                LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                LEFT JOIN master_parts mp ON mp.id = s.part_id
                WHERE UPPER(isl.ref_number) IN ($inRefPassPlaceholders)
                  $partLotCond
                  AND (
                      isl.lot_result IN ('passed', 'skipped')
                      OR (s.status = 'passed' AND (isl.lot_result IS NULL OR isl.lot_result != 'rejected') AND (isl.lot_status IS NULL OR isl.lot_status NOT IN ('rejected', 'ng_quarantine', 'replaced', 'ng_found')))
                  )
                  AND (isl.lot_result IS NULL OR isl.lot_result != 'rejected')
                  AND (isl.lot_status IS NULL OR isl.lot_status NOT IN ('rejected', 'ng_quarantine', 'replaced', 'ng_found'))
                  $typeCond
                LIMIT 1
            ";
            $stmtChkPassed = $pdo->prepare($sqlChkPassed);
            $stmtChkPassed->execute(array_merge($refsToCheckPassed, $partLotParams));
            $passRow = $stmtChkPassed->fetch(PDO::FETCH_ASSOC);
            if ($passRow) {
                $pDesc = ($passRow['inspection_type'] === 'safety_stock') ? 'Safety Stock Gudang' : ('Kanban ' . ($passRow['kanban_no'] ?: '-'));
                if (ob_get_length()) ob_clean();
                echo json_encode([
                    'success' => false,
                    'error_type' => 'already_passed_duplicate',
                    'message' => "Label Box Part \"{$partCode}\" / Lot \"{$lotNumber}\" / Ref \"{$passRow['ref_number']}\" SUDAH PERNAH diinspeksi & dinyatakan PASSED pada Sesi #{$passRow['session_id']} ({$pDesc}). Label ini tidak dapat digunakan kembali untuk menghindari data ganda!"
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

    // Bypass auto-fulfill dinonaktifkan: Seluruh box Safety Stock di-scan fisik ulang dan dites sampelnya di workbench

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
