<?php
/**
 * Native PHP XLSX Parser for DID Import
 * Zero dependencies — uses ZipArchive + SimpleXML only.
 *
 * Returns:
 *   [ 'YYYY-MM-DD' => [ ['part_code'=>..., 'part_name'=>..., ...], ... ], ... ]
 */

/**
 * Main entry point. Returns array keyed by inspecting_date (Y-m-d).
 */
function xlsx_parse_did(string $filePath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive extension is required. Enable it in php.ini.');
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('Cannot open XLSX file. Pastikan file tidak corrupt.');
    }

    // ── 1. Shared Strings Table ──────────────────────────────────────────────
    $strings = [];
    $sstRaw = $zip->getFromName('xl/sharedStrings.xml');
    if ($sstRaw) {
        $sst = @simplexml_load_string($sstRaw);
        if ($sst) {
            foreach ($sst->si as $si) {
                // Collect all <t> text, including rich-text runs
                $parts = [];
                foreach ($si->xpath('.//*[local-name()="t"]') as $t) {
                    $parts[] = (string)$t;
                }
                $strings[] = implode('', $parts);
            }
        }
    }

    // ── 2. Sheet list from workbook.xml ──────────────────────────────────────
    $wbRaw = $zip->getFromName('xl/workbook.xml');
    if (!$wbRaw) {
        $zip->close();
        throw new RuntimeException('Invalid XLSX: missing xl/workbook.xml');
    }
    $wb = @simplexml_load_string($wbRaw);
    $wb->registerXPathNamespace('ns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $wb->registerXPathNamespace('r',  'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

    $sheetList = [];
    foreach ($wb->xpath('//ns:sheet') as $sh) {
        $name = trim((string)$sh['name']);
        $rid  = (string)$sh->attributes(
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
        )['id'];
        $sheetList[] = ['name' => $name, 'rid' => $rid];
    }

    // ── 3. rId → worksheet file path (xl/_rels/workbook.xml.rels) ────────────
    $relsRaw = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $ridToPath = [];
    if ($relsRaw) {
        $rels = @simplexml_load_string($relsRaw);
        if ($rels) {
            foreach ($rels->Relationship as $rel) {
                $rid    = (string)$rel['Id'];
                $target = (string)$rel['Target'];
                // Target can be relative like "worksheets/sheet1.xml"
                $ridToPath[$rid] = (strpos($target, 'xl/') === 0) ? $target : 'xl/' . $target;
            }
        }
    }

    // ── 4. Parse each sheet ───────────────────────────────────────────────────
    $result = [];

    foreach ($sheetList as $info) {
        $tabName       = trim($info['name']);
        $inspectingDate = _parse_tab_date($tabName);

        if (!$inspectingDate) {
            continue; // skip Sheet2, cover sheets, etc.
        }

        $wsPath = $ridToPath[$info['rid']] ?? null;
        if (!$wsPath) continue;

        $wsRaw = $zip->getFromName($wsPath);
        if (!$wsRaw) continue;

        $ws = @simplexml_load_string($wsRaw);
        if (!$ws) continue;

        $rows = [];

        foreach ($ws->xpath('//*[local-name()="row"]') as $row) {
            $rowNum = (int)$row['r'];
            if ($rowNum < 6) continue; // rows 1–5 = header / title

            // Map cells by column letter
            $cells = [];
            foreach ($row->xpath('*[local-name()="c"]') as $cell) {
                $ref  = (string)$cell['r'];                        // e.g. "B6"
                $col  = rtrim($ref, '0123456789');                 // "B"
                $type = (string)$cell['t'];
                $vArr = $cell->xpath('*[local-name()="v"]');
                $val  = !empty($vArr) ? (string)$vArr[0] : '';

                if ($type === 's' && $val !== '') {
                    $val = $strings[(int)$val] ?? '';
                }
                $cells[$col] = $val;
            }

            // Column mapping per did.xlsx format
            // A=No  B=PartCode  C=PartName  D=LotNo  E=Cav  F=Date(serial)  G=Status  H=PIC  I=Remark
            $partCode = strtoupper(trim($cells['B'] ?? ''));
            $partName = trim($cells['C'] ?? '');
            $lotNumber = strtoupper(trim($cells['D'] ?? ''));
            $cavity    = trim($cells['E'] ?? '') ?: '1';
            $dateRaw   = trim($cells['F'] ?? '');
            $statusRaw = strtoupper(trim($cells['G'] ?? ''));
            $pic       = trim($cells['H'] ?? '');
            $remark    = trim($cells['I'] ?? '');

            if (empty($partCode)) continue; // skip fully empty rows

            // Normalize status: anything containing "NG" → NG, else → OK
            $status = (strpos($statusRaw, 'NG') !== false) ? 'NG' : 'OK';

            // Prefer date from tab name (reliable); fall back to Excel serial
            $rowDate = $inspectingDate;
            if (!empty($dateRaw) && is_numeric($dateRaw) && (float)$dateRaw > 1000) {
                $converted = _excel_serial_to_date((float)$dateRaw);
                if ($converted) $rowDate = $converted;
            }

            $rows[] = [
                'part_code'       => $partCode,
                'part_name'       => $partName ?: $partCode,
                'lot_number'      => $lotNumber,
                'cavity'          => $cavity,
                'inspecting_date' => $rowDate,
                'status_inspect'  => $status,
                'pic'             => $pic ?: 'Import QC',
                'remark'          => $remark,
            ];
        }

        if (!empty($rows)) {
            $result[$inspectingDate] = $rows;
        }
    }

    $zip->close();

    // Sort by date ascending
    ksort($result);
    return $result;
}

