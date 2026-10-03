<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();
$sCols = $pdo->query("DESCRIBE inspection_sessions")->fetchAll(PDO::FETCH_COLUMN);
echo "SESSIONS COLS:\n" . implode(", ", $sCols) . "\n\n";
$didCols = $pdo->query("DESCRIBE daily_inspection_data")->fetchAll(PDO::FETCH_COLUMN);
echo "DID COLS:\n" . implode(", ", $didCols) . "\n\n";
