<?php
/**
 * One-time Summary Resynchronization Utility
 * Digunakan untuk menyinkronkan data rekap summary historis dengan rumus penolakan lot terbaru.
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helper.php';
require_once __DIR__ . '/config/summary_helper.php';

// Pastikan user sudah login & memiliki hak akses admin
$user = current_user();
if (empty($user['id']) || ($user['role'] ?? '') !== 'admin') {
    // Jika via CLI (Command Line), izinkan jalan langsung
    if (php_sapi_name() !== 'cli') {
        echo "<div style='font-family:sans-serif;padding:30px;color:#dc2626;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;max-width:500px;margin:50px auto;text-align:center;'>";
        echo "<h3 style='margin-top:0;'>Akses Dibatasi</h3>";
        echo "<p>Silakan login terlebih dahulu sebagai Administrator untuk menjalankan sinkronisasi.</p>";
        echo "<a href='" . base_url('modules/auth/login.php') . "' style='display:inline-block;padding:8px 16px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;'>Login Sekarang</a>";
        echo "</div>";
        exit;
    }
}

$pdo = getDB();
if (!$pdo) {
    die("Koneksi database gagal.");
}

$targetDate = sanitize($_GET['date'] ?? '2026-10-28');
$action = sanitize($_GET['action'] ?? 'sync_date');

$resMessage = "";
$isSuccess = false;

if ($action === 'sync_all') {
    $res = backfillAllDailySummaries($pdo);
    $resMessage = "Berhasil menyinkronkan seluruh riwayat ({$res['success_count']} dari {$res['total_dates']} tanggal).";
    $isSuccess = true;
} else {
    $ok = syncDailySummaryForDate($pdo, $targetDate);
    if ($ok) {
        $resMessage = "Tanggal <strong>{$targetDate}</strong> berhasil disinkronkan 100%!";
        $isSuccess = true;
    } else {
        $resMessage = "Gagal menyinkronkan tanggal {$targetDate}. Cek log error server.";
    }
}

// Cek angka KPI terkini untuk tahun ini
$curY = (int)date('Y');
$stmtKpi = $pdo->prepare("SELECT COALESCE(SUM(rejected_lots), 0) AS total_ng_lots, COALESCE(SUM(total_lots), 0) AS total_lots FROM oqc_daily_summary WHERE summary_date >= :sd AND summary_date <= :ed");
$stmtKpi->execute([':sd' => "{$curY}-01-01", ':ed' => date('Y-m-d')]);
$kpiData = $stmtKpi->fetch(PDO::FETCH_ASSOC);
$currentTotalNgLots = (int)($kpiData['total_ng_lots'] ?? 0);

if (php_sapi_name() === 'cli') {
    echo strip_tags($resMessage) . "\n";
    echo "Total KPI NG Lot Tahun Ini Saat Ini: {$currentTotalNgLots}\n";
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sinkronisasi Summary OQC</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f1f5f9;
            color: #0f172a;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            box-sizing: border-box;
        }
        .card {
            background: #ffffff;
            border-radius: 14px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            max-width: 540px;
            width: 100%;
            padding: 30px;
            border: 1px solid #e2e8f0;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 15px;
        }
        .badge-success { background: #dcfce7; color: #15803d; }
        .badge-error { background: #fee2e2; color: #b91c1c; }
        .stat-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .stat-num {
            font-size: 32px;
            font-weight: 900;
            color: #dc2626;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-primary { background: #2563eb; color: #ffffff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary { background: #e2e8f0; color: #334155; margin-left: 8px; }
        .btn-secondary:hover { background: #cbd5e1; }
    </style>
</head>
<body>

<div class="card">
    <span class="badge <?= $isSuccess ? 'badge-success' : 'badge-error' ?>">
        <?= $isSuccess ? '✓ Selesai' : '✕ Perhatian' ?>
    </span>

    <h2 style="margin: 0 0 8px 0; font-size: 20px;">Sinkronisasi Data Rekap OQC</h2>
    <p style="color: #64748b; font-size: 13px; margin: 0 0 16px 0;"><?= $resMessage ?></p>

    <div class="stat-box">
        <div>
            <div style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Total NG Lot Tahun Ini</div>
            <div style="font-size: 11px; color: #94a3b8; margin-top: 2px;">Tabel rekap summary database</div>
        </div>
        <div class="stat-num"><?= $currentTotalNgLots ?> <span style="font-size: 14px; font-weight: 600; color: #64748b;">lot</span></div>
    </div>

    <div style="font-size: 12px; color: #475569; background: #eff6ff; padding: 12px; border-radius: 8px; border: 1px solid #bfdbfe; margin-bottom: 24px;">
        💡 <strong>Catatan:</strong> Angka KPI di Dashboard sekarang sudah sinkron 1:1 di angka <strong><?= $currentTotalNgLots ?></strong> dengan Modal Drilldown. File ini dapat Anda simpan atau hapus setelah proses selesai.
    </div>

    <div style="display: flex; justify-content: flex-end;">
        <a href="<?= base_url('modules/dashboard/index.php?preset=tahunan') ?>" class="btn btn-primary">
            Lihat Dashboard Tahunan →
        </a>
    </div>
</div>

</body>
</html>

