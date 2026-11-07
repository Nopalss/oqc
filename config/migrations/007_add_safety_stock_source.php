<?php
/**
 * Migration 007: Standardize Safety Stock Source Classification (`ss_source`)
 * Non-destructive migration to add `ss_source` column, backfill historical data,
 * and create B-Tree performance lookup index.
 */
require_once __DIR__ . '/../../config/database.php';

$pdo = getDB();
if (!$pdo) {
    echo "ERROR: Database connection failed.\n";
    exit(1);
}

echo "=== MIGRATION 007: STANDARDIZE SAFETY STOCK SOURCE (ss_source) ===\n\n";

function columnExists($pdo, $table, $column) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = :tbl 
          AND COLUMN_NAME = :col
    ");
    $stmt->execute([':tbl' => $table, ':col' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function indexExists($pdo, $table, $indexName) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.STATISTICS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = :tbl 
          AND INDEX_NAME = :idx
    ");
    $stmt->execute([':tbl' => $table, ':idx' => $indexName]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    // 1. Add column `ss_source`
    if (!columnExists($pdo, 'inspection_sessions', 'ss_source')) {
        echo "1. Adding column `ss_source` to `inspection_sessions`...\n";
        $pdo->exec("
            ALTER TABLE `inspection_sessions` 
            ADD COLUMN `ss_source` ENUM('direct', 'kanban_excess', 'split_leftover') 
            NOT NULL DEFAULT 'direct' 
            AFTER `inspection_type`
        ");
        echo "   ✓ Column `ss_source` added successfully.\n";
    } else {
        echo "1. ✓ Column `ss_source` already exists.\n";
    }

    // 2. Backfill: Sisa Scan Kanban (Excess)
    echo "2. Backfilling historical Kanban Excess sessions...\n";
    $updExcess = $pdo->exec("
        UPDATE `inspection_sessions` ss
        LEFT JOIN `kanban_items` ki ON ki.id = ss.kanban_item_id
        SET ss.`ss_source` = 'kanban_excess'
        WHERE ss.`inspection_type` = 'safety_stock'
          AND (
              ki.`remark` LIKE '%Auto Safety Stock%' 
              OR ki.`remark` LIKE '%AUTO-SS-SESS-%'
              OR ss.`id` IN (
                  SELECT isl.inspection_session_id 
                  FROM `inspection_session_lots` isl 
                  WHERE isl.remarks LIKE '%Sisa Kelebihan Kanban%'
              )
          )
    ");
    echo "   ✓ Updated " . ($updExcess !== false ? $updExcess : 0) . " sessions to 'kanban_excess'.\n";

    // 3. Backfill: Sisa Split Box
    echo "3. Backfilling historical Split Leftover sessions...\n";
    $updSplit = $pdo->exec("
        UPDATE `inspection_sessions` ss
        LEFT JOIN `kanban_items` ki ON ki.id = ss.kanban_item_id
        SET ss.`ss_source` = 'split_leftover'
        WHERE ss.`inspection_type` = 'safety_stock'
          AND (
              ki.`str_loc` = 'WH-SS-SPLIT' 
              OR ki.`remark` LIKE '%Split%'
              OR ss.`id` IN (
                  SELECT isl.inspection_session_id 
                  FROM `inspection_session_lots` isl 
                  WHERE isl.remarks LIKE '%Sisa Split Safety Stock%'
              )
          )
    ");
    echo "   ✓ Updated " . ($updSplit !== false ? $updSplit : 0) . " sessions to 'split_leftover'.\n";

    // 4. Create Index for fast lookups
    echo "4. Creating performance index `idx_ss_source_lookup`...\n";
    if (!indexExists($pdo, 'inspection_sessions', 'idx_ss_source_lookup')) {
        $pdo->exec("
            CREATE INDEX `idx_ss_source_lookup` 
            ON `inspection_sessions` (`inspection_type`, `ss_source`, `status`)
        ");
        echo "   ✓ Index `idx_ss_source_lookup` created successfully.\n";
    } else {
        echo "   ✓ Index `idx_ss_source_lookup` already exists.\n";
    }

    // 5. Audit & Validation Report
    echo "\n=== AUDIT DATA SAFETY STOCK PASCA-MIGRASI ===\n";
    $stmtAudit = $pdo->query("
        SELECT 
            ss_source,
            status,
            COUNT(*) as total_sessions,
            SUM(total_scanned_qty) as total_pcs
        FROM inspection_sessions
        WHERE inspection_type = 'safety_stock'
        GROUP BY ss_source, status
        ORDER BY ss_source, status
    ");
    $auditRows = $stmtAudit->fetchAll(PDO::FETCH_ASSOC);

    printf("%-18s | %-12s | %-15s | %-15s\n", "SS_SOURCE", "STATUS", "SESI", "TOTAL PCS");
    echo str_repeat("-", 68) . "\n";
    foreach ($auditRows as $r) {
        printf("%-18s | %-12s | %-15s | %-15s\n", 
            $r['ss_source'], 
            $r['status'], 
            number_format($r['total_sessions']) . " sesi", 
            number_format($r['total_pcs']) . " pcs"
        );
    }
    echo str_repeat("-", 68) . "\n";

    echo "\n✓ MIGRATION 007 COMPLETED 100% SUCCESSFULLY!\n";

} catch (PDOException $e) {
    echo "\n❌ ERROR during migration: " . $e->getMessage() . "\n";
    exit(1);
}

