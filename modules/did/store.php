<?php
/**
 * Action Handler: Store Daily Inspection Data (DID)
 * Creates Batch Record in did_batches & Tags batch_id on each item
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/xlsx_parser.php';

require_menu_access('did');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/did/index.php');
}

if (!verify_csrf_token()) {
    set_flash('error', 'Sesi keamanan (CSRF Token) tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.');
    redirect('modules/did/create.php');
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
        $pdo->beginTransaction();

        $currentUserId = current_user()['id'];

        // Create Batch Header
        $stmtBatch = $pdo->prepare("INSERT INTO did_batches (batch_name, inspecting_date, import_method, total_items, created_by, created_at) VALUES (:bname, :idate, 'manual', 0, :cby, NOW())");
        $batchTitle = "Manual Entry DID (" . date('d M Y', strtotime($inspectingDate)) . ")";
        $stmtBatch->execute([':bname' => $batchTitle, ':idate' => $inspectingDate, ':cby' => $currentUserId]);
        $batchId = $pdo->lastInsertId();

        $successCount = 0;
        $skipCount = 0;
        $okCount = 0;
        $ngCount = 0;

        // Cache existing master parts into memory
        $cachedParts = [];
        $stmtAllParts = $pdo->query("SELECT UPPER(part_code) as pcode FROM master_parts");
        while ($pRow = $stmtAllParts->fetch(PDO::FETCH_ASSOC)) {
            $cachedParts[$pRow['pcode']] = true;
        }

        $stmtInsertMaster = $pdo->prepare("INSERT INTO master_parts (part_code, part_name, source, created_at) VALUES (:pcode, :pname, 'auto_generated', NOW())");
        $stmtInsert = $pdo->prepare("INSERT INTO daily_inspection_data 
            (batch_id, part_code, part_name, lot_number, cavity, inspecting_date, status_inspect, pic, remark, created_at) 
            VALUES (:bid, :pcode, :pname, :lot, :cav, :idate, :status, :pic, :remark, NOW())");

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

            // Auto-create Master Part if not exists (using in-memory cache)
            if (!isset($cachedParts[$partCode])) {
                $stmtInsertMaster->execute([':pcode' => $partCode, ':pname' => $partName]);
                $cachedParts[$partCode] = true;
            }

            // Insert into daily_inspection_data with batch_id
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
            $pdo->commit();
            set_flash('success', 'Selamat! Data DID berhasil disimpan.');
        } else {
            // Delete empty batch
            $pdo->rollBack();
            set_flash('warning', 'Gagal menyimpan data DID! Seluruh baris duplikat atau tidak valid.');
        }

    } catch (PDOException $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
        $currentUserId = current_user()['id'];
        $stmtBatch = $pdo->prepare("INSERT INTO did_batches (batch_name, inspecting_date, import_method, total_items, created_by, created_at) VALUES (:bname, :idate, 'excel_import', 0, :cby, NOW())");
        $batchTitle = "Import Excel: " . $fileName;
        $stmtBatch->execute([':bname' => $batchTitle, ':idate' => $firstDate, ':cby' => $currentUserId]);
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

        set_flash('success', 'Selamat! Import data CSV berhasil diproses.');
    } catch (PDOException $e) {
        set_flash('error', 'Gagal memproses batch import: ' . $e->getMessage());
    }

} else if ($entryType === 'xlsx') {
    // -------------------------------------------------------
    // 3. MULTI-TAB EXCEL (.XLSX) IMPORT — High-Speed Upsert Logic
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

    // Pre-cache all master parts from DB: [UPPER(part_code)] => official_part_name
    $cachedParts = [];
    $stmtAllParts = $pdo->query("SELECT UPPER(part_code) as pcode, part_name FROM master_parts");
    while ($pRow = $stmtAllParts->fetch(PDO::FETCH_ASSOC)) {
        $cachedParts[$pRow['pcode']] = $pRow['part_name'];
    }

    $stmtInsertMaster = $pdo->prepare("INSERT INTO master_parts (part_code, part_name, source, created_at) VALUES (:pc, :pn, 'auto_generated', NOW())");

    // Collect all dates from parsedData
    $allDates = array_keys($parsedData);
    if (empty($allDates)) {
        set_flash('warning', 'Tidak ada data valid di dalam file Excel.');
        redirect('modules/did/create.php');
    }

    // Pre-cache existing batches for these dates: [date] => batch_id
    $existingBatches = [];
    $inClause = implode(',', array_fill(0, count($allDates), '?'));
    $stmtBatches = $pdo->prepare("SELECT id, inspecting_date FROM did_batches WHERE inspecting_date IN ($inClause)");
    $stmtBatches->execute($allDates);
    while ($bRow = $stmtBatches->fetch(PDO::FETCH_ASSOC)) {
        $existingBatches[$bRow['inspecting_date']] = (int)$bRow['id'];
    }

    // Pre-cache existing daily_inspection_data records for these dates:
    // Key: "date|UPPER(pcode)|UPPER(lot)" => id
    $existingItems = [];
    $stmtItems = $pdo->prepare("SELECT id, inspecting_date, UPPER(part_code) as pcode, UPPER(lot_number) as lot FROM daily_inspection_data WHERE inspecting_date IN ($inClause)");
    $stmtItems->execute($allDates);
    while ($iRow = $stmtItems->fetch(PDO::FETCH_ASSOC)) {
        $key = $iRow['inspecting_date'] . '|' . $iRow['pcode'] . '|' . $iRow['lot'];
        $existingItems[$key] = (int)$iRow['id'];
    }

    // Prepared statements for INSERT and UPDATE daily_inspection_data
    $stmtInsertDID = $pdo->prepare("INSERT INTO daily_inspection_data
        (batch_id, part_code, part_name, lot_number, cavity, inspecting_date, status_inspect, pic, remark, created_at)
        VALUES (:bid, :pcode, :pname, :lot, :cav, :idate, :status, :pic, :remark, NOW())");

    $stmtUpdateDID = $pdo->prepare("UPDATE daily_inspection_data SET
        batch_id = :bid, part_name = :pname, cavity = :cav, status_inspect = :status, pic = :pic, remark = :remark, updated_at = NOW()
        WHERE id = :id");

    $stmtInsertBatch = $pdo->prepare("INSERT INTO did_batches (batch_name, inspecting_date, import_method, total_items, created_by, created_at) VALUES (:bname, :idate, 'excel_import', 0, :cby, NOW())");

    $stmtRecalcBatch = $pdo->prepare("UPDATE did_batches SET 
        total_items = (SELECT COUNT(*) FROM daily_inspection_data WHERE batch_id = :bid1),
        ok_count = (SELECT COUNT(*) FROM daily_inspection_data WHERE batch_id = :bid2 AND status_inspect = 'OK'),
        ng_count = (SELECT COUNT(*) FROM daily_inspection_data WHERE batch_id = :bid3 AND status_inspect = 'NG')
        WHERE id = :bid4");

    $currentUserId = current_user()['id'];
    $totalInserted = 0;
    $totalUpdated  = 0;
    $summaryLines  = [];
    $affectedBatches = [];

    $pdo->beginTransaction();

    try {
        foreach ($parsedData as $inspectingDate => $rows) {
            // Get or Create Batch ID
            if (isset($existingBatches[$inspectingDate])) {
                $batchId = $existingBatches[$inspectingDate];
            } else {
                $batchTitle = 'Import Excel DID (' . date('d M Y', strtotime($inspectingDate)) . ')';
                $stmtInsertBatch->execute([':bname' => $batchTitle, ':idate' => $inspectingDate, ':cby' => $currentUserId]);
                $batchId = (int)$pdo->lastInsertId();
                $existingBatches[$inspectingDate] = $batchId;
            }
            $affectedBatches[$batchId] = true;

            $sheetInserted = 0;
            $sheetUpdated  = 0;

            foreach ($rows as $r) {
                $partCode = strtoupper(trim($r['part_code']));
                $lotNumber = strtoupper(trim($r['lot_number']));
                if (empty($partCode)) continue;

                // Sync Part Name with Master Part
                if (isset($cachedParts[$partCode])) {
                    $officialPartName = $cachedParts[$partCode];
                } else {
                    // Part code not in master, auto-register it using excel's part name
                    $officialPartName = trim($r['part_name']) ?: $partCode;
                    $stmtInsertMaster->execute([':pc' => $partCode, ':pn' => $officialPartName]);
                    $cachedParts[$partCode] = $officialPartName;
                }

                $key = $inspectingDate . '|' . $partCode . '|' . $lotNumber;

                if (isset($existingItems[$key])) {
                    // UPDATE existing item
                    $didId = $existingItems[$key];
                    $stmtUpdateDID->execute([
                        ':bid'    => $batchId,
                        ':pname'  => $officialPartName,
                        ':cav'    => $r['cavity'] ?: '1',
                        ':status' => $r['status_inspect'],
                        ':pic'    => $r['pic'] ?: 'Import QC',
                        ':remark' => $r['remark'],
                        ':id'     => $didId
                    ]);
                    $sheetUpdated++;
                } else {
                    // INSERT new item
                    $stmtInsertDID->execute([
                        ':bid'    => $batchId,
                        ':pcode'  => $partCode,
                        ':pname'  => $officialPartName,
                        ':lot'    => $lotNumber,
                        ':cav'    => $r['cavity'] ?: '1',
                        ':idate'  => $inspectingDate,
                        ':status' => $r['status_inspect'],
                        ':pic'    => $r['pic'] ?: 'Import QC',
                        ':remark' => $r['remark']
                    ]);
                    $newId = (int)$pdo->lastInsertId();
                    $existingItems[$key] = $newId;
                    $sheetInserted++;
                }
            }

            $totalInserted += $sheetInserted;
            $totalUpdated  += $sheetUpdated;

            $summaryLines[] = date('d M Y', strtotime($inspectingDate)) . ' → ' . ($sheetInserted + $sheetUpdated) . ' item (' . $sheetInserted . ' baru, ' . $sheetUpdated . ' di-update)';
        }

        // Recalculate summary stats for all affected batches
        foreach (array_keys($affectedBatches) as $bId) {
            $stmtRecalcBatch->execute([':bid1' => $bId, ':bid2' => $bId, ':bid3' => $bId, ':bid4' => $bId]);
        }

        $pdo->commit();

        set_flash('success', 'Selamat! Import data Excel berhasil diproses.');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Gagal memproses import Excel: ' . $e->getMessage());
    }
}

redirect('modules/did/index.php');

