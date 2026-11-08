<?php
/**
 * Modul Safety Stock - Action Handler Penyesuaian Stok Fisik
 * Menangani aksi Edit Kuantiti dan Hapus Label Box (Single & Batch) untuk role Admin / Chief.
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('safety_stock');

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1')
    || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

// Validasi Hak Akses Khusus Admin & Chief
$currentUser = current_user();
if (!in_array($currentUser['role'] ?? '', ['admin', 'chief'])) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Akses ditolak: Hanya Admin atau Chief yang berhak melakukan penyesuaian stok Safety Stock.'
        ]);
        exit;
    }
    set_flash('error', 'Akses ditolak: Hanya Admin atau Chief yang berhak melakukan penyesuaian stok Safety Stock.');
    redirect('modules/safety_stock/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Metode request tidak valid.']);
        exit;
    }
    redirect('modules/safety_stock/index.php');
    exit;
}

$action         = sanitize($_POST['action'] ?? '');
$lotId          = isset($_POST['lot_id']) ? (int)$_POST['lot_id'] : 0;
$sessionId      = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
$partCode       = sanitize($_POST['part_code'] ?? '');
$redirectTarget = sanitize($_POST['redirect_target'] ?? '');

$redirectUrl = ($redirectTarget === 'index' || empty($partCode))
    ? 'modules/safety_stock/index.php'
    : 'modules/safety_stock/detail.php?part_code=' . urlencode($partCode);

$pdo = getDB();
if (!$pdo) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Koneksi database gagal.']);
        exit;
    }
    set_flash('error', 'Koneksi database gagal.');
    redirect($redirectUrl);
    exit;
}

/**
 * Helper: Kirim respon sukses
 */
function respondSuccess($message, $redirectUrl, $isAjax) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'message' => $message]);
        exit;
    }
    set_flash('success', $message);
    redirect($redirectUrl);
    exit;
}

/**
 * Helper: Kirim respon error
 */
function respondError($message, $redirectUrl, $isAjax, $pdo = null) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
    set_flash('error', $message);
    redirect($redirectUrl);
    exit;
}

