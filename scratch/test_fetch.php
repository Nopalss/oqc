<?php
$html = file_get_contents('http://localhost/oqc/modules/inspection/index.php');
$pos = strpos($html, 'Target:');
if ($pos !== false) {
    echo substr($html, $pos, 2500);
} else {
    echo "Not found";
}
