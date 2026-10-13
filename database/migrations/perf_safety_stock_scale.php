<?php
/**
 * Migration: High-Scale Performance Optimization for Safety Stock Module
 * 
 * 1. Add composite index on inspection_sessions (inspection_type, auto_fulfilled_by_session_id, did_id)
 * 2. Add composite index on inspection_sessions (inspection_type, part_id, auto_fulfilled_by_session_id)
 * 3. Add composite index on inspection_session_lots (inspection_session_id, ref_number, lot_number)
 * 4. Add composite index on daily_inspection_data (part_code, id)
 */
require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();
if (!$pdo) {
    die("Database connection failed\n");
}

echo "=== HIGH-SCALE INDEXING FOR SAFETY STOCK ===\n";

$targetIndexes = [
    [
        'table'   => 'inspection_sessions',
        'name'    => 'idx_ss_perf_lookup',
        'columns' => '(`inspection_type`, `auto_fulfilled_by_session_id`, `did_id`)'
    ],
    [
        'table'   => 'inspection_sessions',
        'name'    => 'idx_ss_perf_part',
        'columns' => '(`inspection_type`, `part_id`, `auto_fulfilled_by_session_id`)'
    ],
    [
        'table'   => 'inspection_session_lots',
        'name'    => 'idx_isl_perf_ref_lot',
        'columns' => '(`inspection_session_id`, `ref_number`, `lot_number`)'
    ],
    [
        'table'   => 'daily_inspection_data',
        'name'    => 'idx_did_part_code_id',
        'columns' => '(`part_code`, `id`)'
    ]
];

foreach ($targetIndexes as $ti) {
    $tbl = $ti['table'];
    $name = $ti['name'];
    $cols = $ti['columns'];

    $exists = $pdo->query("SHOW INDEX FROM `$tbl` WHERE `Key_name` = '$name'")->fetchAll();
    if (empty($exists)) {
        echo "Creating index `$name` on `$tbl` $cols...\n";
        $pdo->exec("ALTER TABLE `$tbl` ADD INDEX `$name` $cols");
        echo "Index `$name` created successfully.\n";
    } else {
        echo "Index `$name` already exists on `$tbl`.\n";
    }
}

echo "\nSafety stock high-scale indexing migration completed!\n";