try {
    // ═════════════════════════════════════════════════════════════════════════
    // 1. UPDATE QTY TUNGGAL (Single Lot)
    // ═════════════════════════════════════════════════════════════════════════
    if ($action === 'update_qty') {
        $newQty = filter_var($_POST['new_qty'] ?? null, FILTER_VALIDATE_INT);
        if ($newQty === false || $newQty === null || $newQty < 1) {
            respondError('Kuantiti baru harus berupa bilangan bulat positif (minimal 1 pcs).', $redirectUrl, $isAjax);
        }

        $pdo->beginTransaction();

        if ($lotId > 0) {
            $stLot = $pdo->prepare("
                SELECT isl.*, ss.auto_fulfilled_by_session_id, ss.kanban_item_id
                FROM inspection_session_lots isl
                JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
                WHERE isl.id = :id FOR UPDATE
            ");
            $stLot->execute([':id' => $lotId]);
            $lotData = $stLot->fetch(PDO::FETCH_ASSOC);

            if (!$lotData) {
                respondError('Data label box tidak ditemukan.', $redirectUrl, $isAjax, $pdo);
            }

            if (!empty($lotData['auto_fulfilled_by_session_id']) && (int)$lotData['auto_fulfilled_by_session_id'] > 0) {
                respondError('Kuantiti tidak dapat diubah karena label ini sudah dialokasikan untuk pemotongan Kanban.', $redirectUrl, $isAjax, $pdo);
            }

            $currentSessionId = (int)$lotData['inspection_session_id'];
            $oldQty = (int)$lotData['qty'];
            $refText = $lotData['ref_number'] ?: ('Lot #' . $lotData['lot_number']);

            $stUpdLot = $pdo->prepare("UPDATE inspection_session_lots SET qty = :qty WHERE id = :id");
            $stUpdLot->execute([':qty' => $newQty, ':id' => $lotId]);

            $stSum = $pdo->prepare("SELECT COALESCE(SUM(qty), 0) AS total_qty FROM inspection_session_lots WHERE inspection_session_id = :sid");
            $stSum->execute([':sid' => $currentSessionId]);
            $totalSessionQty = (int)$stSum->fetchColumn();

            $stUpdSess = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = :tqty WHERE id = :sid");
            $stUpdSess->execute([':tqty' => $totalSessionQty, ':sid' => $currentSessionId]);

            if (!empty($lotData['kanban_item_id'])) {
                $stUpdK = $pdo->prepare("UPDATE kanban_items SET qty = :tqty WHERE id = :kid AND plan_type = 'safety_stock'");
                $stUpdK->execute([':tqty' => $totalSessionQty, ':kid' => $lotData['kanban_item_id']]);
            }

            $pdo->commit();
            respondSuccess("Kuantiti label {$refText} berhasil disesuaikan dari {$oldQty} pcs menjadi {$newQty} pcs.", $redirectUrl, $isAjax);

        } elseif ($sessionId > 0) {
            $stSess = $pdo->prepare("SELECT * FROM inspection_sessions WHERE id = :sid FOR UPDATE");
            $stSess->execute([':sid' => $sessionId]);
            $sessData = $stSess->fetch(PDO::FETCH_ASSOC);

            if (!$sessData) {
                respondError('Sesi inspeksi tidak ditemukan.', $redirectUrl, $isAjax, $pdo);
            }

            if (!empty($sessData['auto_fulfilled_by_session_id']) && (int)$sessData['auto_fulfilled_by_session_id'] > 0) {
                respondError('Sesi tidak dapat diubah karena sudah dialokasikan untuk pemotongan Kanban.', $redirectUrl, $isAjax, $pdo);
            }

            $oldQty = (int)$sessData['total_scanned_qty'];

            $stUpd = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = :qty WHERE id = :sid");
            $stUpd->execute([':qty' => $newQty, ':sid' => $sessionId]);

            if (!empty($sessData['kanban_item_id'])) {
                $stUpdK = $pdo->prepare("UPDATE kanban_items SET qty = :qty WHERE id = :kid AND plan_type = 'safety_stock'");
                $stUpdK->execute([':qty' => $newQty, ':kid' => $sessData['kanban_item_id']]);
            }

            $pdo->commit();
            respondSuccess("Kuantiti stok sesi #{$sessionId} berhasil disesuaikan dari {$oldQty} pcs menjadi {$newQty} pcs.", $redirectUrl, $isAjax);
        }

    // ═════════════════════════════════════════════════════════════════════════
    // 2. HAPUS LOT TUNGGAL (Single Lot)
    // ═════════════════════════════════════════════════════════════════════════
    } elseif ($action === 'delete_lot') {
        $pdo->beginTransaction();

        if ($lotId > 0) {
            $stLot = $pdo->prepare("
                SELECT isl.*, ss.auto_fulfilled_by_session_id, ss.kanban_item_id
                FROM inspection_session_lots isl
                JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
                WHERE isl.id = :id FOR UPDATE
            ");
            $stLot->execute([':id' => $lotId]);
            $lotData = $stLot->fetch(PDO::FETCH_ASSOC);

            if (!$lotData) {
                respondError('Data label box tidak ditemukan.', $redirectUrl, $isAjax, $pdo);
            }

            if (!empty($lotData['auto_fulfilled_by_session_id']) && (int)$lotData['auto_fulfilled_by_session_id'] > 0) {
                respondError('Label tidak dapat dihapus karena sudah dialokasikan untuk pemotongan Kanban.', $redirectUrl, $isAjax, $pdo);
            }

            $currentSessionId = (int)$lotData['inspection_session_id'];
            $refText = $lotData['ref_number'] ?: ('Lot #' . $lotData['lot_number']);
            $deletedQty = (int)$lotData['qty'];

            $stDel = $pdo->prepare("DELETE FROM inspection_session_lots WHERE id = :id");
            $stDel->execute([':id' => $lotId]);

            $stCheck = $pdo->prepare("
                SELECT COUNT(*) AS remaining_count, COALESCE(SUM(qty), 0) AS total_qty 
                FROM inspection_session_lots 
                WHERE inspection_session_id = :sid
            ");
            $stCheck->execute([':sid' => $currentSessionId]);
            $remaining = $stCheck->fetch(PDO::FETCH_ASSOC);
            $remainingCount = (int)$remaining['remaining_count'];
            $newTotalQty = (int)$remaining['total_qty'];

            if ($remainingCount > 0) {
                $stUpdSess = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = :tqty WHERE id = :sid");
                $stUpdSess->execute([':tqty' => $newTotalQty, ':sid' => $currentSessionId]);

                if (!empty($lotData['kanban_item_id'])) {
                    $stUpdK = $pdo->prepare("UPDATE kanban_items SET qty = :tqty WHERE id = :kid AND plan_type = 'safety_stock'");
                    $stUpdK->execute([':tqty' => $newTotalQty, ':kid' => $lotData['kanban_item_id']]);
                }
            } else {
                try {
                    $stDelSess = $pdo->prepare("DELETE FROM inspection_sessions WHERE id = :sid");
                    $stDelSess->execute([':sid' => $currentSessionId]);

                    if (!empty($lotData['kanban_item_id'])) {
                        $stDelK = $pdo->prepare("DELETE FROM kanban_items WHERE id = :kid AND plan_type = 'safety_stock'");
                        $stDelK->execute([':kid' => $lotData['kanban_item_id']]);
                    }
                } catch (PDOException $eFk) {
                    $stZero = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = 0 WHERE id = :sid");
                    $stZero->execute([':sid' => $currentSessionId]);
                }
            }

            $pdo->commit();
            respondSuccess("Label box {$refText} ({$deletedQty} pcs) berhasil dihapus dari stok gudang.", $redirectUrl, $isAjax);

        } elseif ($sessionId > 0) {
            $stSess = $pdo->prepare("SELECT * FROM inspection_sessions WHERE id = :sid FOR UPDATE");
            $stSess->execute([':sid' => $sessionId]);
            $sessData = $stSess->fetch(PDO::FETCH_ASSOC);

            if (!$sessData) {
                respondError('Sesi inspeksi tidak ditemukan.', $redirectUrl, $isAjax, $pdo);
            }

            if (!empty($sessData['auto_fulfilled_by_session_id']) && (int)$sessData['auto_fulfilled_by_session_id'] > 0) {
                respondError('Sesi tidak dapat dihapus karena sudah dialokasikan untuk pemotongan Kanban.', $redirectUrl, $isAjax, $pdo);
            }

            $deletedQty = (int)$sessData['total_scanned_qty'];

            try {
                $stDel = $pdo->prepare("DELETE FROM inspection_sessions WHERE id = :sid");
                $stDel->execute([':sid' => $sessionId]);

                if (!empty($sessData['kanban_item_id'])) {
                    $stDelK = $pdo->prepare("DELETE FROM kanban_items WHERE id = :kid AND plan_type = 'safety_stock'");
                    $stDelK->execute([':kid' => $sessData['kanban_item_id']]);
                }
            } catch (PDOException $eFk) {
                $stZero = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = 0 WHERE id = :sid");
                $stZero->execute([':sid' => $sessionId]);
            }

            $pdo->commit();
            respondSuccess("Stok sesi #{$sessionId} ({$deletedQty} pcs) berhasil dihapus dari gudang.", $redirectUrl, $isAjax);
        }

    // ═════════════════════════════════════════════════════════════════════════
    // 3. HAPUS BANYAK LOT SEKALIGUS (BATCH DELETE LOTS)
    // ═════════════════════════════════════════════════════════════════════════
    } elseif ($action === 'batch_delete_lots') {
        $lotIds = $_POST['lot_ids'] ?? [];
        $fallbackSessionIds = $_POST['session_ids'] ?? [];

        // Parse jika dikirim sebagai string JSON atau comma-separated
        if (is_string($lotIds)) {
            $decoded = json_decode($lotIds, true);
            $lotIds = is_array($decoded) ? $decoded : array_filter(array_map('intval', explode(',', $lotIds)));
        }
        if (is_string($fallbackSessionIds)) {
            $decodedS = json_decode($fallbackSessionIds, true);
            $fallbackSessionIds = is_array($decodedS) ? $decodedS : array_filter(array_map('intval', explode(',', $fallbackSessionIds)));
        }

        $cleanLotIds = array_values(array_unique(array_filter(array_map('intval', (array)$lotIds))));
        $cleanSessionIds = array_values(array_unique(array_filter(array_map('intval', (array)$fallbackSessionIds))));

        if (empty($cleanLotIds) && empty($cleanSessionIds)) {
            respondError('Pilih minimal satu lot atau box yang ingin dihapus.', $redirectUrl, $isAjax);
        }

        $pdo->beginTransaction();

        $affectedSessionIds = [];
        $deletedCount = 0;
        $totalDeletedQty = 0;

        // 1. Proses Hapus Lot Individual dari inspection_session_lots
        if (!empty($cleanLotIds)) {
            $inClause = implode(',', array_fill(0, count($cleanLotIds), '?'));
            $stFetch = $pdo->prepare("
                SELECT isl.id, isl.inspection_session_id, isl.qty, ss.auto_fulfilled_by_session_id, ss.kanban_item_id
                FROM inspection_session_lots isl
                JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
                WHERE isl.id IN ({$inClause})
                FOR UPDATE
            ");
            $stFetch->execute($cleanLotIds);
            $validLots = $stFetch->fetchAll(PDO::FETCH_ASSOC);

            foreach ($validLots as $vl) {
                // Lewatkan jika sudah dialokasikan Kanban
                if (!empty($vl['auto_fulfilled_by_session_id']) && (int)$vl['auto_fulfilled_by_session_id'] > 0) {
                    continue;
                }

                $lId = (int)$vl['id'];
                $sId = (int)$vl['inspection_session_id'];
                $q   = (int)$vl['qty'];

                $stDelSingle = $pdo->prepare("DELETE FROM inspection_session_lots WHERE id = :id");
                $stDelSingle->execute([':id' => $lId]);

                $affectedSessionIds[$sId] = $vl['kanban_item_id'];
                $deletedCount++;
                $totalDeletedQty += $q;
            }

            // Hitung ulang dan sinkronisasi kuantiti pada seluruh sesi yang terpengaruh
            foreach ($affectedSessionIds as $sId => $kanbanItemId) {
                $stRemain = $pdo->prepare("
                    SELECT COUNT(*) AS remain_count, COALESCE(SUM(qty), 0) AS total_qty
                    FROM inspection_session_lots
                    WHERE inspection_session_id = :sid
                ");
                $stRemain->execute([':sid' => $sId]);
                $rem = $stRemain->fetch(PDO::FETCH_ASSOC);
                $remCount = (int)$rem['remain_count'];
                $newSessQty = (int)$rem['total_qty'];

                if ($remCount > 0) {
                    $stUpd = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = :tqty WHERE id = :sid");
                    $stUpd->execute([':tqty' => $newSessQty, ':sid' => $sId]);

                    if (!empty($kanbanItemId)) {
                        $stUpdK = $pdo->prepare("UPDATE kanban_items SET qty = :tqty WHERE id = :kid AND plan_type = 'safety_stock'");
                        $stUpdK->execute([':tqty' => $newSessQty, ':kid' => $kanbanItemId]);
                    }
                } else {
                    try {
                        $stDelS = $pdo->prepare("DELETE FROM inspection_sessions WHERE id = :sid");
                        $stDelS->execute([':sid' => $sId]);

                        if (!empty($kanbanItemId)) {
                            $stDelK = $pdo->prepare("DELETE FROM kanban_items WHERE id = :kid AND plan_type = 'safety_stock'");
                            $stDelK->execute([':kid' => $kanbanItemId]);
                        }
                    } catch (PDOException $eFk) {
                        $stZero = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = 0 WHERE id = :sid");
                        $stZero->execute([':sid' => $sId]);
                    }
                }
            }
        }

        // 2. Proses Hapus Fallback Sesi Legacy (Jika ada)
        if (!empty($cleanSessionIds)) {
            foreach ($cleanSessionIds as $fsId) {
                $stSessFb = $pdo->prepare("SELECT * FROM inspection_sessions WHERE id = :sid FOR UPDATE");
                $stSessFb->execute([':sid' => $fsId]);
                $sRow = $stSessFb->fetch(PDO::FETCH_ASSOC);

                if ($sRow && (empty($sRow['auto_fulfilled_by_session_id']) || $sRow['auto_fulfilled_by_session_id'] == 0)) {
                    $delQ = (int)$sRow['total_scanned_qty'];
                    try {
                        $stDelFb = $pdo->prepare("DELETE FROM inspection_sessions WHERE id = :sid");
                        $stDelFb->execute([':sid' => $fsId]);

                        if (!empty($sRow['kanban_item_id'])) {
                            $stDelK = $pdo->prepare("DELETE FROM kanban_items WHERE id = :kid AND plan_type = 'safety_stock'");
                            $stDelK->execute([':kid' => $sRow['kanban_item_id']]);
                        }
                    } catch (PDOException $eFk) {
                        $stZero = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = 0 WHERE id = :sid");
                        $stZero->execute([':sid' => $fsId]);
                    }
                    $deletedCount++;
                    $totalDeletedQty += $delQ;
                }
            }
        }

        $pdo->commit();
        respondSuccess("Berhasil menghapus {$deletedCount} box/lot (" . number_format($totalDeletedQty) . " pcs) dari stok gudang.", $redirectUrl, $isAjax);

    // ═════════════════════════════════════════════════════════════════════════
    // 4. PENYESUAIAN KUANTITI MASAL (BATCH UPDATE QTY)
    // ═════════════════════════════════════════════════════════════════════════
    } elseif ($action === 'batch_update_qty') {
        $lotQtys = $_POST['lot_qtys'] ?? []; // Map [lot_id => new_qty]
        $sessionQtys = $_POST['session_qtys'] ?? []; // Map [session_id => new_qty] untuk fallback sesi

        if (is_string($lotQtys)) {
            $lotQtys = json_decode($lotQtys, true) ?: [];
        }
        if (is_string($sessionQtys)) {
            $sessionQtys = json_decode($sessionQtys, true) ?: [];
        }

        if (empty($lotQtys) && empty($sessionQtys)) {
            respondError('Tidak ada perubahan kuantiti yang dikirim.', $redirectUrl, $isAjax);
        }

        $pdo->beginTransaction();

        $updatedCount = 0;
        $affectedSessions = [];

        // 1. Update per Lot
        foreach ($lotQtys as $lIdStr => $newQ) {
            $lId = (int)$lIdStr;
            $nQty = filter_var($newQ, FILTER_VALIDATE_INT);
            if ($lId <= 0 || $nQty === false || $nQty < 1) continue;

            $stLot = $pdo->prepare("
                SELECT isl.id, isl.inspection_session_id, isl.qty, ss.auto_fulfilled_by_session_id, ss.kanban_item_id
                FROM inspection_session_lots isl
                JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
                WHERE isl.id = :id FOR UPDATE
            ");
            $stLot->execute([':id' => $lId]);
            $lData = $stLot->fetch(PDO::FETCH_ASSOC);

            if ($lData && (empty($lData['auto_fulfilled_by_session_id']) || $lData['auto_fulfilled_by_session_id'] == 0)) {
                if ((int)$lData['qty'] !== $nQty) {
                    $stUpd = $pdo->prepare("UPDATE inspection_session_lots SET qty = :qty WHERE id = :id");
                    $stUpd->execute([':qty' => $nQty, ':id' => $lId]);
                    $affectedSessions[(int)$lData['inspection_session_id']] = $lData['kanban_item_id'];
                    $updatedCount++;
                }
            }
        }

        // 2. Update Fallback Sesi Legacy
        foreach ($sessionQtys as $sIdStr => $newQ) {
            $sId = (int)$sIdStr;
            $nQty = filter_var($newQ, FILTER_VALIDATE_INT);
            if ($sId <= 0 || $nQty === false || $nQty < 1) continue;

            $stSess = $pdo->prepare("SELECT * FROM inspection_sessions WHERE id = :sid FOR UPDATE");
            $stSess->execute([':sid' => $sId]);
            $sData = $stSess->fetch(PDO::FETCH_ASSOC);

            if ($sData && (empty($sData['auto_fulfilled_by_session_id']) || $sData['auto_fulfilled_by_session_id'] == 0)) {
                if ((int)$sData['total_scanned_qty'] !== $nQty) {
                    $stUpdS = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = :qty WHERE id = :sid");
                    $stUpdS->execute([':qty' => $nQty, ':sid' => $sId]);

                    if (!empty($sData['kanban_item_id'])) {
                        $stUpdK = $pdo->prepare("UPDATE kanban_items SET qty = :qty WHERE id = :kid AND plan_type = 'safety_stock'");
                        $stUpdK->execute([':qty' => $nQty, ':kid' => $sData['kanban_item_id']]);
                    }
                    $updatedCount++;
                }
            }
        }

        // 3. Sinkronkan total kuantiti di seluruh sesi yang lot-nya ter-update
        foreach ($affectedSessions as $sId => $kanbanItemId) {
            $stSum = $pdo->prepare("SELECT COALESCE(SUM(qty), 0) FROM inspection_session_lots WHERE inspection_session_id = :sid");
            $stSum->execute([':sid' => $sId]);
            $newTot = (int)$stSum->fetchColumn();

            $stUpdSess = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = :qty WHERE id = :sid");
            $stUpdSess->execute([':qty' => $newTot, ':sid' => $sId]);

            if (!empty($kanbanItemId)) {
                $stUpdK = $pdo->prepare("UPDATE kanban_items SET qty = :qty WHERE id = :kid AND plan_type = 'safety_stock'");
                $stUpdK->execute([':qty' => $newTot, ':kid' => $kanbanItemId]);
            }
        }

        $pdo->commit();
        respondSuccess("Berhasil memperbarui kuantiti untuk {$updatedCount} box/lot fisik.", $redirectUrl, $isAjax);
    }

    respondError('Aksi tidak valid.', $redirectUrl, $isAjax);

} catch (PDOException $e) {
    respondError('Gagal memproses penyesuaian stok: ' . $e->getMessage(), $redirectUrl, $isAjax, $pdo);
}
