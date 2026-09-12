<?php
require_once __DIR__ . '/../config/database.php';
$pdo->exec("DELETE FROM inspection_session_lots WHERE inspection_session_id IN (9991, 9994, 9995)");
$pdo->exec("DELETE FROM inspection_sessions WHERE id IN (9991, 9994, 9995)");
$pdo->exec("DELETE FROM kanban_items WHERE id IN (9991, 9992, 9994)");
$pdo->exec("DELETE FROM daily_inspection_data WHERE id = 999");
echo "Cleaned test data.\n";
