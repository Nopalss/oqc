<?php
$breadcrumbCategory = "OPERASIONAL";
$pageTitle          = "Dashboard Laporan";
$pageSubtitle       = "Rekap hasil inspeksi outgoing quality control — PT. Surya Technology Industri";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$pdo = getDB();

// ── Filter Resolvers (Date & Customer) ──────────────────────────────────────
$presetFilter     = sanitize($_GET['preset'] ?? 'bulanan');
$selectedCustomer = sanitize($_GET['customer'] ?? '');

if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) {
    $startDate    = sanitize($_GET['start_date']);
    $endDate      = sanitize($_GET['end_date']);
    $presetFilter = 'custom';
} else {
    switch ($presetFilter) {
        case 'hari_ini':
            $startDate = date('Y-m-d');
            $endDate   = date('Y-m-d');
            break;
        case 'mingguan':
            $startDate = date('Y-m-d', strtotime('monday this week'));
            $endDate   = date('Y-m-d');
            break;
        case 'tahunan':
            $startDate = date('Y-01-01');
            $endDate   = date('Y-m-d');
            break;
        default:
            $presetFilter = 'bulanan';
            $startDate    = date('Y-m-01');
            $endDate      = date('Y-m-d');
    }
}

// ── Fetch Master Customers List for Filter Dropdown ────────────────────────
$customerOptions = [];
if ($pdo) {
    try {
        $stmtCust = $pdo->query("SELECT DISTINCT name FROM master_customers UNION SELECT DISTINCT customer FROM kanban_items WHERE customer IS NOT NULL AND customer != '' ORDER BY name ASC");
        $customerOptions = array_filter(array_map('trim', $stmtCust->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Exception $e) {}
}

// ── Init metric variables ──────────────────────────────────────────────────
$kpi = ['total_inspected' => 0, 'pass_rate' => 0, 'total_ng' => 0, 'total_lot' => 0,
        'pass_count' => 0, 'rejected_count' => 0];
$trendLabels       = [];
$trendNG           = [];
$defectLabels      = [];
$defectCounts      = [];
$top5Parts         = [];
$pieLabels         = [];
$pieCounts         = [];
$worstPartsGrouped = [];
$PALETTE           = ['#2563eb','#0ea5e9','#6366f1','#f59e0b','#10b981','#ef4444','#8b5cf6','#ec4899'];

if ($pdo) {
    try {
        $p = [':sd' => $startDate, ':ed' => $endDate];
        $custJoin = "";
        $custCond = "";

        if ($selectedCustomer !== '') {
            $custJoin = " LEFT JOIN kanban_items ki ON ss.kanban_item_id = ki.id ";
            $custCond = " AND ki.customer = :cust ";
            $p[':cust'] = $selectedCustomer;
        }

        // 1. Total Inspected Samples
        $stmt = $pdo->prepare("SELECT COUNT(s.id) FROM inspection_samples s INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id {$custJoin} WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond}");
        $stmt->execute($p); $kpi['total_inspected'] = (int)$stmt->fetchColumn();

        // 2. Total Lot Inspection Sessions
        $stmt = $pdo->prepare("SELECT COUNT(*) AS tl, SUM(ss.status='passed') AS pc, SUM(ss.status='rejected') AS rc FROM inspection_sessions ss {$custJoin} WHERE DATE(ss.started_at) BETWEEN :sd AND :ed {$custCond}");
        $stmt->execute($p); $row = $stmt->fetch();
        $kpi['total_lot']      = (int)($row['tl'] ?? 0);
        $kpi['pass_count']     = (int)($row['pc'] ?? 0);
        $kpi['rejected_count'] = (int)($row['rc'] ?? 0);
        $kpi['pass_rate']      = $kpi['total_lot'] > 0 ? round($kpi['pass_count'] / $kpi['total_lot'] * 100, 1) : 0;

        // 3. Total NG Pcs
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(ngr.qty_ng),0) FROM inspection_ng_records ngr INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id {$custJoin} WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond}");
        $stmt->execute($p); $kpi['total_ng'] = (int)$stmt->fetchColumn();

        // 4. Dynamic Trend NG (Per Bulan jika Tahunan / >90 Hari, Per Tanggal jika Bulanan / <=90 Hari + Auto Fill Zero Gap)
        $daysDiff = (strtotime($endDate) - strtotime($startDate)) / 86400;

        if ($presetFilter === 'tahunan' || $daysDiff > 90) {
            $trendSubtext = 'per bulan';
            $trendMap     = [];

            $startPeriod  = new DateTime(date('Y-m-01', strtotime($startDate)));
            $endPeriod    = new DateTime(date('Y-m-01', strtotime($endDate)));
            $endPeriod->modify('+1 month');
            $interval     = DateInterval::createFromDateString('1 month');
            $period       = new DatePeriod($startPeriod, $interval, $endPeriod);

            foreach ($period as $dt) {
                $key = $dt->format('Y-m');
                $lbl = $dt->format('M Y');
                $trendMap[$key] = ['label' => $lbl, 'ng' => 0];
            }

            $stmtT = $pdo->prepare("SELECT DATE_FORMAT(s.checked_at, '%Y-%m') AS month_key, COALESCE(SUM(ngr.qty_ng),0) AS ng 
                                    FROM inspection_samples s 
                                    INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id 
                                    {$custJoin}
                                    LEFT JOIN inspection_ng_records ngr ON ngr.inspection_sample_id=s.id 
                                    WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} 
                                    GROUP BY month_key 
                                    ORDER BY month_key ASC");
            $stmtT->execute($p);
            foreach ($stmtT->fetchAll() as $r) {
                if (isset($trendMap[$r['month_key']])) {
                    $trendMap[$r['month_key']]['ng'] = (int)$r['ng'];
                }
            }

            foreach ($trendMap as $item) {
                $trendLabels[] = $item['label'];
                $trendNG[]     = $item['ng'];
            }
        } else {
            $trendSubtext = 'per tanggal';
            $trendMap     = [];

            $startPeriod  = new DateTime($startDate);
            $endPeriod    = new DateTime($endDate);
            $endPeriod->modify('+1 day');
            $interval     = DateInterval::createFromDateString('1 day');
            $period       = new DatePeriod($startPeriod, $interval, $endPeriod);

            foreach ($period as $dt) {
                $key = $dt->format('Y-m-d');
                $lbl = $dt->format('d M');
                $trendMap[$key] = ['label' => $lbl, 'ng' => 0];
            }

            $stmtT = $pdo->prepare("SELECT DATE(s.checked_at) AS tgl, COALESCE(SUM(ngr.qty_ng),0) AS ng 
                                    FROM inspection_samples s 
                                    INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id 
                                    {$custJoin}
                                    LEFT JOIN inspection_ng_records ngr ON ngr.inspection_sample_id=s.id 
                                    WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} 
                                    GROUP BY DATE(s.checked_at) 
                                    ORDER BY tgl ASC");
            $stmtT->execute($p);
            foreach ($stmtT->fetchAll() as $r) {
                if (isset($trendMap[$r['tgl']])) {
                    $trendMap[$r['tgl']]['ng'] = (int)$r['ng'];
                }
            }

            foreach ($trendMap as $item) {
                $trendLabels[] = $item['label'];
                $trendNG[]     = $item['ng'];
            }
        }

        // 5. Defect Types Breakdown
        $stmt = $pdo->prepare("SELECT dt.name, COALESCE(SUM(ngr.qty_ng),0) AS total FROM inspection_ng_records ngr INNER JOIN defect_types dt ON ngr.defect_type_id=dt.id INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id {$custJoin} WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} GROUP BY dt.id,dt.name ORDER BY total DESC");
        $stmt->execute($p);
        foreach ($stmt->fetchAll() as $r) { $defectLabels[] = $r['name']; $defectCounts[] = (int)$r['total']; }

        // 6. Top 5 Part NG
        $stmt = $pdo->prepare("SELECT mp.part_code, mp.part_name, COALESCE(SUM(ngr.qty_ng),0) AS total_ng FROM inspection_ng_records ngr INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id LEFT JOIN master_parts mp ON ss.part_id=mp.id {$custJoin} WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} GROUP BY ss.part_id,mp.part_code,mp.part_name ORDER BY total_ng DESC LIMIT 5");
        $stmt->execute($p); $top5Parts = $stmt->fetchAll();
        foreach ($top5Parts as $r) { $pieLabels[] = htmlspecialchars($r['part_name'] ?? $r['part_code'] ?? 'Unknown'); $pieCounts[] = (int)$r['total_ng']; }

        // 7. Worst Part OQC Grouped by Top 3 Defect Types (for II. WORST PART OQC section)
        $top3DefectNames = array_slice($defectLabels, 0, 3);
        foreach ($top3DefectNames as $defName) {
            $pDef = array_merge($p, [':defname' => $defName]);
            $stmtWP = $pdo->prepare("SELECT 
                        COALESCE(mp.part_name, 'UNKNOWN PART') AS part_name,
                        COALESCE(mp.part_code, '-') AS part_code,
                        COALESCE(mp.model, '-') AS model,
                        COUNT(DISTINCT ss.id) AS lot_case,
                        SUM(CASE WHEN ss.status = 'rejected' THEN 1 ELSE 0 END) AS ng_case
                    FROM inspection_ng_records ngr
                    INNER JOIN defect_types dt ON ngr.defect_type_id = dt.id
                    INNER JOIN inspection_samples s ON ngr.inspection_sample_id = s.id
                    INNER JOIN inspection_sessions ss ON s.inspection_session_id = ss.id
                    LEFT JOIN master_parts mp ON ss.part_id = mp.id
                    {$custJoin}
                    WHERE dt.name = :defname
                      AND DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond}
                    GROUP BY mp.id, mp.part_name, mp.part_code, mp.model
                    ORDER BY ng_case DESC, lot_case DESC
                    LIMIT 3");
            $stmtWP->execute($pDef);
            $worstPartsGrouped[$defName] = $stmtWP->fetchAll();
        }

    } catch (PDOException $e) {}
}

