<?php
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["DOCUMENT_ROOT"] = "d:/laragon/www/oqc";
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["REQUEST_URI"] = "/oqc/modules/inspection/index.php";

// Start session and set auth before header.php is loaded
session_start();
$_SESSION["user_id"] = 1;
$_SESSION["username"] = "admin";
$_SESSION["role"] = "superadmin";

ob_start();
include "d:/laragon/www/oqc/modules/inspection/index.php";
$html = ob_get_clean();

echo "Rendered length: " . strlen($html) . "\n";
echo "Has Jadwal & ETA: " . (strpos($html, "Jadwal & ETA") !== false ? "YES" : "NO") . "\n";
echo "Has Lanjutkan: " . (strpos($html, "Lanjutkan") !== false ? "YES" : "NO") . "\n";
echo "Has Progress Bar: " . (strpos($html, "selesai") !== false || strpos($html, "berjalan") !== false ? "YES" : "NO") . "\n";
