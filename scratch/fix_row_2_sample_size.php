<?php
require_once __DIR__ . '/../config/database.php';
$pdo->exec("UPDATE inspection_sessions SET sample_size = 50 WHERE id = 2");
echo "Updated session ID 2 sample_size to 50.\n";
