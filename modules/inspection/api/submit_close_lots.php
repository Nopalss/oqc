<?php
/**
 * API: Submit Close Kanban dengan Multi-Lot Fisik
 * Menyimpan sesi inspeksi Passed dan mendaftarkan lot-lot fisik yang dimasukkan user
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';
require_once __DIR__ . '/../../../config/summary_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metode request wajib POST']);
    exit;
}

$user = current_user();
if (empty($user['id'])) {
    echo json_encode(['success' => false, 'message' => 'Sesi login telah berakhir']);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Gagal koneksi database']);
    exit;
}

$kanbanId = (int)($_POST['kanban_id'] ?? 0);
$remarks  = trim(sanitize($_POST['remarks'] ?? ''));
$rawLots  = $_POST['lots'] ?? [];

if ($kanbanId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID Kanban tidak valid']);
    exit;
}

if (empty($rawLots) || !is_array($rawLots)) {
    echo json_encode(['success' => false, 'message' => 'Minimal harus memasukkan 1 Lot']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Lock data Kanban
    $stmtK = $pdo->prepare("SELECT id, batch_id, plan_type, kanban_no, item_code, item_description, customer, qty, status FROM kanban_items WHERE id = :kid FOR UPDATE");
    $stmtK->execute([':kid' => $kanbanId]);
    $kRow = $stmtK->fetch(PDO::FETCH_ASSOC);

    if (!$kRow) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Data Kanban tidak ditemukan']);
        exit;
    }

    if ($kRow['status'] === 'completed') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => "Kanban #{$kRow['kanban_no']} sudah berstatus Selesai"]);
        exit;
    }

    $targetQty = (int)$kRow['qty'];

    // 2. Hitung kuota yang sudah lulus dari sesi sebelumnya
    $stmtS = $pdo->prepare("SELECT id, line_id, line_name, total_scanned_qty, excess_qty, status FROM inspection_sessions WHERE kanban_item_id = :kid ORDER BY id ASC");
    $stmtS->execute([':kid' => $kanbanId]);
    $allSessions = $stmtS->fetchAll(PDO::FETCH_ASSOC);

    $alreadyPassed = 0;
    foreach ($allSessions as $s) {
        if ($s['status'] === 'in_progress') {
            $pdo->rollBack();
            $line = $s['line_name'] ?: 'Line';
            echo json_encode([
                'success' => false,
                'message' => "Kanban sedang dikerjakan pada Sesi #{$s['id']} ({$line}). Selesaikan atau batalkan sesi aktif tersebut terlebih dahulu."
            ]);
            exit;
        }
        if ($s['status'] === 'passed') {
            $effective = (int)$s['total_scanned_qty'] - (int)($s['excess_qty'] ?? 0);
            $alreadyPassed += max(0, $effective);
        }
    }

    $remainingQty = max(0, $targetQty - $alreadyPassed);
    if ($remainingQty <= 0) {
        $pdo->prepare("UPDATE kanban_items SET status = 'completed', updated_at = NOW() WHERE id = :kid")->execute([':kid' => $kanbanId]);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Kanban sudah terpenuhi dan ditutup']);
        exit;
    }

    // 3. Filter dan olah lot yang valid
    $cleanLots = [];
    foreach ($rawLots as $idx => $l) {
        $lNum = trim(sanitize($l['lot_number'] ?? ''));
        $rNum = trim(sanitize($l['ref_number'] ?? ''));
        $qVal = isset($l['qty']) && is_numeric($l['qty']) ? (int)$l['qty'] : 0;
        $isFixed = !empty($l['ng_fixed']) ? 1 : 0;

        if (!empty($lNum)) {
            if (empty($rNum)) {
                $rNum = 'REF-' . date('md') . '-' . ($idx + 1) . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 5));
            }
            $cleanLots[] = [
                'lot_number' => $lNum,
                'ref_number' => $rNum,
                'qty'        => $qVal,
                'ng_fixed'   => $isFixed
            ];
        }
    }

    $lotCount = count($cleanLots);
    if ($lotCount === 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Tidak ada data lot yang valid']);
        exit;
    }

    // Helper AQL per Lot (Level II Standard ISO 2859-1 / MIL-STD-105E)
    function calculateLotAqlSample($qty, $pdo) {
        $qty = (int)$qty;
        if ($qty <= 0) return ['sample_size' => 0, 'reject_number' => 1];
        try {
            $stmtAql = $pdo->prepare("
                SELECT sample_size, reject_number 
                FROM aql_standards 
                WHERE inspection_level IN ('G-II', 'Level II', 'II') 
                  AND :qty >= qty_min AND :qty <= qty_max 
                LIMIT 1
            ");
            $stmtAql->execute([':qty' => $qty]);
            $row = $stmtAql->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'sample_size'   => (int)$row['sample_size'],
                    'reject_number' => (int)$row['reject_number']
                ];
            }
        } catch (Exception $e) {}

        // Fallback Standar ISO 2859-1 Level II
        if ($qty <= 8) return ['sample_size' => min(2, $qty), 'reject_number' => 1];
        if ($qty <= 15) return ['sample_size' => min(3, $qty), 'reject_number' => 1];
        if ($qty <= 25) return ['sample_size' => min(5, $qty), 'reject_number' => 1];
        if ($qty <= 50) return ['sample_size' => min(8, $qty), 'reject_number' => 1];
        if ($qty <= 90) return ['sample_size' => min(13, $qty), 'reject_number' => 1];
        if ($qty <= 150) return ['sample_size' => min(20, $qty), 'reject_number' => 1];
        if ($qty <= 280) return ['sample_size' => min(32, $qty), 'reject_number' => 1];
        if ($qty <= 500) return ['sample_size' => min(50, $qty), 'reject_number' => 1];
        if ($qty <= 1200) return ['sample_size' => min(80, $qty), 'reject_number' => 2];
        if ($qty <= 3200) return ['sample_size' => min(125, $qty), 'reject_number' => 2];
        if ($qty <= 10000) return ['sample_size' => min(200, $qty), 'reject_number' => 3];
        return ['sample_size' => min(315, $qty), 'reject_number' => 4];
    }

    // 4. Pembagian Qty: Jika qty = 0, bagi rata sisa target Kanban
    $totalSpecifiedQty = 0;
    $unspecifiedIndices = [];

    foreach ($cleanLots as $idx => $lot) {
        if ($lot['qty'] > 0) {
            $totalSpecifiedQty += $lot['qty'];
        } else {
            $unspecifiedIndices[] = $idx;
        }
    }

    $unspecifiedCount = count($unspecifiedIndices);
    if ($unspecifiedCount > 0) {
        $leftoverToDistribute = max(0, $remainingQty - $totalSpecifiedQty);
        $baseQtyPerLot = (int)floor($leftoverToDistribute / $unspecifiedCount);
        $remainder = $leftoverToDistribute % $unspecifiedCount;

        foreach ($unspecifiedIndices as $i => $uIdx) {
            $cleanLots[$uIdx]['qty'] = $baseQtyPerLot + ($i < $remainder ? 1 : 0);
        }
    }

    // 4.1. Server-side Protection & Safety Stock Allocation Handling
    $checkUsedStmt = $pdo->prepare("
        SELECT isl.id, isl.lot_number, isl.ref_number, k.kanban_no, s.id as sess_id
        FROM inspection_session_lots isl
        JOIN inspection_sessions s ON s.id = isl.inspection_session_id
        JOIN kanban_items k ON k.id = s.kanban_item_id
        LEFT JOIN master_parts mp ON mp.id = s.part_id
        WHERE (UPPER(COALESCE(mp.part_code, k.item_code)) = UPPER(:pcode) OR UPPER(k.item_code) = UPPER(:pcode2))
          AND UPPER(isl.ref_number) = UPPER(:ref_num)
          AND isl.lot_result = 'passed'
          AND (isl.lot_status IS NULL OR isl.lot_status != 'replaced')
          AND s.status = 'passed'
          AND s.inspection_type = 'kanban'
          AND s.kanban_item_id != :curr_kid
        ORDER BY isl.id DESC
        LIMIT 1
    ");

    $stmtFindSS = $pdo->prepare("
        SELECT isl.id AS ss_lot_id, s.id AS ss_session_id, s.status AS ss_session_status,
               COALESCE(isl.qty, s.total_scanned_qty, 0) AS available_qty
        FROM inspection_session_lots isl
        JOIN inspection_sessions s ON s.id = isl.inspection_session_id
        LEFT JOIN daily_inspection_data did ON did.id = s.did_id
        LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
        LEFT JOIN master_parts mp ON mp.id = s.part_id
        WHERE s.inspection_type = 'safety_stock'
          AND s.status IN ('passed', 'rejected')
          AND (s.auto_fulfilled_by_session_id IS NULL OR s.auto_fulfilled_by_session_id = 0)
          AND UPPER(isl.ref_number) = UPPER(:ref)
          AND (UPPER(did.part_code) = UPPER(:p1) OR UPPER(ki.item_code) = UPPER(:p2) OR UPPER(mp.part_code) = UPPER(:p3))
        ORDER BY isl.id DESC
        LIMIT 1
    ");

    $totalSafetyStockUsedQty = 0;
    $safetyStockAllocations = []; // Menyimpan info pemotongan SS
    $seenRefInPayload = [];

    foreach ($cleanLots as $kIdx => &$lot) {
        // Cek duplikasi ref_number di dalam payload form yang sama
        if (!empty($lot['ref_number'])) {
            $upperRef = strtoupper($lot['ref_number']);
            if (isset($seenRefInPayload[$upperRef])) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => "Ref Number '{$lot['ref_number']}' terduplikasi di dalam form pengisian ini. Setiap box fisik harus memiliki Ref Number unik."
                ]);
                exit;
            }
            $seenRefInPayload[$upperRef] = true;

            // Cek apakah berasal dari Safety Stock
            $stmtFindSS->execute([
                ':ref' => $lot['ref_number'],
                ':p1'  => $kRow['item_code'],
                ':p2'  => $kRow['item_code'],
                ':p3'  => $kRow['item_code']
            ]);
            $ssMatch = $stmtFindSS->fetch(PDO::FETCH_ASSOC);

            if ($ssMatch) {
                $avail = (int)$ssMatch['available_qty'];
                if ($avail <= 0) {
                    $pdo->rollBack();
                    echo json_encode([
                        'success' => false,
                        'message' => "Label Safety Stock Ref '{$lot['ref_number']}' sudah habis terpakai (0 pcs) di gudang!"
                    ]);
                    exit;
                }

                // Ambil qty sisa, tidak boleh melebihi sisa stok riil
                if ($lot['qty'] > $avail || $lot['qty'] <= 0) {
                    $lot['qty'] = $avail;
                }

                $totalSafetyStockUsedQty += $lot['qty'];
                $safetyStockAllocations[] = [
                    'ss_session_id'     => (int)$ssMatch['ss_session_id'],
                    'ss_lot_id'         => (int)$ssMatch['ss_lot_id'],
                    'ss_session_status' => $ssMatch['ss_session_status'] ?? 'passed',
                    'used_qty'          => $lot['qty'],
                    'avail_qty'         => $avail
                ];
            } else {
                // Jika bukan Safety Stock, proteksi double-use Kanban lain secara ketat
                $checkUsedStmt->execute([
                    ':pcode'    => $kRow['item_code'],
                    ':pcode2'   => $kRow['item_code'],
                    ':ref_num'  => $lot['ref_number'],
                    ':curr_kid' => $kanbanId
                ]);
                $usedRow = $checkUsedStmt->fetch(PDO::FETCH_ASSOC);
                if ($usedRow) {
                    $pdo->rollBack();
                    $kbNo = $usedRow['kanban_no'] ?: ('#' . $kanbanId);
                    $usedLot = $usedRow['lot_number'] ?: $lot['lot_number'];
                    echo json_encode([
                        'success' => false,
                        'message' => "Label Box Ref: '{$lot['ref_number']}' (Lot: '{$usedLot}', Part: {$kRow['item_code']}) DITOLAK karena sudah digunakan & passed pada Kanban {$kbNo} sebelumnya."
                    ]);
                    exit;
                }
            }
        }
    }
    unset($lot);

    // 5. Hitung total akumulasi qty dan Sample AQL murni per lot
    $actualScannedQty = 0;
    $totalSessionSampleSize = 0;
    $maxRejectNumber = 1;

    foreach ($cleanLots as $kIdx => $lot) {
        $actualScannedQty += $lot['qty'];
        $lotAql = calculateLotAqlSample($lot['qty'], $pdo);
        $cleanLots[$kIdx]['sample_size'] = $lotAql['sample_size'];
        $cleanLots[$kIdx]['reject_number'] = $lotAql['reject_number'];
        $totalSessionSampleSize += $lotAql['sample_size'];
        if ($lotAql['reject_number'] > $maxRejectNumber) {
            $maxRejectNumber = $lotAql['reject_number'];
        }
    }

    // 5.1. Hitung Kelebihan Qty (Excess Qty) untuk Safety Stock
    $calculatedExcessQty = max(0, $actualScannedQty - $remainingQty);

    // 6. Lookup Master Part ID & Daily Inspection Data (DID)
    $partId = null;
    $partName = $kRow['item_description'] ?: $kRow['item_code'];
    $stmtP = $pdo->prepare("SELECT id, part_name FROM master_parts WHERE part_code = :pcode LIMIT 1");
    $stmtP->execute([':pcode' => $kRow['item_code']]);
    $partRow = $stmtP->fetch(PDO::FETCH_ASSOC);
    if ($partRow) {
        $partId = (int)$partRow['id'];
        if (!empty($partRow['part_name'])) {
            $partName = $partRow['part_name'];
        }
    }

    // Pastikan record DID tersedia untuk relasi sesi inspeksi
    $stmtDid = $pdo->prepare("SELECT id FROM daily_inspection_data WHERE UPPER(part_code) = UPPER(:pcode) AND inspecting_date = CURDATE() ORDER BY id DESC LIMIT 1");
    $stmtDid->execute([':pcode' => $kRow['item_code']]);
    $didId = (int)$stmtDid->fetchColumn();

    if ($didId <= 0) {
        $firstLot = !empty($cleanLots[0]['lot_number']) ? $cleanLots[0]['lot_number'] : ('KB-' . ($kRow['kanban_no'] ?: $kanbanId));
        $stmtInsDid = $pdo->prepare("
            INSERT INTO daily_inspection_data 
            (part_code, part_name, lot_number, cavity, inspecting_date, status_inspect, pic, created_at) 
            VALUES (:pcode, :pname, :lot, '1', CURDATE(), 'OK', :pic, NOW())
        ");
        $stmtInsDid->execute([
            ':pcode' => $kRow['item_code'],
            ':pname' => $partName,
            ':lot'   => $firstLot,
            ':pic'   => $user['name'] ?? 'SYSTEM'
        ]);
        $didId = (int)$pdo->lastInsertId();
    }

    // 7. Buat Sesi Inspeksi Passed
    $now = date('Y-m-d H:i:s');
    $sessionRemarks = 'Tutup Kanban (Multi-Lot Fisik: ' . $lotCount . ' Lot)';
    if ($calculatedExcessQty > 0) {
        $sessionRemarks .= ' [Excess +' . $calculatedExcessQty . ' pcs ke Safety Stock]';
    }
    if ($totalSafetyStockUsedQty > 0) {
        $sessionRemarks .= ' [Alokasi ' . $totalSafetyStockUsedQty . ' pcs dari Safety Stock]';
    }
    if (!empty($remarks)) {
        $sessionRemarks .= ' - ' . $remarks;
    }

    $insSessSql = "
        INSERT INTO inspection_sessions (
            flow_version, did_id, inspection_type, kanban_item_id, part_id, inspector_id,
            line_id, line_name, started_at, closed_at, status, total_scanned_qty,
            sample_size, batch_sample_size, reject_number, samples_checked, ng_count,
            excess_qty, use_safety_stock_qty, approval_notes
        ) VALUES (
            2, :did, 'kanban', :kid, :pid, :uid,
            1, 'Line 1', :started_at, :closed_at, 'passed', :scanned_qty,
            :sample_size, :batch_sample_size, :reject_number, :samples_checked, 0,
            :excess_qty, :use_ss_qty, :notes
        )
    ";
    $stmtInsSess = $pdo->prepare($insSessSql);
    $stmtInsSess->execute([
        ':did'               => $didId,
        ':kid'               => $kanbanId,
        ':pid'               => $partId,
        ':uid'               => $user['id'],
        ':started_at'        => $now,
        ':closed_at'         => $now,
        ':scanned_qty'       => $actualScannedQty,
        ':sample_size'       => $totalSessionSampleSize,
        ':batch_sample_size' => $totalSessionSampleSize,
        ':reject_number'     => $maxRejectNumber,
        ':samples_checked'   => $totalSessionSampleSize,
        ':excess_qty'        => $calculatedExcessQty,
        ':use_ss_qty'        => $totalSafetyStockUsedQty,
        ':notes'             => $sessionRemarks
    ]);
    $sessionId = (int)$pdo->lastInsertId();

    // 7.1. Potong kuota Safety Stock yang terpakai
    foreach ($safetyStockAllocations as $alloc) {
        $ssLotId  = $alloc['ss_lot_id'];
        $ssSessId = $alloc['ss_session_id'];
        $usedQty  = $alloc['used_qty'];
        $availQty = $alloc['avail_qty'];

        $kbNoText = $kRow['kanban_no'] ?? (string)$kanbanId;
        $prevStatus = $alloc['ss_session_status'] ?? 'passed';

        if ($usedQty >= $availQty) {
            // Habis terpakai seluruhnya
            $stmtUpdSSFull = $pdo->prepare("
                UPDATE inspection_sessions 
                SET auto_fulfilled_by_session_id = :ksid,
                    status = 'passed',
                    reinspection_notes = CASE 
                        WHEN :prev_status = 'rejected' THEN CONCAT(COALESCE(reinspection_notes, ''), ' [Re-inspeksi Lulus via Kanban #', :kb_no, ']')
                        ELSE reinspection_notes
                    END
                WHERE id = :ssid
            ");
            $stmtUpdSSFull->execute([
                ':ksid'        => $sessionId,
                ':ssid'        => $ssSessId,
                ':prev_status' => $prevStatus,
                ':kb_no'       => $kbNoText
            ]);

            // Update lot SS asal
            if ($ssLotId > 0) {
                $pdo->prepare("
                    UPDATE inspection_session_lots 
                    SET lot_status = 'reinspected', 
                        lot_result = 'passed',
                        remarks = CONCAT(COALESCE(remarks, ''), ' [Digunakan Kanban #', :kb_no, ']')
                    WHERE id = :lid
                ")->execute([':kb_no' => $kbNoText, ':lid' => $ssLotId]);
            }
        } else {
            // Terpakai sebagian -> kurangi qty di lot SS asal dan sesi SS
            $newLeftoverQty = $availQty - $usedQty;
            $stmtUpdSSPartLot = $pdo->prepare("UPDATE inspection_session_lots SET qty = :nqty WHERE id = :lid");
            $stmtUpdSSPartLot->execute([':nqty' => $newLeftoverQty, ':lid' => $ssLotId]);

            $stmtUpdSSPartSess = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = :nqty WHERE id = :ssid");
            $stmtUpdSSPartSess->execute([':nqty' => $newLeftoverQty, ':ssid' => $ssSessId]);
        }
    }

    // 8. Masukkan Data Lot ke inspection_session_lots dengan sample size AQL masing-masing
    $insLotSql = "
        INSERT INTO inspection_session_lots (
            inspection_session_id, lot_number, ref_number, qty,
            sample_size, reject_number, ng_count, lot_result, lot_status, remarks,
            created_at
        ) VALUES (
            :sid, :lot_no, :ref_no, :qty,
            :sample_sz, :rej_no, 0, 'passed', 'ok', :lot_rem,
            NOW()
        )
    ";
    $stmtInsLot = $pdo->prepare($insLotSql);

    foreach ($cleanLots as $lot) {
        $lotRem = 'Tutup Kanban Langsung';
        if ($lot['ng_fixed'] === 1) {
            $lotRem .= ' (NG Sebelumnya Sudah Diperbaiki/Fix)';
        }

        $stmtInsLot->execute([
            ':sid'       => $sessionId,
            ':lot_no'    => $lot['lot_number'],
            ':ref_no'    => !empty($lot['ref_number']) ? $lot['ref_number'] : null,
            ':qty'       => $lot['qty'],
            ':sample_sz' => $lot['sample_size'],
            ':rej_no'    => $lot['reject_number'],
            ':lot_rem'   => $lotRem
        ]);
    }

    // 9. Update status Kanban menjadi completed
    $stmtUpdK = $pdo->prepare("UPDATE kanban_items SET status = 'completed', updated_at = NOW() WHERE id = :kid");
    $stmtUpdK->execute([':kid' => $kanbanId]);

    $pdo->commit();

    // 10. Auto-create Safety Stock jika terdapat excess_qty > 0 (setara alur session.php / process_sample.php)
    if ($calculatedExcessQty > 0 && function_exists('createAutoSafetyStockForSession')) {
        createAutoSafetyStockForSession($pdo, $sessionId);
    }

    // Sinkronisasi status & ringkasan jika fungsi pembantu tersedia
    if (function_exists('syncKanbanStatus')) {
        syncKanbanStatus($pdo, $kanbanId);
    }
    if (function_exists('syncDailySummaryForDate')) {
        syncDailySummaryForDate($pdo, date('Y-m-d'));
    }

    $msg = "Kanban #{$kRow['kanban_no']} berhasil ditutup dengan {$lotCount} lot fisik.";
    if ($calculatedExcessQty > 0) {
        $msg .= " Kelebihan {$calculatedExcessQty} pcs otomatis tersimpan ke Safety Stock.";
    }
    if ($totalSafetyStockUsedQty > 0) {
        $msg .= " Alokasi {$totalSafetyStockUsedQty} pcs diambil dari Safety Stock gudang.";
    }

    echo json_encode([
        'success'           => true,
        'message'           => $msg,
        'session_id'        => $sessionId,
        'excess_qty'        => $calculatedExcessQty,
        'safety_stock_used' => $totalSafetyStockUsedQty
    ]);

} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
