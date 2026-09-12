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

    $prevSSRow = null;

    if ($reqInspectionType === 'safety_stock') {
        // If inspecting Safety Stock, check if any of the specific Ref Numbers being scanned were ALREADY passed/rejected
        if (!empty($scannedRefs)) {
            $inRefPlaceholders = implode(',', array_fill(0, count($scannedRefs), '?'));
            $sqlPrevRef = "
                SELECT ss.*, isl.ref_number AS matched_ref_number,
                       ki.item_code, ki.item_description, ki.customer, ki.id AS kanban_item_id,
                       did.lot_number, did.part_code, did.pic AS did_pic,
                       u.name AS inspector_name
                FROM inspection_sessions ss
                JOIN daily_inspection_data did ON ss.did_id = did.id
                JOIN inspection_session_lots isl ON isl.inspection_session_id = ss.id
                LEFT JOIN kanban_items ki ON (ss.kanban_item_id = ki.id OR ss.did_id = ki.id)
                LEFT JOIN users u ON ss.inspector_id = u.id
                WHERE UPPER(did.part_code) = ? 
                  AND UPPER(did.lot_number) = ?
                  AND UPPER(isl.ref_number) IN ($inRefPlaceholders)
                  AND ss.inspection_type = 'safety_stock'
                ORDER BY ss.id DESC LIMIT 1
            ";
            $paramsPrev = array_merge([$partCode, $lotNumber], $scannedRefs);
            $stmtPrevSS = $pdo->prepare($sqlPrevRef);
            $stmtPrevSS->execute($paramsPrev);
            $prevSSRow = $stmtPrevSS->fetch(PDO::FETCH_ASSOC);
        }
    } else {
        // For Kanban customer inspection, check if an existing Safety Stock session exists for this part_code and lot_number
        $stmtPrevSS = $pdo->prepare("
            SELECT ss.*, 
                   ki.item_code, ki.item_description, ki.customer, ki.id AS kanban_item_id,
                   did.lot_number, did.part_code, did.pic AS did_pic,
                   u.name AS inspector_name
            FROM inspection_sessions ss
            JOIN daily_inspection_data did ON ss.did_id = did.id
            LEFT JOIN kanban_items ki ON (ss.kanban_item_id = ki.id OR ss.did_id = ki.id)
            LEFT JOIN users u ON ss.inspector_id = u.id
            WHERE UPPER(did.part_code) = :pcode 
              AND UPPER(did.lot_number) = :lot
              AND (ss.inspection_type = 'safety_stock' OR ki.plan_type = 'safety_stock' OR ki.kanban_no LIKE 'SS%')
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
                } else {
                    $stmtInsK = $pdo->prepare("INSERT INTO inspection_sessions 
                        (inspection_type, did_id, kanban_item_id, part_id, inspector_id, sample_size, reject_number, samples_checked, ng_count, status, started_at, closed_at) 
                        VALUES ('kanban', :did, :kanban, :part, :inspector, :ssize1, 1, :ssize2, 0, 'passed', NOW(), NOW())");
                    $stmtInsK->execute([
                        ':did'       => $prevSSRow['did_id'],
                        ':kanban'    => $kanbanItemId,
                        ':part'      => $prevSSRow['part_id'] ?? null,
                        ':inspector' => $prevSSRow['inspector_id'] ?? null,
                        ':ssize1'    => (int)($prevSSRow['sample_size'] ?: 32),
                        ':ssize2'    => (int)($prevSSRow['sample_size'] ?: 32)
                    ]);
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
    $totalQty = ($reqTotalScanned > 0) ? $reqTotalScanned : ($kanbanRow ? clean_qty($kanbanRow['qty']) : 500); // Priority to Total Scanned Qty
    // Safety Stock selalu customer INTERNAL — jangan ambil customer dari kanbanRow order lain
    if ($inspectionType === 'safety_stock') {
        $customerName = 'INTERNAL SAFETY STOCK';
    } else {
        $customerName = $kanbanRow ? ($kanbanRow['customer'] ?? 'PT. Indonesia Epson Industry') : 'PT. Indonesia Epson Industry';
    }

    // 3. Fetch Master Part & Master Drawings (2D PDF & 3D STP) to get assigned aql_level
    $stmtPart = $pdo->prepare("SELECT p.id as part_id, p.part_code, p.part_name, p.aql_level, d.drawing_2d_path, d.drawing_3d_path 
                               FROM master_parts p 
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
    $stmtAql = $pdo->prepare("SELECT * FROM aql_standards WHERE inspection_level = :lvl AND :qty BETWEEN qty_min AND qty_max LIMIT 1");
    $stmtAql->execute([':lvl' => $aqlLevel, ':qty' => $totalQty]);
    $aqlRow = $stmtAql->fetch(PDO::FETCH_ASSOC);

    if (!$aqlRow) {
        // Fallback search without inspection_level if specific row not found
        $stmtAqlFB = $pdo->prepare("SELECT * FROM aql_standards WHERE :qty BETWEEN qty_min AND qty_max LIMIT 1");
        $stmtAqlFB->execute([':qty' => $totalQty]);
        $aqlRow = $stmtAqlFB->fetch(PDO::FETCH_ASSOC);
    }

    if (!$aqlRow) {
        $aqlRow = ['sample_size' => 50, 'reject_number' => 1, 'sample_code' => 'H', 'accept_number' => 0];
    }

    if (ob_get_length()) ob_clean();
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
            'drawing_2d' => $partRow['drawing_2d_path'] ? base_url($partRow['drawing_2d_path']) : null,
            'drawing_3d' => $partRow['drawing_3d_path'] ? base_url($partRow['drawing_3d_path']) : null
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
