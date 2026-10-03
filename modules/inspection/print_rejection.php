<?php
/**
 * Official Printable Rejection Sheet Document (Form STQC-F-167 REV.00)
 * 100% Pixel-Perfect Matching Company Spreadsheet Format: Reject Sheet QC.xlsx
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/../../config/qr_svg.php';
session_write_close();

$sessionId = (int)($_GET['session_id'] ?? $_GET['id'] ?? 0);
$sessionLotId = (int)($_GET['session_lot_id'] ?? 0);
$isAutoPrint = isset($_GET['autoprint']) && $_GET['autoprint'] == 1;

$pdo = getDB();
$session = null;
$ngRecords = [];
$targetLot = null;

if ($sessionLotId > 0 && $pdo) {
    try {
        $stmtLot = $pdo->prepare("
            SELECT isl.*, u_chief.name as chief_display_name
            FROM inspection_session_lots isl
            LEFT JOIN users u_chief ON u_chief.id = isl.chief_approved_by
            WHERE isl.id = :lid
        ");
        $stmtLot->execute([':lid' => $sessionLotId]);
        $targetLot = $stmtLot->fetch(PDO::FETCH_ASSOC);
        if ($targetLot) {
            $sessionId = (int)$targetLot['inspection_session_id'];
        }
    } catch (PDOException $e) {}
}

if ($sessionId > 0 && $pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, 
                   did.part_code, did.part_name, did.lot_number, did.cavity, did.pic as did_pic,
                   k.kanban_no, k.customer, k.qty as kanban_qty, b.document_number as doc_no,
                   COALESCE(m.name, p.model) as model, u.name as inspector_name,
                   u_chief.name as chief_display_name
            FROM inspection_sessions s
            JOIN daily_inspection_data did ON did.id = s.did_id
            LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
            LEFT JOIN kanban_batches b ON b.id = k.batch_id
            LEFT JOIN master_parts p ON p.id = COALESCE(
                s.part_id,
                (SELECT mp.id FROM master_parts mp WHERE UPPER(mp.part_code) = UPPER(did.part_code) LIMIT 1)
            )
            LEFT JOIN master_models m ON m.id = p.model_id
            LEFT JOIN users u ON u.id = s.inspector_id
            LEFT JOIN users u_chief ON u_chief.id = s.chief_approved_by
            WHERE s.id = :id
        ");
        $stmt->execute([':id' => $sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($session) {
            // Jika mencetak lembar rejection per-lot
            if ($targetLot) {
                $session['lot_number'] = $targetLot['lot_number'] . (!empty($targetLot['ref_number']) ? ' (Ref: ' . $targetLot['ref_number'] . ')' : '');
                $session['sample_size'] = (int)$targetLot['sample_size'];
                $session['total_scanned_qty'] = (int)$targetLot['qty'];
                $session['ng_count'] = (int)$targetLot['ng_count'];
                $session['reject_number'] = (int)($targetLot['reject_number'] ?? 1);

                $stmtNg = $pdo->prepare("
                    SELECT n.*, d.name as defect_name, COALESCE(sp.sample_number, 1) as sample_number
                    FROM inspection_ng_records n
                    LEFT JOIN inspection_samples sp ON sp.id = n.inspection_sample_id
                    JOIN defect_types d ON d.id = n.defect_type_id
                    WHERE n.session_lot_id = :lid AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
                    ORDER BY n.id ASC
                ");
                $stmtNg->execute([':lid' => $sessionLotId]);
                $ngRecords = $stmtNg->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Mode lama (per sesi)
                $stmtNg = $pdo->prepare("
                    SELECT n.*, d.name as defect_name, COALESCE(sp.sample_number, 1) as sample_number
                    FROM inspection_ng_records n
                    LEFT JOIN inspection_samples sp ON sp.id = n.inspection_sample_id
                    JOIN defect_types d ON d.id = n.defect_type_id
                    WHERE (n.inspection_session_id = :sid OR sp.inspection_session_id = :sid2)
                      AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
                    ORDER BY n.id ASC
                ");
                $stmtNg->execute([':sid' => $sessionId, ':sid2' => $sessionId]);
                $ngRecords = $stmtNg->fetchAll(PDO::FETCH_ASSOC);
            }

            // Log manual reprint if user clicked reprint
            if (!$isAutoPrint) {
                $pdo->prepare("INSERT INTO rejection_sheet_prints (inspection_session_id, print_type, printed_at) VALUES (:sid, 'manual_reprint', NOW())")
                    ->execute([':sid' => $sessionId]);
            }
        }
    } catch (PDOException $e) {
        $session = null;
    }
}

if (!$session) {
    die("Dokumen Rejection Sheet tidak ditemukan!");
}

// Build defect names summary string
$defectSummaryList = [];
foreach ($ngRecords as $rec) {
    $defectSummaryList[] = $rec['defect_name'];
}
$defectProblemText = !empty($defectSummaryList) ? implode(', ', array_unique($defectSummaryList)) : 'Visual / Dimension Defect';

// Fetch list of active users for Chief selection modal
$chiefUsersList = [];
if ($pdo) {
    try {
        $stmtUsers = $pdo->query("SELECT id, name, username, role FROM users WHERE status = 'active' ORDER BY name ASC");
        $chiefUsersList = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $eUsers) {}
}

// Inspector & Chief Approval Status Metadata
$inspectorDisplayName = !empty($session['inspector_name']) ? $session['inspector_name'] : (!empty($session['did_pic']) ? $session['did_pic'] : 'QC Inspector');
$partsInspector = preg_split('/\s+/', trim($inspectorDisplayName));
$inspectorShortSign = !empty($partsInspector[0]) ? $partsInspector[0] : 'Inspector';

// Chief Approval Status & Hybrid Stamp Metadata (Dedicated Chief Columns - Per Lot Priority)
if ($targetLot) {
    $isChiefApproved = (!empty($targetLot['is_chief_approved']) && (int)$targetLot['is_chief_approved'] === 1);
    $chiefName = !empty($targetLot['chief_display_name']) ? $targetLot['chief_display_name'] : (!empty($session['chief_display_name']) ? $session['chief_display_name'] : 'Chief QC');
    $chiefApprovedAtRaw = $targetLot['chief_approved_at'] ?? null;
} else {
    $isChiefApproved = (!empty($session['is_chief_approved']) && (int)$session['is_chief_approved'] === 1);
    $chiefName = !empty($session['chief_display_name']) ? $session['chief_display_name'] : 'Chief QC';
    $chiefApprovedAtRaw = $session['chief_approved_at'] ?? null;
}
$isApproved = $isChiefApproved;
$partsChief = preg_split('/\s+/', trim($chiefName));
$chiefShortSign = !empty($partsChief[0]) ? $partsChief[0] : 'Chief';
$chiefAppDate = !empty($chiefApprovedAtRaw) ? date('d / m / Y', strtotime($chiefApprovedAtRaw)) : date('d / m / Y');
$chiefAppTime = !empty($chiefApprovedAtRaw) ? date('H:i', strtotime($chiefApprovedAtRaw)) . ' WIB' : date('H:i') . ' WIB';

// Generate Mini QR Codes (100% pure-PHP offline SVG with URL verification link)
$inspectorQrUrl = base_url("modules/inspection/verify.php?id=" . $session['id'] . ($sessionLotId > 0 ? "&session_lot_id=" . $sessionLotId : "") . "&role=inspector");
$inspectorQrSvg = generate_qr_svg($inspectorQrUrl, 38);

$chiefQrUrl = base_url("modules/inspection/verify.php?id=" . $session['id'] . ($sessionLotId > 0 ? "&session_lot_id=" . $sessionLotId : "") . "&role=chief");
$chiefQrSvg = generate_qr_svg($chiefQrUrl, 38);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>REJECT INFORMATION SHEET — STQC-F-167 REV.00 (<?= htmlspecialchars($session['part_code']) ?>)</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 5mm;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9.5px;
            color: #000000;
            background-color: #f1f5f9;
            margin: 0;
            padding: 12px;
        }
        .no-print-bar {
            max-width: 790px;
            margin: 0 auto 10px auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            padding: 6px 14px;
            font-size: 11px;
            font-weight: bold;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            border: none;
        }
        .btn-back { background: #e2e8f0; color: #1e293b; }
        .btn-print { background: #dc2626; color: #ffffff; }

        /* Unified QC Digital Stamp & Mini QR Hybrid Styling */
        .qc-stamp-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            height: 56px;
            padding: 1px 2px;
            box-sizing: border-box;
            width: 100%;
        }
        .qc-qr-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 3px;
            padding: 1px;
            flex-shrink: 0;
            width: 42px;
            height: 42px;
            box-sizing: border-box;
            text-decoration: none;
            cursor: pointer;
        }
        .qc-qr-wrap svg {
            width: 38px;
            height: 38px;
            display: block;
        }
        .qc-digital-badge {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            border-radius: 4px;
            line-height: 1.15;
            padding: 2px 3px;
            box-sizing: border-box;
            flex: 1;
            min-width: 0;
            height: 52px;
        }
        /* Inspector Variant */
        .qc-badge-inspector {
            border: 1.5px solid #0f766e;
            background-color: #f0fdf4;
            color: #0f766e;
            box-shadow: inset 0 0 0 1px #86efac;
        }
        .qc-badge-inspector .qc-badge-title {
            color: #15803d;
            border-bottom: 1px solid #86efac;
        }
        .qc-badge-inspector .qc-badge-meta {
            color: #047857;
        }

        /* Chief Variant */
        .qc-badge-chief {
            border: 1.5px solid #1e40af;
            background-color: #f0f7ff;
            color: #1e40af;
            box-shadow: inset 0 0 0 1px #93c5fd;
        }
        .qc-badge-chief .qc-badge-title {
            color: #1e40af;
            border-bottom: 1px solid #93c5fd;
        }
        .qc-badge-chief .qc-badge-meta {
            color: #2563eb;
        }

        /* Common Badge Typography */
        .qc-badge-title {
            font-size: 7px;
            font-weight: 900;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            padding-bottom: 1px;
            margin-bottom: 1px;
            width: 100%;
            white-space: nowrap;
        }
        .qc-badge-sign-name {
            font-size: 9.5px;
            font-family: 'Segoe Script', 'Brush Script MT', 'Dancing Script', cursive, sans-serif;
            font-weight: 700;
            color: #0f172a;
            transform: rotate(-2deg);
            padding: 0 1px;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
            line-height: 1.2;
        }
        .qc-badge-meta {
            font-size: 5.5px;
            font-family: monospace;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-top: 1px;
            white-space: nowrap;
        }
        .btn-chief-acc {
            background: #16a34a;
            color: #ffffff;
            transition: all 0.2s;
            box-shadow: 0 1px 2px rgba(22, 163, 74, 0.3);
        }
        .btn-chief-acc:hover {
            background: #15803d;
        }
        .btn-chief-unacc {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
            padding: 5px 10px;
            font-size: 10px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            transition: all 0.15s;
        }
        .btn-chief-unacc:hover {
            background: #fecaca;
        }
        .chief-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            background: #dcfce7;
            border: 1px solid #86efac;
            color: #166534;
            border-radius: 6px;
            font-size: 11px;
            font-weight: bold;
        }

        .sheet-container {
            width: 790px;
            margin: 0 auto;
            background: #ffffff;
            border: 2px solid #000000;
            padding: 8px 10px;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            gap: 3px;
        }
        @media print {
            .no-print-bar { display: none !important; }
        }

        /* Table Grid Layouts */
        table.tbl-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }
        table.tbl-grid td, table.tbl-grid th {
            border: 1px solid #000000;
            padding: 4px 6px;
            vertical-align: middle;
            font-size: 9.5px;
        }
        .sec-head {
            background-color: #d9d9d9 !important;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10px;
            padding: 4px 6px;
        }
        .bg-gray { background-color: #f2f2f2 !important; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        .font-mono { font-family: monospace, monospace; }
        .checkbox-box {
            display: inline-block;
            width: 10px;
            height: 10px;
            border: 1.5px solid #000;
            text-align: center;
            line-height: 8px;
            font-size: 8px;
            font-weight: bold;
            margin-right: 2px;
            vertical-align: middle;
        }
        .checkbox-box-lg {
            display: inline-block;
            width: 24px;
            height: 24px;
            border: 1.5px solid #000;
            text-align: center;
            line-height: 20px;
            font-size: 18px;
            font-weight: 900;
            margin-right: 6px;
            vertical-align: middle;
            background: #ffffff;
        }
        .judgment-cell {
            border: 1px solid #000000 !important;
            background: #ffffff !important;
        }
        .checked::after { content: '✓'; }
    </style>
</head>
<body>

    <!-- Action Bar (Hidden on print) -->
    <div class="no-print-bar">
        <a href="<?= base_url('modules/inspection/session.php?id=' . $session['id']) ?>" class="btn-action btn-back">
            &larr; Kembali ke Workbench Inspeksi
        </a>
        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <!-- Dual-Workflow Chief Approval Controls -->
            <div id="chief-action-container" style="display: flex; align-items: center; gap: 6px;">
                <?php if ($isApproved): ?>
                    <div class="chief-status-pill" id="badge-chief-acc">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        <span>ACC Chief QC: <b id="bar-chief-name"><?= htmlspecialchars($chiefName) ?></b></span>
                        <span style="font-size: 9.5px; opacity: 0.85;" id="bar-chief-time">(<?= !empty($chiefApprovedAtRaw) ? date('d/m/Y H:i', strtotime($chiefApprovedAtRaw)) : date('d/m/Y H:i') ?>)</span>
                    </div>
                    <button type="button" onclick="triggerChiefUnapprove()" class="btn-chief-unacc" id="btn-unapprove-chief" title="Batalkan ACC Chief jika ada perbaikan">
                        Batal ACC
                    </button>
                <?php else: ?>
                    <button type="button" onclick="triggerChiefApprove()" class="btn-action btn-chief-acc" id="btn-approve-chief">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        ACC sebagai Chief QC
                    </button>
                <?php endif; ?>
            </div>

            <button onclick="downloadPDF(this)" class="btn-action" style="background: #0284c7; color: #ffffff;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Download PDF
            </button>
            <button onclick="printCleanPDF(this)" class="btn-action btn-print">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Cetak PDF (Full A4)
            </button>
        </div>
    </div>

    <!-- Official Rejection Sheet Container -->
    <div class="sheet-container">

        <!-- HEADER TABLE -->
        <table class="tbl-grid">
            <tr>
                <td style="width: 28%; font-weight: 800; font-size: 10.5px; line-height: 1.25; border-bottom: 2px solid #000;">
                    PT. SURYA TECHNOLOGY INDUSTRI
                    <div style="font-size: 8.5px; font-weight: 700; color: #475569; letter-spacing: 0.04em; margin-top: 1px;">QUALITY CONTROL</div>
                </td>
                <td class="text-center" style="width: 42%; font-weight: 900; font-size: 17.5px; letter-spacing: 0.04em; line-height: 1.15; border-bottom: 2px solid #000;">
                    REJECT INFORMATION SHEET
                </td>
                <td style="width: 30%; font-size: 8.5px; border-bottom: 2px solid #000;">
                    <div style="display: flex; flex-wrap: wrap; gap: 4px 6px; justify-content: flex-end;">
                        <span><span class="checkbox-box"></span> CUSTOMER CLAIM</span>
                        <span><span class="checkbox-box checked"></span> OQC</span>
                        <span><span class="checkbox-box"></span> PQC</span>
                        <span><span class="checkbox-box"></span> ASSY/2nd PROC</span>
                        <span><span class="checkbox-box"></span> STOCK WH</span>
                    </div>
                </td>
            </tr>
        </table>

        <!-- MAIN 2-COLUMN SECTION GRID -->
        <table class="tbl-grid main-grid" style="table-layout: fixed;">
            <colgroup>
                <col style="width: 50%;">
                <col style="width: 50%;">
            </colgroup>
            <tr>
                <!-- LEFT MAIN COLUMN: SECTION 1 & SECTION 2 -->
                <td style="vertical-align: top; padding: 0; border: none;">
                    
                    <!-- SECTION 1: REJECT INFORMATION DETAIL -->
                    <table class="tbl-grid" style="margin-bottom: 0;">
                        <tr>
                            <td colspan="2" class="sec-head">1. REJECT INFORMATION DETAIL</td>
                        </tr>
                        <tr>
                            <td style="width: 35%; font-weight: bold;" class="bg-gray">PROBLEM</td>
                            <td style="width: 65%; font-weight: 900; font-size: 11px; color: #dc2626;"><?= htmlspecialchars($defectProblemText) ?></td>
                        </tr>
                        <tr>
                            <td class="bg-gray" style="font-weight: bold;">PART NAME</td>
                            <td class="font-bold"><?= htmlspecialchars($session['part_name']) ?></td>
                        </tr>
                        <tr>
                            <td class="bg-gray" style="font-weight: bold;">PART CODE</td>
                            <td class="font-mono font-black text-sm" style="font-size: 12px; font-weight: 900;"><?= htmlspecialchars($session['part_code']) ?></td>
                        </tr>
                        <tr>
                            <td class="bg-gray" style="font-weight: bold;">MODEL</td>
                            <td class="font-mono font-bold" style="font-size: 11px; font-weight: 800;"><?= htmlspecialchars($session['model'] ?: '-') ?></td>
                        </tr>
                        <tr>
                            <td class="bg-gray" style="font-weight: bold;">DATE</td>
                            <td><?= date('d / m / Y', strtotime($session['started_at'])) ?></td>
                        </tr>
                        <tr>
                            <td class="bg-gray" style="font-weight: bold;">TIME</td>
                            <td><?= date('H:i', strtotime($session['closed_at'] ?: $session['started_at'])) ?> WIB</td>
                        </tr>
                        <tr>
                            <td class="bg-gray" style="font-weight: bold;">DIVISION</td>
                            <td class="font-bold">OQC (Outgoing Quality Control)</td>
                        </tr>
                        <tr>
                            <td class="bg-gray" style="font-weight: bold;">CAV. NO</td>
                            <td class="font-mono font-bold"><?= htmlspecialchars($session['cavity']) ?></td>
                        </tr>
                        <tr>
                            <td class="bg-gray" style="font-weight: bold;">QTY. INSPECTION</td>
                            <td class="font-mono font-bold"><?= number_format($session['kanban_qty'] ?? 200) ?> Pcs</td>
                        </tr>
                        <tr>
                            <td class="bg-gray" style="font-weight: bold;">QTY. SAMPLING</td>
                            <td class="font-mono font-bold"><?= number_format($session['sample_size']) ?> Pcs</td>
                        </tr>
                        <tr>
                            <td class="bg-gray" style="font-weight: bold;">NG</td>
                            <td class="font-mono font-black" style="color: #dc2626; font-size: 12px; font-weight: 900;"><?= number_format($session['ng_count']) ?> Pcs</td>
                        </tr>
                        <tr>
                            <td class="bg-gray" style="font-weight: bold;">DELAY / STOP LINE (EFFECT)</td>
                            <td>
                                <span style="margin-right: 15px;"><span class="checkbox-box"></span> YES</span>
                                <span><span class="checkbox-box"></span> NO</span>
                            </td>
                        </tr>
                        <tr>
                            <td class="bg-gray" style="font-weight: bold;">SA ROUTE</td>
                            <td class="font-mono"></td>
                        </tr>
                    </table>

                    <!-- SECTION 2: ENGINEERING / QA CONFIRM -->
                    <table class="tbl-grid" style="margin-top: 4px; margin-bottom: 0;">
                        <tr>
                            <td colspan="2" class="sec-head">2. ENGINEERING / QA CONFIRM (IF NEEDED)</td>
                        </tr>
                        <tr>
                            <td style="width: 35%; font-weight: bold;" class="bg-gray">PIC CONFIRM :</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td class="bg-gray" style="font-weight: bold;">TIME CONFIRM :</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td class="bg-gray" style="font-weight: bold;">Reason :</td>
                            <td style="height: 30px;"></td>
                        </tr>
                        <tr>
                            <td class="bg-gray" style="font-weight: bold; vertical-align: middle;">CONFIRM JUDGMENT</td>
                            <td class="judgment-cell" style="vertical-align: middle; padding: 6px 10px;">
                                <span style="margin-right: 22px; font-weight: 900; font-size: 16px; color: #15803d; letter-spacing: 1px; display: inline-flex; align-items: center;">
                                    <span class="checkbox-box-lg"></span> ACCEPT
                                </span>
                                <span style="font-weight: 900; font-size: 16px; color: #b91c1c; letter-spacing: 1px; display: inline-flex; align-items: center;">
                                    <span class="checkbox-box-lg"></span> REJECT
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="bg-gray" style="font-weight: bold;">Urgently note :</td>
                            <td style="height: 25px;"></td>
                        </tr>
                    </table>

                </td>

                <!-- RIGHT MAIN COLUMN: SECTION 3 & SECTION 4 -->
                <td style="vertical-align: top; padding: 0; border: none; padding-left: 4px;">
                    
                    <!-- SECTION 3: TREATMENT PART -->
                    <table class="tbl-grid" style="margin-bottom: 0; table-layout: fixed;">
                        <colgroup>
                            <col style="width: 22%;">
                            <col style="width: 28%;">
                            <col style="width: 22%;">
                            <col style="width: 28%;">
                        </colgroup>
                        <tr>
                            <td colspan="4" class="sec-head">3. TREATMENT PART</td>
                        </tr>
                        <tr>
                            <td colspan="4" class="bg-gray text-center font-bold">TREATMENT PART AFTER PENDING</td>
                        </tr>
                        <tr>
                            <td class="text-center font-bold"><span class="checkbox-box"></span> SORTING</td>
                            <td class="text-center font-bold"><span class="checkbox-box"></span> REWORK</td>
                            <td class="text-center font-bold" colspan="2"><span class="checkbox-box"></span> ABOLISH</td>
                        </tr>
                        <tr>
                            <td colspan="4" class="bg-gray text-center font-bold">QTY INFORMATION</td>
                        </tr>
                        <tr>
                            <td class="bg-gray font-bold">QTY BOX :</td>
                            <td style="min-height: 20px;"></td>
                            <td class="bg-gray font-bold">HOUR :</td>
                            <td style="min-height: 20px;"></td>
                        </tr>
                        <tr>
                            <td class="bg-gray font-bold">QTY BAG :</td>
                            <td style="min-height: 20px;"></td>
                            <td class="bg-gray font-bold">QTY TOTAL :</td>
                            <td style="min-height: 20px;"></td>
                        </tr>
                    </table>

                    <!-- SECTION 4: SECOND PROCESS INFORMATION DETAIL -->
                    <table class="tbl-grid" style="margin-top: 4px; margin-bottom: 0; table-layout: fixed;">
                        <colgroup>
                            <col style="width: 22%;">
                            <col style="width: 28%;">
                            <col style="width: 22%;">
                            <col style="width: 28%;">
                        </colgroup>
                        <tr>
                            <td colspan="4" class="sec-head">4. SECOND PROCESS INFORMATION DETAIL</td>
                        </tr>
                        <tr>
                            <td class="bg-gray font-bold text-center">2nd PROCESS</td>
                            <td class="bg-gray font-bold text-center">REWORK</td>
                            <td class="bg-gray font-bold text-center" colspan="2">SORTING</td>
                        </tr>
                        <tr>
                            <td class="bg-gray font-bold">DATE</td>
                            <td colspan="3"></td>
                        </tr>
                        <tr>
                            <td class="bg-gray font-bold">START TIME</td>
                            <td></td>
                            <td class="bg-gray font-bold">FINISH TIME</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td class="bg-gray font-bold">DIVISION</td>
                            <td></td>
                            <td class="bg-gray font-bold">STOCK WH</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td class="bg-gray font-bold">OK QTY</td>
                            <td></td>
                            <td class="bg-gray font-bold">NG QTY</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td class="bg-gray font-bold">TOTAL QTY</td>
                            <td colspan="3"></td>
                        </tr>
                        <tr>
                            <td class="bg-gray font-bold">QC PIC</td>
                            <td></td>
                            <td class="bg-gray font-bold">GUARANTEE LABEL</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td class="bg-gray font-bold">STATUS</td>
                            <td colspan="3"></td>
                        </tr>
                        <tr>
                            <td class="bg-gray" style="font-weight: bold; vertical-align: middle;">QC INSPECTION</td>
                            <td colspan="3" class="judgment-cell" style="vertical-align: middle; padding: 6px 10px;">
                                <span style="margin-right: 28px; font-weight: 900; font-size: 16px; color: #15803d; letter-spacing: 1px; display: inline-flex; align-items: center;">
                                    <span class="checkbox-box-lg"></span> OK
                                </span>
                                <span style="font-weight: 900; font-size: 16px; color: #b91c1c; letter-spacing: 1px; display: inline-flex; align-items: center;">
                                    <span class="checkbox-box-lg"></span> NG
                                </span>
                            </td>
                        </tr>
                    </table>

                </td>
            </tr>
        </table>

        <!-- FOOTNOTE NOTE BOX -->
        <table class="tbl-grid">
            <tr>
                <td style="font-size: 8px; background-color: #fafafa; padding: 4px 6px;">
                    <b>Note :</b> Prioritas Action sorting 1X 24 jam selesai garansi lot problem. Batas target sorting 3 hari setelah terbit QTR, 7 hari Batas Maximum target Rework dan batas waktu menjawab QTR (Quality Trouble Report) / Abnormal Quality paling lama 5 hari atau sesuai permintaan.
                </td>
            </tr>
        </table>

        <!-- SECTION 5: SIGNATURE & APPROVAL BLOCK -->
        <table class="tbl-grid text-center" style="table-layout: fixed;">
            <tr class="sec-head">
                <td colspan="3" style="width: 50%;">QUALITY CONTROL SITE</td>
                <td colspan="2" style="width: 50%;">WAREHOUSE (PART STORE) SITE</td>
            </tr>
            <tr class="bg-gray font-bold" style="font-size: 8.5px;">
                <td style="width: 16.6%;">INSPECTOR</td>
                <td style="width: 16.7%;">CHIEF</td>
                <td style="width: 16.7%;">LEADER / SPV</td>
                <td style="width: 25%;">IN CHARGE</td>
                <td style="width: 25%;">LEADER / SPV</td>
            </tr>
            <tr style="height: 60px;" class="signature-row">
                <td style="width: 16.6%; vertical-align: middle; padding: 1px;" id="cell-inspector-sign">
                    <?php if (!empty($inspectorDisplayName)): ?>
                        <div class="qc-stamp-container" id="inspector-stamp-box">
                            <a href="<?= htmlspecialchars($inspectorQrUrl) ?>" target="_blank" class="qc-qr-wrap" title="Verifikasi Inspektor: <?= htmlspecialchars($inspectorQrUrl) ?>">
                                <?= $inspectorQrSvg ?>
                            </a>
                            <div class="qc-digital-badge qc-badge-inspector">
                                <div class="qc-badge-title">OQC INSPECTOR</div>
                                <div class="qc-badge-sign-name"><?= htmlspecialchars($inspectorShortSign) ?></div>
                                <div class="qc-badge-meta">VERIFIED DIGITAL</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div id="inspector-empty-box" style="height: 56px;"></div>
                    <?php endif; ?>
                </td>
                <td style="width: 16.7%; vertical-align: middle; padding: 1px;" id="cell-chief-sign">
                    <?php if ($isApproved): ?>
                        <div class="qc-stamp-container" id="chief-stamp-box">
                            <a href="<?= htmlspecialchars($chiefQrUrl) ?>" target="_blank" class="qc-qr-wrap" title="Verifikasi Chief: <?= htmlspecialchars($chiefQrUrl) ?>">
                                <?= $chiefQrSvg ?>
                            </a>
                            <div class="qc-digital-badge qc-badge-chief">
                                <div class="qc-badge-title">OQC CHIEF ACC</div>
                                <div class="qc-badge-sign-name"><?= htmlspecialchars($chiefShortSign) ?></div>
                                <div class="qc-badge-meta">VERIFIED DIGITAL</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div id="chief-empty-box" style="height: 56px;"></div>
                    <?php endif; ?>
                </td>
                <td></td>
                <td></td>
                <td></td>
            </tr>
            <!-- NAME ROW -->
            <tr style="font-size: 8px; text-align: left;" class="bg-gray">
                <td>NAME : <span class="font-bold"><?= htmlspecialchars($session['inspector_name'] ?? $session['did_pic'] ?? '') ?></span></td>
                <td>NAME : <span class="font-bold" id="txt-chief-name"><?= $isApproved ? htmlspecialchars($chiefName) : '' ?></span></td>
                <td>NAME :</td>
                <td>NAME :</td>
                <td>NAME :</td>
            </tr>
            <!-- DATE ROW -->
            <tr style="font-size: 8px; text-align: left;" class="bg-gray">
                <td>DATE : <span class="font-bold"><?= date('d/m/Y', strtotime($session['started_at'])) ?></span></td>
                <td>DATE : <span class="font-bold" id="txt-chief-date"><?= $isApproved ? $chiefAppDate : '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;/&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;/' ?></span></td>
                <td>DATE : &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;/&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;/</td>
                <td>DATE : &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;/&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;/</td>
                <td>DATE : &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;/&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;/</td>
            </tr>
            <!-- TIME ROW -->
            <tr style="font-size: 8px; text-align: left;" class="bg-gray">
                <td>TIME : <span class="font-bold"><?= date('H:i', strtotime($session['closed_at'] ?: $session['started_at'])) ?> WIB</span></td>
                <td>TIME : <span class="font-bold" id="txt-chief-time"><?= $isApproved ? $chiefAppTime : '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;:' ?></span></td>
                <td>TIME : &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;:</td>
                <td>TIME : &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;:</td>
                <td>TIME : &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;:</td>
            </tr>
        </table>

        <!-- DOCUMENT REVISION FOOTER CODE -->
        <div style="display: flex; align-items: center; justify-content: space-between; font-size: 8.5px; font-weight: bold; margin-top: 3px; color: #333;">
            <span>Doc Ref: OQC-REJ-<?= str_pad($session['id'], 6, '0', STR_PAD_LEFT) ?></span>
            <span>STQC-F-167 REV.00</span>
        </div>

    </div>

    <!-- SweetAlert2 for Interactive Approval Modal -->
    <script src="<?= base_url('assets/js/vendor/sweetalert2.all.min.js?v=' . time()) ?>"></script>
    <script src="<?= base_url('assets/js/vendor/html2pdf.bundle.min.js') ?>"></script>
    <script>
        var PDF_FILENAME = 'Rejection_Sheet_STQC-F-167_<?= htmlspecialchars($session['part_code']) ?>.pdf';
        var CURRENT_SESSION_ID = <?= (int)$session['id'] ?>;
        var CURRENT_SESSION_LOT_ID = <?= (int)$sessionLotId ?>;
        var DEFAULT_CHIEF_NAME = '<?= addslashes($chiefName) ?>';
        var CHIEF_USERS = <?= json_encode($chiefUsersList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function setBtnLoading(btn, isLoading) {
            if (!btn) return;
            if (isLoading) {
                btn.dataset.origText = btn.innerHTML;
                btn.innerHTML = 'Memproses...';
                btn.disabled = true;
            } else {
                btn.innerHTML = btn.dataset.origText || btn.innerHTML;
                btn.disabled = false;
            }
        }

        /**
         * Trigger Chief QC Approval via Modal (User Selection + Password Verification)
         */
        function triggerChiefApprove() {
            var userOptionsHtml = '<option value="">-- Pilih Akun Chief QC --</option>';
            if (CHIEF_USERS && CHIEF_USERS.length > 0) {
                CHIEF_USERS.forEach(function(u) {
                    var roleLabel = u.role ? (' (' + u.role.toUpperCase() + ')') : '';
                    userOptionsHtml += '<option value="' + u.id + '">' + escapeHtml(u.name) + roleLabel + '</option>';
                });
            }

            var capturedUserId = '';

            Swal.fire({
                title: 'Persetujuan (ACC) Chief QC',
                html: '<div style="font-size: 13px; text-align: left; line-height: 1.5; color: #334155;">' +
                      '<p style="margin-bottom: 12px;">Konfirmasi persetujuan lembar penolakan (Rejection Sheet) untuk Part <b style="color: #1d4ed8; font-family: monospace;"><?= htmlspecialchars($session['part_code']) ?></b> (Lot #<?= htmlspecialchars($session['lot_number']) ?>):</p>' +
                      '<div style="margin-bottom: 10px;">' +
                      '  <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Pilih Akun Chief QC:</label>' +
                      '  <select id="swal-chief-user-id" style="width: 100%; box-sizing: border-box; font-size: 13px; font-weight: 600; padding: 8px 10px; border: 1.5px solid #cbd5e1; border-radius: 6px; background-color: #fff; outline: none;">' +
                         userOptionsHtml +
                      '  </select>' +
                      '</div>' +
                      '<p style="font-size: 11px; color: #64748b; margin-top: 8px; line-height: 1.4;">Pilih nama Chief QC yang bertugas untuk menerbitkan stempel resmi digital.</p>' +
                      '</div>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#16a34a',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Verifikasi & ACC',
                cancelButtonText: 'Batal',
                focusConfirm: false,
                preConfirm: function() {
                    var selectEl = document.getElementById('swal-chief-user-id');
                    var userId = selectEl ? selectEl.value : '';

                    if (!userId) {
                        Swal.showValidationMessage('Silakan pilih akun Chief QC terlebih dahulu!');
                        return false;
                    }
                    capturedUserId = userId;
                    return { chief_user_id: userId };
                }
            }).then(function(result) {
                if (!result || !result.isConfirmed) return;

                var finalUserId = (result.value && result.value.chief_user_id) ? result.value.chief_user_id : capturedUserId;

                if (!finalUserId) {
                    Swal.fire({ icon: 'error', title: 'Data Kurang', text: 'Silakan pilih akun Chief QC terlebih dahulu!' });
                    return;
                }

                Swal.fire({
                    title: 'Menyimpan ACC...',
                    allowOutsideClick: false,
                    didOpen: function() { Swal.showLoading(); }
                });

                var payload = new FormData();
                payload.append('session_id', CURRENT_SESSION_ID);
                if (CURRENT_SESSION_LOT_ID > 0) {
                    payload.append('session_lot_id', CURRENT_SESSION_LOT_ID);
                }
                payload.append('action', 'approve');
                payload.append('chief_user_id', finalUserId);

                fetch('<?= base_url("modules/inspection/api/chief_approve.php") ?>', {
                    method: 'POST',
                    body: payload
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (!data.success) {
                        Swal.fire({ icon: 'error', title: 'Verifikasi Gagal', text: data.message || 'Terjadi kesalahan sistem' });
                        return;
                    }

                    Swal.fire({
                        icon: 'success',
                        title: 'Disetujui Chief QC',
                        text: 'ACC berhasil diverifikasi! Stempel digital & QR verifikasi telah diterbitkan.',
                        timer: 1400,
                        showConfirmButton: false
                    }).then(function() {
                        window.location.reload();
                    });
                })
                .catch(function(err) {
                    console.error(err);
                    Swal.fire({ icon: 'error', title: 'Koneksi Error', text: 'Gagal menghubungi server' });
                });
            });
        }

        /**
         * Rollback / Unapprove Chief QC
         */
        function triggerChiefUnapprove() {
            Swal.fire({
                title: 'Batalkan ACC Chief QC?',
                text: 'Format cetak lembar penolakan akan dikembalikan ke tanda tangan basah manual (stempel & QR digital akan dihapus).',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Ya, Batalkan ACC',
                cancelButtonText: 'Batal'
            }).then(function(res) {
                if (!res.isConfirmed) return;

                Swal.fire({
                    title: 'Membatalkan...',
                    allowOutsideClick: false,
                    didOpen: function() { Swal.showLoading(); }
                });

                var payload = new FormData();
                payload.append('session_id', CURRENT_SESSION_ID);
                if (CURRENT_SESSION_LOT_ID > 0) {
                    payload.append('session_lot_id', CURRENT_SESSION_LOT_ID);
                }
                payload.append('action', 'unapprove');

                fetch('<?= base_url("modules/inspection/api/chief_approve.php") ?>', {
                    method: 'POST',
                    body: payload
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) {
                        Swal.fire({ icon: 'error', title: 'Gagal', text: data.message });
                        return;
                    }

                    Swal.fire({
                        icon: 'info',
                        title: 'ACC Dibatalkan',
                        text: 'Format cetak kembali ke tanda tangan basah manual.',
                        timer: 1400,
                        showConfirmButton: false
                    }).then(function() {
                        window.location.reload();
                    });
                })
                .catch(function() {
                    Swal.fire({ icon: 'error', title: 'Koneksi Error', text: 'Gagal menghubungi server' });
                });
            });
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // Hitung skala yang dibutuhkan agar konten pas di A4 (Portrait)
        // lalu inject @media print CSS secara dinamis.
        function injectFullA4PrintCSS() {
            var el = document.querySelector('.sheet-container');
            var contentW = el.scrollWidth  || el.offsetWidth;
            var contentH = el.scrollHeight || el.offsetHeight;

            // A4 Portrait pada 96 dpi: 794px x 1123px  (210mm x 297mm)
            var A4_W = 794, A4_H = 1123;
            var sx = (A4_W / contentW).toFixed(5);
            var sy = (A4_H / contentH).toFixed(5);

            var id = 'dynamic-a4-print-css';
            var old = document.getElementById(id);
            if (old) old.remove();

            var style = document.createElement('style');
            style.id   = id;
            style.textContent =
                '@media print {' +
                '  @page { size: A4 portrait; margin: 0 !important; }' +
                '  html, body {' +
                '    width: 210mm !important; height: 297mm !important;' +
                '    margin: 0 !important; padding: 0 !important;' +
                '    background: #ffffff !important; overflow: hidden !important;' +
                '  }' +
                '  .no-print-bar { display: none !important; }' +
                '  .sheet-container {' +
                '    position: fixed !important;' +
                '    top: 0 !important; left: 0 !important;' +
                '    margin: 0 !important;' +
                '    width: ' + contentW + 'px !important;' +
                '    transform: scale(' + sx + ', ' + sy + ') !important;' +
                '    transform-origin: top left !important;' +
                '  }' +
                '}';
            document.head.appendChild(style);
            return style;
        }

        // Cetak / Simpan PDF — native browser print dialog (Full A4)
        function printCleanPDF(btn) {
            setBtnLoading(btn, true);
            var style = injectFullA4PrintCSS();
            setTimeout(function() {
                window.print();
                setTimeout(function() {
                    style.remove();
                    setBtnLoading(btn, false);
                }, 3500);
            }, 150);
        }

        // Download PDF — sama dengan print (pilih "Save as PDF" di dialog)
        function downloadPDF(btn) {
            printCleanPDF(btn);
        }

        <?php if ($isAutoPrint): ?>
        window.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() { printCleanPDF(null); }, 600);
        });
        <?php endif; ?>
    </script>
</body>
</html>
