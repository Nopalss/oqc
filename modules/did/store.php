<?php
/**
 * Action Handler: Store Daily Inspection Data (DID)
 * Creates Batch Record in did_batches & Tags batch_id on each item
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/xlsx_parser.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/did/index.php');
}

$pdo = getDB();

if (!$pdo) {
    set_flash('error', 'Database tidak terhubung!');
    redirect('modules/did/index.php');
}

$entryType = sanitize($_POST['entry_type'] ?? 'manual');

if ($entryType === 'manual') {
    // ----------------------------------------------------
    // 1. MULTI-ROW MANUAL MATRIX ENTRY
    // ----------------------------------------------------
    $inspectingDate = sanitize($_POST['inspecting_date'] ?? date('Y-m-d'));
    $pic = trim(sanitize($_POST['pic'] ?? 'QC Inspector'));
    $rows = $_POST['rows'] ?? [];

    if (empty($rows) || !is_array($rows)) {
        set_flash('error', 'Tidak ada baris data DID yang dikirim!');
        redirect('modules/did/create.php');
    }

    // =======================================================
    // VALIDASI: 1 Batch DID per Tanggal Inspeksi
    // =======================================================
    try {
        $stmtCheck = $pdo->prepare("SELECT id, batch_name FROM did_batches WHERE inspecting_date = :idate LIMIT 1");
        $stmtCheck->execute([':idate' => $inspectingDate]);
        $existingBatch = $stmtCheck->fetch();
        if ($existingBatch) {
            $tgl = date('d M Y', strtotime($inspectingDate));
            set_flash('error', 'Tanggal ' . $tgl . ' sudah memiliki batch DID! Setiap tanggal hanya boleh 1 batch. Gunakan fitur "Edit / Tambah Baris" pada batch yang sudah ada, atau hapus batch lama terlebih dahulu.');
            redirect('modules/did/create.php');
        }
    } catch (PDOException $e) {
        // Jika kolom inspecting_date belum ada, lanjutkan tanpa cek
    }

    try {
        // Create Batch Header
        $stmtBatch = $pdo->prepare("INSERT INTO did_batches (batch_name, inspecting_date, import_method, total_items, created_at) VALUES (:bname, :idate, 'manual', 0, NOW())");
        $batchTitle = "Manual Entry DID (" . date('d M Y') . ")";
        $stmtBatch->execute([':bname' => $batchTitle, ':idate' => $inspectingDate]);
        $batchId = $pdo->lastInsertId();

        $successCount = 0;
        $skipCount = 0;
        $okCount = 0;
        $ngCount = 0;

        foreach ($rows as $r) {
            $selectCode = trim(sanitize($r['part_code_select'] ?? ''));
            $customCode = strtoupper(trim(sanitize($r['part_code_custom'] ?? '')));
            $partCode = ($selectCode === 'custom') ? $customCode : strtoupper($selectCode);
            $partName = trim(sanitize($r['part_name'] ?? ''));
            $lotNumber = strtoupper(trim(sanitize($r['lot_number'] ?? '')));
            $cavity = trim(sanitize($r['cavity'] ?? '1'));
            $statusInspect = (sanitize($r['status_inspect'] ?? 'OK') === 'NG') ? 'NG' : 'OK';
            $remark = trim(sanitize($r['remark'] ?? ''));

            if (empty($partCode) || empty($lotNumber) || empty($partName)) {
                $skipCount++;
                continue;
            }

            // Check duplicate (Part Code + Lot Number)
            $stmtDup = $pdo->prepare("SELECT id FROM daily_inspection_data WHERE part_code = :pcode AND lot_number = :lot");
            $stmtDup->execute([':pcode' => $partCode, ':lot' => $lotNumber]);
            if ($stmtDup->fetch()) {
                $skipCount++;
                continue;
            }

            // Auto-create Master Part if not exists
            $stmtCheckMaster = $pdo->prepare("SELECT id FROM master_parts WHERE part_code = :pcode");
            $stmtCheckMaster->execute([':pcode' => $partCode]);
            if (!$stmtCheckMaster->fetch()) {
                $stmtInsertMaster = $pdo->prepare("INSERT INTO master_parts (part_code, part_name, source, created_at) VALUES (:pcode, :pname, 'auto_generated', NOW())");
                $stmtInsertMaster->execute([':pcode' => $partCode, ':pname' => $partName]);
            }

            // Insert into daily_inspection_data with batch_id
            $stmtInsert = $pdo->prepare("INSERT INTO daily_inspection_data 
                (batch_id, part_code, part_name, lot_number, cavity, inspecting_date, status_inspect, pic, remark, created_at) 
                VALUES (:bid, :pcode, :pname, :lot, :cav, :idate, :status, :pic, :remark, NOW())");
            
            $stmtInsert->execute([
                ':bid'    => $batchId,
                ':pcode'  => $partCode,
                ':pname'  => $partName,
                ':lot'    => $lotNumber,
                ':cav'    => $cavity,
                ':idate'  => $inspectingDate,
                ':status' => $statusInspect,
                ':pic'    => $pic,
                ':remark' => $remark
            ]);

            $successCount++;
            if ($statusInspect === 'OK') $okCount++; else $ngCount++;
        }

        // Update Batch Summary Stats
        $stmtUpdBatch = $pdo->prepare("UPDATE did_batches SET total_items = :total, ok_count = :ok, ng_count = :ng WHERE id = :bid");
        $stmtUpdBatch->execute([':total' => $successCount, ':ok' => $okCount, ':ng' => $ngCount, ':bid' => $batchId]);

        if ($successCount > 0) {
            $msg = 'Berhasil menyimpan ' . $successCount . ' baris data DID baru ke Riwayat Batch.';
            if ($skipCount > 0) {
                $msg .= ' (' . $skipCount . ' baris duplikat/invalid dilewati).';
            }
            set_flash('success', $msg);
        } else {
            // Delete empty batch
            $pdo->exec("DELETE FROM did_batches WHERE id = {$batchId}");
            set_flash('warning', 'Gagal menyimpan data DID! Seluruh baris duplikat atau tidak valid.');
        }

    } catch (PDOException $e) {
        set_flash('error', 'Gagal menyimpan batch DID: ' . $e->getMessage());
    }

} else if ($entryType === 'excel') {
    // ----------------------------------------------------
    // 2. BATCH CSV / EXCEL IMPORT
    // ----------------------------------------------------
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        set_flash('error', 'Pilih file CSV/Excel yang valid untuk diunggah!');
        redirect('modules/did/create.php');
    }

    $fileName = $_FILES['excel_file']['name'];
    $filePath = $_FILES['excel_file']['tmp_name'];
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        set_flash('error', 'Gagal membaca file unggahan.');
        redirect('modules/did/create.php');
    }

    try {
        // Peek first data row to detect inspecting_date for duplicate check
        $firstDate = date('Y-m-d');
        $peekHandle = fopen($filePath, 'r');
        if ($peekHandle) {
            $lineIdx = 0;
            while (($peek = fgetcsv($peekHandle, 2000, ',')) !== false) {
                $lineIdx++;
                if ($lineIdx === 1 && (strtolower($peek[0] ?? '') === 'part_code' || strtolower($peek[0] ?? '') === 'part code')) continue;
                if (!empty($peek[4])) { $firstDate = trim($peek[4]); }
                break;
            }
            fclose($peekHandle);
        }

        // VALIDASI: 1 Batch DID per Tanggal Inspeksi
        $stmtCheck = $pdo->prepare("SELECT id FROM did_batches WHERE inspecting_date = :idate LIMIT 1");
        $stmtCheck->execute([':idate' => $firstDate]);
        if ($stmtCheck->fetch()) {
            fclose($handle);
            $tgl = date('d M Y', strtotime($firstDate));
            set_flash('error', 'Tanggal ' . $tgl . ' sudah memiliki batch DID! Setiap tanggal hanya boleh 1 batch. Gunakan "Edit / Tambah Baris" pada batch yang sudah ada.');
            redirect('modules/did/create.php');
        }

        // Create Batch Header
        $stmtBatch = $pdo->prepare("INSERT INTO did_batches (batch_name, inspecting_date, import_method, total_items, created_at) VALUES (:bname, :idate, 'excel_import', 0, NOW())");
        $batchTitle = "Import Excel: " . $fileName;
        $stmtBatch->execute([':bname' => $batchTitle, ':idate' => $firstDate]);
        $batchId = $pdo->lastInsertId();

        $successCount = 0;
        $skipCount = 0;
        $okCount = 0;
        $ngCount = 0;
        $lineNum = 0;

        while (($data = fgetcsv($handle, 2000, ",")) !== false) {
            $lineNum++;
            if ($lineNum === 1 && (strtolower($data[0] ?? '') === 'part_code' || strtolower($data[0] ?? '') === 'part code')) {
                continue;
            }

            $partCode = strtoupper(trim(sanitize($data[0] ?? '')));
            $partName = trim(sanitize($data[1] ?? ''));
            $lotNumber = strtoupper(trim(sanitize($data[2] ?? '')));
            $cavity = trim(sanitize($data[3] ?? ''));
            $inspectingDate = sanitize($data[4] ?? date('Y-m-d'));
            $statusInspect = (strtoupper(trim(sanitize($data[5] ?? 'OK'))) === 'NG') ? 'NG' : 'OK';
            $pic = trim(sanitize($data[6] ?? ''));
            $remark = trim(sanitize($data[7] ?? ''));

            if (empty($partCode) || empty($lotNumber)) {
                continue;
            }

            // Check duplicate
            $stmtDup = $pdo->prepare("SELECT id FROM daily_inspection_data WHERE part_code = :pcode AND lot_number = :lot");
            $stmtDup->execute([':pcode' => $partCode, ':lot' => $lotNumber]);
            if ($stmtDup->fetch()) {
                $skipCount++;
                continue;
            }

            // Auto-create Master Part if not exists
            $stmtCheckMaster = $pdo->prepare("SELECT id FROM master_parts WHERE part_code = :pcode");
            $stmtCheckMaster->execute([':pcode' => $partCode]);
            if (!$stmtCheckMaster->fetch()) {
                $stmtInsertMaster = $pdo->prepare("INSERT INTO master_parts (part_code, part_name, source, created_at) VALUES (:pcode, :pname, 'auto_generated', NOW())");
                $stmtInsertMaster->execute([':pcode' => $partCode, ':pname' => $partName ?: $partCode]);
            }

            // Insert DID
            $stmtInsert = $pdo->prepare("INSERT INTO daily_inspection_data 
                (batch_id, part_code, part_name, lot_number, cavity, inspecting_date, status_inspect, pic, remark, created_at) 
                VALUES (:bid, :pcode, :pname, :lot, :cav, :idate, :status, :pic, :remark, NOW())");
            
            $stmtInsert->execute([
                ':bid'    => $batchId,
                ':pcode'  => $partCode,
                ':pname'  => $partName ?: $partCode,
                ':lot'    => $lotNumber,
                ':cav'    => $cavity ?: '1',
                ':idate'  => $inspectingDate ?: date('Y-m-d'),
                ':status' => $statusInspect,
                ':pic'    => $pic ?: 'Import QC',
                ':remark' => $remark
            ]);

            $successCount++;
            if ($statusInspect === 'OK') $okCount++; else $ngCount++;
        }

        fclose($handle);

        // Update Batch Stats
        $stmtUpdBatch = $pdo->prepare("UPDATE did_batches SET total_items = :total, ok_count = :ok, ng_count = :ng WHERE id = :bid");
        $stmtUpdBatch->execute([':total' => $successCount, ':ok' => $okCount, ':ng' => $ngCount, ':bid' => $batchId]);

        set_flash('success', 'Berhasil meng-import ' . $successCount . ' data DID baru ke Riwayat Batch. (' . $skipCount . ' data duplikat/invalid dilewati).');
    } catch (PDOException $e) {
        set_flash('error', 'Gagal memproses batch import: ' . $e->getMessage());
    }

} else if ($entryType === 'xlsx') {
    // -------------------------------------------------------
    // 3. MULTI-TAB EXCEL (.XLSX) IMPORT — 1 batch per tab/hari
    // -------------------------------------------------------
    if (!isset($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) {
        set_flash('error', 'Pilih file Excel (.xlsx) yang valid untuk diunggah!');
        redirect('modules/did/create.php');
    }

    $ext = strtolower(pathinfo($_FILES['xlsx_file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        set_flash('error', 'Hanya file .xlsx yang didukung untuk fitur ini.');
        redirect('modules/did/create.php');
    }

    try {
        $parsedData = xlsx_parse_did($_FILES['xlsx_file']['tmp_name']);
    } catch (Exception $e) {
        set_flash('error', 'Gagal membaca file Excel: ' . $e->getMessage());
        redirect('modules/did/create.php');
    }

    if (empty($parsedData)) {
        set_flash('warning', 'Tidak ada tab tanggal yang ditemukan di file Excel. Pastikan nama tab berformat DD-MM-YYYY dan ada data mulai baris 6.');
        redirect('modules/did/create.php');
    }

    $batchesCreated = 0;
    $batchesSkipped = 0;
    $totalItemsOk   = 0;
    $totalItemsNg   = 0;
    $summaryLines   = [];
    $skippedDates   = [];

    foreach ($parsedData as $inspectingDate => $rows) {
        // Cek apakah tanggal sudah punya batch
        try {
            $stmtChk = $pdo->prepare("SELECT id FROM did_batches WHERE inspecting_date = :d LIMIT 1");
            $stmtChk->execute([':d' => $inspectingDate]);
            if ($stmtChk->fetch()) {
                $batchesSkipped++;
                $skippedDates[] = date('d M Y', strtotime($inspectingDate));
                continue;
            }
        } catch (PDOException $e) { /* lanjut */ }

        // Buat batch baru untuk tanggal ini
        try {
            $batchTitle = 'Import Excel DID (' . date('d M Y', strtotime($inspectingDate)) . ')';
            $stmtBatch = $pdo->prepare("INSERT INTO did_batches (batch_name, inspecting_date, import_method, total_items, created_at) VALUES (:bname, :idate, 'excel_import', 0, NOW())");
            $stmtBatch->execute([':bname' => $batchTitle, ':idate' => $inspectingDate]);
            $batchId = $pdo->lastInsertId();

            $okCount  = 0;
            $ngCount  = 0;
            $inserted = 0;

            $stmtInsert = $pdo->prepare("INSERT INTO daily_inspection_data
                (batch_id, part_code, part_name, lot_number, cavity, inspecting_date, status_inspect, pic, remark, created_at)
                VALUES (:bid, :pcode, :pname, :lot, :cav, :idate, :status, :pic, :remark, NOW())");

            foreach ($rows as $r) {
                $partCode = $r['part_code'];
                $partName = $r['part_name'];
                if (empty($partCode)) continue;

                try {
                    // Auto-create master part if not exists
                    $stmtChkMaster = $pdo->prepare("SELECT id FROM master_parts WHERE part_code = :pc");
                    $stmtChkMaster->execute([':pc' => $partCode]);
                    if (!$stmtChkMaster->fetch()) {
                        $pdo->prepare("INSERT INTO master_parts (part_code, part_name, source, created_at) VALUES (:pc, :pn, 'auto_generated', NOW())")
                            ->execute([':pc' => $partCode, ':pn' => $partName]);
                    }

                    $stmtInsert->execute([
                        ':bid'    => $batchId,
                        ':pcode'  => $partCode,
                        ':pname'  => $partName,
                        ':lot'    => $r['lot_number'],
                        ':cav'    => $r['cavity'],
                        ':idate'  => $r['inspecting_date'],
                        ':status' => $r['status_inspect'],
                        ':pic'    => $r['pic'],
                        ':remark' => $r['remark'],
                    ]);

                    $inserted++;
                    if ($r['status_inspect'] === 'OK') $okCount++; else $ngCount++;
                } catch (PDOException $rowEx) {
                    // Skip single problematic row, don't abort the entire batch
                    continue;
                }
            }

            // Update batch stats
            $pdo->prepare("UPDATE did_batches SET total_items = :t, ok_count = :ok, ng_count = :ng WHERE id = :bid")
                ->execute([':t' => $inserted, ':ok' => $okCount, ':ng' => $ngCount, ':bid' => $batchId]);

            if ($inserted > 0) {
                $batchesCreated++;
                $totalItemsOk += $okCount;
                $totalItemsNg += $ngCount;
                $summaryLines[] = date('d M Y', strtotime($inspectingDate)) . ' → ' . $inserted . ' item (' . $okCount . ' OK' . ($ngCount > 0 ? ', ' . $ngCount . ' NG' : '') . ')';
            } else {
                // Delete empty batch header if no rows were inserted
                $pdo->exec("DELETE FROM did_batches WHERE id = {$batchId}");
            }

        } catch (PDOException $e) {
            // Batch creation failed
            if (!empty($batchId)) {
                try { $pdo->exec("DELETE FROM did_batches WHERE id = {$batchId} AND total_items = 0"); } catch (Exception $_) {}
            }
        }
    }

    // Flash message ringkasan
    if ($batchesCreated > 0) {
        $msg = '✅ Import Excel berhasil! ' . $batchesCreated . ' batch baru dibuat:' . "\n" . implode("\n", $summaryLines);
        if ($batchesSkipped > 0) {
            $msg .= "\n⚠ " . $batchesSkipped . ' tab dilewati (tanggal sudah ada batch): ' . implode(', ', $skippedDates);
        }
        set_flash('success', $msg);
    } else {
        $msg = 'Semua tab dilewati — setiap tanggal sudah punya batch DID.';
        if (!empty($skippedDates)) $msg .= ' (' . implode(', ', $skippedDates) . ')';
        set_flash('warning', $msg);
    }
}

redirect('modules/did/index.php');
