<?php
$filePath = 'd:/laragon/www/oqc/modules/inspection/index.php';
$content = file_get_contents($filePath);

// 1. Ganti subquery di query kanban index.php
$target1 = '                                                  FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0) - COALESCE(s2.excess_qty, 0)' . "\r\n" .
           '                                    WHEN s2.status = \'passed\' THEN' . "\r\n" .
           '                                        (s2.total_scanned_qty - COALESCE(s2.excess_qty, 0))';

$replace1 = '                                                  FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0)' . "\r\n" .
            '                                    WHEN s2.status = \'passed\' THEN' . "\r\n" .
            '                                        s2.total_scanned_qty';

if (strpos($content, $target1) !== false) {
    $content = str_replace($target1, $replace1, $content);
    echo "Target 1 replaced (CRLF)\n";
} else {
    $target1_lf = str_replace("\r\n", "\n", $target1);
    $replace1_lf = str_replace("\r\n", "\n", $replace1);
    if (strpos($content, $target1_lf) !== false) {
        $content = str_replace($target1_lf, $replace1_lf, $content);
        echo "Target 1 replaced (LF)\n";
    } else {
        echo "Target 1 NOT found\n";
    }
}

// 2. Ganti kalkulasi loop di index.php: $calcPassedQty += max(0, $sessLotPass - (int)($ks['excess_qty'] ?? 0));
$target2 = '$calcPassedQty += max(0, $sessLotPass - (int)($ks[\'excess_qty\'] ?? 0));' . "\r\n" .
           '                                        } elseif (($ks[\'status\'] ?? \'\') === \'passed\') {' . "\r\n" .
           '                                            $calcPassedQty += max(0, (int)$ks[\'total_scanned_qty\'] - (int)($ks[\'excess_qty\'] ?? 0));';

$replace2 = '$calcPassedQty += $sessLotPass;' . "\r\n" .
            '                                        } elseif (($ks[\'status\'] ?? \'\') === \'passed\') {' . "\r\n" .
            '                                            $calcPassedQty += (int)$ks[\'total_scanned_qty\'];';

if (strpos($content, $target2) !== false) {
    $content = str_replace($target2, $replace2, $content);
    echo "Target 2 replaced (CRLF)\n";
} else {
    $target2_lf = str_replace("\r\n", "\n", $target2);
    $replace2_lf = str_replace("\r\n", "\n", $replace2);
    if (strpos($content, $target2_lf) !== false) {
        $content = str_replace($target2_lf, $replace2_lf, $content);
        echo "Target 2 replaced (LF)\n";
    } else {
        echo "Target 2 NOT found\n";
    }
}

file_put_contents($filePath, $content);
echo "index.php updated successfully!\n";
