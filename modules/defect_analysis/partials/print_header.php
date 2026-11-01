        <!-- ── KOP RESMI LAPORAN CETAK (PRINT-ONLY HEADER BANNER) ────────────────── -->
        <div id="printOfficialReportHeader" class="print-only" style="margin-bottom:14px;border-bottom:2px solid #0f172a;padding-bottom:10px;">
            <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #cbd5e1;padding-bottom:8px;margin-bottom:8px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:36px;height:36px;background:#2563eb;color:#ffffff;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:14px;">
                        ST
                    </div>
                    <div>
                        <h1 style="margin:0;font-size:16px;font-weight:900;color:#0f172a;letter-spacing:-0.3px;">
                            PT. SURYA TECHNOLOGY INDUSTRI
                        </h1>
                        <span style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">
                            Outgoing Quality Control (OQC) Division &mdash; Official Report
                        </span>
                    </div>
                </div>
                <div style="text-align:right;">
                    <span style="display:block;font-size:9px;color:#64748b;font-weight:600;">Waktu Cetak:</span>
                    <span id="printHeaderTimestamp" style="font-size:11px;font-weight:800;color:#0f172a;">-</span>
                </div>
            </div>
            
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                    <div>
                        <h2 id="printReportTitle" style="margin:0;font-size:14px;font-weight:900;color:#1e40af;text-transform:uppercase;">
                            LAPORAN ANALISIS &amp; TREN
                        </h2>
                        <div style="display:flex;align-items:center;gap:8px;margin-top:3px;">
                            <span style="font-size:10px;font-weight:700;color:#475569;">Target Analisis:</span>
                            <span id="printReportTargetBadge" style="display:inline-block;padding:2px 8px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:5px;font-size:11px;font-weight:800;">
                                -
                            </span>
                        </div>
                    </div>

                    <!-- Top 3 Ranking Defect / Part di Kop Cetak (Menjejer) -->
                    <div id="printReportTop3Defects" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <!-- Dinamis di-render via renderTop3SummaryPills -->
                    </div>
                </div>

                <div style="display:flex;align-items:center;gap:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:4px 12px;font-size:10px;">
                    <div>
                        <span style="color:#64748b;font-weight:600;">Periode:</span>
                        <strong id="printReportPeriod" style="color:#0f172a;margin-left:3px;">-</strong>
                    </div>
                    <div>
                        <span style="color:#64748b;font-weight:600;">Customer:</span>
                        <strong id="printReportCustomer" style="color:#0f172a;margin-left:3px;">Semua</strong>
                    </div>
                </div>
            </div>
        </div>

