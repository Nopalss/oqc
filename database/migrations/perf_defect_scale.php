<?php
/**
 * Migration: High-Scale Performance Optimization for OQC Inspection & Defect Analysis
 * 
 * 1. Denormalize inspection_session_id & part_id to inspection_ng_records
 * 2. Add model_id to oqc_daily_defect_summary
 * 3. Add composite covering indexes
 * 4. Backfill existing records
 */
require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();
if (!$pdo) {
    die("Database connection failed\n");
}

echo "=== 1. CHECK & ALTER TABLE inspection_ng_records ===\n";
$colsNgr = $pdo->query("SHOW COLUMNS FROM inspection_ng_records")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('inspection_session_id', $colsNgr)) {
    echo "Adding column 'inspection_session_id' to inspection_ng_records...\n";
    $pdo->exec("ALTER TABLE inspection_ng_records ADD COLUMN inspection_session_id BIGINT UNSIGNED NULL AFTER inspection_sample_id");
    echo "✓ Column inspection_session_id added.\n";
} else {
    echo "- Column inspection_session_id already exists.\n";
}

if (!in_array('part_id', $colsNgr)) {
    echo "Adding column 'part_id' to inspection_ng_records...\n";
    $pdo->exec("ALTER TABLE inspection_ng_records ADD COLUMN part_id BIGINT UNSIGNED NULL AFTER session_lot_id");
    echo "✓ Column part_id added.\n";
} else {
    echo "- Column part_id already exists.\n";
}

echo "\n=== 2. CHECK & ALTER TABLE oqc_daily_defect_summary ===\n";
$colsDds = $pdo->query("SHOW COLUMNS FROM oqc_daily_defect_summary")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('model_id', $colsDds)) {
    echo "Adding column 'model_id' to oqc_daily_defect_summary...\n";
    $pdo->exec("ALTER TABLE oqc_daily_defect_summary ADD COLUMN model_id BIGINT UNSIGNED NULL DEFAULT 0 AFTER part_id");
    echo "✓ Column model_id added.\n";
} else {
    echo "- Column model_id already exists.\n";
}

echo "\n=== 3. BACKFILL DATA FOR EXISTING RECORDS ===\n";
// Backfill inspection_ng_records
$stCheckNgr = $pdo->query("SELECT COUNT(*) FROM inspection_ng_records WHERE inspection_session_id IS NULL OR part_id IS NULL");
$needBackfillNgr = (int)$stCheckNgr->fetchColumn();
echo "Records in inspection_ng_records needing backfill: $needBackfillNgr\n";

if ($needBackfillNgr > 0) {
    $affected = $pdo->exec("
        UPDATE inspection_ng_records ngr
        INNER JOIN inspection_samples sp ON ngr.inspection_sample_id = sp.id
        INNER JOIN inspection_sessions s ON sp.inspection_session_id = s.id
        SET ngr.inspection_session_id = s.id,
            ngr.part_id = s.part_id
        WHERE ngr.inspection_session_id IS NULL OR ngr.part_id IS NULL
    ");
    echo "✓ Backfilled $affected records in inspection_ng_records.\n";
}

// Backfill oqc_daily_defect_summary model_id
$stCheckDds = $pdo->query("SELECT COUNT(*) FROM oqc_daily_defect_summary WHERE model_id IS NULL OR model_id = 0");
$needBackfillDds = (int)$stCheckDds->fetchColumn();
echo "Records in oqc_daily_defect_summary needing backfill: $needBackfillDds\n";

if ($needBackfillDds > 0) {
    $affectedDds = $pdo->exec("
        UPDATE oqc_daily_defect_summary dds
        INNER JOIN master_parts mp ON dds.part_id = mp.id
        SET dds.model_id = COALESCE(mp.model_id, 0)
        WHERE dds.model_id IS NULL OR dds.model_id = 0
    ");
    echo "✓ Backfilled $affectedDds records in oqc_daily_defect_summary.\n";
}

echo "\n=== 4. CREATE COMPOSITE COVERING INDEXES ===\n";
$targetIndexes = [
    [
        'table'   => 'inspection_ng_records',
        'name'    => 'idx_ngr_session_part',
        'columns' => '(`inspection_session_id`, `part_id`, `is_cancelled`)'
    ],
    [
        'table'   => 'inspection_ng_records',
        'name'    => 'idx_ngr_perf_query',
        'columns' => '(`part_id`, `defect_type_id`, `is_cancelled`, `created_at`)'
    ],
    [
        'table'   => 'oqc_daily_defect_summary',
        'name'    => 'idx_dds_perf',
        'columns' => '(`summary_date`, `defect_type_id`, `model_id`, `part_id`)'
    ]
];

foreach ($targetIndexes as $ti) {
    $tbl = $ti['table'];
    $name = $ti['name'];
    $cols = $ti['columns'];

    $stIdx = $pdo->query("SHOW INDEX FROM `$tbl` WHERE Key_name = '$name'");
    if ($stIdx->fetch()) {
        echo "- Index '$name' on '$tbl' already exists.\n";
    } else {
        echo "Creating index '$name' on '$tbl'...\n";
        $pdo->exec("ALTER TABLE `$tbl` ADD INDEX `$name` $cols");
        echo "✓ Index '$name' created on '$tbl'.\n";
    }
}

echo "\n=== 5. VERIFY DATABASE STATE ===\n";
$cntNullNgr = $pdo->query("SELECT COUNT(*) FROM inspection_ng_records WHERE inspection_session_id IS NULL OR part_id IS NULL")->fetchColumn();
echo "inspection_ng_records remaining un-backfilled: $cntNullNgr (Target: 0)\n";

$cntNullDds = $pdo->query("SELECT COUNT(*) FROM oqc_daily_defect_summary WHERE model_id IS NULL")->fetchColumn();
echo "oqc_daily_defect_summary remaining NULL model_id: $cntNullDds (Target: 0)\n";

echo "\n✅ MIGRATION COMPLETED SUCCESSFULLY!\n";
