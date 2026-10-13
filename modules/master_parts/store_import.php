<?php
/**
 * Action Handler: Execute Store/Import Master Parts from Confirmed Review
 * Protected with RBAC, CSRF token, and optimized batch queries
 */
@set_time_limit(180);
@ini_set('memory_limit', '256M');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('master_parts');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/master_parts/index.php');
}

csrf_verify();

$rawJson = $_POST['import_data_json'] ?? '';
if (empty($rawJson)) {
    set_flash('error', 'Tidak ada data valid yang dikirim untuk disimpan!');
    redirect('modules/master_parts/import.php');
}

$rows = json_decode($rawJson, true);
if (!is_array($rows) || empty($rows)) {
    set_flash('error', 'Format data import tidak valid atau kosong!');
    redirect('modules/master_parts/import.php');
}

$pdo = getDB();
if (!$pdo) {
    set_flash('error', 'Koneksi database gagal!');
    redirect('modules/master_parts/import.php');
}

// Current User ID
$currentUser = function_exists('current_user') ? current_user() : null;
$createdById = !empty($currentUser['id']) ? (int)$currentUser['id'] : 1;

try {
    // 1. Pre-fetch existing part codes to eliminate N+1 queries during the transaction
    $allCodes = [];
    foreach ($rows as $item) {
        $c = strtoupper(trim((string)($item['part_code'] ?? '')));
        if ($c !== '') {
            $allCodes[] = $c;
        }
    }
    $allCodes = array_values(array_unique($allCodes));

    $existingDbParts = [];
    if (!empty($allCodes)) {
        foreach (array_chunk($allCodes, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $chkStmt = $pdo->prepare("SELECT part_code FROM master_parts WHERE part_code IN ($placeholders)");
            $chkStmt->execute($chunk);
            while ($col = $chkStmt->fetchColumn()) {
                $existingDbParts[strtoupper(trim($col))] = true;
            }
        }
    }

    $pdo->beginTransaction();

    // 2. Prepare Model Resolver Cache
    $modelCache = [];
    $stmtAllModels = $pdo->query("SELECT id, name FROM master_models");
    while ($m = $stmtAllModels->fetch(PDO::FETCH_ASSOC)) {
        $modelCache[strtoupper(trim($m['name']))] = (int)$m['id'];
    }

    $stmtInsertModel = $pdo->prepare("INSERT INTO master_models (name, created_at, updated_at) VALUES (:name, NOW(), NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name)");
    $stmtGetModelId  = $pdo->prepare("SELECT id FROM master_models WHERE name = :name LIMIT 1");

    // 3. Prepare Part Insert Statement
    $stmtInsertPart = $pdo->prepare("
        INSERT INTO master_parts (part_code, part_name, model_id, aql_level, source, created_by, created_at)
        VALUES (:part_code, :part_name, :model_id, :aql_level, 'manual', :created_by, NOW())
    ");

    $successCount   = 0;
    $skipCount      = 0;
    $newModelsCount = 0;

    foreach ($rows as $item) {
        $partCode = strtoupper(trim((string)($item['part_code'] ?? '')));
        $partName = trim((string)($item['part_name'] ?? ''));
        $model    = trim((string)($item['model'] ?? ''));
        $aqlLevel = trim((string)($item['aql_level'] ?? 'G-II'));

        if (!in_array($aqlLevel, ['G-I', 'G-II', 'G-III'])) {
            $aqlLevel = 'G-II'; // Default G-II requested by user
        }

        // Validate essentials
        if ($partCode === '' || $partName === '') {
            $skipCount++;
            continue;
        }

        // Check duplicate from pre-fetched array (Ultra-fast in-memory check)
        if (isset($existingDbParts[$partCode])) {
            $skipCount++;
            continue;
        }

        // Resolve Model ID (Insert if new)
        $modelId = null;
        if ($model !== '') {
            $upperModel = strtoupper($model);
            if (isset($modelCache[$upperModel])) {
                $modelId = $modelCache[$upperModel];
            } else {
                // Insert into master_models
                $stmtInsertModel->execute([':name' => $model]);
                $stmtGetModelId->execute([':name' => $model]);
                $freshModelId = $stmtGetModelId->fetchColumn();

                if ($freshModelId) {
                    $modelId = (int)$freshModelId;
                    $modelCache[$upperModel] = $modelId;
                    $newModelsCount++;
                }
            }
        }

        // Insert Master Part
        $stmtInsertPart->execute([
            ':part_code'  => $partCode,
            ':part_name'  => $partName,
            ':model_id'   => $modelId,
            ':aql_level'  => $aqlLevel,
            ':created_by' => $createdById
        ]);

        // Mark as inserted to prevent intra-file duplicates
        $existingDbParts[$partCode] = true;
        $successCount++;
    }

    $pdo->commit();

    // Success Flash Message
    $msg = "Berhasil meng-import <strong>{$successCount}</strong> Master Part baru!";
    if ($newModelsCount > 0) {
        $msg .= " (Termasuk mendaftarkan <strong>{$newModelsCount}</strong> Model Produk baru).";
    }
    if ($skipCount > 0) {
        $msg .= " Sebanyak <strong>{$skipCount}</strong> data duplikat/invalid dilewati.";
    }

    set_flash('success', $msg);
    redirect('modules/master_parts/index.php');

} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', 'Gagal memproses import data ke database: ' . $e->getMessage());
    redirect('modules/master_parts/import.php');
}
