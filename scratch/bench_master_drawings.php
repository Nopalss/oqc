<?php
require 'config/database.php';
require 'config/helper.php';
require 'config/document_helper.php';

$pdo = getDB();
$count = $pdo->query('SELECT COUNT(*) FROM master_parts')->fetchColumn();
echo Total master_parts di DB: $count\n;

$t0 = microtime(true);
$rootAppDir = realpath('.');
$baseDrawingsDir = $rootAppDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'drawings';
$dirs = glob($baseDrawingsDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
$countDirs = count($dirs);

$pDirsCount = 0;
foreach ($dirs as $mDir) {
    $pDirs = glob($mDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
    $pDirsCount += count($pDirs);
}
$t1 = microtime(true);
echo 1. Scanning seluruh folder disk ( . round($t1 - $t0, 3) . s) - Model: $countDirs, Folder Part: $pDirsCount\n;

$sql = SELECT p.id as part_id, p.part_code, p.part_name, 
 COALESCE(m.name, p.model, '') as model_name,
 d.id as drawing_id, d.drawing_2d_path, d.drawing_3d_path
 FROM master_parts p
 LEFT JOIN master_models m ON m.id = p.model_id
 LEFT JOIN master_drawings d ON p.id = d.part_id;
$rawParts = $pdo->query($sql)->fetchAll();

$t2 = microtime(true);
$fallbackCount = 0;
foreach ($rawParts as $row) {
    $path2D = !empty($row['drawing_2d_path']) && file_exists($rootAppDir . DIRECTORY_SEPARATOR . $row['drawing_2d_path']) ? $row['drawing_2d_path'] : null;
    $path3D = !empty($row['drawing_3d_path']) && file_exists($rootAppDir . DIRECTORY_SEPARATOR . $row['drawing_3d_path']) ? $row['drawing_3d_path'] : null;
    if (!$path2D || !$path3D) {
        $fallbackCount++;
        $fs = get_part_drawing_assets($row['part_code'], $row['model_name']);
    }
}
$t3 = microtime(true);
echo 2. Loop fallback get_part_drawing_assets ($fallbackCount part):  . round($t3 - $t2, 3) . s\n;

$t4 = microtime(true);
echo Total benchmark time:  . round($t3 - $t0, 3) . s\n;