/**
 * Returns list of all date-named sheets and their row counts.
 * Used by the preview AJAX endpoint.
 */
function xlsx_preview_sheets(string $filePath): array
{
    if (!class_exists('ZipArchive')) return [];

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return [];

    $strings = [];
    $sstRaw = $zip->getFromName('xl/sharedStrings.xml');
    if ($sstRaw) {
        $sst = @simplexml_load_string($sstRaw);
        if ($sst) {
            foreach ($sst->si as $si) {
                $parts = [];
                foreach ($si->xpath('.//*[local-name()="t"]') as $t) {
                    $parts[] = (string)$t;
                }
                $strings[] = implode('', $parts);
            }
        }
    }

    $wbRaw = $zip->getFromName('xl/workbook.xml');
    if (!$wbRaw) { $zip->close(); return []; }
    $wb = @simplexml_load_string($wbRaw);

    $relsRaw = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $ridToPath = [];
    if ($relsRaw) {
        $rels = @simplexml_load_string($relsRaw);
        if ($rels) {
            foreach ($rels->Relationship as $rel) {
                $rid    = (string)$rel['Id'];
                $target = (string)$rel['Target'];
                $ridToPath[$rid] = (strpos($target, 'xl/') === 0) ? $target : 'xl/' . $target;
            }
        }
    }

    $preview = [];
    $wb->registerXPathNamespace('ns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    foreach ($wb->xpath('//ns:sheet') as $sh) {
        $name = trim((string)$sh['name']);
        $rid  = (string)$sh->attributes(
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
        )['id'];
        $date = _parse_tab_date($name);
        if (!$date) continue;

        $wsPath = $ridToPath[$rid] ?? null;
        $rowCount = 0;
        if ($wsPath) {
            $wsRaw = $zip->getFromName($wsPath);
            if ($wsRaw) {
                $ws = @simplexml_load_string($wsRaw);
                if ($ws) {
                    foreach ($ws->xpath('//*[local-name()="row"]') as $row) {
                        if ((int)$row['r'] >= 6) {
                            // Count rows with B column data
                            $bArr = $row->xpath('*[local-name()="c"][starts-with(@r,"B")]');
                            if (!empty($bArr)) {
                                $vArr = $bArr[0]->xpath('*[local-name()="v"]');
                                if (!empty($vArr)) $rowCount++;
                            }
                        }
                    }
                }
            }
        }
        $preview[] = ['tab' => $name, 'date' => $date, 'rows' => $rowCount];
    }

    $zip->close();
    return $preview;
}

/**
 * Parse tab name like "01-08-2026" or "01/08/2026" → "2026-08-01"
 */
function _parse_tab_date(string $name): ?string
{
    $name = trim($name);
    // DD-MM-YYYY or D-M-YYYY
    if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $name, $m)) {
        $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
        if ($y >= 2000 && $y <= 2100 && checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }
    return null;
}

/**
 * Convert Excel date serial to Y-m-d string.
 * Excel epoch: Jan 1 1900 = serial 1 (with 1900 leap year bug offset).
 */
function _excel_serial_to_date(float $serial): ?string
{
    if ($serial < 1) return null;
    // 25569 = days from 1900-01-01 to 1970-01-01 (adjusted for Excel bug)
    $unix = (int)(($serial - 25569) * 86400);
    $date = @date('Y-m-d', $unix);
    return ($date && $date !== '1970-01-01') ? $date : null;
}
