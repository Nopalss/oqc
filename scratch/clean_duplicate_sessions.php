<?php
require_once __DIR__ . '/../config/database.php';

// Remove 0-qty duplicate in_progress sessions where a passed session exists for the same kanban_item_id
$pdo->exec("
    DELETE s1 FROM inspection_sessions s1
    JOIN inspection_sessions s2 ON s1.kanban_item_id = s2.kanban_item_id AND s1.id != s2.id
    WHERE s1.total_scanned_qty = 0 AND s2.status = 'passed'
");

echo "Cleaned duplicate 0-qty sessions from database.\n";
