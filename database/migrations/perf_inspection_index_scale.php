<?php
require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

echo "Running Performance Migration for Inspection Index...\n";

// 1. Index on inspection_ng_records (session_lot_id, is_cancelled)
try {
    $exists = $pdo->query("SHOW INDEX FROM inspection_ng_records WHERE Key_name = 'idx_ngr_lot_cancelled'")->fetch();
    if (!$exists) {
        $pdo->exec("CREATE INDEX idx_ngr_lot_cancelled ON inspection_ng_records (session_lot_id, is_cancelled)");
        echo "ADDED: idx_ngr_lot_cancelled on inspection_ng_records (session_lot_id, is_cancelled)\n";
    } else {
        echo "EXISTS: idx_ngr_lot_cancelled already exists\n";
    }
} catch (Exception $e) {
    echo "ERROR 1: " . $e->getMessage() . "\n";
}

// 2. Index on inspection_session_lots (inspection_session_id, sample_size, lot_result)
try {
    $exists = $pdo->query("SHOW INDEX FROM inspection_session_lots WHERE Key_name = 'idx_isl_sess_sample_res'")->fetch();
    if (!$exists) {
        $pdo->exec("CREATE INDEX idx_isl_sess_sample_res ON inspection_session_lots (inspection_session_id, sample_size, lot_result)");
        echo "ADDED: idx_isl_sess_sample_res on inspection_session_lots (inspection_session_id, sample_size, lot_result)\n";
    } else {
        echo "EXISTS: idx_isl_sess_sample_res already exists\n";
    }
} catch (Exception $e) {
    echo "ERROR 2: " . $e->getMessage() . "\n";
}

// 3. Index on inspection_session_lots (created_at) for period range filtering
try {
    $exists = $pdo->query("SHOW INDEX FROM inspection_session_lots WHERE Key_name = 'idx_isl_created_at'")->fetch();
    if (!$exists) {
        $pdo->exec("CREATE INDEX idx_isl_created_at ON inspection_session_lots (created_at)");
        echo "ADDED: idx_isl_created_at on inspection_session_lots (created_at)\n";
    } else {
        echo "EXISTS: idx_isl_created_at already exists\n";
    }
} catch (Exception $e) {
    echo "ERROR 3: " . $e->getMessage() . "\n";
}

echo "Migration finished successfully!\n";
