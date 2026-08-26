<?php
/**
 * AJAX Endpoint: Scan & Validate Label (Part Code + Lot Number)
 * Supports explicit Planning selection (kanban_item_id) matching & DID validation
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$partCode = strtoupper(trim(sanitize($_REQUEST['part_code'] ?? '')));
$lotNumber = strtoupper(trim(sanitize($_REQUEST['lot_number'] ?? '')));
$kanbanItemId = (int)($_REQUEST['kanban_item_id'] ?? 0);

if (empty($partCode) || empty($lotNumber)) {
    echo json_encode([
        'success' => false,
        'message' => 'Part Code dan Lot Number wajib diisi!'
    ]);
    exit;
}

$pdo = getDB();
if (!$pdo) {
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
    if (!$kanbanRow) {
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

    if (!$didRow) {
        echo json_encode([
            'success' => false,
            'error_type' => 'did_missing',
            'message' => "Lot Number \"{$lotNumber}\" untuk Part \"{$partCode}\" BELUM tercatat/lolos di Daily Inspection Data (DID). Pastikan barang sudah melalui cek dimensi produksi."
        ]);
        exit;
    }

    if (strtoupper($didRow['status_inspect']) === 'NG') {
        echo json_encode([
            'success' => false,
            'error_type' => 'did_ng',
            'message' => "PERINGATAN: Lot Number \"{$lotNumber}\" tercatat ber-status NG pada Daily Inspection Data (DID). Pengecekan OQC dihentikan."
        ]);
        exit;
    }

    $inspectionType = ($kanbanRow && ($kanbanRow['plan_type'] ?? 'kanban') === 'safety_stock') ? 'safety_stock' : 'kanban';
    $totalQty = $kanbanRow ? (int)$kanbanRow['qty'] : 500; // Default fallback Qty
    $customerName = $kanbanRow ? ($kanbanRow['customer'] ?? ($inspectionType === 'safety_stock' ? 'INTERNAL STOCK' : 'PT. Indonesia Epson Industry')) : 'PT. Indonesia Epson Industry';

    // 3. AQL Sampling Calculation (Level G-II, AQL 0.4)
    $stmtAql = $pdo->prepare("SELECT * FROM aql_standards WHERE :qty BETWEEN qty_min AND qty_max LIMIT 1");
    $stmtAql->execute([':qty' => $totalQty]);
    $aqlRow = $stmtAql->fetch(PDO::FETCH_ASSOC);

    if (!$aqlRow) {
        $aqlRow = ['sample_size' => 50, 'reject_number' => 1, 'sample_code' => 'H', 'accept_number' => 0];
    }

    // 4. Fetch Master Part & Master Drawings (2D PDF & 3D STP)
    $stmtPart = $pdo->prepare("SELECT p.id as part_id, p.part_code, p.part_name, d.drawing_2d_path, d.drawing_3d_path 
                               FROM master_parts p 
                               LEFT JOIN master_drawings d ON d.part_id = p.id 
                               WHERE UPPER(p.part_code) = :pcode LIMIT 1");
    $stmtPart->execute([':pcode' => $partCode]);
    $partRow = $stmtPart->fetch(PDO::FETCH_ASSOC);

    // Auto-create Master Part if not existing
    if (!$partRow) {
        $partName = $didRow['part_name'] ?? ($kanbanRow['item_description'] ?? 'Part ' . $partCode);
        $stmtInsPart = $pdo->prepare("INSERT INTO master_parts (part_code, part_name, source, created_at) VALUES (:code, :name, 'auto_generated', NOW())");
        $stmtInsPart->execute([':code' => $partCode, ':name' => $partName]);
        $newPartId = $pdo->lastInsertId();

        $partRow = [
            'part_id' => $newPartId,
            'part_code' => $partCode,
            'part_name' => $partName,
            'drawing_2d_path' => null,
            'drawing_3d_path' => null
        ];
    }

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
            'drawing_2d' => $partRow['drawing_2d_path'] ? base_url($partRow['drawing_2d_path']) : null,
            'drawing_3d' => $partRow['drawing_3d_path'] ? base_url($partRow['drawing_3d_path']) : null
        ],
        'aql' => [
            'total_qty' => $totalQty,
            'sample_size' => (int)$aqlRow['sample_size'],
            'reject_number' => (int)$aqlRow['reject_number'],
            'standard' => 'G-II / AQL 0.4'
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Kesalahan Database: ' . $e->getMessage()
    ]);
}
