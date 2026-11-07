<?php
/**
 * Action Handler: Update Master Part
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('master_parts');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/master_parts/index.php');
}

csrf_verify();

$id         = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$part_code  = strtoupper(sanitize($_POST['part_code'] ?? ''));
$part_name  = sanitize($_POST['part_name'] ?? '');
$model_id   = filter_input(INPUT_POST, 'model_id', FILTER_VALIDATE_INT) ?: null;
$model_name = strtoupper(trim(sanitize($_POST['model_name'] ?? '')));
$aql_level  = sanitize($_POST['aql_level'] ?? 'G-II');
if (!in_array($aql_level, ['G-I', 'G-II', 'G-III'])) {
    $aql_level = 'G-II';
}

if (!$id || empty($part_code) || empty($part_name)) {
    set_flash('error', 'Data input tidak valid!');
    redirect('modules/master_parts/index.php');
}

$pdo = getDB();

if ($pdo) {
    try {
        // Cek duplikat part_code dengan ID lain
        $checkStmt = $pdo->prepare("SELECT id FROM master_parts WHERE part_code = :part_code AND id != :id");
        $checkStmt->execute([':part_code' => $part_code, ':id' => $id]);
        if ($checkStmt->fetch()) {
            set_flash('error', "Part Code '{$part_code}' sudah digunakan oleh part lain!");
            redirect("modules/master_parts/edit.php?id={$id}");
        }

        // Resolusi Model: Jika model_name diinput (manual/baru), cari atau buat di master_models
        $resolvedModelName = null;
        if (!empty($model_name)) {
            $stmtChkModel = $pdo->prepare("SELECT id, name FROM master_models WHERE UPPER(name) = :name LIMIT 1");
            $stmtChkModel->execute([':name' => $model_name]);
            $existingModel = $stmtChkModel->fetch();

            if ($existingModel) {
                $model_id = (int)$existingModel['id'];
                $resolvedModelName = $existingModel['name'];
            } else {
                $stmtInsModel = $pdo->prepare("INSERT INTO master_models (name, created_at) VALUES (:name, NOW())");
                $stmtInsModel->execute([':name' => $model_name]);
                $model_id = (int)$pdo->lastInsertId();
                $resolvedModelName = $model_name;
            }
        } elseif ($model_id) {
            $stmtGetModel = $pdo->prepare("SELECT name FROM master_models WHERE id = :id LIMIT 1");
            $stmtGetModel->execute([':id' => $model_id]);
            $resolvedModelName = $stmtGetModel->fetchColumn() ?: null;
            if (!$resolvedModelName) {
                $model_id = null;
            }
        }

        $stmt = $pdo->prepare("UPDATE master_parts SET part_code = :part_code, part_name = :part_name, model_id = :model_id, model = :model, aql_level = :aql_level WHERE id = :id");
        $stmt->execute([
            ':part_code' => $part_code,
            ':part_name' => $part_name,
            ':model_id'  => $model_id,
            ':model'     => $resolvedModelName,
            ':aql_level' => $aql_level,
            ':id'        => $id
        ]);

        set_flash('success', "Master Part '{$part_code}' berhasil diperbarui!");
    } catch (PDOException $e) {
        set_flash('error', 'Gagal memperbarui ke database: ' . $e->getMessage());
    }
}

redirect('modules/master_parts/index.php');
