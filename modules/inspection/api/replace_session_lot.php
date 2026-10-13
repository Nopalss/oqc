<?php
/**
 * API: Replace Session Lot (Opsi A: Re-Inspeksi & Ganti Box Baru Per-Lot)
 * Menggantikan lot yang REJECTED dengan label box / lot baru dalam sesi yang sama.
 * 
 * PT. Surya Technology Industri — OQC System
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi login Anda telah berakhir. Silakan login kembali.', 'unauthorized' => true]);
    exit;
}

$currentUser = current_user();
$currentUserId = (int)$currentUser['id'];
$currentUserRole = $currentUser['role'];
$isPrivilegedUser = in_array($currentUserRole, ['admin', 'chief', 'supervisor', 'manager'], true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metode permintaan tidak valid']);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Gagal terhubung ke database server']);
    exit;
}

$rawInput = file_get_contents('php://input');
$jsonBody = json_decode($rawInput, true) ?: [];

$sessionLotId = (int)($jsonBody['session_lot_id'] ?? ($_POST['session_lot_id'] ?? 0));
$newZ1        = strtoupper(trim(sanitize($jsonBody['new_z1'] ?? ($_POST['new_z1'] ?? ''))));
$newZ2        = strtoupper(trim(sanitize($jsonBody['new_z2'] ?? ($_POST['new_z2'] ?? ''))));
$newZ3        = (int)($jsonBody['new_z3'] ?? ($_POST['new_z3'] ?? 0));
$newZ4        = trim(sanitize($jsonBody['new_z4'] ?? ($_POST['new_z4'] ?? '')));
$newZ5        = strtoupper(trim(sanitize($jsonBody['new_z5'] ?? ($_POST['new_z5'] ?? ''))));
$remarks      = trim(sanitize($jsonBody['remarks'] ?? ($_POST['remarks'] ?? '')));

if ($sessionLotId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID Lot yang akan diganti tidak valid']);
    exit;
}

if (empty($newZ2)) {
    echo json_encode(['success' => false, 'message' => 'Lot Number box pengganti wajib diisi']);
    exit;
}

if ($newZ3 <= 0) {
    echo json_encode(['success' => false, 'message' => 'Qty box pengganti harus lebih besar dari 0']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Ambil data lot lama & sesi
    $stmtLot = $pdo->prepare("
        SELECT isl.*, 
               s.id as session_id, s.part_id, s.kanban_item_id, s.flow_version, 
               s.status as session_status, s.inspector_id,
               did.part_code as session_part_code, did.part_name as session_part_name,
               p.aql_level as part_aql_level
        FROM inspection_session_lots isl
        JOIN inspection_sessions s ON s.id = isl.inspection_session_id
        LEFT JOIN daily_inspection_data did ON did.id = s.did_id
        LEFT JOIN master_parts p ON p.id = s.part_id
        WHERE isl.id = :id
        FOR UPDATE
    ");
    $stmtLot->execute([':id' => $sessionLotId]);
    $oldLot = $stmtLot->fetch(PDO::FETCH_ASSOC);

    if (!$oldLot) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Data lot lama tidak ditemukan']);
        exit;
    }

    // Ownership check (IDOR protection)
    $isSessionOwner = (!empty($oldLot['inspector_id']) && (int)$oldLot['inspector_id'] === $currentUserId);
    if (!$isSessionOwner && !$isPrivilegedUser) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak untuk menggantikan lot pada sesi inspektur lain.']);
        exit;
    }

    // Closed session check
    if (in_array($oldLot['session_status'], ['passed', 'rejected'], true) && !$isSessionOwner && !$isPrivilegedUser) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Sesi inspeksi telah selesai/ditutup dan tidak dapat dimodifikasi.']);
        exit;
    }

    if ($oldLot['lot_status'] === 'replaced') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Lot ini sudah pernah digantikan sebelumnya']);
        exit;
    }

    $sessionId = (int)$oldLot['session_id'];
    $sessionPartCode = $oldLot['session_part_code'] ?: '';

    // 2. Validasi Part Code Match
    if (!empty($newZ1) && !empty($sessionPartCode) && $newZ1 !== strtoupper($sessionPartCode)) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false, 
            'message' => "Part Code pada box pengganti ({$newZ1}) tidak cocok dengan part sesi ({$sessionPartCode})"
        ]);
        exit;
    }

    $effectivePartCode = !empty($newZ1) ? $newZ1 : $sessionPartCode;

    // 3. Validasi Real-Time Cek Dimensi (DID) untuk Lot Baru
    $stmtDid = $pdo->prepare("
        SELECT id, status_inspect 
        FROM daily_inspection_data 
        WHERE UPPER(part_code) = UPPER(:pc) AND UPPER(lot_number) = UPPER(:ln) 
        ORDER BY id DESC LIMIT 1
    ");
    $stmtDid->execute([':pc' => $effectivePartCode, ':ln' => $newZ2]);
    $didRow = $stmtDid->fetch(PDO::FETCH_ASSOC);

    if (!$didRow) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false, 
            'message' => "Lot #{$newZ2} belum terdaftar pada data Cek Dimensi (DID). Pastikan part telah dicek dimensi!"
        ]);
        exit;
    }

    if (strtoupper($didRow['status_inspect']) !== 'OK') {
        $pdo->rollBack();
        echo json_encode([
            'success' => false, 
            'message' => "Lot #{$newZ2} belum lolos Cek Dimensi (Status DID: {$didRow['status_inspect']}). Wajib status OK!"
        ]);
        exit;
    }

    // 4. Validasi Duplikat Ref Number pada Lot Aktif dalam Sesi
    if (!empty($newZ5)) {
        $stmtChkRef = $pdo->prepare("
            SELECT id FROM inspection_session_lots 
            WHERE inspection_session_id = :sid 
              AND UPPER(ref_number) = UPPER(:ref) 
              AND id != :curr_id 
              AND lot_status != 'replaced' 
            LIMIT 1
        ");
        $stmtChkRef->execute([
            ':sid'     => $sessionId,
            ':ref'     => $newZ5,
            ':curr_id' => $sessionLotId
        ]);
        if ($stmtChkRef->fetchColumn()) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false, 
                'message' => "Ref Number ({$newZ5}) sudah terdaftar pada box aktif lain dalam sesi ini"
            ]);
            exit;
        }
    }

    // 4.A Validasi 3-Way Match (Part Code + Lot Number + Ref Number): Cek apakah Box sudah PASSED di Kanban lain
    // Jika box sudah lolos (passed) di kanban lain dan bukan safety stock yang masih ada saldo, TOLAK KERAS!
    $refToCheck = !empty($newZ5) ? $newZ5 : '';
    if (!empty($refToCheck)) {
        $stmtChkPassed = $pdo->prepare("
            SELECT s.id AS passed_session_id, ki.kanban_no, s.inspection_type, isl.ref_number, isl.lot_number
            FROM inspection_session_lots isl
            JOIN inspection_sessions s ON s.id = isl.inspection_session_id
            LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
            LEFT JOIN daily_inspection_data did ON did.id = s.did_id
            LEFT JOIN master_parts mp ON mp.id = s.part_id
            WHERE UPPER(isl.ref_number) = UPPER(:ref)
              AND UPPER(isl.lot_number) = UPPER(:lot)
              AND (UPPER(did.part_code) = UPPER(:pc1) OR UPPER(ki.item_code) = UPPER(:pc2) OR UPPER(mp.part_code) = UPPER(:pc3))
              AND (isl.lot_result IN ('passed', 'skipped') OR s.status = 'passed')
              AND s.status != 'in_progress'
              AND s.id != :curr_sid
            ORDER BY isl.id DESC
            LIMIT 1
        ");
        $stmtChkPassed->execute([
            ':ref'      => $refToCheck,
            ':lot'      => $newZ2,
            ':pc1'      => $effectivePartCode,
            ':pc2'      => $effectivePartCode,
            ':pc3'      => $effectivePartCode,
            ':curr_sid' => $sessionId
        ]);
        $passedRow = $stmtChkPassed->fetch(PDO::FETCH_ASSOC);

        if ($passedRow && $passedRow['inspection_type'] !== 'safety_stock') {
            // Periksa apakah box ini ada di Safety Stock dan masih ada saldo
            $stmtSSCheck = $pdo->prepare("
                SELECT isl.qty FROM inspection_session_lots isl
                JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                WHERE s.inspection_type = 'safety_stock'
                  AND s.status = 'passed'
                  AND (s.auto_fulfilled_by_session_id IS NULL OR s.auto_fulfilled_by_session_id = 0)
                  AND (isl.lot_status IS NULL OR isl.lot_status IN ('ok', 'reinspected', 'passed', ''))
                  AND (isl.lot_result IS NULL OR isl.lot_result IN ('passed', 'skipped'))
                  AND isl.qty > 0
                  AND UPPER(isl.ref_number) = UPPER(:ref)
                  AND UPPER(isl.lot_number) = UPPER(:lot)
                LIMIT 1
            ");
            $stmtSSCheck->execute([':ref' => $refToCheck, ':lot' => $newZ2]);
            $ssBal = (int)$stmtSSCheck->fetchColumn();

            if ($ssBal <= 0) {
                $pdo->rollBack();
                $kbInfo = !empty($passedRow['kanban_no']) ? " (Kanban: {$passedRow['kanban_no']})" : "";
                echo json_encode([
                    'success' => false,
                    'error_type' => 'already_passed_duplicate',
                    'message' => "Box pengganti Part {$effectivePartCode} | Lot #{$newZ2} | Ref #{$refToCheck} SUDAH PERNAH diinspeksi & dinyatakan PASSED pada Sesi #{$passedRow['passed_session_id']}{$kbInfo}. Box ini tidak boleh digunakan kembali!"
                ]);
                exit;
            }
        }
    }

    // 4.B Validasi apakah Box Pengganti berasal dari Safety Stock Gudang
    if (!empty($newZ5)) {
        $stmtEverSS = $pdo->prepare("
            SELECT 1 FROM inspection_session_lots isl
            JOIN inspection_sessions s ON s.id = isl.inspection_session_id
            WHERE s.inspection_type = 'safety_stock'
              AND UPPER(isl.ref_number) = UPPER(:ref)
            LIMIT 1
        ");
        $stmtEverSS->execute([':ref' => $newZ5]);
        $isEverSS = (bool)$stmtEverSS->fetchColumn();

        if ($isEverSS) {
            $stmtChkSS = $pdo->prepare("
                SELECT isl.id as lot_id, isl.qty, isl.lot_number, isl.ref_number, s.id as session_id
                FROM inspection_session_lots isl
                JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                WHERE s.inspection_type = 'safety_stock'
                  AND s.status = 'passed'
                  AND (s.auto_fulfilled_by_session_id IS NULL OR s.auto_fulfilled_by_session_id = 0)
                  AND (isl.lot_status IS NULL OR isl.lot_status IN ('ok', 'reinspected', 'passed', ''))
                  AND (isl.lot_result IS NULL OR isl.lot_result IN ('passed', 'skipped'))
                  AND isl.qty > 0
                  AND UPPER(isl.ref_number) = UPPER(:ref)
                ORDER BY isl.id DESC
                LIMIT 1
            ");
            $stmtChkSS->execute([':ref' => $newZ5]);
            $ssLot = $stmtChkSS->fetch(PDO::FETCH_ASSOC);

            if (!$ssLot || (int)$ssLot['qty'] <= 0) {
                $pdo->rollBack();
                echo json_encode([
                    'success'    => false,
                    'error_type' => 'safety_stock_exhausted',
                    'message'    => "Safety Stock Ref #{$newZ5} (Lot #{$newZ2}) sudah habis terpakai (sisa 0 pcs) di gudang!"
                ]);
                exit;
            }

            // Clamp qty pengganti jika melebihi saldo riil gudang
            if ($newZ3 > (int)$ssLot['qty']) {
                $newZ3 = (int)$ssLot['qty'];
            }
        }
    }

    // 5. Hitung Standar AQL untuk Box Pengganti
    $aqlLevel = !empty($oldLot['part_aql_level']) ? $oldLot['part_aql_level'] : 'G-II';
    $stmtAql = $pdo->prepare("
        SELECT sample_code, sample_size, accept_number, reject_number 
        FROM aql_standards 
        WHERE inspection_level = :lvl AND :qty BETWEEN qty_min AND qty_max 
        LIMIT 1
    ");
    $stmtAql->execute([':lvl' => $aqlLevel, ':qty' => $newZ3]);
    $aqlRow = $stmtAql->fetch(PDO::FETCH_ASSOC);

    if (!$aqlRow) {
        $stmtAqlFb = $pdo->prepare("
            SELECT sample_code, sample_size, accept_number, reject_number 
            FROM aql_standards 
            WHERE :qty BETWEEN qty_min AND qty_max 
            LIMIT 1
        ");
        $stmtAqlFb->execute([':qty' => $newZ3]);
        $aqlRow = $stmtAqlFb->fetch(PDO::FETCH_ASSOC);
    }

    $sampleCode   = $aqlRow ? $aqlRow['sample_code'] : 'H';
    $sampleSize   = $aqlRow ? (int)$aqlRow['sample_size'] : min(5, $newZ3);
    $acceptNumber = $aqlRow ? (int)$aqlRow['accept_number'] : 0;
    $rejectNumber = $aqlRow ? (int)$aqlRow['reject_number'] : 1;

    $rawQr = "Z1{$effectivePartCode}|Z2{$newZ2}|Z3{$newZ3}" . (!empty($newZ5) ? "|Z5{$newZ5}" : '');
    $lotRemark = "Box Pengganti untuk Lot #{$oldLot['lot_number']}" . (!empty($remarks) ? " - {$remarks}" : '');

    // 6. Masukkan Box Pengganti Baru ke inspection_session_lots
    $stmtInsNew = $pdo->prepare("
        INSERT INTO inspection_session_lots (
            inspection_session_id, ref_number, lot_number, qty,
            aql_level, sample_code, sample_size, accept_number, reject_number,
            ng_count, lot_result, lot_status, scanned_qr_raw, remarks, created_at
        ) VALUES (
            :sid, :ref, :lot, :qty,
            :aql_lvl, :scode, :ssize, :acc, :rej,
            0, 'in_progress', 'ok', :raw_qr, :rem, NOW()
        )
    ");
    $stmtInsNew->execute([
        ':sid'     => $sessionId,
        ':ref'     => $newZ5 ?: null,
        ':lot'     => $newZ2,
        ':qty'     => $newZ3,
        ':aql_lvl' => $aqlLevel,
        ':scode'   => $sampleCode,
        ':ssize'   => $sampleSize,
        ':acc'     => $acceptNumber,
        ':rej'     => $rejectNumber,
        ':raw_qr'  => $rawQr,
        ':rem'     => $lotRemark
    ]);
    $newLotId = (int)$pdo->lastInsertId();

    // 7. Tandai Lot Lama Menjadi 'replaced'
    $stmtUpdOld = $pdo->prepare("
        UPDATE inspection_session_lots
        SET lot_status = 'replaced',
            replaced_by_lot_id = :new_id,
            action_noted_at = NOW()
        WHERE id = :old_id
    ");
    $stmtUpdOld->execute([
        ':new_id' => $newLotId,
        ':old_id' => $sessionLotId
    ]);

    // 8. Catat ke Tabel Silsilah Pergantian Lot (lot_substitution_log)
    $userId = (int)($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0)) ?: null;
    $substNotes = "Box #{$oldLot['lot_number']} (Ref: {$oldLot['ref_number']}) digantikan oleh Box #{$newZ2} (Ref: {$newZ5})." . (!empty($remarks) ? " Catatan: {$remarks}" : '');

    $stmtSubst = $pdo->prepare("
        INSERT INTO lot_substitution_log (
            original_session_id, ng_session_lot_id, replacement_session_lot_id,
            action_type, actioned_by, notes, created_at
        ) VALUES (
            :sid, :old_id, :new_id,
            'replace_box', :uid, :notes, NOW()
        )
    ");
    $stmtSubst->execute([
        ':sid'    => $sessionId,
        ':old_id' => $sessionLotId,
        ':new_id' => $newLotId,
        ':uid'    => $userId,
        ':notes'  => $substNotes
    ]);

    // 9. Update Agregat Sesi (Perhitungan sampel riil yang diperiksa & lot aktif)
    $stmtAggr = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN lot_status != 'replaced' THEN qty ELSE 0 END), 0) as total_scanned_qty,
            COALESCE(SUM(CASE 
                WHEN (lot_status != 'replaced' AND lot_result IN ('passed', 'rejected')) 
                     OR lot_status IN ('replaced', 'reinspected') 
                THEN sample_size 
                ELSE 0 
            END), 0) as checked_samples,
            COALESCE(SUM(sample_size), 0) as total_samples,
            (SELECT COALESCE(SUM(qty_ng), 0) FROM inspection_ng_records WHERE inspection_session_id = :sid_ng AND (is_cancelled IS NULL OR is_cancelled = 0)) as total_ng,
            SUM(CASE WHEN lot_status != 'replaced' AND lot_result = 'in_progress' THEN 1 ELSE 0 END) as in_progress_lots
        FROM inspection_session_lots
        WHERE inspection_session_id = :sid
    ");
    $stmtAggr->execute([':sid' => $sessionId, ':sid_ng' => $sessionId]);
    $aggr = $stmtAggr->fetch(PDO::FETCH_ASSOC);

    $stmtUpdSess = $pdo->prepare("
        UPDATE inspection_sessions
        SET total_scanned_qty = :tot_qty,
            samples_checked   = :chk_samples,
            sample_size       = :tot_samples,
            ng_count          = :tot_ng,
            status            = 'in_progress',
            closed_at         = NULL
        WHERE id = :sid
    ");
    $stmtUpdSess->execute([
        ':tot_qty'     => (int)$aggr['total_scanned_qty'],
        ':chk_samples' => (int)$aggr['checked_samples'],
        ':tot_samples' => (int)$aggr['total_samples'],
        ':tot_ng'      => (int)$aggr['total_ng'],
        ':sid'         => $sessionId
    ]);

    $pdo->commit();

    echo json_encode([
        'success'        => true,
        'message'        => "Box pengganti Lot #{$newZ2} ({$newZ3} pcs) berhasil didaftarkan. Silakan periksa sampel fisiknya.",
        'session_id'     => $sessionId,
        'old_lot_id'     => $sessionLotId,
        'new_lot_id'     => $newLotId,
        'sample_size'    => $sampleSize,
        'reject_number'  => $rejectNumber
    ]);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem saat mendaftarkan box pengganti: ' . $e->getMessage()
    ]);
    exit;
}
