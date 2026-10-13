<?php
/**
 * Action Handler: Update DID Batch & Sync Item Rows
 * Hardened: RBAC Check + CSRF Protection + Inspection Guard on removed items
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('did');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/did/index.php');
}

if (!verify_csrf_token()) {
    set_flash('error', 'Sesi keamanan (CSRF Token) tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.');
    redirect('modules/did/index.php');
}

$batch_id = filter_input(INPUT_POST, 'batch_id', FILTER_VALIDATE_INT);
$batchName = trim(sanitize($_POST['batch_name'] ?? ''));
$picDefault = trim(sanitize($_POST['pic_default'] ?? 'Budi QC'));
$rows = $_POST['rows'] ?? [];

if (!$batch_id || empty($batchName)) {
    set_flash('error', 'Data Batch tidak valid!');
    redirect('modules/did/index.php');
}

$pdo = getDB();

if ($pdo) {
    try {
        $pdo->beginTransaction();

        // 1. Update batch header
        $stmtUpdHeader = $pdo->prepare("UPDATE did_batches SET batch_name = :name WHERE id = :id");
        $stmtUpdHeader->execute([':name' => $batchName, ':id' => $batch_id]);

        // 2. Fetch existing items for deletion diff
        $stmtCurr = $pdo->prepare("SELECT id FROM daily_inspection_data WHERE batch_id = :bid");
        $stmtCurr->execute([':bid' => $batch_id]);
        $existingIds = $stmtCurr->fetchAll(PDO::FETCH_COLUMN);

        $submittedIds = [];
        $okCount = 0;
        $ngCount = 0;

        // Cache master parts to avoid repetitive queries
        $cachedParts = [];
        $stmtAllParts = $pdo->query("SELECT UPPER(part_code) as pcode FROM master_parts");
        while ($pRow = $stmtAllParts->fetch(PDO::FETCH_ASSOC)) {
            $cachedParts[$pRow['pcode']] = true;
        }

        $stmtInsertMaster = $pdo->prepare("INSERT INTO master_parts (part_code, part_name, source, created_at) VALUES (:pcode, :pname, 'auto_generated', NOW())");
        $stmtUpdate = $pdo->prepare("UPDATE daily_inspection_data SET 
            part_code = :pcode, part_name = :pname, lot_number = :lot, cavity = :cav, status_inspect = :status, remark = :remark, updated_at = NOW() 
            WHERE id = :id AND batch_id = :bid");
        $stmtInsert = $pdo->prepare("INSERT INTO daily_inspection_data 
            (batch_id, part_code, part_name, lot_number, cavity, inspecting_date, status_inspect, pic, remark, created_at) 
            VALUES (:bid, :pcode, :pname, :lot, :cav, NOW(), :status, :pic, :remark, NOW())");

        foreach ($rows as $r) {
            $itemId = $r['item_id'] ?? 'new';
            $selectCode = trim(sanitize($r['part_code_select'] ?? ''));
            $customCode = strtoupper(trim(sanitize($r['part_code_custom'] ?? '')));
            $partCode = (!empty($r['part_code'])) ? strtoupper(trim(sanitize($r['part_code']))) : (($selectCode === 'custom') ? $customCode : strtoupper($selectCode));
            $partName = trim(sanitize($r['part_name'] ?? ''));
            $lotNumber = strtoupper(trim(sanitize($r['lot_number'] ?? '')));
            $cavity = trim(sanitize($r['cavity'] ?? '1'));
            $statusInspect = (sanitize($r['status_inspect'] ?? 'OK') === 'NG') ? 'NG' : 'OK';
            $remark = trim(sanitize($r['remark'] ?? ''));

            if (empty($partCode) || empty($lotNumber) || empty($partName)) {
                continue;
            }

            // Auto-create Master Part if not exists (using in-memory cache)
            if (!isset($cachedParts[$partCode])) {
                $stmtInsertMaster->execute([':pcode' => $partCode, ':pname' => $partName]);
                $cachedParts[$partCode] = true;
            }

            if (is_numeric($itemId) && in_array($itemId, $existingIds)) {
                // Update existing item
                $stmtUpdate->execute([
                    ':pcode'  => $partCode,
                    ':pname'  => $partName,
                    ':lot'    => $lotNumber,
                    ':cav'    => $cavity,
                    ':status' => $statusInspect,
                    ':remark' => $remark,
                    ':id'     => $itemId,
                    ':bid'    => $batch_id
                ]);
                $submittedIds[] = (int)$itemId;
            } else {
                // Insert new item
                $stmtInsert->execute([
                    ':bid'    => $batch_id,
                    ':pcode'  => $partCode,
                    ':pname'  => $partName,
                    ':lot'    => $lotNumber,
                    ':cav'    => $cavity,
                    ':status' => $statusInspect,
                    ':pic'    => $picDefault ?: 'Budi QC',
                    ':remark' => $remark
                ]);
                $submittedIds[] = (int)$pdo->lastInsertId();
            }

            if ($statusInspect === 'OK') $okCount++; else $ngCount++;
        }

        // 3. Handle removed items: GUARD against deleting items linked to active inspection sessions
        $toDelete = array_diff($existingIds, $submittedIds);
        if (!empty($toDelete)) {
            $inClause = implode(',', array_map('intval', $toDelete));

            // Check if any deleted item has inspection sessions
            $stmtCheckLinked = $pdo->query("SELECT COUNT(*) FROM inspection_sessions WHERE did_id IN ({$inClause})");
            $linkedCount = (int)$stmtCheckLinked->fetchColumn();

            if ($linkedCount > 0) {
                $pdo->rollBack();
                set_flash('warning', 'Beberapa baris yang Anda hapus TIDAK DAPAT DIHAPUS karena sudah memiliki ' . $linkedCount . ' riwayat sesi inspeksi QC. Perubahan batch dibatalkan untuk menjaga keutuhan data.');
                redirect('modules/did/detail_batch.php?batch_id=' . $batch_id);
            }

            $pdo->exec("DELETE FROM daily_inspection_data WHERE id IN ({$inClause}) AND batch_id = {$batch_id}");
        }

        // 4. Update batch summary stats
        $totalFinal = count($submittedIds);
        $stmtUpdBatch = $pdo->prepare("UPDATE did_batches SET total_items = :total, ok_count = :ok, ng_count = :ng WHERE id = :bid");
        $stmtUpdBatch->execute([':total' => $totalFinal, ':ok' => $okCount, ':ng' => $ngCount, ':bid' => $batch_id]);

        $pdo->commit();
        set_flash('success', 'Sesi Batch DID berhasil diperbarui!');
    } catch (PDOException $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Gagal memperbarui sesi batch: ' . $e->getMessage());
    }
} else {
    set_flash('error', 'Database tidak terhubung!');
}

redirect('modules/did/detail_batch.php?batch_id=' . $batch_id);
