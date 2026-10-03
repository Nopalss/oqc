<?php
require_once __DIR__ . '/../config/app.php';
$_SESSION['user'] = [
    'id' => 1,
    'name' => 'Admin',
    'role' => 'admin'
];

$_GET['tab'] = 'my_inspections';
$_GET['period'] = 'all';

ob_start();
try {
    include __DIR__ . '/../modules/inspection/index.php';
    $html = ob_get_clean();
    echo "SUCCESS: Output length: " . strlen($html) . " bytes\n";
    if (strpos($html, 'Riwayat Saya') !== false) {
        echo "Found 'Riwayat Saya' tab\n";
    }
    if (strpos($html, 'Rincian Box / Lot yang Diperiksa') !== false) {
        echo "Found 'Rincian Box / Lot yang Diperiksa' section\n";
    }
    if (strpos($html, 'sort_reinspect') !== false || strpos($html, 'Verifikasi / Re-inspeksi') !== false) {
        echo "Found 'Verifikasi / Re-inspeksi' banner\n";
    }
    if (strpos($html, 'REJECTED (Awal)') !== false) {
        echo "Found 'REJECTED (Awal)' badge\n";
    }
    if (strpos($html, 'PASSED (Re-inspeksi)') !== false) {
        echo "Found 'PASSED (Re-inspeksi)' badge\n";
    }
} catch (Throwable $e) {
    ob_end_clean();
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
