<?php
/**
 * Action Handler: Update DID Batch & Sync Item Rows
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
        // Update batch header
        $stmtUpdHeader = $pdo->prepare("UPDATE did_batches SET batch_name = :name WHERE id = :id");
        $stmtUpdHeader->execute([':name' => $batchName, ':id' => $batch_id]);

        // Fetch existing items for deletion diff
        $stmtCurr = $pdo->prepare("SELECT id FROM daily_inspection_data WHERE batch_id = :bid");
        $stmtCurr->execute([':bid' => $batch_id]);
        $existingIds = $stmtCurr->fetchAll(PDO::FETCH_COLUMN);

        $submittedIds = [];
        $okCount = 0;
        $ngCount = 0;

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

            // Auto-create Master Part if not exists
            $stmtCheckMaster = $pdo->prepare("SELECT id FROM master_parts WHERE part_code = :pcode");
            $stmtCheckMaster->execute([':pcode' => $partCode]);
            if (!$stmtCheckMaster->fetch()) {
                $stmtInsertMaster = $pdo->prepare("INSERT INTO master_parts (part_code, part_name, source, created_at) VALUES (:pcode, :pname, 'auto_generated', NOW())");
                $stmtInsertMaster->execute([':pcode' => $partCode, ':pname' => $partName]);
            }

            if (is_numeric($itemId) && in_array($itemId, $existingIds)) {
                // Update existing item
                $stmtUpdate = $pdo->prepare("UPDATE daily_inspection_data SET 
                    part_code = :pcode, part_name = :pname, lot_number = :lot, cavity = :cav, status_inspect = :status, remark = :remark, updated_at = NOW() 
                    WHERE id = :id AND batch_id = :bid");
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
                $stmtInsert = $pdo->prepare("INSERT INTO daily_inspection_data 
                    (batch_id, part_code, part_name, lot_number, cavity, inspecting_date, status_inspect, pic, remark, created_at) 
                    VALUES (:bid, :pcode, :pname, :lot, :cav, NOW(), :status, :pic, :remark, NOW())");
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

        // Delete removed items
        $toDelete = array_diff($existingIds, $submittedIds);
        if (!empty($toDelete)) {
            $inClause = implode(',', array_map('intval', $toDelete));
            $pdo->exec("DELETE FROM inspection_sessions WHERE did_id IN ({$inClause})");
            $pdo->exec("DELETE FROM daily_inspection_data WHERE id IN ({$inClause}) AND batch_id = {$batch_id}");
        }

        // Update batch summary stats
        $totalFinal = count($submittedIds);
        $stmtUpdBatch = $pdo->prepare("UPDATE did_batches SET total_items = :total, ok_count = :ok, ng_count = :ng WHERE id = :bid");
        $stmtUpdBatch->execute([':total' => $totalFinal, ':ok' => $okCount, ':ng' => $ngCount, ':bid' => $batch_id]);

        set_flash('success', 'Sesi Batch DID berhasil diperbarui!');
    } catch (PDOException $e) {
        set_flash('error', 'Gagal memperbarui sesi batch: ' . $e->getMessage());
    }
} else {
    set_flash('error', 'Database tidak terhubung!');
}

redirect('modules/did/detail_batch.php?batch_id=' . $batch_id);
