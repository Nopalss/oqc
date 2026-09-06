<?php
/**
 * Official Printable Rejection Sheet Document (Form STQC-F-167 REV.00)
 * 100% Pixel-Perfect Matching Company Spreadsheet Format: Reject Sheet QC.xlsx
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
session_write_close();

$sessionId = (int)($_GET['session_id'] ?? $_GET['id'] ?? 0);
$isAutoPrint = isset($_GET['autoprint']) && $_GET['autoprint'] == 1;

$pdo = getDB();
$session = null;
$ngRecords = [];

if ($sessionId > 0 && $pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, 
                   did.part_code, did.part_name, did.lot_number, did.cavity, did.pic as did_pic,
                   k.kanban_no, k.customer, k.qty as kanban_qty, b.document_number as doc_no,
                   COALESCE(m.name, p.model) as model, u.name as inspector_name
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
            WHERE s.id = :id
        ");
        $stmt->execute([':id' => $sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($session) {
            $stmtNg = $pdo->prepare("
                SELECT n.*, d.name as defect_name, sp.sample_number
                FROM inspection_ng_records n
                JOIN inspection_samples sp ON sp.id = n.inspection_sample_id
                JOIN defect_types d ON d.id = n.defect_type_id
                WHERE sp.inspection_session_id = :sid
                ORDER BY n.id ASC
            ");
            $stmtNg->execute([':sid' => $sessionId]);
            $ngRecords = $stmtNg->fetchAll(PDO::FETCH_ASSOC);

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
    $defectSummaryList[] = $rec['defect_name'] . ' (Qty ' . $rec['qty_ng'] . ')';
}
$defectProblemText = !empty($defectSummaryList) ? implode(', ', array_unique($defectSummaryList)) : 'Visual / Dimension Defect';
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
        <div style="display: flex; gap: 8px;">
            <button onclick="downloadPDF(this)" class="btn-action" style="background: #0284c7; color: #ffffff;">
                📥 Download PDF
            </button>
            <button onclick="printCleanPDF(this)" class="btn-action btn-print">
                🖨️ Cetak PDF (Full A4)
            </button>
        </div>
    </div>

    <!-- Official Rejection Sheet Container -->
    <div class="sheet-container">

        <!-- HEADER TABLE -->
        <table class="tbl-grid">
            <tr>
                <td style="width: 35%; font-weight: bold; font-size: 13px; border-bottom: 2px solid #000;">
                    PT. SURYA TECHNOLOGY INDUSTRI
                    <div style="font-size: 10px; font-weight: bold; color: #333;">QUALITY CONTROL</div>
                </td>
                <td class="text-center" style="width: 30%; font-weight: 900; font-size: 14px; border-bottom: 2px solid #000;">
                    REJECT INFORMATION SHEET
                </td>
                <td style="width: 35%; font-size: 8.5px; border-bottom: 2px solid #000;">
                    <div style="display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-end;">
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
                            <td class="font-mono font-black" style="color: #dc2626; font-size: 12px; font-weight: 900;"><?= number_format($session['ng_count']) ?> Pcs (Reject Limit: <?= $session['reject_number'] ?>)</td>
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
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
            </tr>
            <!-- NAME ROW -->
            <tr style="font-size: 8px; text-align: left;" class="bg-gray">
                <td>NAME : <span class="font-bold"><?= htmlspecialchars($session['inspector_name'] ?? $session['did_pic'] ?? '') ?></span></td>
                <td>NAME :</td>
                <td>NAME :</td>
                <td>NAME :</td>
                <td>NAME :</td>
            </tr>
            <!-- DATE ROW -->
            <tr style="font-size: 8px; text-align: left;" class="bg-gray">
                <td>DATE : <span class="font-bold"><?= date('d/m/Y', strtotime($session['started_at'])) ?></span></td>
                <td>DATE : &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;/&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;/</td>
                <td>DATE : &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;/&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;/</td>
                <td>DATE : &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;/&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;/</td>
                <td>DATE : &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;/&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;/</td>
            </tr>
            <!-- TIME ROW -->
            <tr style="font-size: 8px; text-align: left;" class="bg-gray">
                <td>TIME : <span class="font-bold"><?= date('H:i', strtotime($session['closed_at'] ?: $session['started_at'])) ?> WIB</span></td>
                <td>TIME : &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;:</td>
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

    <script src="<?= base_url('assets/js/vendor/html2pdf.bundle.min.js') ?>"></script>
    <script>
        var PDF_FILENAME = 'Rejection_Sheet_STQC-F-167_<?= htmlspecialchars($session['part_code']) ?>.pdf';

        // ─────────────────────────────────────────────────────────────────
        //  PENDEKATAN: Browser Native Print (bukan html2canvas)
        //  html2canvas tidak support flexbox + table-layout:fixed dengan
        //  benar, menyebabkan kolom kiri kepotong secara konsisten.
        //
        //  Solusi: inject @media print CSS dengan transform:scale() agar
        //  konten mengisi penuh kertas A4. Browser render 100% akurat.
        // ─────────────────────────────────────────────────────────────────

        function setBtnLoading(btn, isLoading) {
            if (!btn) return;
            if (isLoading) {
                btn.dataset.origText = btn.innerHTML;
                btn.innerHTML = '⏳ Memproses...';
                btn.disabled = true;
            } else {
                btn.innerHTML = btn.dataset.origText || btn.innerHTML;
                btn.disabled = false;
            }
        }

        // Hitung skala yang dibutuhkan agar konten pas di A4 (Portrait)
        // lalu inject @media print CSS secara dinamis.
        function injectFullA4PrintCSS() {
            var el = document.querySelector('.sheet-container');
            var contentW = el.scrollWidth  || el.offsetWidth;
            var contentH = el.scrollHeight || el.offsetHeight;

            // A4 Portrait pada 96 dpi: 794px × 1123px  (210mm × 297mm)
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
                // Hapus style setelah dialog print ditutup
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
