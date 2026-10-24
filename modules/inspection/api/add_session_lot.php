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

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi login Anda telah berakhir. Silakan login kembali.', 'unauthorized' => true]);
    exit;
}

$currentUser = current_user();
$currentUserId = (int)$currentUser['id'];
$currentUserRole = $currentUser['role'];
$isPrivilegedUser = in_array($currentUserRole, ['admin', 'chief', 'supervisor', 'manager'], true);

// Release PHP session lock immediately so parallel AJAX / polling requests are not blocked
session_write_close();

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
               ki.kanban_no, ki.customer, ki.qty as kanban_item_qty
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

    // Ownership check (IDOR protection)
    $isSessionOwner = (!empty($sess['inspector_id']) && (int)$sess['inspector_id'] === $currentUserId);
    if (!$isSessionOwner && !$isPrivilegedUser) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak untuk menambah lot pada sesi inspektur lain.']);
        exit;
    }

    // Closed session check
    if (in_array($sess['status'], ['passed', 'rejected'], true) && !$isSessionOwner && !$isPrivilegedUser) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Sesi inspeksi telah selesai/ditutup dan tidak dapat ditambahkan lot baru.'
        ]);
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

    $kanbanTargetQty = (int)($sess['kanban_item_qty'] ?? 0);
    $isKanban = (($sess['inspection_type'] ?? 'kanban') === 'kanban' && $kanbanTargetQty > 0);

    // Hitung Qty yang sudah teralokasi pada sesi ini (hanya lot yang aktif / belum replaced)
    $stmtCurrLots = $pdo->prepare("SELECT COALESCE(SUM(qty), 0) FROM inspection_session_lots WHERE inspection_session_id = :sid AND (lot_status != 'replaced' OR lot_status IS NULL)");
    $stmtCurrLots->execute([':sid' => $sessionId]);
    $currSessionAllocatedQty = (int)$stmtCurrLots->fetchColumn();

    $insertedLotIds = [];
    $seenRefs = [];

    foreach ($inputLots as $lotItem) {
        $lotNumber = $lotItem['lot_number'];
        $qty       = $lotItem['qty'];
        $refNumber = $lotItem['ref_number'];
        $rawQr     = $lotItem['raw_qr'];

        // Wajib Ref Number untuk menjaga integritas data & penelusuran (traceability)
        if (empty($refNumber)) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'message' => "Ref Number wajib diisi untuk tiap box demi menjaga integritas data penelusuran (traceability)!"
            ]);
            exit;
        }

        // Cek duplikasi di dalam antrean input yang sama (3-way match: ref + lot)
        if (!empty($refNumber)) {
            $seenKey = strtoupper($refNumber) . '|' . strtoupper($lotNumber);
            if (isset($seenRefs[$seenKey])) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => "Kombinasi Lot \"{$lotNumber}\" & Ref Number \"{$refNumber}\" duplikat dalam antrean penambahan!"
                ]);
                exit;
            }
            $seenRefs[$seenKey] = true;
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

        // 3b. Validasi Lot ABOLISHED (Scrap / Dibuang) — Blokir Permanen
        $stmtChkDoneAbolish = $pdo->prepare("
            SELECT isl.id, isl.lot_number, isl.ref_number, lqc.notes, lqc.closed_at
            FROM inspection_session_lots isl
            LEFT JOIN lot_quarantine_closures lqc ON lqc.session_lot_id = isl.id
            WHERE UPPER(isl.lot_number) = UPPER(:ln)
              AND (
                  isl.lot_status = 'abolished'
                  OR lqc.closure_type = 'abolish'
              )
              " . (!empty($refNumber) ? "AND UPPER(isl.ref_number) = UPPER(:rn)" : "") . "
            LIMIT 1
        ");
        $abolishParams = [':ln' => $lotNumber];
        if (!empty($refNumber)) {
            $abolishParams[':rn'] = $refNumber;
        }
        $stmtChkDoneAbolish->execute($abolishParams);
        $abolishedLot = $stmtChkDoneAbolish->fetch(PDO::FETCH_ASSOC);

        if ($abolishedLot) {
            $pdo->rollBack();
            $abNotes = !empty($abolishedLot['notes']) ? " Alasan: \"{$abolishedLot['notes']}\"." : "";
            echo json_encode([
                'success' => false,
                'message' => "PERINGATAN: Lot #{$lotNumber}" . (!empty($refNumber) ? " (Ref #{$refNumber})" : "") . " telah di-ABOLISH (Scrap / Dibuang) dan ditutup secara permanen!{$abNotes} Lot ini tidak dapat diinspeksi kembali."
            ]);
            exit;
        }

        // 4. Validasi Ref Number + Lot Number Duplikat (3-way match)
        if (!empty($refNumber)) {
            // A. Cek di sesi saat ini (ref + lot harus sama baru dianggap duplikat)
            $stmtChkThis = $pdo->prepare("
                SELECT id FROM inspection_session_lots 
                WHERE inspection_session_id = :sid 
                  AND UPPER(ref_number) = UPPER(:ref)
                  AND UPPER(lot_number) = UPPER(:lot)
                  AND (lot_status != 'replaced' OR lot_status IS NULL)
                LIMIT 1
            ");
            $stmtChkThis->execute([':sid' => $sessionId, ':ref' => $refNumber, ':lot' => $lotNumber]);
            if ($stmtChkThis->fetchColumn()) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => "Kombinasi Lot \"{$lotNumber}\" & Ref Number \"{$refNumber}\" sudah terdaftar dalam sesi ini!"
                ]);
                exit;
            }

            // B. Cek apakah aktif di sesi lain (in_progress lock) — 3-way match: part_code + ref + lot
            $stmtChkInProg = $pdo->prepare("
                SELECT s.id as session_id, s.line_name, u.name as inspector_name, ki.kanban_no
                FROM inspection_session_lots isl
                JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                LEFT JOIN users u ON u.id = s.inspector_id
                LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                LEFT JOIN master_parts mp ON mp.id = s.part_id
                WHERE UPPER(isl.ref_number) = UPPER(:ref)
                  AND UPPER(isl.lot_number) = UPPER(:lot)
                  AND (UPPER(did.part_code) = UPPER(:pcode1) OR UPPER(ki.item_code) = UPPER(:pcode2) OR UPPER(mp.part_code) = UPPER(:pcode3))
                  AND s.status = 'in_progress'
                  AND s.id != :sid
                LIMIT 1
            ");
            $stmtChkInProg->execute([':ref' => $refNumber, ':lot' => $lotNumber, ':pcode1' => $effectivePartCode, ':pcode2' => $effectivePartCode, ':pcode3' => $effectivePartCode, ':sid' => $sessionId]);
            $inProgRow = $stmtChkInProg->fetch(PDO::FETCH_ASSOC);
            if ($inProgRow) {
                $pdo->rollBack();
                $kDesc = $inProgRow['kanban_no'] ? " (Kanban {$inProgRow['kanban_no']})" : "";
                echo json_encode([
                    'success' => false,
                    'message' => "Label Box Part \"{$effectivePartCode}\" / Lot \"{$lotNumber}\" / Ref \"{$refNumber}\" saat ini sedang aktif diinspeksi pada Sesi #{$inProgRow['session_id']}{$kDesc} oleh " . ($inProgRow['inspector_name'] ?: 'Inspector lain') . ". Label tidak dapat digunakan bersamaan!"
                ]);
                exit;
            }

            // C. Cek apakah sudah pernah PASSED di sesi lain — 3-way match: part_code + ref + lot
            // Logika: Safety Stock → tolak selalu. Kanban → tolak hanya jika passed-nya di Kanban juga.
            $stmtChkPassed = $pdo->prepare("
                SELECT isl.id, s.id as session_id, s.inspection_type, ki.kanban_no, isl.qty, s.closed_at, s.line_name,
                       u.name AS inspector_name,
                       COALESCE(mp.part_name, did.part_name, ki.item_description, '') AS part_name,
                       COALESCE(mp.part_code, did.part_code, ki.item_code, :pcode1) AS matched_part_code
                FROM inspection_session_lots isl
                JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                LEFT JOIN users u ON u.id = s.inspector_id
                LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                LEFT JOIN master_parts mp ON mp.id = s.part_id
                WHERE UPPER(isl.ref_number) = UPPER(:ref)
                  AND UPPER(isl.lot_number) = UPPER(:lot)
                  AND (UPPER(did.part_code) = UPPER(:pcode2) OR UPPER(ki.item_code) = UPPER(:pcode3) OR UPPER(mp.part_code) = UPPER(:pcode4))
                  AND (isl.lot_result = 'passed' OR s.status = 'passed')
                  AND s.id != :sid
                LIMIT 1
            ");
            $stmtChkPassed->execute([
                ':ref'    => $refNumber,
                ':lot'    => $lotNumber,
                ':pcode1' => $effectivePartCode,
                ':pcode2' => $effectivePartCode,
                ':pcode3' => $effectivePartCode,
                ':pcode4' => $effectivePartCode,
                ':sid'    => $sessionId
            ]);
            $passedRow = $stmtChkPassed->fetch(PDO::FETCH_ASSOC);

            // Cek apakah Safety Stock ini masih memiliki saldo aktif (available_qty > 0)
            $stmtSsActive = $pdo->prepare("
                SELECT isl.id as lot_id, isl.qty, isl.lot_number, isl.ref_number, s.id as session_id
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
                ORDER BY isl.id DESC
                LIMIT 1
            ");
            $stmtSsActive->execute([
                ':ref' => $refNumber,
                ':lot' => $lotNumber,
                ':p1'  => $effectivePartCode,
                ':p2'  => $effectivePartCode,
                ':p3'  => $effectivePartCode
            ]);
            $ssActiveRow = $stmtSsActive->fetch(PDO::FETCH_ASSOC);

            $currentInspectionType = $sess['inspection_type'] ?? 'kanban';

            // Jika scan di Kanban dan ada saldo aktif di Safety Stock → BOLEH LANJUT!
            if ($currentInspectionType !== 'safety_stock' && $ssActiveRow) {
                // Clamp qty ke saldo real Safety Stock jika input melebihi saldo gudang
                if ($qty > (int)$ssActiveRow['qty']) {
                    $qty = (int)$ssActiveRow['qty'];
                }
            } elseif ($passedRow) {
                // Jika pernah Safety Stock tapi saldo sudah habis
                if ($currentInspectionType !== 'safety_stock' && $passedRow['inspection_type'] === 'safety_stock') {
                    $pdo->rollBack();
                    echo json_encode([
                        'success'    => false,
                        'error_type' => 'safety_stock_exhausted',
                        'message'    => "Safety Stock Ref #{$refNumber} (Lot #{$lotNumber}) sudah habis terpakai (sisa 0 pcs) di gudang!"
                    ]);
                    exit;
                }
                    // Safety Stock: tolak selalu. Kanban: tolak jika passed-nya di Kanban.
                    $pdo->rollBack();
                    $kDesc = $passedRow['kanban_no'] ? " (Kanban {$passedRow['kanban_no']})" : ($passedRow['inspection_type'] === 'safety_stock' ? " (Safety Stock Gudang)" : "");

                    $resolvedPartCode = !empty($passedRow['matched_part_code']) ? $passedRow['matched_part_code'] : $effectivePartCode;
                    $resolvedPartName = !empty($passedRow['part_name']) ? $passedRow['part_name'] : '';

                    if (empty($resolvedPartName) && !empty($resolvedPartCode)) {
                        $stmtPn = $pdo->prepare("SELECT part_name FROM master_parts WHERE UPPER(part_code) = UPPER(:pc) LIMIT 1");
                        $stmtPn->execute([':pc' => $resolvedPartCode]);
                        $resolvedPartName = $stmtPn->fetchColumn() ?: '';
                    }
                    if (empty($resolvedPartName) && !empty($resolvedPartCode)) {
                        $stmtPn = $pdo->prepare("SELECT part_name FROM daily_inspection_data WHERE UPPER(part_code) = UPPER(:pc) ORDER BY id DESC LIMIT 1");
                        $stmtPn->execute([':pc' => $resolvedPartCode]);
                        $resolvedPartName = $stmtPn->fetchColumn() ?: '';
                    }
                    if (empty($resolvedPartName) && !empty($resolvedPartCode)) {
                        $stmtPn = $pdo->prepare("SELECT item_description FROM kanban_items WHERE UPPER(item_code) = UPPER(:pc) ORDER BY id DESC LIMIT 1");
                        $stmtPn->execute([':pc' => $resolvedPartCode]);
                        $resolvedPartName = $stmtPn->fetchColumn() ?: '';
                    }

                    echo json_encode([
                        'success'    => false,
                        'error_type' => 'already_passed_duplicate',
                        'message'    => "Label Box Part \"{$resolvedPartCode}\" / Lot \"{$lotNumber}\" / Ref \"{$refNumber}\" SUDAH PERNAH diinspeksi & PASSED pada Sesi #{$passedRow['session_id']}{$kDesc}. Mencegah data ganda!",
                        'details'    => [
                            'part_code'       => $resolvedPartCode,
                            'part_name'       => $resolvedPartName ?: ($sess['part_name'] ?? '-'),
                            'lot_number'      => $lotNumber,
                            'ref_number'      => $refNumber,
                            'qty'             => (int)($passedRow['qty'] ?? ($lot['qty'] ?? 0)),
                            'session_id'      => (int)$passedRow['session_id'],
                            'inspection_type' => $passedRow['inspection_type'],
                            'kanban_no'       => $passedRow['kanban_no'] ?: '-',
                            'line_name'       => $passedRow['line_name'] ?: '-',
                            'inspector_name'  => $passedRow['inspector_name'] ?: 'Inspector QC',
                            'closed_at'       => !empty($passedRow['closed_at']) ? date('d/m/Y H:i', strtotime($passedRow['closed_at'])) : '-'
                        ]
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

        // 5.5 Deteksi apakah box berasal dari Safety Stock Gudang
        $matchedSS = null;
        if (($sess['inspection_type'] ?? 'kanban') === 'kanban' && !empty($refNumber)) {
            $stmtFindSS = $pdo->prepare("
                SELECT ss.id AS ss_session_id, ss.status AS ss_status, ss.total_scanned_qty,
                       isl_ss.id AS ss_lot_id, isl_ss.qty AS ss_lot_qty, isl_ss.lot_result, isl_ss.lot_status
                FROM inspection_session_lots isl_ss
                JOIN inspection_sessions ss ON ss.id = isl_ss.inspection_session_id
                JOIN daily_inspection_data did ON did.id = ss.did_id
                WHERE ss.inspection_type = 'safety_stock'
                  AND (ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0)
                  AND UPPER(did.part_code) = UPPER(:pcode)
                  AND UPPER(isl_ss.ref_number) = UPPER(:ref)
                ORDER BY ss.id DESC
                LIMIT 1
            ");
            $stmtFindSS->execute([':pcode' => $effectivePartCode, ':ref' => $refNumber]);
            $matchedSS = $stmtFindSS->fetch(PDO::FETCH_ASSOC);
        }

        // Jika box berasal dari Safety Stock dan sesi bertipe Kanban:
        // PENTING: Qty box yang masuk ke Sesi Kanban HARUS TETAP UTUH sesuai fisik label box yang discan ($qty, misal 300 pcs)!
        // Jangan pernah memotong qty box fisik menjadi 200 pcs.
        $allocatedQtyForThisLot = $qty;
        $ssAvailQty = $matchedSS ? (int)($matchedSS['ss_lot_qty'] ?: $matchedSS['total_scanned_qty']) : $qty;
        $ssLeftoverQty = 0;

        if ($matchedSS && $isKanban) {
            $remainingKanbanQuota = max(0, $kanbanTargetQty - $currSessionAllocatedQty);
            // Sisa stok Safety Stock yang masih ada di gudang jika ada kelebihan stok
            if ($qty < $ssAvailQty) {
                $ssLeftoverQty = $ssAvailQty - $qty;
            }
        }

        $boxRemarks = $matchedSS ? "Alokasi Safety Stock Fisik (Sesi #{$matchedSS['ss_session_id']})" : "Box Tambahan";

        // 6. Masukkan Box ke inspection_session_lots (Qty tetap utuh sesuai fisik box!)
        $stmtIns = $pdo->prepare("
            INSERT INTO inspection_session_lots (
                inspection_session_id, ref_number, lot_number, qty,
                aql_level, sample_code, sample_size, accept_number, reject_number,
                ng_count, lot_result, lot_status, scanned_qr_raw, remarks, created_at
            ) VALUES (
                :sid, :ref, :lot, :qty,
                :aql_lvl, :scode, :ssize, :acc, :rej,
                0, 'in_progress', 'ok', :raw_qr, :remarks, NOW()
            )
        ");
        $stmtIns->execute([
            ':sid'     => $sessionId,
            ':ref'     => !empty($refNumber) ? $refNumber : null,
            ':lot'     => $lotNumber,
            ':qty'     => $allocatedQtyForThisLot,
            ':aql_lvl' => $aqlLevel,
            ':scode'   => $sampleCode,
            ':ssize'   => $sampleSize,
            ':acc'     => $acceptNumber,
            ':rej'     => $rejectNumber,
            ':raw_qr'  => $rawQr,
            ':remarks' => $boxRemarks
        ]);
        $insertedLotIds[] = (int)$pdo->lastInsertId();
        $currSessionAllocatedQty += $allocatedQtyForThisLot;

        // Auto-link atau kurangi kuota pada source Safety Stock session jika box diambil dari SS gudang
        if ($matchedSS) {
            $matchedSsSessId = (int)$matchedSS['ss_session_id'];
            $matchedSsLotId  = (int)$matchedSS['ss_lot_id'];
            $matchedSsStatus = $matchedSS['ss_status'] ?? 'passed';

            if ($ssLeftoverQty > 0) {
                // SISA STOK DIPISAH KE BARIS SESI SAFETY STOCK BARU:
                // Sesi awal tetap mencatat history asli (tidak dipotong angkanya), tapi ditandai auto_fulfilled_by_session_id ke sesi Kanban ini
                $stmtLinkOld = $pdo->prepare("
                    UPDATE inspection_sessions 
                    SET auto_fulfilled_by_session_id = :ksid,
                        status = 'passed',
                        reinspection_notes = CONCAT(COALESCE(reinspection_notes, ''), ' [Terpakai {$allocatedQtyForThisLot} pcs ke Kanban #{$sess['kanban_no']}, sisa {$ssLeftoverQty} pcs]')
                    WHERE id = :ssid
                ");
                $stmtLinkOld->execute([':ksid' => $sessionId, ':ssid' => $matchedSsSessId]);

                // Query template data dari sesi SS asal untuk menduplikasi header sesi baru
                $stmtOrigHeader = $pdo->prepare("SELECT * FROM inspection_sessions WHERE id = :ssid");
                $stmtOrigHeader->execute([':ssid' => $matchedSsSessId]);
                $origH = $stmtOrigHeader->fetch(PDO::FETCH_ASSOC);

                // Buat baris SESI SAFETY STOCK BARU untuk sisa kuota (tersedia di gudang)
                $stmtInsNewSs = $pdo->prepare("
                    INSERT INTO inspection_sessions (
                        inspection_type, flow_version, did_id, kanban_item_id, part_id,
                        sample_size, total_scanned_qty, batch_sample_size, excess_qty,
                        use_safety_stock_qty, reject_number, samples_checked, ng_count,
                        status, auto_fulfilled_by_session_id, original_ss_session_id,
                        parent_session_id, is_reinspection, reinspection_type, reinspection_notes,
                        inspector_id, line_id, line_name, started_at, closed_at
                    ) VALUES (
                        'safety_stock', 2, :did_id, :kid, :part_id,
                        :spl_size, :tot_qty, :batch_spl, 0,
                        0, :rej, :samples_chk, 0,
                        'passed', NULL, :orig_ssid,
                        :parent_ssid, 0, NULL, :notes,
                        :insp, :lid, :line, NOW(), NOW()
                    )
                ");
                $stmtInsNewSs->execute([
                    ':did_id'      => $origH['did_id'],
                    ':kid'         => $origH['kanban_item_id'],
                    ':part_id'     => $origH['part_id'],
                    ':spl_size'    => $sampleSize,
                    ':tot_qty'     => $ssLeftoverQty,
                    ':batch_spl'   => $sampleSize,
                    ':rej'         => $rejectNumber,
                    ':samples_chk' => $sampleSize,
                    ':orig_ssid'   => $matchedSsSessId,
                    ':parent_ssid' => $matchedSsSessId,
                    ':notes'       => "Sisa Stok ({$ssLeftoverQty} pcs) pecahan dari Sesi #{$matchedSsSessId}",
                    ':insp'        => $origH['inspector_id'],
                    ':lid'         => $origH['line_id'],
                    ':line'        => $origH['line_name']
                ]);
                $newSsSessionId = (int)$pdo->lastInsertId();

                // Buat baris LOT pada sesi Safety Stock baru
                $stmtInsNewSsLot = $pdo->prepare("
                    INSERT INTO inspection_session_lots (
                        inspection_session_id, ref_number, lot_number, qty,
                        aql_level, sample_code, sample_size, accept_number, reject_number,
                        ng_count, lot_result, lot_status, scanned_qr_raw, remarks, created_at, closed_at
                    ) VALUES (
                        :ssid, :ref, :lot, :qty,
                        :aql_lvl, :scode, :ssize, :acc, :rej,
                        0, 'passed', 'ok', :raw_qr, :rem, NOW(), NOW()
                    )
                ");
                $newQrRaw = "Z1{$effectivePartCode}|Z2{$lotNumber}|Z3{$ssLeftoverQty}|Z5{$refNumber}";
                $stmtInsNewSsLot->execute([
                    ':ssid'    => $newSsSessionId,
                    ':ref'     => $refNumber,
                    ':lot'     => $lotNumber,
                    ':qty'     => $ssLeftoverQty,
                    ':aql_lvl' => $aqlLevel,
                    ':scode'   => $sampleCode,
                    ':ssize'   => $sampleSize,
                    ':acc'     => $acceptNumber,
                    ':rej'     => $rejectNumber,
                    ':raw_qr'  => $newQrRaw,
                    ':rem'     => "Sisa Stok dari Sesi #{$matchedSsSessId}"
                ]);
            } else {
                // HABIS TERPAKAI SELURUHNYA: Tandai fulfilled
                $stmtLink = $pdo->prepare("
                    UPDATE inspection_sessions 
                    SET auto_fulfilled_by_session_id = :ksid,
                        status = 'passed',
                        reinspection_notes = CASE 
                            WHEN :old_status = 'rejected' THEN CONCAT(COALESCE(reinspection_notes, ''), ' [Re-inspeksi Lulus via Kanban]')
                            ELSE reinspection_notes
                        END
                    WHERE id = :ssid AND (auto_fulfilled_by_session_id IS NULL OR auto_fulfilled_by_session_id = 0)
                ");
                $stmtLink->execute([
                    ':ksid'       => $sessionId, 
                    ':old_status' => $matchedSsStatus,
                    ':ssid'       => $matchedSsSessId
                ]);
            }

            // Update status sesi safety stock asal:
            // Jika asalnya 'rejected', ubah menjadi 'reinspected'. Jika asalnya sudah 'passed', biarkan tetap 'ok' / 'passed'.
            if ($matchedSsStatus === 'rejected' || ($matchedSS['lot_result'] ?? '') === 'rejected') {
                $pdo->prepare("
                    UPDATE inspection_session_lots 
                    SET lot_status = 'reinspected',
                        lot_result = 'passed'
                    WHERE id = :lid
                ")->execute([':lid' => $matchedSsLotId]);
            } else {
                // Barang memang sudah PASSED sejak awal, pastikan statusnya tetap OK
                $pdo->prepare("
                    UPDATE inspection_session_lots 
                    SET lot_status = 'ok',
                        lot_result = 'passed'
                    WHERE id = :lid
                ")->execute([':lid' => $matchedSsLotId]);
            }

            $stmtAddSsQty = $pdo->prepare("UPDATE inspection_sessions SET use_safety_stock_qty = use_safety_stock_qty + :qty WHERE id = :ksid");
            $stmtAddSsQty->execute([':qty' => $allocatedQtyForThisLot, ':ksid' => $sessionId]);
        }
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
