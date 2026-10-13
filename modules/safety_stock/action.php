<?php
/**
 * Modul Safety Stock - Action Handler Penyesuaian Stok Fisik
 * Menangani aksi Edit Kuantiti dan Hapus Label Box khusus untuk role Admin.
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('safety_stock');

// Validasi Hak Akses Khusus Admin
$currentUser = current_user();
if ($currentUser['role'] !== 'admin') {
    set_flash('error', 'Akses ditolak: Hanya Admin yang berhak melakukan penyesuaian stok Safety Stock.');
    redirect('modules/safety_stock/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/safety_stock/index.php');
    exit;
}

$action    = sanitize($_POST['action'] ?? '');
$lotId     = isset($_POST['lot_id']) ? (int)$_POST['lot_id'] : 0;
$sessionId = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
$partCode  = sanitize($_POST['part_code'] ?? '');
$redirectUrl = !empty($partCode) 
    ? 'modules/safety_stock/detail.php?part_code=' . urlencode($partCode) 
    : 'modules/safety_stock/index.php';

$pdo = getDB();
if (!$pdo) {
    set_flash('error', 'Koneksi database gagal.');
    redirect($redirectUrl);
    exit;
}

try {
    if ($action === 'update_qty') {
        $newQty = filter_var($_POST['new_qty'] ?? null, FILTER_VALIDATE_INT);
        if ($newQty === false || $newQty === null || $newQty < 1) {
            set_flash('error', 'Kuantiti baru harus berupa bilangan bulat positif (minimal 1 pcs).');
            redirect($redirectUrl);
            exit;
        }

        $pdo->beginTransaction();

        if ($lotId > 0) {
            // Cek data lot di database
            $stLot = $pdo->prepare("
                SELECT isl.*, ss.auto_fulfilled_by_session_id, ss.kanban_item_id
                FROM inspection_session_lots isl
                JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
                WHERE isl.id = :id FOR UPDATE
            ");
            $stLot->execute([':id' => $lotId]);
            $lotData = $stLot->fetch(PDO::FETCH_ASSOC);

            if (!$lotData) {
                $pdo->rollBack();
                set_flash('error', 'Data label box tidak ditemukan.');
                redirect($redirectUrl);
                exit;
            }

            if (!empty($lotData['auto_fulfilled_by_session_id']) && (int)$lotData['auto_fulfilled_by_session_id'] > 0) {
                $pdo->rollBack();
                set_flash('error', 'Kuantiti tidak dapat diubah karena label ini sudah dialokasikan untuk pemotongan Kanban.');
                redirect($redirectUrl);
                exit;
            }

            $currentSessionId = (int)$lotData['inspection_session_id'];
            $oldQty = (int)$lotData['qty'];
            $refText = $lotData['ref_number'] ?: ('Lot #' . $lotData['lot_number']);

            // 1. Update kuantiti pada inspection_session_lots
            $stUpdLot = $pdo->prepare("UPDATE inspection_session_lots SET qty = :qty WHERE id = :id");
            $stUpdLot->execute([':qty' => $newQty, ':id' => $lotId]);

            // 2. Hitung total kuantiti baru pada sesi ini
            $stSum = $pdo->prepare("SELECT COALESCE(SUM(qty), 0) AS total_qty FROM inspection_session_lots WHERE inspection_session_id = :sid");
            $stSum->execute([':sid' => $currentSessionId]);
            $totalSessionQty = (int)$stSum->fetchColumn();

            // 3. Update total_scanned_qty pada inspection_sessions
            $stUpdSess = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = :tqty WHERE id = :sid");
            $stUpdSess->execute([':tqty' => $totalSessionQty, ':sid' => $currentSessionId]);

            // 4. Update qty pada kanban_items terkait jika bertipe safety_stock
            if (!empty($lotData['kanban_item_id'])) {
                $stUpdK = $pdo->prepare("UPDATE kanban_items SET qty = :tqty WHERE id = :kid AND plan_type = 'safety_stock'");
                $stUpdK->execute([':tqty' => $totalSessionQty, ':kid' => $lotData['kanban_item_id']]);
            }

            $pdo->commit();
            set_flash('success', "Kuantiti label {$refText} berhasil disesuaikan dari {$oldQty} pcs menjadi {$newQty} pcs.");
            redirect($redirectUrl);
            exit;

        } elseif ($sessionId > 0) {
            // Kasus sesi safety stock tanpa session_lots terpisah
            $stSess = $pdo->prepare("SELECT * FROM inspection_sessions WHERE id = :sid FOR UPDATE");
            $stSess->execute([':sid' => $sessionId]);
            $sessData = $stSess->fetch(PDO::FETCH_ASSOC);

            if (!$sessData) {
                $pdo->rollBack();
                set_flash('error', 'Sesi inspeksi tidak ditemukan.');
                redirect($redirectUrl);
                exit;
            }

            if (!empty($sessData['auto_fulfilled_by_session_id']) && (int)$sessData['auto_fulfilled_by_session_id'] > 0) {
                $pdo->rollBack();
                set_flash('error', 'Sesi tidak dapat diubah karena sudah dialokasikan untuk pemotongan Kanban.');
                redirect($redirectUrl);
                exit;
            }

            $oldQty = (int)$sessData['total_scanned_qty'];

            $stUpd = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = :qty WHERE id = :sid");
            $stUpd->execute([':qty' => $newQty, ':sid' => $sessionId]);

            if (!empty($sessData['kanban_item_id'])) {
                $stUpdK = $pdo->prepare("UPDATE kanban_items SET qty = :qty WHERE id = :kid AND plan_type = 'safety_stock'");
                $stUpdK->execute([':qty' => $newQty, ':kid' => $sessData['kanban_item_id']]);
            }

            $pdo->commit();
            set_flash('success', "Kuantiti stok sesi #{$sessionId} berhasil disesuaikan dari {$oldQty} pcs menjadi {$newQty} pcs.");
            redirect($redirectUrl);
            exit;
        }

    } elseif ($action === 'delete_lot') {
        $pdo->beginTransaction();

        if ($lotId > 0) {
            // Cek data lot di database
            $stLot = $pdo->prepare("
                SELECT isl.*, ss.auto_fulfilled_by_session_id, ss.kanban_item_id
                FROM inspection_session_lots isl
                JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
                WHERE isl.id = :id FOR UPDATE
            ");
            $stLot->execute([':id' => $lotId]);
            $lotData = $stLot->fetch(PDO::FETCH_ASSOC);

            if (!$lotData) {
                $pdo->rollBack();
                set_flash('error', 'Data label box tidak ditemukan.');
                redirect($redirectUrl);
                exit;
            }

            if (!empty($lotData['auto_fulfilled_by_session_id']) && (int)$lotData['auto_fulfilled_by_session_id'] > 0) {
                $pdo->rollBack();
                set_flash('error', 'Label tidak dapat dihapus karena sudah dialokasikan untuk pemotongan Kanban.');
                redirect($redirectUrl);
                exit;
            }

            $currentSessionId = (int)$lotData['inspection_session_id'];
            $refText = $lotData['ref_number'] ?: ('Lot #' . $lotData['lot_number']);
            $deletedQty = (int)$lotData['qty'];

            // 1. Hapus baris dari inspection_session_lots
            $stDel = $pdo->prepare("DELETE FROM inspection_session_lots WHERE id = :id");
            $stDel->execute([':id' => $lotId]);

            // 2. Hitung sisa lot pada sesi ini
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
                // Masih ada box lain di sesi ini: update total_scanned_qty
                $stUpdSess = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = :tqty WHERE id = :sid");
                $stUpdSess->execute([':tqty' => $newTotalQty, ':sid' => $currentSessionId]);

                if (!empty($lotData['kanban_item_id'])) {
                    $stUpdK = $pdo->prepare("UPDATE kanban_items SET qty = :tqty WHERE id = :kid AND plan_type = 'safety_stock'");
                    $stUpdK->execute([':tqty' => $newTotalQty, ':kid' => $lotData['kanban_item_id']]);
                }
            } else {
                // Seluruh box di sesi ini sudah habis/terhapus: hapus sesi atau nolkan
                try {
                    $stDelSess = $pdo->prepare("DELETE FROM inspection_sessions WHERE id = :sid");
                    $stDelSess->execute([':sid' => $currentSessionId]);

                    if (!empty($lotData['kanban_item_id'])) {
                        $stDelK = $pdo->prepare("DELETE FROM kanban_items WHERE id = :kid AND plan_type = 'safety_stock'");
                        $stDelK->execute([':kid' => $lotData['kanban_item_id']]);
                    }
                } catch (PDOException $eFk) {
                    // Fallback jika ada foreign key (misal history log): set qty menjadi 0
                    $stZero = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = 0 WHERE id = :sid");
                    $stZero->execute([':sid' => $currentSessionId]);
                }
            }

            $pdo->commit();
            set_flash('success', "Label box {$refText} ({$deletedQty} pcs) berhasil dihapus dari stok gudang.");
            redirect($redirectUrl);
            exit;

        } elseif ($sessionId > 0) {
            // Kasus sesi virtual tanpa session_lots
            $stSess = $pdo->prepare("SELECT * FROM inspection_sessions WHERE id = :sid FOR UPDATE");
            $stSess->execute([':sid' => $sessionId]);
            $sessData = $stSess->fetch(PDO::FETCH_ASSOC);

            if (!$sessData) {
                $pdo->rollBack();
                set_flash('error', 'Sesi inspeksi tidak ditemukan.');
                redirect($redirectUrl);
                exit;
            }

            if (!empty($sessData['auto_fulfilled_by_session_id']) && (int)$sessData['auto_fulfilled_by_session_id'] > 0) {
                $pdo->rollBack();
                set_flash('error', 'Sesi tidak dapat dihapus karena sudah dialokasikan untuk pemotongan Kanban.');
                redirect($redirectUrl);
                exit;
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
            set_flash('success', "Stok sesi #{$sessionId} ({$deletedQty} pcs) berhasil dihapus dari gudang.");
            redirect($redirectUrl);
            exit;
        }
    }

    set_flash('error', 'Aksi tidak valid.');
    redirect($redirectUrl);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', 'Gagal memproses penyesuaian stok: ' . $e->getMessage());
    redirect($redirectUrl);
}
