<?php
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/database.php';

$pdo = getDB();
if (!$pdo) {
    echo "ERROR: Failed to connect to DB\n";
    exit(1);
}

echo "Running migrations...\n";

try {
    $cols = $pdo->query("SHOW COLUMNS FROM `kanban_batches` LIKE 'plan_type'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `kanban_batches` ADD COLUMN `plan_type` ENUM('kanban', 'safety_stock') NOT NULL DEFAULT 'kanban' AFTER `id`");
        echo "Added plan_type to kanban_batches\n";
    } else {
        echo "kanban_batches.plan_type already exists\n";
    }
} catch (Exception $e) {
    echo "Err batches: " . $e->getMessage() . "\n";
}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM `kanban_items` LIKE 'plan_type'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `kanban_items` ADD COLUMN `plan_type` ENUM('kanban', 'safety_stock') NOT NULL DEFAULT 'kanban' AFTER `batch_id`");
        $pdo->exec("ALTER TABLE `kanban_items` MODIFY COLUMN `kanban_no` VARCHAR(50) NULL");
        echo "Added plan_type to kanban_items\n";
    } else {
        echo "kanban_items.plan_type already exists\n";
    }
} catch (Exception $e) {
    echo "Err items: " . $e->getMessage() . "\n";
}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM `inspection_sessions` LIKE 'inspection_type'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `inspection_sessions` ADD COLUMN `inspection_type` ENUM('kanban', 'safety_stock') NOT NULL DEFAULT 'kanban' AFTER `id`");
        echo "Added inspection_type to inspection_sessions\n";
    } else {
        echo "inspection_sessions.inspection_type already exists\n";
    }

    $cols2 = $pdo->query("SHOW COLUMNS FROM `inspection_sessions` LIKE 'auto_fulfilled_by_session_id'")->fetchAll();
    if (empty($cols2)) {
        $pdo->exec("ALTER TABLE `inspection_sessions` ADD COLUMN `auto_fulfilled_by_session_id` BIGINT(20) UNSIGNED NULL AFTER `status`");
        echo "Added auto_fulfilled_by_session_id to inspection_sessions\n";
    } else {
        echo "inspection_sessions.auto_fulfilled_by_session_id already exists\n";
    }
} catch (Exception $e) {
    echo "Err sessions: " . $e->getMessage() . "\n";
}

echo "MIGRATION_COMPLETED_SUCCESSFULLY\n";
