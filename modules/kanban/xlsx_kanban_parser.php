<?php
/**
 * Native PHP XLSX Parser for Kanban (FR-1)
 * Zero external dependencies (uses ZipArchive & SimpleXML)
 */

function xlsx_parse_kanban($filepath) {
    if (!file_exists($filepath)) {
        return ['success' => false, 'message' => 'File tidak ditemukan!'];
    }

    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        return ['success' => false, 'message' => 'Gagal membuka file Excel (.xlsx)!'];
    }

    // 1. Read Workbook & Sheet Name Mapping
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml     = $zip->getFromName('xl/_rels/workbook.xml.rels');
    
    if (!$workbookXml || !$relsXml) {
        $zip->close();
        return ['success' => false, 'message' => 'Format internal workbook Excel tidak valid!'];
    }

    $xmlWB   = simplexml_load_string($workbookXml);
    $xmlRels = simplexml_load_string($relsXml);
    
    $relMap = [];
    foreach ($xmlRels->Relationship as $rel) {
        $relMap[(string)$rel['Id']] = (string)$rel['Target'];
    }

    $sheets = [];
    $targetSheetPath = null;
    $targetSheetName = null;

    foreach ($xmlWB->sheets->sheet as $s) {
        $name = (string)$s['name'];
        $rId  = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $target = $relMap[$rId] ?? '';
        $fullPath = 'xl/' . ltrim($target, '/');
        
        $sheets[] = [
            'name' => $name,
            'path' => $fullPath
        ];

        // Prefer sheet named 'kanban' (case-insensitive)
        if (strtolower(trim($name)) === 'kanban') {
            $targetSheetPath = $fullPath;
            $targetSheetName = $name;
        }
    }

    // If no sheet specifically named 'kanban', use the first sheet
    if (!$targetSheetPath && !empty($sheets)) {
        $targetSheetPath = $sheets[0]['path'];
        $targetSheetName = $sheets[0]['name'];
    }

    // 2. Read Shared Strings Table
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $xmlSS = simplexml_load_string($ssXml);
        foreach ($xmlSS->si as $val) {
            if (isset($val->t)) {
                $sharedStrings[] = (string)$val->t;
            } elseif (isset($val->r)) {
                $t = '';
                foreach ($val->r as $run) {
                    $t .= (string)$run->t;
                }
                $sharedStrings[] = $t;
            } else {
                $sharedStrings[] = '';
            }
        }
    }

    // 3. Read & Parse Sheet Content
    $sheetXml = $zip->getFromName($targetSheetPath);
    $zip->close();

    if (!$sheetXml) {
        return ['success' => false, 'message' => "Gagal membaca worksheet $targetSheetName!"];
    }

    $xmlSheet = simplexml_load_string($sheetXml);
    
    // Convert XML rows to raw matrix
    $rawMatrix = [];
    foreach ($xmlSheet->sheetData->row as $row) {
        $rNum = (int)$row['r'];
        $rowCells = [];
        
        foreach ($row->c as $c) {
            $colRef = (string)$c['r']; // e.g. A5, B5, J10
            preg_match('/^([A-Z]+)/', $colRef, $m);
            $colLetters = $m[1] ?? 'A';
            $colIndex   = colLetterToIndex($colLetters);

            $type = (string)$c['t'];
            $val  = (string)$c->v;

            if ($type === 's' && is_numeric($val) && isset($sharedStrings[(int)$val])) {
                $cellVal = $sharedStrings[(int)$val];
            } else {
                $cellVal = $val;
            }

            // Clean non-breaking spaces \xC2\xA0 and trim
            $cellVal = trim(str_replace(["\xC2\xA0", "\xA0"], ' ', $cellVal));
            $rowCells[$colIndex] = $cellVal;
        }

        if (!empty($rowCells)) {
            $rawMatrix[$rNum] = $rowCells;
        }
    }

    // 4. Smart Header Detection
    $headerRowIndex = null;
    $colMap = [
        'kanban_no'        => null,
        'item_code'        => null,
        'item_description' => null,
        'req_date'         => null,
        'qty'              => null,
        'eta'              => null,
        'str_loc'          => null,
        'check_type'       => null,
        'remark'           => null,
    ];

    foreach ($rawMatrix as $rNum => $cells) {
        $rowStr = strtolower(implode(' ', $cells));
        if (strpos($rowStr, 'kanban') !== false || strpos($rowStr, 'item code') !== false || strpos($rowStr, 'part no') !== false) {
            $headerRowIndex = $rNum;
            foreach ($cells as $colIdx => $val) {
                $vLower = strtolower($val);
                if (strpos($vLower, 'kanban') !== false && $colMap['kanban_no'] === null) {
                    $colMap['kanban_no'] = $colIdx;
                } elseif ((strpos($vLower, 'item code') !== false || strpos($vLower, 'part no') !== false) && $colMap['item_code'] === null) {
                    $colMap['item_code'] = $colIdx;
                } elseif ((strpos($vLower, 'description') !== false || strpos($vLower, 'item desc') !== false) && $colMap['item_description'] === null) {
                    $colMap['item_description'] = $colIdx;
                } elseif (strpos($vLower, 'req') !== false && strpos($vLower, 'date') !== false && $colMap['req_date'] === null) {
                    $colMap['req_date'] = $colIdx;
                } elseif ((strpos($vLower, 'qty') !== false || strpos($vLower, 'quantity') !== false) && $colMap['qty'] === null) {
                    $colMap['qty'] = $colIdx;
                } elseif (strpos($vLower, 'eta') !== false && $colMap['eta'] === null) {
                    $colMap['eta'] = $colIdx;
                } elseif ((strpos($vLower, 'str.loc') !== false || strpos($vLower, 'str loc') !== false || strpos($vLower, 'location') !== false) && $colMap['str_loc'] === null) {
                    $colMap['str_loc'] = $colIdx;
                } elseif (($vLower === 'cek' || strpos($vLower, 'check') !== false) && $colMap['check_type'] === null) {
                    $colMap['check_type'] = $colIdx;
                } elseif ((strpos($vLower, 'remark') !== false || strpos($vLower, 'catatan') !== false) && $colMap['remark'] === null) {
                    $colMap['remark'] = $colIdx;
                }
            }
            break;
        }
    }

    // Fallback column positions if header not found
    if ($headerRowIndex === null) {
        $headerRowIndex = 1;
        $colMap = [
            'kanban_no'        => 1,
            'item_code'        => 2,
            'item_description' => 3,
            'req_date'         => 4,
            'qty'              => 5,
            'eta'              => 6,
            'str_loc'          => 7,
            'check_type'       => 10,
            'remark'           => 9
        ];
    }

    // 5. Extract Valid Data Rows
    $parsedRows = [];
    $skippedCount = 0;

    foreach ($rawMatrix as $rNum => $cells) {
        if ($rNum <= $headerRowIndex) continue;

        $kanbanNo = isset($colMap['kanban_no']) ? ($cells[$colMap['kanban_no']] ?? '') : '';
        $kanbanNo = trim(preg_replace('/\s+/', ' ', $kanbanNo));

        // Skip rows without Kanban No (or non-digit / subtotal rows like row 7, 9)
        if (empty($kanbanNo) || $kanbanNo === '0') {
            $skippedCount++;
            continue;
        }

        $itemCode = isset($colMap['item_code']) ? ($cells[$colMap['item_code']] ?? '') : '';
        $itemDesc = isset($colMap['item_description']) ? ($cells[$colMap['item_description']] ?? '') : '';
        $reqDate  = isset($colMap['req_date']) ? ($cells[$colMap['req_date']] ?? '') : '';
        $qty      = isset($colMap['qty']) ? (int)($cells[$colMap['qty']] ?? 0) : 0;
        $eta      = isset($colMap['eta']) ? ($cells[$colMap['eta']] ?? '') : '';
        $strLoc   = isset($colMap['str_loc']) ? ($cells[$colMap['str_loc']] ?? '') : '';
        $rawCheck = isset($colMap['check_type']) ? ($cells[$colMap['check_type']] ?? '') : '';
        $remark   = isset($colMap['remark']) ? ($cells[$colMap['remark']] ?? '') : '';

        // Clean check_type string (#N/A or empty -> '')
        $cleanCheck = trim($rawCheck);
        if ($cleanCheck === '#N/A' || $cleanCheck === 'N/A' || $cleanCheck === '#VALUE!' || $cleanCheck === '0') {
            $checkType = '';
        } else {
            $checkType = $cleanCheck;
        }

        // Format dates if Excel serial number or clean string
        $formattedReqDate = format_excel_date_kanban($reqDate);
        $formattedEta     = format_excel_date_kanban($eta);

        $parsedRows[] = [
            'row_num'          => $rNum,
            'kanban_no'        => strtoupper($kanbanNo),
            'item_code'        => strtoupper(trim($itemCode)),
            'item_description' => trim($itemDesc),
            'req_date'         => $formattedReqDate ?: date('Y-m-d'),
            'qty'              => ($qty > 0) ? $qty : 1,
            'eta'              => $formattedEta ?: date('Y-m-d'),
            'str_loc'          => trim($strLoc) ?: 'WH-A01',
            'check_type'       => $checkType,
            'remark'           => trim($remark)
        ];
    }

    return [
        'success'      => true,
        'sheet_name'   => $targetSheetName,
        'sheets'       => array_column($sheets, 'name'),
        'total_parsed' => count($parsedRows),
        'total_skipped'=> $skippedCount,
        'rows'         => $parsedRows
    ];
}

// Convert Excel column letters (A, B, Z, AA) to 0-based index
function colLetterToIndex($col) {
    $col = strtoupper($col);
    $len = strlen($col);
    $index = 0;
    for ($i = 0; $i < $len; $i++) {
        $index = $index * 26 + (ord($col[$i]) - ord('A') + 1);
    }
    return $index - 1;
}

// Format Excel Date (Supports string '2026/08/06 07:24:22' or Excel timestamp serials with time)
function format_excel_date_kanban($dateVal) {
    if (empty($dateVal)) return null;

    $dateVal = str_replace('/', '-', trim($dateVal));

    // Check if numeric serial date (e.g. 46241.308)
    if (is_numeric($dateVal)) {
        $timestamp = ($dateVal - 25569) * 86400;
        return date('Y-m-d H:i:s', $timestamp);
    }

    $timestamp = strtotime($dateVal);
    if ($timestamp !== false && $timestamp > 0) {
        return date('Y-m-d H:i:s', $timestamp);
    }

    return null;
}