// Generate Conclusion Summary for Worst Parts
$top3PartsNames = [];
foreach ($worstPartsGrouped as $dName => $parts) {
    if (!empty($parts[0]['part_name'])) {
        $top3PartsNames[] = htmlspecialchars($parts[0]['part_name']) . " (" . strtolower($dName) . ")";
    }
}
$top3PartsSummaryStr = !empty($top3PartsNames) ? implode(', ', $top3PartsNames) : 'Belum Ada Part Reject';

$jsLabels       = json_encode($trendLabels);
$jsTrendNG      = json_encode($trendNG);
$jsDefectLabels = json_encode($defectLabels);
$jsDefectCounts = json_encode($defectCounts);
$jsDefectColors = json_encode(array_values(array_slice($PALETTE, 0, max(count($defectLabels), 1))));
$jsPieLabels    = json_encode($pieLabels);
$jsPieCounts    = json_encode($pieCounts);
$jsPieColors    = json_encode(array_values(array_slice($PALETTE, 0, max(count($pieLabels), 1))));

$passColor  = $kpi['pass_rate'] >= 90 ? '#059669' : ($kpi['pass_rate'] >= 70 ? '#d97706' : '#dc2626');
$rankColors = ['#f59e0b','#94a3b8','#b45309','#3b82f6','#6366f1'];
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col transition-all duration-300 min-h-screen bg-slate-100">
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <!-- Dashboard body: spacious layout with natural scrolling -->
    <div id="dash-body" class="flex-1 p-4 space-y-4 overflow-y-auto">

        <?= render_flash() ?>

        <!-- ── Filter Bar ─────────────────────────────────── -->
        <div class="card" style="padding:10px 14px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                
                <!-- Preset Tabs -->
                <div style="display:flex;align-items:center;gap:3px;background:#f1f5f9;padding:3px;border-radius:10px;">
                    <?php $presets = ['hari_ini'=>'Hari Ini','mingguan'=>'Mingguan','bulanan'=>'Bulanan','tahunan'=>'Tahunan'];
                    foreach ($presets as $k => $lbl): 
                        $active = ($presetFilter === $k); 
                        $presetUrl = "?preset=" . $k . ($selectedCustomer !== '' ? '&customer=' . urlencode($selectedCustomer) : '');
                    ?>
                        <a href="<?= $presetUrl ?>"
                           style="padding:4px 12px;border-radius:7px;font-size:11px;font-weight:600;text-decoration:none;
                                  background:<?= $active ? '#2563eb' : 'transparent' ?>;
                                  color:<?= $active ? '#fff' : '#64748b' ?>;
                                  <?= $active ? 'box-shadow:0 1px 4px rgba(37,99,235,.25);' : '' ?>">
                            <?= $lbl ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Filters Form (Customer & Date Range) -->
                <form action="" method="GET" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <?php if ($presetFilter !== 'custom'): ?>
                        <input type="hidden" name="preset" value="<?= htmlspecialchars($presetFilter) ?>">
                    <?php endif; ?>

                    <!-- Customer Filter -->
                    <div style="display:flex;align-items:center;gap:4px;">
                        <span style="font-size:11px;color:#94a3b8;white-space:nowrap;font-weight:600;">Customer:</span>
                        <select name="customer" onchange="this.form.submit()" class="form-input text-xs" style="padding:3px 8px;max-width:180px;border-radius:6px;font-weight:600;">
                            <option value="">-- Semua Customer --</option>
                            <?php foreach ($customerOptions as $cOpt): ?>
                                <option value="<?= htmlspecialchars($cOpt) ?>" <?= $selectedCustomer === $cOpt ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cOpt) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Custom Date Range -->
                    <div style="display:flex;align-items:center;gap:4px;">
                        <span style="font-size:11px;color:#94a3b8;white-space:nowrap;">Rentang:</span>
                        <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="form-input text-xs" style="width:115px;padding:3px 8px;">
                        <span style="color:#cbd5e1;flex-shrink:0;">—</span>
                        <input type="date" name="end_date"   value="<?= htmlspecialchars($endDate) ?>"   class="form-input text-xs" style="width:115px;padding:3px 8px;">
                    </div>

                    <button type="submit" class="btn-secondary text-xs font-semibold" style="padding:4px 12px;white-space:nowrap;">Terapkan</button>
                    
                    <?php if ($selectedCustomer !== '' || $presetFilter === 'custom'): ?>
                        <a href="index.php" class="text-xs text-red-600 font-semibold hover:underline" style="padding:4px 4px;">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- ── 4 KPI Cards ───────────────────────────────── -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;" id="kpi-grid">

            <?php
            $kpis = [
                ['label'=>'Total Inspected','val'=>number_format($kpi['total_inspected']),'unit'=>'pcs','sub'=>'Sample dicek','icon'=>'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z','iconColor'=>'#2563eb','bg'=>'#eff6ff'],
                ['label'=>'Pass Rate','val'=>$kpi['pass_rate'].'%','unit'=>'','sub'=>$kpi['pass_count'].' passed / '.$kpi['total_lot'].' lot','icon'=>'M5 13l4 4L19 7','iconColor'=>'#059669','bg'=>'#f0fdf4','valColor'=>$passColor],
                ['label'=>'Total NG','val'=>number_format($kpi['total_ng']),'unit'=>'pcs','sub'=>$kpi['rejected_count'].' lot rejected','icon'=>'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z','iconColor'=>'#dc2626','bg'=>'#fff1f2'],
                ['label'=>'Total Lot','val'=>number_format($kpi['total_lot']),'unit'=>'lot','sub'=>'Sesi inspeksi','icon'=>'M4 6h16M4 10h16M4 14h16M4 18h16','iconColor'=>'#0284c7','bg'=>'#f0f9ff'],
            ];
            foreach ($kpis as $kitem): ?>
            <div class="card" style="padding:12px 14px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:40px;height:40px;border-radius:10px;background:<?= $kitem['bg'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg width="20" height="20" fill="none" stroke="<?= $kitem['iconColor'] ?>" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $kitem['icon'] ?>"/></svg>
                    </div>
                    <div style="min-width:0;">
                        <div style="font-size:9.5px;font-weight:700;color:#94a3b8;letter-spacing:.05em;text-transform:uppercase;"><?= $kitem['label'] ?></div>
                        <div style="font-size:20px;font-weight:900;color:<?= $kitem['valColor'] ?? '#0f172a' ?>;line-height:1.15;">
                            <?= $kitem['val'] ?><?php if($kitem['unit']): ?> <span style="font-size:11px;font-weight:500;color:#94a3b8;"><?= $kitem['unit'] ?></span><?php endif; ?>
                        </div>
                        <div style="font-size:9.5px;color:#64748b;margin-top:2px;"><?= $kitem['sub'] ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

        </div>

        <!-- ── Chart Row 1: Line (Height Enlarged) + Top5 ─── -->
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:10px;" id="dash-row-1">

            <!-- Tren Total NG (Enlarged Height) -->
            <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;">
                <div style="flex-shrink:0;margin-bottom:10px;">
                    <div style="font-size:13px;font-weight:800;color:#1e293b;">Tren Total NG</div>
                    <div style="font-size:10px;color:#94a3b8;"><?= date('d M Y', strtotime($startDate)) ?> &ndash; <?= date('d M Y', strtotime($endDate)) ?> &middot; <?= htmlspecialchars($trendSubtext ?? 'per tanggal') ?><?= $selectedCustomer !== '' ? ' &middot; Customer: ' . htmlspecialchars($selectedCustomer) : '' ?></div>
                </div>
                <div style="min-height:240px;height:240px;position:relative;">
                    <?php if (empty($trendLabels)): ?>
                        <div style="height:100%;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">Belum ada data inspeksi</div>
                    <?php else: ?>
                        <canvas id="chartTrendNG" style="width:100%;height:100%;"></canvas>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top 5 Part NG -->
            <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;">
                <div style="flex-shrink:0;margin-bottom:10px;">
                    <div style="font-size:13px;font-weight:800;color:#1e293b;">Top 5 Part Paling Banyak NG</div>
                    <div style="font-size:10px;color:#94a3b8;"><?= date('d M', strtotime($startDate)) ?> &ndash; <?= date('d M Y', strtotime($endDate)) ?></div>
                </div>
                <div style="flex:1;min-height:0;overflow-y:auto;">
                    <?php if (empty($top5Parts)): ?>
                        <div style="padding:40px 0;text-align:center;color:#cbd5e1;font-size:11px;">Belum ada data NG</div>
                    <?php else: ?>
                        <div style="display:flex;flex-direction:column;gap:12px;">
                            <?php $maxNG = max(array_column($top5Parts,'total_ng')) ?: 1;
                            foreach ($top5Parts as $i => $pt):
                                $pct = round($pt['total_ng']/$maxNG*100);
                                $rc  = $rankColors[$i] ?? '#cbd5e1'; ?>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div style="width:24px;height:24px;border-radius:7px;background:<?= $rc ?>;color:#fff;font-weight:800;font-size:10.5px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?= $i+1 ?></div>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-weight:700;font-size:11px;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($pt['part_name'] ?? 'Unknown') ?></div>
                                    <div style="font-size:9.5px;color:#94a3b8;font-family:monospace;"><?= htmlspecialchars($pt['part_code'] ?? '-') ?></div>
                                    <div style="height:4px;background:#f1f5f9;border-radius:99px;margin-top:4px;overflow:hidden;">
                                        <div style="height:100%;width:<?= $pct ?>%;background:<?= $rc ?>;border-radius:99px;"></div>
                                    </div>
                                </div>
                                <div style="font-weight:900;font-size:13px;color:#dc2626;flex-shrink:0;"><?= number_format($pt['total_ng']) ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ── Chart Row 2: Defect Donut + Part Pie (Refined Layout) ─────── -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;" id="dash-row-2">

            <!-- Breakdown Defect -->
            <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;">
                <div style="flex-shrink:0;margin-bottom:10px;">
                    <div style="font-size:13px;font-weight:800;color:#1e293b;">Breakdown Jenis Defect</div>
                    <div style="font-size:10px;color:#94a3b8;">Distribusi tipe cacat</div>
                </div>
                <?php if (empty($defectCounts)): ?>
                    <div style="height:160px;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">Belum ada data defect</div>
                <?php else: ?>
                    <div style="display:flex;gap:16px;align-items:center;min-height:160px;">
                        <div style="width:140px;height:140px;flex-shrink:0;position:relative;">
                            <canvas id="chartDefect" style="width:100%;height:100%;"></canvas>
                        </div>
                        <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:6px;max-height:160px;overflow-y:auto;">
                            <?php $totalD = array_sum($defectCounts) ?: 1;
                            foreach ($defectLabels as $di => $dl):
                                $dpct = round($defectCounts[$di]/$totalD*100,1);
                                $dc   = $PALETTE[$di%count($PALETTE)]; ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;font-size:11px;gap:8px;padding:3px 6px;border-radius:6px;background:#f8fafc;">
                                <span style="display:flex;align-items:center;gap:6px;color:#334155;flex:1;min-width:0;">
                                    <span style="width:9px;height:9px;border-radius:50%;background:<?= $dc ?>;flex-shrink:0;display:inline-block;"></span>
                                    <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600;"><?= htmlspecialchars($dl) ?></span>
                                </span>
                                <span style="font-weight:800;color:#0f172a;flex-shrink:0;"><?= number_format($defectCounts[$di]) ?> <span style="font-weight:500;color:#64748b;">(<?= $dpct ?>%)</span></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Distribusi NG per Part -->
            <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;">
                <div style="flex-shrink:0;margin-bottom:10px;">
                    <div style="font-size:13px;font-weight:800;color:#1e293b;">Distribusi NG per Part</div>
                    <div style="font-size:10px;color:#94a3b8;">Proporsi berdasarkan part</div>
                </div>
                <?php if (empty($pieCounts)): ?>
                    <div style="height:160px;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">Belum ada data NG per part</div>
                <?php else: ?>
                    <div style="display:flex;gap:16px;align-items:center;min-height:160px;">
                        <div style="width:140px;height:140px;flex-shrink:0;position:relative;">
                            <canvas id="chartPartPie" style="width:100%;height:100%;"></canvas>
                        </div>
                        <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:6px;max-height:160px;overflow-y:auto;">
                            <?php $totalP = array_sum($pieCounts) ?: 1;
                            foreach ($pieLabels as $pi => $pl):
                                $ppct = round($pieCounts[$pi]/$totalP*100,1);
                                $pc   = $PALETTE[$pi%count($PALETTE)]; ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;font-size:11px;gap:8px;padding:3px 6px;border-radius:6px;background:#f8fafc;">
                                <span style="display:flex;align-items:center;gap:6px;color:#334155;flex:1;min-width:0;">
                                    <span style="width:9px;height:9px;border-radius:50%;background:<?= $pc ?>;flex-shrink:0;display:inline-block;"></span>
                                    <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600;"><?= htmlspecialchars($pl) ?></span>
                                </span>
                                <span style="font-weight:800;color:#0f172a;flex-shrink:0;"><?= number_format($pieCounts[$pi]) ?> <span style="font-weight:500;color:#64748b;">(<?= $ppct ?>%)</span></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- ── Section 3: II. WORST PART OQC (Integrated Table & Conclusion) ─────── -->
        <div class="card" style="padding:16px 18px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #f1f5f9;">
                <div>
                    <h2 style="font-size:14px;font-weight:900;color:#1e293b;letter-spacing:.02em;text-transform:uppercase;margin:0;display:flex;align-items:center;gap:6px;">
                        <span style="color:#2563eb;">II.</span> WORST PART OQC
                    </h2>
                </div>
                <span style="font-size:11px;font-weight:600;color:#94a3b8;">Breakdown per Defect Area</span>
            </div>

            <?php if (empty($worstPartsGrouped)): ?>
                <div style="padding:30px 0;text-align:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">
                    Belum ada data part terburuk pada periode ini.
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:12px;">
                    <table style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">
                        <thead>
                            <tr style="background:#1e293b;color:#ffffff;font-weight:800;font-size:10px;text-transform:uppercase;letter-spacing:.05em;">
                                <th style="padding:8px 12px;text-align:center;width:45px;">NO</th>
                                <th style="padding:8px 12px;">PART NAME</th>
                                <th style="padding:8px 12px;">PART CODE</th>
                                <th style="padding:8px 12px;">MODEL</th>
                                <th style="padding:8px 12px;text-align:center;">LOT CASE</th>
                                <th style="padding:8px 12px;text-align:center;">NG CASE</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($worstPartsGrouped as $defectCategory => $parts): ?>
                                <!-- Defect Category Group Header -->
                                <tr style="background:#fff1f2;border-top:1px solid #fecdd3;border-bottom:1px solid #fecdd3;">
                                    <td colspan="6" style="padding:6px 12px;font-weight:900;color:#be123c;font-size:11px;text-transform:uppercase;letter-spacing:.04em;">
                                        🚨 <?= htmlspecialchars($defectCategory) ?>
                                    </td>
                                </tr>
                                <?php if (empty($parts)): ?>
                                    <tr>
                                        <td colspan="6" style="padding:8px 12px;text-align:center;color:#94a3b8;font-style:italic;">Tidak ada rekaman part</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($parts as $pIdx => $p): ?>
                                        <tr style="border-bottom:1px solid #f1f5f9;" class="hover:bg-slate-50">
                                            <td style="padding:8px 12px;text-align:center;font-weight:700;color:#94a3b8;"><?= $pIdx + 1 ?></td>
                                            <td style="padding:8px 12px;font-weight:800;color:#0f172a;"><?= htmlspecialchars($p['part_name']) ?></td>
                                            <td style="padding:8px 12px;font-family:monospace;color:#475569;"><?= htmlspecialchars($p['part_code']) ?></td>
                                            <td style="padding:8px 12px;color:#475569;"><?= htmlspecialchars($p['model']) ?></td>
                                            <td style="padding:8px 12px;text-align:center;font-family:monospace;font-weight:700;color:#334155;"><?= number_format($p['lot_case']) ?></td>
                                            <td style="padding:8px 12px;text-align:center;font-family:monospace;font-weight:900;color:#dc2626;background:#fff1f2;"><?= number_format($p['ng_case']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Soft Blue Conclusion Box (Matching Presentation Slide) -->
                <div style="background:#eff6ff;border:1px solid #dbeafe;border-radius:10px;padding:10px 14px;">
                    <span style="font-size:12px;font-weight:900;color:#1e40af;">Conclusion : </span>
                    <span style="font-size:12px;font-weight:700;color:#1d4ed8;">
                        3 worst parts are <span style="font-weight:900;color:#0f172a;"><?= htmlspecialchars($top3PartsSummaryStr) ?></span>
                    </span>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- end dash-body -->

    <!-- Mobile fallback -->
    <style>
        @media (max-width: 767px) {
            #main-content-wrapper { height:auto !important; overflow:auto !important; }
            #dash-body { overflow:auto !important; }
            #kpi-grid   { grid-template-columns: repeat(2,1fr) !important; }
            #dash-row-1,#dash-row-2 { grid-template-columns: 1fr !important; flex: none !important; }
        }
    </style>

    <script src="<?= base_url('assets/js/vendor/chart.min.js') ?>"></script>
    <script>
    Chart.defaults.font.family = "'system-ui','Segoe UI',sans-serif";
    Chart.defaults.color       = '#94a3b8';

    <?php if (!empty($trendLabels)): ?>
    new Chart(document.getElementById('chartTrendNG'), {
        type: 'line',
        data: {
            labels: <?= $jsLabels ?>,
            datasets: [{ label:'Total NG', data:<?= $jsTrendNG ?>, borderColor:'#2563eb', backgroundColor:'rgba(37,99,235,0.07)', borderWidth:2.5, pointBackgroundColor:'#2563eb', pointBorderColor:'#fff', pointBorderWidth:2, pointRadius:3, pointHoverRadius:5, fill:true, tension:0.4 }]
        },
        options: {
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{display:false}, tooltip:{mode:'index',intersect:false} },
            scales:{
                x:{ grid:{display:false}, ticks:{font:{size:10,weight:'600'},color:'#64748b'} },
                y:{ beginAtZero:true, grid:{color:'#f1f5f9'}, ticks:{font:{size:10},color:'#64748b',precision:0} }
            }
        }
    });
    <?php endif; ?>

    <?php if (!empty($defectCounts)): ?>
    new Chart(document.getElementById('chartDefect'), {
        type:'doughnut',
        data:{ labels:<?= $jsDefectLabels ?>, datasets:[{ data:<?= $jsDefectCounts ?>, backgroundColor:<?= $jsDefectColors ?>, borderWidth:2, borderColor:'#fff', hoverOffset:4 }] },
        options:{ responsive:true, maintainAspectRatio:false, cutout:'58%', plugins:{ legend:{display:false}, tooltip:{ callbacks:{ label:function(c){ var t=c.dataset.data.reduce(function(a,b){return a+b;},0); return ' '+c.raw+' pcs ('+Math.round(c.raw/t*100)+'%)'; } } } } }
    });
    <?php endif; ?>

    <?php if (!empty($pieCounts)): ?>
    new Chart(document.getElementById('chartPartPie'), {
        type:'pie',
        data:{ labels:<?= $jsPieLabels ?>, datasets:[{ data:<?= $jsPieCounts ?>, backgroundColor:<?= $jsPieColors ?>, borderWidth:2, borderColor:'#fff', hoverOffset:4 }] },
        options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false}, tooltip:{ callbacks:{ label:function(c){ var t=c.dataset.data.reduce(function(a,b){return a+b;},0); return ' '+c.raw+' NG ('+Math.round(c.raw/t*100)+'%)'; } } } } }
    });
    <?php endif; ?>
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
