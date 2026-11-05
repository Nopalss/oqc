<?php
/**
 * Action Handler: Cancel / Delete Inspection Session (Rollback Lots, Kanban & Safety Stock)
 * Digunakan oleh modul Daily Report untuk membatalkan sesi inspeksi kanban/safety stock
 * yang terlanjur diinspeksi secara salah:
 * 1. Melepaskan lot barcode agar dapat di-scan ulang tanpa pesan duplikat.
 * 2. Mengembalikan status kanban menjadi belum diinspeksi (uninspected).
 * 3. Menghapus sesi Safety Stock turunan (hasil sisa kelebihan / excess scan kanban).
 * 4. Menghapus entri kanban_items virtual jika sesi yang dibatalkan adalah Safety Stock murni.
 * 5. Memulihkan alokasi Safety Stock gudang yang sempat dikonsumsi oleh sesi ini.
 * 
 * PT. Surya Technology Industri — Outgoing Quality Control Division
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/../../config/summary_helper.php';

header('Content-Type: application/json; charset=utf-8');

$isAjax = isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi Anda telah berakhir. Silakan login kembali.']);
    exit;
}

$currentUser = current_user();
$userRole = strtolower($currentUser['role'] ?? '');
$allowedRoles = ['supervisor', 'chief', 'admin', 'leader'];

if (!in_array($userRole, $allowedRoles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak: Hanya Admin atau Supervisor yang berhak membatalkan sesi inspeksi.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode permintaan tidak valid.']);
    exit;
}

if (!verify_csrf_token()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Validasi token keamanan (CSRF) gagal. Silakan muat ulang halaman.']);
    exit;
}

$sessionId = filter_input(INPUT_POST, 'session_id', FILTER_VALIDATE_INT);
$reason    = sanitize($_POST['reason'] ?? 'Dibatalkan oleh supervisor/admin');

if (!$sessionId || $sessionId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID Sesi inspeksi tidak valid.']);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Gagal terhubung ke basis data server.']);
    exit;
}

try {
    // 1. Ambil detail sesi inspeksi utama
    $stmtSess = $pdo->prepare("
        SELECT 
            ss.id, 
            ss.inspection_type, 
            ss.kanban_item_id, 
            ss.did_id, 
            ss.is_approved, 
            ss.status, 
            ss.started_at,
            ss.excess_qty,
            ss.auto_fulfilled_by_session_id,
            ki.kanban_no,
            ki.plan_type,
            COALESCE(did.part_code, ki.item_code, mp.part_code) AS part_code
        FROM inspection_sessions ss
        LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
        LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
        LEFT JOIN master_parts mp ON mp.id = ss.part_id
        WHERE ss.id = :id
        LIMIT 1
    ");
    $stmtSess->execute([':id' => $sessionId]);
    $session = $stmtSess->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode(['success' => false, 'message' => 'Sesi inspeksi tidak ditemukan atau sudah dibatalkan sebelumnya.']);
        exit;
    }

    // 2. Cegah pembatalan jika sesi SUDAH DI-ACC supervisor
    if (!empty($session['is_approved']) && (int)$session['is_approved'] === 1) {
        echo json_encode([
            'success' => false, 
            'message' => "Sesi #{$sessionId} sudah disetujui (ACC) oleh Supervisor. Harap batalkan persetujuan ACC terlebih dahulu sebelum membatalkan sesi ini."
        ]);
        exit;
    }

    // 3. Deteksi Sesi Safety Stock Turunan yang Terbentuk dari Kelebihan (Excess) Sesi Ini
    // Dibuat otomatis oleh createAutoSafetyStockForSession() dengan penanda AUTO-SS-SESS-[sessionId]
    $checkRef = "AUTO-SS-SESS-" . $sessionId;
    $stmtChildSS = $pdo->prepare("
        SELECT ss.id AS ss_session_id, ss.kanban_item_id, ss.auto_fulfilled_by_session_id, ss.did_id, ki.kanban_no
        FROM inspection_sessions ss
        LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
        WHERE ss.inspection_type = 'safety_stock'
          AND (
              ki.remark LIKE :ref1 
              OR ki.remark LIKE :ref2 
              OR ss.original_ss_session_id = :sid
          )
    ");
    $stmtChildSS->execute([
        ':ref1' => '%' . $checkRef . '%',
        ':ref2' => '%[Ref: ' . $checkRef . '%',
        ':sid'  => $sessionId
    ]);
    $childSSList = $stmtChildSS->fetchAll(PDO::FETCH_ASSOC);

    // 4. Validasi Integritas: Cegah jika stok turunan sudah terlanjur dikonsumsi oleh Sesi Kanban lain
    foreach ($childSSList as $cSS) {
        if (!empty($cSS['auto_fulfilled_by_session_id']) && (int)$cSS['auto_fulfilled_by_session_id'] > 0) {
            echo json_encode([
                'success' => false,
                'message' => "Sesi #{$sessionId} tidak dapat dibatalkan langsung karena sisa stok lebihannya (Safety Stock #{$cSS['ss_session_id']}) telah terlanjur dikonsumsi oleh Sesi Kanban #{$cSS['auto_fulfilled_by_session_id']}. Harap batalkan sesi kanban tersebut terlebih dahulu."
            ]);
            exit;
        }
    }

    $kanbanItemId = !empty($session['kanban_item_id']) ? (int)$session['kanban_item_id'] : 0;
    $sessionDate  = !empty($session['started_at']) ? date('Y-m-d', strtotime($session['started_at'])) : null;
    $kanbanNo     = $session['kanban_no'] ?: '-';
    $partCode     = $session['part_code'] ?: '-';
    $isSafetyStock = ($session['inspection_type'] === 'safety_stock');

    // 5. Eksekusi Rollback dalam Database Transaction (ACID)
    $pdo->beginTransaction();

    // ═══════════════════════════════════════════════════════════════════════
    // A. BERSIHKAN SAFETY STOCK TURUNAN (AUTO-SS DARI EXCESS SESI INI)
    // ═══════════════════════════════════════════════════════════════════════
    $deletedChildSSCount = 0;
    $deletedChildLotsCount = 0;

    if (!empty($childSSList)) {
        $childSessionIds = array_column($childSSList, 'ss_session_id');
        $childKanbanIds  = array_filter(array_column($childSSList, 'kanban_item_id'));
        $childDidIds     = array_filter(array_column($childSSList, 'did_id'));

        $childPlaceholders = implode(',', array_fill(0, count($childSessionIds), '?'));

        // A1. Hapus riwayat print rejection untuk sesi turunan
        $stmtDelChildPrints = $pdo->prepare("DELETE FROM rejection_sheet_prints WHERE inspection_session_id IN ($childPlaceholders)");
        $stmtDelChildPrints->execute($childSessionIds);

        // A2. Hapus closure karantina sesi turunan
        $stmtDelChildClosures = $pdo->prepare("
            DELETE FROM lot_quarantine_closures 
            WHERE session_lot_id IN (
                SELECT id FROM inspection_session_lots WHERE inspection_session_id IN ($childPlaceholders)
            )
        ");
        $stmtDelChildClosures->execute($childSessionIds);

        // A3. Hapus substitution log sesi turunan
        $stmtDelChildSubst = $pdo->prepare("
            DELETE FROM lot_substitution_log 
            WHERE original_session_id IN ($childPlaceholders)
               OR reinspection_session_id IN ($childPlaceholders)
               OR ng_session_lot_id IN (SELECT id FROM inspection_session_lots WHERE inspection_session_id IN ($childPlaceholders))
               OR replacement_session_lot_id IN (SELECT id FROM inspection_session_lots WHERE inspection_session_id IN ($childPlaceholders))
        ");
        $stmtDelChildSubst->execute(array_merge($childSessionIds, $childSessionIds, $childSessionIds, $childSessionIds));

        // A4. Hapus defect & sample sesi turunan
        $stmtDelChildNg = $pdo->prepare("DELETE FROM inspection_ng_records WHERE inspection_session_id IN ($childPlaceholders)");
        $stmtDelChildNg->execute($childSessionIds);

        $stmtDelChildSamples = $pdo->prepare("DELETE FROM inspection_samples WHERE inspection_session_id IN ($childPlaceholders)");
        $stmtDelChildSamples->execute($childSessionIds);

        // A5. Hapus lot box dari sesi turunan
        $stmtDelChildLots = $pdo->prepare("DELETE FROM inspection_session_lots WHERE inspection_session_id IN ($childPlaceholders)");
        $stmtDelChildLots->execute($childSessionIds);
        $deletedChildLotsCount = $stmtDelChildLots->rowCount();

        // A6. Hapus sesi turunan
        $stmtDelChildSess = $pdo->prepare("DELETE FROM inspection_sessions WHERE id IN ($childPlaceholders)");
        $stmtDelChildSess->execute($childSessionIds);
        $deletedChildSSCount = $stmtDelChildSess->rowCount();

        // A7. Hapus entri kanban_items bertipe safety_stock milik sesi turunan
        if (!empty($childKanbanIds)) {
            $kidPlaceholders = implode(',', array_fill(0, count($childKanbanIds), '?'));
            $stmtDelChildK = $pdo->prepare("DELETE FROM kanban_items WHERE id IN ($kidPlaceholders) AND plan_type = 'safety_stock'");
            $stmtDelChildK->execute(array_values($childKanbanIds));
        }

        // A8. Bersihkan DID virtual overflow jika tidak dipakai oleh sesi lain
        if (!empty($childDidIds)) {
            foreach (array_unique($childDidIds) as $cDidId) {
                $stmtChkDidOther = $pdo->prepare("SELECT COUNT(*) FROM inspection_sessions WHERE did_id = :did_id");
                $stmtChkDidOther->execute([':did_id' => $cDidId]);
                if ((int)$stmtChkDidOther->fetchColumn() === 0) {
                    $stmtDelDid = $pdo->prepare("DELETE FROM daily_inspection_data WHERE id = :did_id AND pic = 'SAFETY_STOCK_OVERFLOW'");
                    $stmtDelDid->execute([':did_id' => $cDidId]);
                }
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // B. PULIHKAN SAFETY STOCK YANG DIKONSUMSI OLEH SESI INI
    // ═══════════════════════════════════════════════════════════════════════
    // Jika sesi ini pernah mengonsumsi Safety Stock gudang, lepaskan alokasinya agar kembali available
    $stmtRestoreSS = $pdo->prepare("
        UPDATE inspection_sessions 
        SET auto_fulfilled_by_session_id = NULL 
        WHERE auto_fulfilled_by_session_id = :sid
    ");
    $stmtRestoreSS->execute([':sid' => $sessionId]);
    $restoredSSCount = $stmtRestoreSS->rowCount();

    // ═══════════════════════════════════════════════════════════════════════
    // C. HAPUS SESI UTAMA DAN DATA TERKAIT
    // ═══════════════════════════════════════════════════════════════════════
    // C1. Hapus riwayat cetak form reject
    $stmtPrints = $pdo->prepare("DELETE FROM rejection_sheet_prints WHERE inspection_session_id = :sid");
    $stmtPrints->execute([':sid' => $sessionId]);

    // C2. Hapus karantina closure
    $stmtClosure = $pdo->prepare("
        DELETE FROM lot_quarantine_closures 
        WHERE session_lot_id IN (
            SELECT id FROM inspection_session_lots WHERE inspection_session_id = :sid
        )
    ");
    $stmtClosure->execute([':sid' => $sessionId]);

    // C3. Hapus substitution log
    $stmtSubst = $pdo->prepare("
        DELETE FROM lot_substitution_log 
        WHERE original_session_id = :sid 
           OR reinspection_session_id = :sid_re
           OR ng_session_lot_id IN (SELECT id FROM inspection_session_lots WHERE inspection_session_id = :sid_l1)
           OR replacement_session_lot_id IN (SELECT id FROM inspection_session_lots WHERE inspection_session_id = :sid_l2)
    ");
    $stmtSubst->execute([
        ':sid'    => $sessionId,
        ':sid_re' => $sessionId,
        ':sid_l1' => $sessionId,
        ':sid_l2' => $sessionId
    ]);

    // C4. Hapus did_approval_requests jika ada
    $stmtReq = $pdo->prepare("DELETE FROM did_approval_requests WHERE inspection_session_id = :sid");
    $stmtReq->execute([':sid' => $sessionId]);

    // C5. Hapus temuan cacat (NG records)
    $stmtDelNg = $pdo->prepare("DELETE FROM inspection_ng_records WHERE inspection_session_id = :sid");
    $stmtDelNg->execute([':sid' => $sessionId]);

    // C6. Hapus sampel inspeksi
    $stmtDelSamples = $pdo->prepare("DELETE FROM inspection_samples WHERE inspection_session_id = :sid");
    $stmtDelSamples->execute([':sid' => $sessionId]);

    // C7. Hapus seluruh data lot yang ter-scan pada sesi utama
    // (Melepaskan barcode label lot agar bebas di-scan ulang tanpa pesan duplikat)
    $stmtDelLots = $pdo->prepare("DELETE FROM inspection_session_lots WHERE inspection_session_id = :sid");
    $stmtDelLots->execute([':sid' => $sessionId]);
    $deletedLotsCount = $stmtDelLots->rowCount();

    // C8. Hapus record sesi inspeksi utama
    $stmtDelSess = $pdo->prepare("DELETE FROM inspection_sessions WHERE id = :sid");
    $stmtDelSess->execute([':sid' => $sessionId]);

    // C9. Jika sesi utama adalah Safety Stock murni (plan_type = 'safety_stock'),
    // periksa dan hapus entri kanban_items virtualnya jika tidak ada sesi lain yang merujuk
    if ($isSafetyStock && $kanbanItemId > 0) {
        $stmtChkOtherSess = $pdo->prepare("SELECT COUNT(*) FROM inspection_sessions WHERE kanban_item_id = :kid");
        $stmtChkOtherSess->execute([':kid' => $kanbanItemId]);
        if ((int)$stmtChkOtherSess->fetchColumn() === 0) {
            $stmtDelKItem = $pdo->prepare("DELETE FROM kanban_items WHERE id = :kid AND plan_type = 'safety_stock'");
            $stmtDelKItem->execute([':kid' => $kanbanItemId]);
        }
    }

    $pdo->commit();

    // ═══════════════════════════════════════════════════════════════════════
    // 6. SINKRONISASI PASCA-ROLLBACK
    // ═══════════════════════════════════════════════════════════════════════
    // A. Sinkronisasi Kanban Item Status: Jika tidak ada sesi lain, status kanban kembali otomatis ke 'uninspected'
    if (!$isSafetyStock && $kanbanItemId > 0 && function_exists('syncKanbanStatus')) {
        syncKanbanStatus($pdo, $kanbanItemId);
    }

    // B. Sinkronisasi Rekapitulasi Harian OQC agar angka total qty, lot, sampling, defect berkurang akurat
    if ($sessionDate && function_exists('syncDailySummaryForDate')) {
        syncDailySummaryForDate($pdo, $sessionDate);
    }

    // 7. Bangun Pesan Respon yang Informatif
    $detailsParts = [];
    $totalLotsReleased = $deletedLotsCount + $deletedChildLotsCount;
    $detailsParts[] = "{$totalLotsReleased} label lot dilepas";

    if ($deletedChildSSCount > 0) {
        $detailsParts[] = "{$deletedChildSSCount} sesi Safety Stock limpahan (excess) ikut dihapus";
    }
    if ($restoredSSCount > 0) {
        $detailsParts[] = "{$restoredSSCount} alokasi Safety Stock gudang dipulihkan";
    }

    $msgInfo = implode(', ', $detailsParts);
    $itemDesc = ($kanbanNo !== '-' ? "Kanban #{$kanbanNo}" : "Part {$partCode}");

    echo json_encode([
        'success'             => true,
        'message'             => "Sesi #{$sessionId} ({$itemDesc}) berhasil dibatalkan. {$msgInfo}.",
        'session_id'          => $sessionId,
        'kanban_item_id'      => $kanbanItemId,
        'deleted_lots'        => $deletedLotsCount,
        'deleted_child_ss'    => $deletedChildSSCount,
        'restored_ss'         => $restoredSSCount,
        'session_date'        => $sessionDate
    ]);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem saat membatalkan sesi inspeksi: ' . $e->getMessage()
    ]);
    exit;
}
