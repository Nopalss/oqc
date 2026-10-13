<?php
/**
 * Native PHP XLSX Parser for Kanban (FR-1)
 * High-Speed Multi-Sheet Parser with zero external dependencies (uses ZipArchive & SimpleXML)
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

    $xmlWB   = @simplexml_load_string($workbookXml);
    $xmlRels = @simplexml_load_string($relsXml);
    
    if (!$xmlWB || !$xmlRels) {
        $zip->close();
        return ['success' => false, 'message' => 'Gagal mengurai struktur XML workbook!'];
    }

    $relMap = [];
    foreach ($xmlRels->Relationship as $rel) {
        $relMap[(string)$rel['Id']] = (string)$rel['Target'];
    }

    $sheets = [];
    foreach ($xmlWB->sheets->sheet as $s) {
        $name = (string)$s['name'];
        $rId  = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $target = $relMap[$rId] ?? '';
        $fullPath = (strpos($target, 'xl/') === 0) ? $target : 'xl/' . ltrim($target, '/');
        
        $sheets[] = [
            'name' => $name,
            'path' => $fullPath
        ];
    }

    if (empty($sheets)) {
        $zip->close();
        return ['success' => false, 'message' => 'Tidak ada worksheet yang ditemukan di file Excel!'];
    }

    // 2. Read Shared Strings Table ONCE for all sheets (RAM Memory Map)
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $xmlSS = @simplexml_load_string($ssXml);
        if ($xmlSS) {
            foreach ($xmlSS->si as $val) {
                if (isset($val->t)) {
                    $rawText = (string)$val->t;
                } elseif (isset($val->r)) {
                    $t = '';
                    foreach ($val->r as $run) {
                        $t .= (string)$run->t;
                    }
                    $rawText = $t;
                } else {
                    $rawText = '';
                }
                $cleanText = str_replace(["\xC2\xA0", "\xA0"], ' ', $rawText);
                $sharedStrings[] = trim(preg_replace('/\s+/', ' ', $cleanText));
            }
        }
    }

    // 3. Iterate & Parse All Worksheets
    $allParsedRows       = [];
    $totalSkipped        = 0;
    $extractedVendor     = '';
    $extractedPrintDt    = null;
    $processedSheetNames = [];

    foreach ($sheets as $shInfo) {
        $shName = $shInfo['name'];
        $shPath = $shInfo['path'];

        $sheetXml = $zip->getFromName($shPath);
        if (!$sheetXml) continue;

        $xmlSheet = @simplexml_load_string($sheetXml);
        if (!$xmlSheet || empty($xmlSheet->sheetData->row)) continue;

        // Convert XML rows to raw matrix for this sheet
        $rawMatrix = [];
        foreach ($xmlSheet->sheetData->row as $row) {
            $rNum = (int)$row['r'];
            $rowCells = [];
            
            foreach ($row->c as $c) {
                $colRef = (string)$c['r'];
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

                $cellVal = trim(str_replace(["\xC2\xA0", "\xA0"], ' ', $cellVal));
                $rowCells[$colIndex] = $cellVal;
            }

            if (!empty($rowCells)) {
                $rawMatrix[$rNum] = $rowCells;
            }
        }

        if (empty($rawMatrix)) continue;

        // Extract Top Header Metadata if not yet found
        if (empty($extractedVendor) || empty($extractedPrintDt)) {
            foreach ($rawMatrix as $rNum => $cells) {
                if ($rNum > 6) break;
                foreach ($cells as $colIdx => $val) {
                    $valLower = strtolower($val);
                    if (empty($extractedVendor) && (strpos($valLower, 'vender') !== false || strpos($valLower, 'vendor') !== false)) {
                        if (!empty($cells[6])) {
                            $extractedVendor = $cells[6];
                        } elseif (!empty($cells[$colIdx + 1])) {
                            $extractedVendor = $cells[$colIdx + 1];
                        }
                    }
                    if (empty($extractedPrintDt) && strpos($valLower, 'print date') !== false) {
                        $datePart = $cells[8] ?? ($cells[$colIdx + 1] ?? '');
                        $timePart = $cells[9] ?? ($cells[$colIdx + 2] ?? '');
                        $combinedDt = trim($datePart . ' ' . $timePart);
                        if (!empty($combinedDt)) {
                            $dtObj = DateTime::createFromFormat('Y/m/d H:i:s', $combinedDt);
                            if (!$dtObj) $dtObj = DateTime::createFromFormat('Y-m-d H:i:s', $combinedDt);
                            if (!$dtObj) {
                                $ts = strtotime(str_replace('/', '-', $combinedDt));
                                if ($ts !== false && $ts > 0) $extractedPrintDt = date('Y-m-d H:i:s', $ts);
                            } else {
                                $extractedPrintDt = $dtObj->format('Y-m-d H:i:s');
                            }
                        }
                    }
                }
            }
        }

        // Smart Header Detection per sheet
        $headerRowIndex = null;
        $colMap = [
            'kanban_no'        => null,
            'item_code'        => null,
            'item_description' => null,
            'req_date'         => null,
            'qty'              => null,
            'eta'              => null,
            'str_loc'          => null,
            'supply_area'      => null,
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
                    } elseif ((strpos($vLower, 'supply') !== false || strpos($vLower, 'area') !== false) && $colMap['supply_area'] === null) {
                        $colMap['supply_area'] = $colIdx;
                    } elseif (($vLower === 'cek' || strpos($vLower, 'check') !== false) && $colMap['check_type'] === null) {
                        $colMap['check_type'] = $colIdx;
                    } elseif ((strpos($vLower, 'remark') !== false || strpos($vLower, 'catatan') !== false) && $colMap['remark'] === null) {
                        $colMap['remark'] = $colIdx;
                    }
                }
                break;
            }
        }

        if ($headerRowIndex === null) {
            $headerRowIndex = 5;
            $colMap = [
                'kanban_no'        => 1,
                'item_code'        => 2,
                'item_description' => 3,
                'req_date'         => 4,
                'qty'              => 5,
                'eta'              => 6,
                'str_loc'          => 7,
                'supply_area'      => 8,
                'remark'           => 9,
                'check_type'       => 10
            ];
        }

        $sheetRowsCount = 0;
        foreach ($rawMatrix as $rNum => $cells) {
            if ($rNum <= $headerRowIndex) continue;

            $kanbanNo = isset($colMap['kanban_no']) ? ($cells[$colMap['kanban_no']] ?? '') : '';
            $kanbanNo = trim(preg_replace('/\s+/', ' ', $kanbanNo));

            if (empty($kanbanNo) || $kanbanNo === '0') {
                $totalSkipped++;
                continue;
            }

            $itemCode   = isset($colMap['item_code']) ? ($cells[$colMap['item_code']] ?? '') : '';
            $itemDesc   = isset($colMap['item_description']) ? ($cells[$colMap['item_description']] ?? '') : '';
            $reqDate    = isset($colMap['req_date']) ? ($cells[$colMap['req_date']] ?? '') : '';
            $qty        = isset($colMap['qty']) ? (int)($cells[$colMap['qty']] ?? 0) : 0;
            $eta        = isset($colMap['eta']) ? ($cells[$colMap['eta']] ?? '') : '';
            $strLoc     = isset($colMap['str_loc']) ? ($cells[$colMap['str_loc']] ?? '') : '';
            $supplyArea = isset($colMap['supply_area']) ? ($cells[$colMap['supply_area']] ?? '') : '';
            $rawCheck   = isset($colMap['check_type']) ? ($cells[$colMap['check_type']] ?? '') : '';
            $remark     = isset($colMap['remark']) ? ($cells[$colMap['remark']] ?? '') : '';

            $cleanCheck = trim($rawCheck);
            if ($cleanCheck === '#N/A' || $cleanCheck === 'N/A' || $cleanCheck === '#VALUE!' || $cleanCheck === '0') {
                $checkType = '';
            } else {
                $checkType = $cleanCheck;
            }

            $cleanSupply = trim($supplyArea);
            if ($cleanSupply === '#N/A' || $cleanSupply === 'N/A' || $cleanSupply === '#VALUE!' || $cleanSupply === '0') {
                $cleanSupply = '';
            }

            $formattedReqDate = format_excel_date_kanban($reqDate);
            $formattedEta     = format_excel_date_kanban($eta);

            $allParsedRows[] = [
                'sheet_name'       => $shName,
                'row_num'          => $rNum,
                'kanban_no'        => strtoupper($kanbanNo),
                'item_code'        => strtoupper(trim($itemCode)),
                'item_description' => trim($itemDesc),
                'req_date'         => $formattedReqDate ?: date('Y-m-d H:i:s'),
                'qty'              => ($qty > 0) ? $qty : 1,
                'eta'              => $formattedEta ?: date('Y-m-d H:i:s'),
                'str_loc'          => trim($strLoc),
                'supply_area'      => $cleanSupply,
                'check_type'       => $checkType,
                'remark'           => trim($remark)
            ];
            $sheetRowsCount++;
        }

        if ($sheetRowsCount > 0) {
            $processedSheetNames[] = $shName;
        }
    }

    $zip->close();

    return [
        'success'         => true,
        'sheet_name'      => implode(', ', $processedSheetNames) ?: 'kanban',
        'sheets'          => array_column($sheets, 'name'),
        'processed_sheets'=> $processedSheetNames,
        'vendor'          => $extractedVendor ?: 'PT. SURYA TECHNOLOGY INDUSTRI',
        'print_datetime'  => $extractedPrintDt,
        'total_parsed'    => count($allParsedRows),
        'total_skipped'   => $totalSkipped,
        'rows'            => $allParsedRows
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
