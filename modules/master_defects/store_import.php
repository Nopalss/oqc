<?php
/**
 * Action Handler: Execute Store/Import Master Defects from Confirmed Review
 */
@set_time_limit(180);
@ini_set('memory_limit', '256M');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/master_defects/index.php');
}

$rawJson = $_POST['import_data_json'] ?? '';
if (empty($rawJson)) {
    set_flash('error', 'Tidak ada data valid yang dikirim untuk disimpan!');
    redirect('modules/master_defects/import.php');
}

$rows = json_decode($rawJson, true);
if (!is_array($rows) || empty($rows)) {
    set_flash('error', 'Format data import tidak valid atau kosong!');
    redirect('modules/master_defects/import.php');
}

$pdo = getDB();
if (!$pdo) {
    set_flash('error', 'Koneksi database gagal!');
    redirect('modules/master_defects/import.php');
}

try {
    $pdo->beginTransaction();

    // Prepare Statements
    $stmtCheckCode = $pdo->prepare("SELECT id FROM defect_types WHERE UPPER(code) = UPPER(:code) LIMIT 1");
    $stmtCheckName = $pdo->prepare("SELECT id FROM defect_types WHERE UPPER(name) = UPPER(:name) LIMIT 1");

    $stmtInsert = $pdo->prepare("
        INSERT INTO defect_types (code, name, created_at)
        VALUES (:code, :name, NOW())
    ");

    $successCount = 0;
    $skipCount    = 0;

    foreach ($rows as $item) {
        $code = trim((string)($item['code'] ?? ''));
        $name = trim((string)($item['defect_name'] ?? ''));

        if ($name === '') {
            $skipCount++;
            continue;
        }

        // Verify defect name duplicate right before insert
        $stmtCheckName->execute([':name' => $name]);
        if ($stmtCheckName->fetch()) {
            $skipCount++;
            continue;
        }

        $stmtInsert->execute([
            ':code' => ($code !== '' ? $code : null),
            ':name' => $name,
        ]);
        $successCount++;
    }

    $pdo->commit();

    if ($successCount > 0) {
        $msg = $successCount . ' data jenis defect berhasil diimport ke sistem!';
        if ($skipCount > 0) {
            $msg .= ' (' . $skipCount . ' data duplikat dilewati)';
        }
        set_flash('success', $msg);
    } else {
        set_flash('warning', 'Tidak ada data baru yang disimpan. Semua data di file sudah terdaftar di sistem.');
    }

    redirect('modules/master_defects/index.php');

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', 'Gagal menyimpan data import: ' . $e->getMessage());
    redirect('modules/master_defects/import.php');
}
