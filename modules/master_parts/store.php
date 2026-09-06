<?php
/**
 * Action Handler: Store New Master Part
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/master_parts/index.php');
}

$part_code = strtoupper(sanitize($_POST['part_code'] ?? ''));
$part_name = sanitize($_POST['part_name'] ?? '');
$model_id  = filter_input(INPUT_POST, 'model_id', FILTER_VALIDATE_INT) ?: null;
$aql_level = sanitize($_POST['aql_level'] ?? 'G-II');
if (!in_array($aql_level, ['G-I', 'G-II', 'G-III'])) {
    $aql_level = 'G-II';
}

if (empty($part_code) || empty($part_name)) {
    set_flash('error', 'Part Code dan Part Name wajib diisi!');
    redirect('modules/master_parts/create.php');
}

$pdo = getDB();

if ($pdo) {
    try {
        // Cek duplikat part_code
        $checkStmt = $pdo->prepare("SELECT id FROM master_parts WHERE part_code = :part_code");
        $checkStmt->execute([':part_code' => $part_code]);
        if ($checkStmt->fetch()) {
            set_flash('error', "Part Code '{$part_code}' sudah terdaftar!");
            redirect('modules/master_parts/create.php');
        }

        $stmt = $pdo->prepare("INSERT INTO master_parts (part_code, part_name, model_id, aql_level, source, created_by, created_at) VALUES (:part_code, :part_name, :model_id, :aql_level, 'manual', 1, NOW())");
        $stmt->execute([
            ':part_code' => $part_code,
            ':part_name' => $part_name,
            ':model_id'  => $model_id,
            ':aql_level' => $aql_level
        ]);

        set_flash('success', "Master Part '{$part_code}' berhasil ditambahkan!");
    } catch (PDOException $e) {
        set_flash('error', 'Gagal menyimpan ke database: ' . $e->getMessage());
    }
} else {
    set_flash('error', 'Database tidak terhubung!');
}

redirect('modules/master_parts/index.php');
