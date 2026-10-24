<?php
$filePath = 'd:/laragon/www/oqc/modules/inspection/index.php';
$lines = file($filePath);

// Line 1280 (index 1279): FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0) - COALESCE(s2.excess_qty, 0)
// Line 1282 (index 1281): (s2.total_scanned_qty - COALESCE(s2.excess_qty, 0))

echo "Line 1280 was: " . $lines[1279];
echo "Line 1282 was: " . $lines[1281];

$lines[1279] = str_replace(' - COALESCE(s2.excess_qty, 0)', '', $lines[1279]);
$lines[1281] = str_replace('(s2.total_scanned_qty - COALESCE(s2.excess_qty, 0))', 's2.total_scanned_qty', $lines[1281]);

file_put_contents($filePath, implode('', $lines));
echo "Successfully patched lines 1280 and 1282!\n";
