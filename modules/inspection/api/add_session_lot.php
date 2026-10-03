<?php
/**
 * API: Add Session Lot (Tambah Box / Lot Baru ke Sesi Aktif)
 * Menambahkan box/lot baru ke dalam sesi inspeksi yang sedang berlangsung (in_progress).
 * Mendukung format scan QR (Z1|Z2|Z3|Z4|Z5) dengan Qty yang dapat diedit secara fleksibel.
 * 
 * PT. Surya Technology Industri — OQC System
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';
require_once __DIR__ . '/../../../config/summary_helper.php';

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
$jsonData = json_decode($rawInput, true) ?: [];

$sessionId = (int)($jsonData['session_id'] ?? ($_POST['session_id'] ?? 0));
$partCodeInput = strtoupper(trim(sanitize($jsonData['part_code'] ?? ($_POST['part_code'] ?? ''))));

$inputLots = [];
if (!empty($jsonData['lots']) && is_array($jsonData['lots'])) {
    foreach ($jsonData['lots'] as $l) {
        $lNum = strtoupper(trim(sanitize($l['lot_number'] ?? '')));
        $lQty = (int)($l['qty'] ?? 0);
        $lRef = strtoupper(trim(sanitize($l['ref_number'] ?? '')));
        $lQr  = trim(sanitize($l['raw_qr'] ?? ''));
        if (!empty($lNum) && $lQty > 0) {
            $inputLots[] = [
                'lot_number' => $lNum,
                'qty'        => $lQty,
                'ref_number' => $lRef,
                'raw_qr'     => $lQr
            ];
        }
    }
} else {
    $lotNumber = strtoupper(trim(sanitize($jsonData['lot_number'] ?? ($_POST['lot_number'] ?? ''))));
    $qty       = (int)($jsonData['qty'] ?? ($_POST['qty'] ?? 0));
    $refNumber = strtoupper(trim(sanitize($jsonData['ref_number'] ?? ($_POST['ref_number'] ?? ''))));
    $rawQr     = trim(sanitize($jsonData['raw_qr'] ?? ($_POST['raw_qr'] ?? '')));
    if (!empty($lotNumber) && $qty > 0) {
        $inputLots[] = [
            'lot_number' => $lotNumber,
            'qty'        => $qty,
            'ref_number' => $refNumber,
            'raw_qr'     => $rawQr
        ];
    }
}

if ($sessionId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID Sesi tidak valid']);
    exit;
}

if (empty($inputLots)) {
    echo json_encode(['success' => false, 'message' => 'Minimal 1 Box harus memiliki Lot Number dan Qty valid']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Ambil data sesi aktif
    $stmtSess = $pdo->prepare("
        SELECT s.*, 
               did.part_code as session_part_code, 
               did.part_name as session_part_name,
               p.aql_level as part_aql_level,
               ki.kanban_no, ki.customer
        FROM inspection_sessions s
        LEFT JOIN daily_inspection_data did ON did.id = s.did_id
        LEFT JOIN master_parts p ON p.id = s.part_id
        LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
        WHERE s.id = :id
        FOR UPDATE
    ");
    $stmtSess->execute([':id' => $sessionId]);
    $sess = $stmtSess->fetch(PDO::FETCH_ASSOC);

    if (!$sess) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Sesi inspeksi tidak ditemukan']);
        exit;
    }

    if (!in_array($sess['status'], ['in_progress', 'passed', 'rejected'])) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Penambahan box tidak dapat dilakukan pada sesi dengan status "' . $sess['status'] . '".'
        ]);
        exit;
    }

    $sessionPartCode = strtoupper($sess['session_part_code'] ?: '');

    // 2. Validasi Part Code Matching
    if (!empty($partCodeInput) && !empty($sessionPartCode) && $partCodeInput !== $sessionPartCode) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => "Part Code pada box baru ({$partCodeInput}) tidak cocok dengan part sesi ({$sessionPartCode}). Pastikan box yang discan berasal dari item yang sama!"
        ]);
        exit;
    }

    $effectivePartCode = !empty($partCodeInput) ? $partCodeInput : $sessionPartCode;
    $aqlLevel = !empty($sess['part_aql_level']) ? $sess['part_aql_level'] : (!empty($sess['aql_level']) ? $sess['aql_level'] : 'G-II');

    $insertedLotIds = [];
    $seenRefs = [];

    foreach ($inputLots as $lotItem) {
        $lotNumber = $lotItem['lot_number'];
        $qty       = $lotItem['qty'];
        $refNumber = $lotItem['ref_number'];
        $rawQr     = $lotItem['raw_qr'];

        // Cek duplikasi di dalam antrean input yang sama
        if (!empty($refNumber)) {
            if (isset($seenRefs[$refNumber])) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => "Ref Number \"{$refNumber}\" duplikat dalam antrean penambahan!"
                ]);
                exit;
            }
            $seenRefs[$refNumber] = true;
        }

        // 3. Validasi Real-Time Daily Inspection Data (DID) Cek Dimensi
        if (!empty($effectivePartCode)) {
            $stmtDid = $pdo->prepare("
                SELECT id, status_inspect 
                FROM daily_inspection_data 
                WHERE UPPER(part_code) = UPPER(:pc) AND UPPER(lot_number) = UPPER(:ln) 
                ORDER BY id DESC LIMIT 1
            ");
            $stmtDid->execute([':pc' => $effectivePartCode, ':ln' => $lotNumber]);
            $didRow = $stmtDid->fetch(PDO::FETCH_ASSOC);

            if (!$didRow) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => "Lot #{$lotNumber} belum terdaftar pada data Cek Dimensi (DID). Pastikan part telah melalui proses Cek Dimensi!"
                ]);
                exit;
            }

            if (strtoupper($didRow['status_inspect']) === 'NG') {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => "PERINGATAN: Lot #{$lotNumber} ber-status NG pada Daily Inspection Data (DID). Box ini tidak dapat ditambahkan ke sesi OQC!"
                ]);
                exit;
            }
        }

        // 4. Validasi Ref Number Duplikat
        if (!empty($refNumber)) {
            // A. Cek di sesi saat ini
            $stmtChkThis = $pdo->prepare("
                SELECT id FROM inspection_session_lots 
                WHERE inspection_session_id = :sid 
                  AND UPPER(ref_number) = UPPER(:ref) 
                  AND (lot_status != 'replaced' OR lot_status IS NULL)
                LIMIT 1
            ");
            $stmtChkThis->execute([':sid' => $sessionId, ':ref' => $refNumber]);
            if ($stmtChkThis->fetchColumn()) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => "Ref Number \"{$refNumber}\" sudah terdaftar dalam sesi ini!"
                ]);
                exit;
            }

            // B. Cek apakah aktif di sesi lain (in_progress lock)
            $stmtChkInProg = $pdo->prepare("
                SELECT s.id as session_id, s.line_name, u.name as inspector_name, ki.kanban_no
                FROM inspection_session_lots isl
                JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                LEFT JOIN users u ON u.id = s.inspector_id
                LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                WHERE UPPER(isl.ref_number) = UPPER(:ref)
                  AND s.status = 'in_progress'
                  AND s.id != :sid
                LIMIT 1
            ");
            $stmtChkInProg->execute([':ref' => $refNumber, ':sid' => $sessionId]);
            $inProgRow = $stmtChkInProg->fetch(PDO::FETCH_ASSOC);
            if ($inProgRow) {
                $pdo->rollBack();
                $kDesc = $inProgRow['kanban_no'] ? " (Kanban {$inProgRow['kanban_no']})" : "";
                echo json_encode([
                    'success' => false,
                    'message' => "Label Box Ref \"{$refNumber}\" saat ini sedang aktif diinspeksi pada Sesi #{$inProgRow['session_id']}{$kDesc} oleh " . ($inProgRow['inspector_name'] ?: 'Inspector lain') . ". Label tidak dapat digunakan bersamaan!"
                ]);
                exit;
            }

            // C. Cek apakah sudah pernah PASSED di sesi lain
            $stmtChkPassed = $pdo->prepare("
                SELECT s.id as session_id, u.name as inspector_name, ki.kanban_no
                FROM inspection_session_lots isl
                JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                LEFT JOIN users u ON u.id = s.inspector_id
                LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                WHERE UPPER(isl.ref_number) = UPPER(:ref)
                  AND (isl.lot_result = 'passed' OR s.status = 'passed')
                  AND s.id != :sid
                LIMIT 1
            ");
            $stmtChkPassed->execute([':ref' => $refNumber, ':sid' => $sessionId]);
            $passedRow = $stmtChkPassed->fetch(PDO::FETCH_ASSOC);
            if ($passedRow) {
                $pdo->rollBack();
                $kDesc = $passedRow['kanban_no'] ? " (Kanban {$passedRow['kanban_no']})" : "";
                echo json_encode([
                    'success' => false,
                    'message' => "Label Box Ref \"{$refNumber}\" SUDAH PERNAH diinspeksi & PASSED pada Sesi #{$passedRow['session_id']}{$kDesc}. Mencegah data ganda!"
                ]);
                exit;
            }
        }

        // 5. Hitung Standar AQL Per-Box Berdasarkan Qty
        $stmtAql = $pdo->prepare("
            SELECT sample_code, sample_size, accept_number, reject_number 
            FROM aql_standards 
            WHERE inspection_level = :lvl AND :qty BETWEEN qty_min AND qty_max 
            LIMIT 1
        ");
        $stmtAql->execute([':lvl' => $aqlLevel, ':qty' => $qty]);
        $aqlRow = $stmtAql->fetch(PDO::FETCH_ASSOC);

        if (!$aqlRow) {
            $stmtAqlFb = $pdo->prepare("
                SELECT sample_code, sample_size, accept_number, reject_number 
                FROM aql_standards 
                WHERE :qty BETWEEN qty_min AND qty_max 
                LIMIT 1
            ");
            $stmtAqlFb->execute([':qty' => $qty]);
            $aqlRow = $stmtAqlFb->fetch(PDO::FETCH_ASSOC);
        }

        $sampleCode   = $aqlRow ? $aqlRow['sample_code'] : 'H';
        $sampleSize   = $aqlRow ? (int)$aqlRow['sample_size'] : min(5, $qty);
        $acceptNumber = $aqlRow ? (int)$aqlRow['accept_number'] : 0;
        $rejectNumber = $aqlRow ? (int)$aqlRow['reject_number'] : 1;

        if (empty($rawQr)) {
            $rawQr = "Z1{$effectivePartCode}|Z2{$lotNumber}|Z3{$qty}" . (!empty($refNumber) ? "|Z5{$refNumber}" : '');
        }

        // 6. Masukkan Box ke inspection_session_lots
        $stmtIns = $pdo->prepare("
            INSERT INTO inspection_session_lots (
                inspection_session_id, ref_number, lot_number, qty,
                aql_level, sample_code, sample_size, accept_number, reject_number,
                ng_count, lot_result, lot_status, scanned_qr_raw, remarks, created_at
            ) VALUES (
                :sid, :ref, :lot, :qty,
                :aql_lvl, :scode, :ssize, :acc, :rej,
                0, 'in_progress', 'ok', :raw_qr, 'Box Tambahan', NOW()
            )
        ");
        $stmtIns->execute([
            ':sid'     => $sessionId,
            ':ref'     => !empty($refNumber) ? $refNumber : null,
            ':lot'     => $lotNumber,
            ':qty'     => $qty,
            ':aql_lvl' => $aqlLevel,
            ':scode'   => $sampleCode,
            ':ssize'   => $sampleSize,
            ':acc'     => $acceptNumber,
            ':rej'     => $rejectNumber,
            ':raw_qr'  => $rawQr
        ]);
        $insertedLotIds[] = (int)$pdo->lastInsertId();
    }

    // 7. Hitung Ulang Agregat Sesi di inspection_sessions
    $stmtAggr = $pdo->prepare("
        SELECT 
            COUNT(*) as total_lots,
            SUM(CASE WHEN lot_result = 'in_progress' AND (lot_status != 'replaced' OR lot_status IS NULL) THEN 1 ELSE 0 END) as in_progress_lots,
            SUM(CASE WHEN lot_result = 'rejected' AND (lot_status != 'replaced' OR lot_status IS NULL) THEN 1 ELSE 0 END) as rejected_lots,
            SUM(CASE WHEN lot_result = 'passed' AND (lot_status != 'replaced' OR lot_status IS NULL) THEN 1 ELSE 0 END) as passed_lots,
            SUM(CASE WHEN lot_result = 'skipped' AND (lot_status != 'replaced' OR lot_status IS NULL) THEN 1 ELSE 0 END) as skipped_lots,
            COALESCE(SUM(CASE 
                WHEN (lot_status != 'replaced' AND lot_result IN ('passed', 'rejected')) 
                     OR lot_status IN ('replaced', 'reinspected') 
                THEN sample_size 
                ELSE 0 
            END), 0) as checked_samples,
            COALESCE(SUM(sample_size), 0) as total_samples,
            (SELECT COALESCE(SUM(qty_ng), 0) FROM inspection_ng_records WHERE inspection_session_id = :sid_ng AND (is_cancelled IS NULL OR is_cancelled = 0)) as total_ng,
            COALESCE(SUM(CASE WHEN lot_status != 'replaced' OR lot_status IS NULL THEN qty ELSE 0 END), 0) as total_scanned_qty
        FROM inspection_session_lots
        WHERE inspection_session_id = :sid
    ");
    $stmtAggr->execute([':sid' => $sessionId, ':sid_ng' => $sessionId]);
    $aggr = $stmtAggr->fetch(PDO::FETCH_ASSOC);

    $remainingTotalLots = (int)$aggr['total_lots'];
    $checkedSamples     = (int)$aggr['checked_samples'];
    $totalSamples       = (int)$aggr['total_samples'];
    $totalNg            = (int)$aggr['total_ng'];
    $newTotalScannedQty = (int)$aggr['total_scanned_qty'];

    // Sesi pasti in_progress karena baru saja ditambah lot yang in_progress
    $stmtUpdSess = $pdo->prepare("
        UPDATE inspection_sessions
        SET total_scanned_qty = :tot_qty,
            samples_checked   = :chk_samples,
            sample_size       = :tot_samples,
            ng_count          = :ng_cnt,
            status            = 'in_progress',
            closed_at         = NULL
        WHERE id = :sid
    ");
    $stmtUpdSess->execute([
        ':tot_qty'     => $newTotalScannedQty,
        ':chk_samples' => $checkedSamples,
        ':tot_samples' => $totalSamples,
        ':ng_cnt'      => $totalNg,
        ':sid'         => $sessionId
    ]);

    // 8. Sinkronisasi Kanban jika terhubung
    $kanbanItemId = (int)($sess['kanban_item_id'] ?? 0);
    if ($kanbanItemId > 0 && function_exists('syncKanbanStatus')) {
        syncKanbanStatus($pdo, $kanbanItemId);
    }

    $pdo->commit();

    $countAdded = count($insertedLotIds);
    $firstLotId = !empty($insertedLotIds) ? $insertedLotIds[0] : 0;
    $msg = ($countAdded > 1) 
        ? "{$countAdded} box berhasil ditambahkan ke sesi." 
        : "Box berhasil ditambahkan ke sesi.";

    echo json_encode([
        'success'           => true,
        'message'           => $msg,
        'count_added'       => $countAdded,
        'new_lot_id'        => $firstLotId,
        'new_lot_ids'       => $insertedLotIds,
        'session_id'        => $sessionId,
        'total_lots'        => $remainingTotalLots,
        'total_scanned_qty' => $newTotalScannedQty,
        'sample_size'       => $totalSamples,
        'samples_checked'   => $checkedSamples
    ]);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem saat menambahkan box: ' . $e->getMessage()
    ]);
    exit;
}
