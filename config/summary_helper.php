<?php
/**
 * Summary Aggregate Helper for OQC System
 * Maintains pre-aggregated daily summaries (oqc_daily_summary and oqc_daily_defect_summary)
 * for sub-millisecond dashboard and report performance at multi-million row scale.
 */

if (!function_exists('syncDailySummaryForDate')) {
    /**
     * Recalculate daily aggregate summary for a specific calendar date.
     * Idempotent: Can be run multiple times safely without double-counting.
     *
     * @param PDO $pdo
     * @param string $targetDate Format 'YYYY-MM-DD'
     * @return bool
     */
    function syncDailySummaryForDate(PDO $pdo, string $targetDate): bool {
        if (empty($targetDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
            return false;
        }

        // Advisory lock per target date to prevent concurrent workers from deadlocking on DELETE/INSERT
        $lockKey = "oqc_sync_summary_{$targetDate}";
        $lockAcquired = false;
        try {
            $stmtLock = $pdo->prepare("SELECT GET_LOCK(:key, 3)");
            $stmtLock->execute([':key' => $lockKey]);
            $lockAcquired = ($stmtLock->fetchColumn() == 1);
        } catch (Exception $eLock) {
            // Non-critical if GET_LOCK fails, proceed with execution
        }

        // Boundary tanggal untuk memaksa penggunaan B-Tree index (idx_sess_status_started)
        $sdMin = date('Y-m-d 00:00:00', strtotime($targetDate . ' -1 day'));
        $sdMax = date('Y-m-d 23:59:59', strtotime($targetDate . ' +1 day'));

        $maxRetries = 3;
        $attempt = 0;
        $success = false;

        while ($attempt < $maxRetries && !$success) {
            $attempt++;
            $inOuterTx = $pdo->inTransaction();

            try {
                if (!$inOuterTx) {
                    $pdo->beginTransaction();
                }

                // 1. Fetch all finished inspection sessions for this operational date
                // Memakai pre-filter index range (sd_min & sd_max) agar tidak full table scan
                $sqlSess = "SELECT ss.id, ss.started_at, ss.closed_at, ss.inspection_type, ss.sample_size, ss.total_scanned_qty, ss.use_safety_stock_qty, ss.auto_fulfilled_by_session_id, ss.batch_sample_size, ss.status,
                                   ss.ng_count, ss.part_id, ss.reinspection_notes, mp.model_id, mp.aql_level,
                                   COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') AS customer_name
                            FROM inspection_sessions ss
                            LEFT JOIN kanban_items ki ON ss.kanban_item_id = ki.id
                            LEFT JOIN master_parts mp ON ss.part_id = mp.id
                            WHERE ss.status IN ('passed', 'rejected')
                              AND ss.started_at >= :sd_min AND ss.started_at <= :sd_max
                              AND NOT (ki.remark LIKE '%AUTO-SS-SESS-%' OR ki.remark LIKE '%Auto Safety Stock Sisa Excess%' OR ki.remark LIKE '%Sisa Excess Kanban%')
                              AND (ki.remark IS NULL OR ki.remark NOT LIKE '%Split%')
                              AND (
                                  CASE 
                                      WHEN HOUR(ss.started_at) >= 20 THEN DATE(ss.started_at)
                                      WHEN ss.closed_at IS NOT NULL AND DATE(closed_at) > DATE(ss.started_at) THEN DATE(ss.closed_at)
                                      ELSE DATE(ss.started_at)
                                  END = :sd
                              )";
                $stmtSess = $pdo->prepare($sqlSess);
                $stmtSess->execute([
                    ':sd_min' => $sdMin,
                    ':sd_max' => $sdMax,
                    ':sd'     => $targetDate
                ]);
                $sessions = $stmtSess->fetchAll(PDO::FETCH_ASSOC);

                if (empty($sessions)) {
                    // Jika tidak ada session yang selesai di tanggal ini, bersihkan data summary tanggal ini
                    $stmtDel1 = $pdo->prepare("DELETE FROM oqc_daily_summary WHERE summary_date = :dt");
                    $stmtDel1->execute([':dt' => $targetDate]);

                    $stmtDel2 = $pdo->prepare("DELETE FROM oqc_daily_defect_summary WHERE summary_date = :dt");
                    $stmtDel2->execute([':dt' => $targetDate]);

                    if (!$inOuterTx && $pdo->inTransaction()) {
                        $pdo->commit();
                    }
                    $success = true;
                    break;
                }

                $sessionIds = array_map(function($s) { return (int)$s['id']; }, $sessions);
                $inClause = implode(',', $sessionIds);

                // 3. Bulk fetch lots for all sessions in a single query (prevents N+1 query storm)
                $lotsBySession = [];
                if (!empty($inClause)) {
                    $sqlLots = "SELECT isl.inspection_session_id, isl.id, isl.lot_status, isl.lot_result, isl.sample_size, isl.qty, isl.ng_count, isl.remarks, isl.ref_number,
                                       COUNT(CASE WHEN (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0) THEN ngr.id END) AS active_ng_cnt
                                FROM inspection_session_lots isl
                                LEFT JOIN inspection_ng_records ngr ON ngr.session_lot_id = isl.id
                                WHERE isl.inspection_session_id IN ($inClause)
                                GROUP BY isl.inspection_session_id, isl.id, isl.lot_status, isl.lot_result, isl.sample_size, isl.qty, isl.ng_count, isl.remarks, isl.ref_number";
                    $stmtLots = $pdo->query($sqlLots);
                    if ($stmtLots) {
                        while ($lotRow = $stmtLots->fetch(PDO::FETCH_ASSOC)) {
                            $sid = (int)$lotRow['inspection_session_id'];
                            $lotsBySession[$sid][] = $lotRow;
                        }
                    }
                }

                // 4. Bulk fetch NG records for all sessions in a single query (prevents N+1 query storm)
                $ngBySession = [];
                if (!empty($inClause)) {
                    $sqlNg = "SELECT ngr.inspection_session_id, ngr.session_lot_id, ngr.inspection_sample_id AS sample_id, ngr.unit_number, ngr.defect_type_id, ngr.qty_ng
                              FROM inspection_ng_records ngr
                              WHERE ngr.inspection_session_id IN ($inClause)
                                AND (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)";
                    $stmtNg = $pdo->query($sqlNg);
                    if ($stmtNg) {
                        while ($ngRow = $stmtNg->fetch(PDO::FETCH_ASSOC)) {
                            $sid = (int)$ngRow['inspection_session_id'];
                            $ngBySession[$sid][] = $ngRow;
                        }
                    }
                }

                // 5. Bulk fetch reinspection/sort log to calculate inspection workload (putaran lot & sampel fisik ulang)
                $reinspectByLot = [];
                if (!empty($inClause)) {
                    $sqlReinspect = "SELECT ng_session_lot_id, COUNT(*) AS reinspect_count
                                     FROM lot_substitution_log
                                     WHERE original_session_id IN ($inClause)
                                       AND action_type = 'sort_reinspect'
                                     GROUP BY ng_session_lot_id";
                    $stmtReinspect = $pdo->query($sqlReinspect);
                    if ($stmtReinspect) {
                        while ($reRow = $stmtReinspect->fetch(PDO::FETCH_ASSOC)) {
                            $reinspectByLot[(int)$reRow['ng_session_lot_id']] = (int)$reRow['reinspect_count'];
                        }
                    }
                }

                $summaryBuckets = [];
                $defectBuckets  = [];

                foreach ($sessions as $s) {
                    $sid     = (int)$s['id'];
                    $cust    = (string)$s['customer_name'];
                    $partId  = (int)($s['part_id'] ?? 0);
                    $modelId = (int)($s['model_id'] ?? 0);
                    $type    = $s['inspection_type'] ?: 'kanban';
                    $key     = "{$cust}|{$partId}|{$modelId}|{$type}";

                    if (!isset($summaryBuckets[$key])) {
                        $summaryBuckets[$key] = [
                            'summary_date'              => $targetDate,
                            'customer'                  => $cust,
                            'part_id'                   => $partId,
                            'model_id'                  => $modelId,
                            'inspection_type'           => $type,
                            'total_sessions'            => 0,
                            'passed_sessions'           => 0,
                            'rejected_sessions'         => 0,
                            'total_lots'                => 0,
                            'passed_lots'               => 0,
                            'rejected_lots'             => 0,
                            'total_sample_size'         => 0,
                            'total_sample_size_lot'     => 0,
                            'total_sample_size_session' => 0,
                            'total_ng_samples'          => 0,
                            'total_ng_pcs'              => 0,
                        ];
                    }

                    $summaryBuckets[$key]['total_sessions']++;
                    if ($s['status'] === 'passed')   $summaryBuckets[$key]['passed_sessions']++;
                    if ($s['status'] === 'rejected') $summaryBuckets[$key]['rejected_sessions']++;

                    $sessionLots = $lotsBySession[$sid] ?? [];
                    $lotSampleSizeSum = 0;
                    $reinspectSamplesSum = 0;
                    $hasPhysicalLot = false;
                    $lotIsNgMap = [];
                    if (!empty($sessionLots)) {
                        foreach ($sessionLots as $lot) {
                            $isExcessLot = !empty($lot['remarks']) && (
                                strpos($lot['remarks'], 'Sisa Kelebihan Kanban') !== false ||
                                strpos($lot['remarks'], 'Sisa Split Safety Stock') !== false
                            );
                            $isDirectCloseLot = (!empty($lot['ref_number']) && (
                                $lot['ref_number'] === 'DIRECT-PASS' ||
                                strpos($lot['ref_number'], 'DIRECT-PASS') !== false
                            )) || (!empty($lot['remarks']) && (
                                strpos($lot['remarks'], 'Direct Close') !== false
                            ));

                            if ($isExcessLot || ($lot['lot_result'] ?? '') === 'skipped') {
                                continue;
                            }
                            $hasPhysicalLot = true;
                            $summaryBuckets[$key]['total_lots']++;
                            $lotSampleSizeSum += (int)($lot['sample_size'] ?? 0);

                            // Definisi lot yang mengalami NG / penolakan mutu fisik (Identik 1:1 dengan query modal drilldown)
                            $isNg = ($lot['lot_result'] === 'rejected')
                                 || in_array($lot['lot_status'], ['ng_found', 'ng_quarantine', 'replaced', 'action_closed', 'abolished'])
                                 || ((int)($lot['active_ng_cnt'] ?? 0) > 0);

                            $lotIsNgMap[(int)$lot['id']] = $isNg;

                            if ($isNg) {
                                $summaryBuckets[$key]['rejected_lots']++;
                            } else {
                                $summaryBuckets[$key]['passed_lots']++;
                            }

                            // Hitung Beban Kerja Fisik untuk Putaran Re-Inspeksi (Sortir & Uji Ulang)
                            // Catatan: Unit rejected_lots TIDAK ditambah di sini agar 1 lot fisik unik tidak terhitung ganda (double-count)
                            $lid = (int)$lot['id'];
                            $reinspectCount = $reinspectByLot[$lid] ?? 0;

                            if ($reinspectCount > 0) {
                                for ($r = 0; $r < $reinspectCount; $r++) {
                                    $summaryBuckets[$key]['total_lots']++;
                                    $lotSampleSizeSum    += (int)($lot['sample_size'] ?? 0);
                                    $reinspectSamplesSum += (int)($lot['sample_size'] ?? 0);
                                }
                            }
                        }
                    } else {
                        // Sesi tanpa catatan lot individual
                        $isSsNoLots = !empty($s['auto_fulfilled_by_session_id']);
                        if (!$isSsNoLots) {
                            $summaryBuckets[$key]['total_lots']++;
                            if ($s['status'] === 'passed')   $summaryBuckets[$key]['passed_lots']++;
                            if ($s['status'] === 'rejected') $summaryBuckets[$key]['rejected_lots']++;
                        }
                    }

                    // 1. Accumulate Lot Basis Sample Size
                    $currentLotSampleSize = 0;
                    if (!empty($sessionLots) && $lotSampleSizeSum > 0) {
                        $currentLotSampleSize = $lotSampleSizeSum;
                    } else {
                        $currentLotSampleSize = (int)($s['sample_size'] ?? 0);
                    }
                    $summaryBuckets[$key]['total_sample_size_lot'] += $currentLotSampleSize;
                    $summaryBuckets[$key]['total_sample_size']     += $currentLotSampleSize;

                    // 2. Accumulate Session / Batch Basis Sample Size
                    $batchAqlSize = (int)($s['batch_sample_size'] ?? 0);
                    if ($batchAqlSize <= 0) {
                        $batchAqlSize = (int)$s['sample_size'];
                    }
                    $summaryBuckets[$key]['total_sample_size_session'] += ($batchAqlSize + $reinspectSamplesSum);

                    $sessionNg = $ngBySession[$sid] ?? [];
                    $distinctSampleNg = [];
                    $distinctUnits = [];
                    $totalDefectQty = 0;

                    foreach ($sessionNg as $nr) {
                        $spId = $nr['sample_id'];
                        if ($spId !== null && $spId !== '') {
                            $distinctSampleNg[(int)$spId] = true;
                        }

                        $slotId = (int)($nr['session_lot_id'] ?? 0);
                        $uNum = $nr['unit_number'] ?? null;
                        if ($uNum !== null && $uNum !== '') {
                            $unitKey = "{$slotId}_{$uNum}";
                            $distinctUnits[$unitKey] = true;
                        }

                        $qty = (int)($nr['qty_ng'] ?: 1);
                        $totalDefectQty += $qty;

                        $defId = (int)$nr['defect_type_id'];
                        if ($defId > 0) {
                            $defKey = "{$cust}|{$partId}|{$modelId}|{$defId}";
                            if (!isset($defectBuckets[$defKey])) {
                                $defectBuckets[$defKey] = [
                                    'summary_date'   => $targetDate,
                                    'customer'       => $cust,
                                    'part_id'        => $partId,
                                    'model_id'       => $modelId,
                                    'defect_type_id' => $defId,
                                    'lot_count'      => 0,
                                    'qty_ng'         => 0,
                                    '_lot_ids'       => []
                                ];
                            }
                            $defectBuckets[$defKey]['qty_ng'] += $qty;

                            // Pastikan hanya menghitung lot yang murni NG / Rejected
                            $isThisLotActuallyNg = false;
                            if ($slotId > 0) {
                                $isThisLotActuallyNg = !empty($lotIsNgMap[$slotId]);
                            } else {
                                $isThisLotActuallyNg = ($s['status'] === 'rejected');
                            }

                            if ($isThisLotActuallyNg) {
                                $lotIdentity = $slotId > 0 ? "lot_{$slotId}" : "sess_{$sid}";
                                if (!isset($defectBuckets[$defKey]['_lot_ids'][$lotIdentity])) {
                                    $defectBuckets[$defKey]['_lot_ids'][$lotIdentity] = true;
                                    $defectBuckets[$defKey]['lot_count']++;
                                }
                            }
                        }
                    }

                    // Tentukan jumlah fisik unit cacat untuk sesi ini (Defective Units / Fisik Sampel Cacat):
                    // Akumulasikan seluruh temuan fisik defect ($totalDefectQty) yang terdata pada inspection_ng_records
                    // (termasuk temuan historis dari ronde inspeksi 1 & 2 yang telah disortir/re-inspeksi).
                    $recordedLotNg = !empty($sessionLots) ? array_sum(array_map(function($l) { return (int)($l['ng_count'] ?? 0); }, $sessionLots)) : 0;
                    $recordedSessNg = (int)($s['ng_count'] ?? 0);
                    $recordedPhysicalNg = max($recordedLotNg, $recordedSessNg);

                    if ($recordedPhysicalNg > 0 && $recordedPhysicalNg >= $totalDefectQty) {
                        $sessionPhysicalNg = $recordedPhysicalNg;
                    } elseif ($totalDefectQty > 0) {
                        $sessionPhysicalNg = $totalDefectQty;
                    } elseif (!empty($distinctUnits)) {
                        $sessionPhysicalNg = count($distinctUnits);
                    } elseif (!empty($distinctSampleNg)) {
                        $sessionPhysicalNg = count($distinctSampleNg);
                    } else {
                        $sessionPhysicalNg = 0;
                    }

                    $summaryBuckets[$key]['total_ng_pcs'] += $sessionPhysicalNg;
                    $summaryBuckets[$key]['total_ng_samples'] += $sessionPhysicalNg;
                }

                // 4. Update or Insert into oqc_daily_summary (Preserve existing IDs, strictly prevent Auto-Increment gaps)
                $stmtGetExistingSum = $pdo->prepare("SELECT id, customer, part_id, inspection_type FROM oqc_daily_summary WHERE summary_date = :sd");
                $stmtGetExistingSum->execute([':sd' => $targetDate]);
                $existingSumMap = [];
                while ($exRow = $stmtGetExistingSum->fetch(PDO::FETCH_ASSOC)) {
                    $exKey = $exRow['customer'] . '|' . (int)$exRow['part_id'] . '|' . $exRow['inspection_type'];
                    $existingSumMap[$exKey] = (int)$exRow['id'];
                }

                $stmtUpdSum = $pdo->prepare("UPDATE oqc_daily_summary SET
                    model_id = :model_id,
                    total_sessions = :total_sessions,
                    passed_sessions = :passed_sessions,
                    rejected_sessions = :rejected_sessions,
                    total_lots = :total_lots,
                    passed_lots = :passed_lots,
                    rejected_lots = :rejected_lots,
                    total_sample_size = :total_sample_size,
                    total_sample_size_lot = :total_sample_size_lot,
                    total_sample_size_session = :total_sample_size_session,
                    total_ng_samples = :total_ng_samples,
                    total_ng_pcs = :total_ng_pcs,
                    updated_at = NOW()
                    WHERE id = :id");

                $stmtInsSum = $pdo->prepare("INSERT INTO oqc_daily_summary (
                    summary_date, customer, part_id, model_id, inspection_type,
                    total_sessions, passed_sessions, rejected_sessions,
                    total_lots, passed_lots, rejected_lots,
                    total_sample_size, total_sample_size_lot, total_sample_size_session,
                    total_ng_samples, total_ng_pcs, updated_at
                ) VALUES (
                    :summary_date, :customer, :part_id, :model_id, :inspection_type,
                    :total_sessions, :passed_sessions, :rejected_sessions,
                    :total_lots, :passed_lots, :rejected_lots,
                    :total_sample_size, :total_sample_size_lot, :total_sample_size_session,
                    :total_ng_samples, :total_ng_pcs, NOW()
                )");

                $retainedSumIds = [];
                foreach ($summaryBuckets as $row) {
                    $lookupKey = $row['customer'] . '|' . (int)$row['part_id'] . '|' . $row['inspection_type'];
                    if (isset($existingSumMap[$lookupKey])) {
                        $targetId = $existingSumMap[$lookupKey];
                        $retainedSumIds[] = $targetId;
                        $stmtUpdSum->execute([
                            ':id'                        => $targetId,
                            ':model_id'                  => (int)$row['model_id'],
                            ':total_sessions'            => (int)$row['total_sessions'],
                            ':passed_sessions'           => (int)$row['passed_sessions'],
                            ':rejected_sessions'         => (int)$row['rejected_sessions'],
                            ':total_lots'                => (int)$row['total_lots'],
                            ':passed_lots'               => (int)$row['passed_lots'],
                            ':rejected_lots'             => (int)$row['rejected_lots'],
                            ':total_sample_size'         => (int)$row['total_sample_size'],
                            ':total_sample_size_lot'     => (int)$row['total_sample_size_lot'],
                            ':total_sample_size_session' => (int)$row['total_sample_size_session'],
                            ':total_ng_samples'          => (int)$row['total_ng_samples'],
                            ':total_ng_pcs'              => (int)$row['total_ng_pcs'],
                        ]);
                    } else {
                        $stmtInsSum->execute($row);
                        $retainedSumIds[] = (int)$pdo->lastInsertId();
                    }
                }

                // Hapus hanya record summary lama pada tanggal ini yang sudah tidak relevan
                if (!empty($retainedSumIds)) {
                    $idListStr = implode(',', array_map('intval', $retainedSumIds));
                    $stmtDelOldSum = $pdo->prepare("DELETE FROM oqc_daily_summary WHERE summary_date = :sd AND id NOT IN ({$idListStr})");
                    $stmtDelOldSum->execute([':sd' => $targetDate]);
                } else {
                    $stmtDelAllSum = $pdo->prepare("DELETE FROM oqc_daily_summary WHERE summary_date = :sd");
                    $stmtDelAllSum->execute([':sd' => $targetDate]);
                }

                // 5. Update or Insert into oqc_daily_defect_summary (Preserve existing IDs)
                $stmtGetExistingDef = $pdo->prepare("SELECT id, customer, part_id, defect_type_id FROM oqc_daily_defect_summary WHERE summary_date = :sd");
                $stmtGetExistingDef->execute([':sd' => $targetDate]);
                $existingDefMap = [];
                while ($exDefRow = $stmtGetExistingDef->fetch(PDO::FETCH_ASSOC)) {
                    $exDefKey = $exDefRow['customer'] . '|' . (int)$exDefRow['part_id'] . '|' . (int)$exDefRow['defect_type_id'];
                    $existingDefMap[$exDefKey] = (int)$exDefRow['id'];
                }

                $stmtUpdDef = $pdo->prepare("UPDATE oqc_daily_defect_summary SET
                    model_id = :model_id,
                    lot_count = :lot_count,
                    qty_ng = :qty_ng,
                    updated_at = NOW()
                    WHERE id = :id");

                $stmtInsDef = $pdo->prepare("INSERT INTO oqc_daily_defect_summary (
                    summary_date, customer, part_id, model_id, defect_type_id, lot_count, qty_ng, updated_at
                ) VALUES (
                    :summary_date, :customer, :part_id, :model_id, :defect_type_id, :lot_count, :qty_ng, NOW()
                )");

                $retainedDefIds = [];
                foreach ($defectBuckets as $dRow) {
                    unset($dRow['_lot_ids']);
                    $dLookupKey = $dRow['customer'] . '|' . (int)$dRow['part_id'] . '|' . (int)$dRow['defect_type_id'];
                    if (isset($existingDefMap[$dLookupKey])) {
                        $targetDefId = $existingDefMap[$dLookupKey];
                        $retainedDefIds[] = $targetDefId;
                        $stmtUpdDef->execute([
                            ':id'        => $targetDefId,
                            ':model_id'  => (int)$dRow['model_id'],
                            ':lot_count' => (int)$dRow['lot_count'],
                            ':qty_ng'    => (int)$dRow['qty_ng'],
                        ]);
                    } else {
                        $stmtInsDef->execute($dRow);
                        $retainedDefIds[] = (int)$pdo->lastInsertId();
                    }
                }

                // Hapus hanya record defect summary pada tanggal ini yang sudah tidak relevan
                if (!empty($retainedDefIds)) {
                    $defIdListStr = implode(',', array_map('intval', $retainedDefIds));
                    $stmtDelOldDef = $pdo->prepare("DELETE FROM oqc_daily_defect_summary WHERE summary_date = :sd AND id NOT IN ({$defIdListStr})");
                    $stmtDelOldDef->execute([':sd' => $targetDate]);
                } else {
                    $stmtCleanDef = $pdo->prepare("DELETE FROM oqc_daily_defect_summary WHERE summary_date = :sd");
                    $stmtCleanDef->execute([':sd' => $targetDate]);
                }

                if (!$inOuterTx && $pdo->inTransaction()) {
                    $pdo->commit();
                }
                $success = true;
            } catch (Exception $e) {
                if (!$inOuterTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // Check for MySQL deadlock error (1213 / 40001)
                $isDeadlock = (strpos($e->getMessage(), '1213') !== false || strpos($e->getMessage(), 'Deadlock') !== false);
                if ($isDeadlock && $attempt < $maxRetries) {
                    usleep(mt_rand(50000, 150000)); // sleep 50-150ms before retry
                    continue;
                }
                error_log("Error in syncDailySummaryForDate({$targetDate}) on attempt {$attempt}: " . $e->getMessage());
                break;
            }
        }

        // Release advisory lock
        if ($lockAcquired) {
            try {
                $stmtUnlock = $pdo->prepare("SELECT RELEASE_LOCK(:key)");
                $stmtUnlock->execute([':key' => $lockKey]);
            } catch (Exception $eUnlock) {}
        }

        return $success;
    }
}

if (!function_exists('syncDailySummaryForSession')) {
    /**
     * Trigger sync for the date corresponding to an inspection session.
     * Safe to call anywhere without crashing the main transaction.
     *
     * @param PDO $pdo
     * @param int $sessionId
     * @return bool
     */
    function syncDailySummaryForSession(PDO $pdo, int $sessionId): bool {
        if ($sessionId <= 0) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT DISTINCT s_date FROM (
                    SELECT DATE(started_at) AS s_date FROM inspection_sessions WHERE id = :sid1 AND started_at IS NOT NULL
                    UNION
                    SELECT DATE(closed_at) AS s_date FROM inspection_sessions WHERE id = :sid2 AND closed_at IS NOT NULL
                    UNION
                    SELECT DATE(created_at) AS s_date FROM inspection_ng_records WHERE inspection_session_id = :sid3 AND (is_cancelled IS NULL OR is_cancelled = 0)
                    UNION
                    SELECT CASE 
                        WHEN HOUR(started_at) >= 20 THEN DATE(started_at)
                        WHEN closed_at IS NOT NULL AND DATE(closed_at) > DATE(started_at) THEN DATE(closed_at)
                        ELSE DATE(started_at)
                    END AS s_date FROM inspection_sessions WHERE id = :sid4 AND started_at IS NOT NULL
                ) d_union WHERE s_date IS NOT NULL
            ");
            $stmt->execute([':sid1' => $sessionId, ':sid2' => $sessionId, ':sid3' => $sessionId, ':sid4' => $sessionId]);
            $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $res = true;
            foreach ($dates as $d) {
                if ($d) {
                    $res = syncDailySummaryForDate($pdo, (string)$d) && $res;
                }
            }
            return $res;
        } catch (Exception $e) {
            error_log("Error in syncDailySummaryForSession({$sessionId}): " . $e->getMessage());
        }
        return false;
    }
}

if (!function_exists('backfillAllDailySummaries')) {
    /**
     * Backfill all historical summary records from finished inspection sessions.
     *
     * @param PDO $pdo
     * @return array ['total_dates' => int, 'success_count' => int]
     */
    function backfillAllDailySummaries(PDO $pdo): array {
        $dates = [];
        try {
            $stmt = $pdo->query("
                SELECT DISTINCT s_date FROM (
                    SELECT DATE(started_at) AS s_date FROM inspection_sessions WHERE started_at IS NOT NULL
                    UNION
                    SELECT DATE(closed_at) AS s_date FROM inspection_sessions WHERE closed_at IS NOT NULL
                    UNION
                    SELECT DATE(created_at) AS s_date FROM inspection_ng_records WHERE (is_cancelled IS NULL OR is_cancelled = 0)
                    UNION
                    SELECT CASE 
                        WHEN HOUR(started_at) >= 20 THEN DATE(started_at)
                        WHEN closed_at IS NOT NULL AND DATE(closed_at) > DATE(started_at) THEN DATE(closed_at)
                        ELSE DATE(started_at)
                    END AS s_date FROM inspection_sessions WHERE started_at IS NOT NULL
                ) d_union WHERE s_date IS NOT NULL ORDER BY s_date ASC
            ");
            $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            error_log("Error fetching dates for backfill: " . $e->getMessage());
            return ['total_dates' => 0, 'success_count' => 0];
        }

        $success = 0;
        foreach ($dates as $d) {
            if ($d && syncDailySummaryForDate($pdo, (string)$d)) {
                $success++;
            }
        }

        return [
            'total_dates'   => count($dates),
            'success_count' => $success
        ];
    }
}

if (!function_exists('getWorkCycleMonthStartDate')) {
    /**
     * Menghitung tanggal mulai siklus kerja (Sunday Anchor: Minggu pertama di bulan terpilih).
     *
     * @param int $year
     * @param int $month
     * @return string Format 'YYYY-MM-DD'
     */
    function getWorkCycleMonthStartDate(int $year, int $month): string {
        $firstDayDt = new DateTime(sprintf('%04d-%02d-01', $year, $month));
        $firstDayDow = (int)$firstDayDt->format('N'); // 1 = Sen ... 7 = Min

        $w1Sun = clone $firstDayDt;
        if ($firstDayDow !== 7) {
            $w1Sun->modify('-' . $firstDayDow . ' days');
        }
        // Sunday Anchor: Jika jatuh di bulan sebelumnya, W1 mulai pada Minggu pertama di bulan ini
        if ($w1Sun->format('m') !== $firstDayDt->format('m')) {
            $w1Sun->modify('+7 days');
        }

        return $w1Sun->format('Y-m-d');
    }
}

if (!function_exists('getWorkCycleWeekStartDate')) {
    /**
     * Menghitung tanggal mulai minggu ini (Sunday Anchor: Hari Minggu terdekat di minggu berjalan).
     * Siklus kerja mingguan pabrik: Minggu s/d Sabtu.
     *
     * @param string|null $refDate YYYY-MM-DD (default hari ini)
     * @return string Format 'YYYY-MM-DD'
     */
    function getWorkCycleWeekStartDate(?string $refDate = null): string {
        $dt = new DateTime($refDate ?: date('Y-m-d'));
        $dow = (int)$dt->format('w'); // 0 = Minggu, 1 = Senin, ... 6 = Sabtu
        if ($dow > 0) {
            $dt->modify("-{$dow} days");
        }
        return $dt->format('Y-m-d');
    }
}

if (!function_exists('getWorkCycleWeeklyBuckets')) {
    /**
     * Membentuk rentang minggu siklus kerja pabrik (Sunday Anchor: Minggu - Sabtu).
     *
     * @param int $year
     * @param int $month
     * @param bool $onlyUntilCurrentWeek Jika true (default), minggu di masa depan pada bulan berjalan tidak akan dibuat.
     * @return array [ 'W1' => ['label' => '...', 'shortLabel' => 'W1', 'start_date' => 'YYYY-MM-DD', 'end_date' => 'YYYY-MM-DD'], ... ]
     */
    function getWorkCycleWeeklyBuckets(int $year, int $month, bool $onlyUntilCurrentWeek = true): array {
        $firstDayDt = new DateTime(sprintf('%04d-%02d-01', $year, $month));
        $w1SunStr   = getWorkCycleMonthStartDate($year, $month);
        $curSun     = new DateTime($w1SunStr);
        $wIdx       = 1;
        $targetMonth = $firstDayDt->format('m');
        $buckets    = [];
        $todayStr   = date('Y-m-d');
        $isCurrentMonthYear = ($year === (int)date('Y') && $month === (int)date('n'));

        while ($curSun->format('m') === $targetMonth && $wIdx <= 6) {
            $sStr = $curSun->format('Y-m-d');
            // Jika bulan yang dipilih adalah bulan berjalan, jangan buat minggu yang tanggal mulainya masih di masa depan
            if ($onlyUntilCurrentWeek && $isCurrentMonthYear && $sStr > $todayStr) {
                break;
            }

            $curSat = clone $curSun;
            $curSat->modify('+6 days');
            $eStr = $curSat->format('Y-m-d');
            $sLabel = $curSun->format('d');
            $eLabel = $curSat->format('d');
            $sMonth = $curSun->format('M');
            $eMonth = $curSat->format('M');

            $lblText = ($sMonth === $eMonth) ? "W{$wIdx} ({$sLabel}-{$eLabel} {$sMonth})" : "W{$wIdx} ({$sLabel} {$sMonth}-{$eLabel} {$eMonth})";

            $buckets["W{$wIdx}"] = [
                'label'      => $lblText,
                'shortLabel' => "W{$wIdx}",
                'start_date' => $sStr,
                'end_date'   => $eStr
            ];

            $curSun->modify('+7 days');
            $wIdx++;
        }

        return $buckets;
    }
}

if (!function_exists('buildDashboardMultiHorizonTrend')) {
    /**
     * Menghitung data tren kualitas OQC:
     * - Mode 'weekly': Multi-horizon lengkap (Tahunan -> Bulanan -> Mingguan W1-W4 Sunday Anchor)
     * - Mode 'daily': Murni tanggal harian dari Minggu pertama s/d akhir bulan (tanpa bar tahun & bulan)
     *
     * @param PDO $pdo
     * @param array $params ['ref_year'=>int, 'ref_month'=>int, 'customer'=>string, 'unit'=>string, 'sample_basis'=>string, 'result'=>string, 'sub_resolution'=>string]
     * @return array
     */
    function buildDashboardMultiHorizonTrend(PDO $pdo, array $params = []): array {
        $refYear       = !empty($params['ref_year']) ? (int)$params['ref_year'] : (int)date('Y');
        $refMonth      = !empty($params['ref_month']) ? (int)$params['ref_month'] : (int)date('n');
        $customer      = trim($params['customer'] ?? '');
        $unit          = in_array($params['unit'] ?? 'pcs', ['pcs', 'lot']) ? ($params['unit'] ?? 'pcs') : 'pcs';
        $sampleBasis   = in_array($params['sample_basis'] ?? 'lot', ['lot', 'session']) ? ($params['sample_basis'] ?? 'lot') : 'lot';
        $isPassed      = (($params['result'] ?? 'rejected') === 'passed');
        $subResolution = in_array($params['sub_resolution'] ?? 'weekly', ['weekly', 'daily']) ? $params['sub_resolution'] : 'weekly';

        $mNames = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
            7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
        ];

        $custWhere = "";
        $baseParams = [];
        if ($customer !== '') {
            $custWhere = " AND customer = :cust ";
            $baseParams[':cust'] = $customer;
        }

        // Cari Hari Minggu pertama siklus W1
        $monthStart = sprintf('%04d-%02d-01', $refYear, $refMonth);
        $w1SunStr   = getWorkCycleMonthStartDate($refYear, $refMonth);
        $w1Sun      = new DateTime($w1SunStr);
        $monthEndStr= date('Y-m-t', strtotime($monthStart));

        // ══════════════════════════════════════════════════════════════════════════════
        // JIKA MODE HARIAN: MURNI HARIAN (TANPA BAR TAHUNAN & BULANAN)
        // ══════════════════════════════════════════════════════════════════════════════
        if ($subResolution === 'daily') {
            $monthEndDt   = new DateTime($monthEndStr);
            $dailyBuckets = [];
            $curDay = clone $w1Sun;
            while ($curDay <= $monthEndDt) {
                $dStr = $curDay->format('Y-m-d');
                $dDayNum = $curDay->format('d');
                $dDowShort = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'][(int)$curDay->format('w')];
                $dailyBuckets[$dStr] = [
                    'dayNum'   => $dDayNum,
                    'tooltip'  => "{$dDowShort}, {$dDayNum} " . ($mNames[$refMonth] ?? '') . " {$refYear}",
                    'sample'   => 0,
                    'ng'       => 0,
                    'passed'   => 0,
                    'rate'     => 0
                ];
                $curDay->modify('+1 day');
            }

            if (!empty($dailyBuckets)) {
                try {
                    $pD = array_merge($baseParams, [
                        ':dStart' => $w1Sun->format('Y-m-d'),
                        ':dEnd'   => $monthEndStr
                    ]);
                    $stmtD = $pdo->prepare("SELECT 
                                                summary_date,
                                                COALESCE(SUM(total_sample_size_lot), 0) AS total_sample_size_lot,
                                                COALESCE(SUM(total_sample_size_session), 0) AS total_sample_size_session,
                                                COALESCE(SUM(total_sample_size), 0) AS total_sample_size,
                                                COALESCE(SUM(total_lots), 0) AS total_lots,
                                                COALESCE(SUM(passed_lots), 0) AS passed_lots,
                                                COALESCE(SUM(rejected_lots), 0) AS rejected_lots,
                                                COALESCE(SUM(total_ng_pcs), 0) AS total_ng_pcs,
                                                COALESCE(SUM(total_ng_samples), 0) AS total_ng_samples
                                            FROM oqc_daily_summary
                                            WHERE summary_date >= :dStart AND summary_date <= :dEnd {$custWhere}
                                            GROUP BY summary_date");
                    $stmtD->execute($pD);
                    foreach ($stmtD->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $sDate = $r['summary_date'];
                        if (!isset($dailyBuckets[$sDate])) continue;

                        if ($unit === 'lot') {
                            $s = (int)$r['total_lots'];
                            $ng = (int)$r['rejected_lots'];
                            $pass = (int)$r['passed_lots'];
                            $rt = $s > 0 ? round(($isPassed ? ($pass / $s) : ($ng / $s)) * 100, 2) : 0;
                        } else {
                            $sLot  = (int)$r['total_sample_size_lot'] ?: (int)$r['total_sample_size'];
                            $sSess = (int)$r['total_sample_size_session'] ?: (int)$r['total_sample_size'];
                            $s = ($sampleBasis === 'session') ? $sSess : $sLot;
                            $ng = max((int)$r['total_ng_pcs'], (int)$r['total_ng_samples']);
                            $pass = max(0, $s - $ng);
                            $rt = $s > 0 ? round(($isPassed ? ($pass / $s) : ($ng / $s)) * 1000000, 1) : 0;
                        }
                        $dailyBuckets[$sDate]['sample'] = $s;
                        $dailyBuckets[$sDate]['ng']     = $ng;
                        $dailyBuckets[$sDate]['passed'] = $pass;
                        $dailyBuckets[$sDate]['rate']   = $rt;
                    }
                } catch (Exception $e) {}
            }

            $allLabels   = [];
            $allRates    = [];
            $allSamples  = [];
            $allNGs      = [];
            $barColors   = [];
            $borderCols  = [];
            $tooltips    = [];

            foreach ($dailyBuckets as $db) {
                $allLabels[]   = $db['dayNum'];
                $allRates[]    = $db['rate'];
                $allSamples[]  = $db['sample'];
                $allNGs[]      = $isPassed ? $db['passed'] : $db['ng'];
                $barColors[]   = '#2563eb'; // Warna royal blue yang seragam dan bersih
                $borderCols[]  = '#1d4ed8';
                $tooltips[]    = $db['tooltip'];
            }

            $w1Day = $w1Sun->format('d');
            $mLastDay = date('d', strtotime($monthEndStr));
            $subDesc = "Tren harian siklus kerja: Tgl {$w1Day} – {$mLastDay} " . ($mNames[$refMonth] ?? '') . " {$refYear} (mulai Minggu pertama).";

            return [
                'success'        => true,
                'sub_resolution' => 'daily',
                'ref_year'       => $refYear,
                'ref_month'      => $refMonth,
                'month_name'     => $mNames[$refMonth] ?? '',
                'labels'         => $allLabels,
                'rates'          => $allRates,
                'samples'        => $allSamples,
                'ngs'            => $allNGs,
                'barColors'      => $barColors,
                'borderColors'   => $borderCols,
                'tooltips'       => $tooltips,
                'sub_desc'       => $subDesc,
                'sections'       => [] // Tidak perlu garis pembatas zona karena murni 1 horizon harian
            ];
        }

        // ══════════════════════════════════════════════════════════════════════════════
        // JIKA MODE MINGGUAN: MULTI-HORIZON (TAHUNAN -> BULANAN -> MINGGUAN W1-W4)
        // ══════════════════════════════════════════════════════════════════════════════

        // 1. HORIZON TAHUNAN (3 atau 4 Tahun Terakhir s/d refYear)
        $detectedYears = [];
        try {
            $stmtYDetect = $pdo->prepare("SELECT DISTINCT YEAR(summary_date) AS yr FROM oqc_daily_summary WHERE YEAR(summary_date) <= :refYear {$custWhere} ORDER BY yr ASC");
            $pYDetect = array_merge($baseParams, [':refYear' => $refYear]);
            $stmtYDetect->execute($pYDetect);
            $detectedYears = array_values(array_filter(array_map('intval', $stmtYDetect->fetchAll(PDO::FETCH_COLUMN))));
        } catch (Exception $e) {}

        if (empty($detectedYears)) {
            $years = [$refYear - 2, $refYear - 1, $refYear];
        } else {
            if (count($detectedYears) > 4) {
                $years = array_slice($detectedYears, -4);
            } else {
                $years = $detectedYears;
            }
            if (!in_array($refYear, $years)) {
                $years[] = $refYear;
                sort($years);
            }
        }

        $minYear = min($years);
        $maxYear = max($years);

        $yearlyData = [];
        foreach ($years as $yr) {
            $yearlyData[(string)$yr] = ['sample' => 0, 'ng' => 0, 'passed' => 0, 'rate' => 0];
        }

        try {
            $pY = array_merge($baseParams, [
                ':min_y' => sprintf('%04d-01-01', $minYear),
                ':max_y' => sprintf('%04d-12-31', $maxYear)
            ]);
            $stmtY = $pdo->prepare("SELECT 
                                        DATE_FORMAT(summary_date, '%Y') AS y_key,
                                        COALESCE(SUM(total_sample_size_lot), 0) AS total_sample_size_lot,
                                        COALESCE(SUM(total_sample_size_session), 0) AS total_sample_size_session,
                                        COALESCE(SUM(total_sample_size), 0) AS total_sample_size,
                                        COALESCE(SUM(total_lots), 0) AS total_lots,
                                        COALESCE(SUM(passed_lots), 0) AS passed_lots,
                                        COALESCE(SUM(rejected_lots), 0) AS rejected_lots,
                                        COALESCE(SUM(total_ng_pcs), 0) AS total_ng_pcs,
                                        COALESCE(SUM(total_ng_samples), 0) AS total_ng_samples
                                    FROM oqc_daily_summary
                                    WHERE summary_date >= :min_y AND summary_date <= :max_y {$custWhere}
                                    GROUP BY y_key");
            $stmtY->execute($pY);
            foreach ($stmtY->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $yk = (string)$r['y_key'];
                if (!isset($yearlyData[$yk])) continue;

                if ($unit === 'lot') {
                    $s = (int)$r['total_lots'];
                    $ng = (int)$r['rejected_lots'];
                    $pass = (int)$r['passed_lots'];
                    $rt = $s > 0 ? round(($isPassed ? ($pass / $s) : ($ng / $s)) * 100, 2) : 0;
                } else {
                    $sLot  = (int)$r['total_sample_size_lot'] ?: (int)$r['total_sample_size'];
                    $sSess = (int)$r['total_sample_size_session'] ?: (int)$r['total_sample_size'];
                    $s = ($sampleBasis === 'session') ? $sSess : $sLot;
                    $ng = max((int)$r['total_ng_pcs'], (int)$r['total_ng_samples']);
                    $pass = max(0, $s - $ng);
                    $rt = $s > 0 ? round(($isPassed ? ($pass / $s) : ($ng / $s)) * 1000000, 1) : 0;
                }
                $yearlyData[$yk] = ['sample' => $s, 'ng' => $ng, 'passed' => $pass, 'rate' => $rt];
            }
        } catch (Exception $e) {}

        // 2. HORIZON BULANAN (Adaptive Rolling 12 Bulan Terakhir s/d refYear-refMonth, mulai data awal)
        $firstDataDate = '2026-10-01';
        try {
            $stMin = $pdo->query("SELECT MIN(summary_date) FROM oqc_daily_summary");
            $dbMin = $stMin ? $stMin->fetchColumn() : null;
            if ($dbMin) {
                $firstDataDate = $dbMin;
            } else {
                $stMinSess = $pdo->query("SELECT MIN(created_at) FROM inspection_sessions WHERE deleted_at IS NULL");
                $dbMinSess = $stMinSess ? substr($stMinSess->fetchColumn(), 0, 10) : null;
                if ($dbMinSess) $firstDataDate = $dbMinSess;
            }
        } catch (Exception $e) {}

        $earliestMonthKey = date('Y-m', strtotime($firstDataDate));

        // Bangun jendela maksimal 12 bulan terakhir yang berakhir di ($refYear, $refMonth)
        $endPeriodDt = new DateTime(sprintf('%04d-%02d-01', $refYear, $refMonth));
        $targetRollingMonths = [];

        // Hitung mundur 12 bulan
        for ($i = 11; $i >= 0; $i--) {
            $dtCursor = clone $endPeriodDt;
            if ($i > 0) {
                $dtCursor->modify("-{$i} months");
            }
            $mKey = $dtCursor->format('Y-m');
            // Jangan sertakan bulan sebelum sistem mulai ada data
            if ($mKey >= $earliestMonthKey) {
                $cYear  = (int)$dtCursor->format('Y');
                $cMonth = (int)$dtCursor->format('n');
                
                // Cari rentang siklus kerja untuk bulan ini (Sunday Anchor)
                $cycleStart = getWorkCycleMonthStartDate($cYear, $cMonth);
                $nextMonthDt = (clone $dtCursor)->modify('+1 month');
                $nextCycleStart = getWorkCycleMonthStartDate((int)$nextMonthDt->format('Y'), (int)$nextMonthDt->format('n'));
                $cycleEnd = date('Y-m-d', strtotime($nextCycleStart . ' -1 day'));

                $targetRollingMonths[$mKey] = [
                    'year'        => $cYear,
                    'month'       => $cMonth,
                    'monthName'   => $mNames[$cMonth],
                    'cycle_start' => $cycleStart,
                    'cycle_end'   => $cycleEnd,
                    'sample'      => 0,
                    'ng'          => 0,
                    'passed'      => 0,
                    'rate'        => 0
                ];
            }
        }

        // Fallback jika kosong, sertakan bulan terpilih
        if (empty($targetRollingMonths)) {
            $mKeySel = $endPeriodDt->format('Y-m');
            $cStart = getWorkCycleMonthStartDate($refYear, $refMonth);
            $nMonthDt = (clone $endPeriodDt)->modify('+1 month');
            $nCStart = getWorkCycleMonthStartDate((int)$nMonthDt->format('Y'), (int)$nMonthDt->format('n'));
            $cEnd = date('Y-m-d', strtotime($nCStart . ' -1 day'));

            $targetRollingMonths[$mKeySel] = [
                'year'        => $refYear,
                'month'       => $refMonth,
                'monthName'   => $mNames[$refMonth],
                'cycle_start' => $cStart,
                'cycle_end'   => $cEnd,
                'sample'      => 0,
                'ng'          => 0,
                'passed'      => 0,
                'rate'        => 0
            ];
        }

        $minRollDate = reset($targetRollingMonths)['cycle_start'];
        $maxRollDate = end($targetRollingMonths)['cycle_end'];

        try {
            $pM = array_merge($baseParams, [
                ':min_m' => $minRollDate,
                ':max_m' => $maxRollDate
            ]);
            $stmtM = $pdo->prepare("SELECT 
                                        summary_date,
                                        COALESCE(SUM(total_sample_size_lot), 0) AS total_sample_size_lot,
                                        COALESCE(SUM(total_sample_size_session), 0) AS total_sample_size_session,
                                        COALESCE(SUM(total_sample_size), 0) AS total_sample_size,
                                        COALESCE(SUM(total_lots), 0) AS total_lots,
                                        COALESCE(SUM(passed_lots), 0) AS passed_lots,
                                        COALESCE(SUM(rejected_lots), 0) AS rejected_lots,
                                        COALESCE(SUM(total_ng_pcs), 0) AS total_ng_pcs,
                                        COALESCE(SUM(total_ng_samples), 0) AS total_ng_samples
                                    FROM oqc_daily_summary
                                    WHERE summary_date >= :min_m AND summary_date <= :max_m {$custWhere}
                                    GROUP BY summary_date");
            $stmtM->execute($pM);
            $rawMRows = $stmtM->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rawMRows as $r) {
                $sDate = $r['summary_date'];
                if ($unit === 'lot') {
                    $s = (int)$r['total_lots'];
                    $ng = (int)$r['rejected_lots'];
                    $pass = (int)$r['passed_lots'];
                } else {
                    $sLot  = (int)$r['total_sample_size_lot'] ?: (int)$r['total_sample_size'];
                    $sSess = (int)$r['total_sample_size_session'] ?: (int)$r['total_sample_size'];
                    $s = ($sampleBasis === 'session') ? $sSess : $sLot;
                    $ng = max((int)$r['total_ng_pcs'], (int)$r['total_ng_samples']);
                    $pass = max(0, $s - $ng);
                }

                foreach ($targetRollingMonths as $ymKey => &$mInfo) {
                    if ($sDate >= $mInfo['cycle_start'] && $sDate <= $mInfo['cycle_end']) {
                        $mInfo['sample'] += $s;
                        $mInfo['ng']     += $ng;
                        $mInfo['passed'] += $pass;
                        break;
                    }
                }
                unset($mInfo);
            }

            foreach ($targetRollingMonths as $ymKey => &$mInfo) {
                $s = $mInfo['sample'];
                $ng = $mInfo['ng'];
                $pass = $mInfo['passed'];
                if ($unit === 'lot') {
                    $mInfo['rate'] = $s > 0 ? round(($isPassed ? ($pass / $s) : ($ng / $s)) * 100, 2) : 0;
                } else {
                    $mInfo['rate'] = $s > 0 ? round(($isPassed ? ($pass / $s) : ($ng / $s)) * 1000000, 1) : 0;
                }
            }
            unset($mInfo);
        } catch (Exception $e) {}

        // 3. HORIZON KE-3: MINGGUAN (DEFAULT: W1 - W4/W5 aturan Sunday Anchor: Minggu - Sabtu)
        $firstDayDt = new DateTime($monthStart);
        $weeklyBuckets = [];
        $curSun = clone $w1Sun;
        $wIdx = 1;
        $targetMonth = $firstDayDt->format('m');
        $todayStr = date('Y-m-d');
        $isCurrentMonthYear = ($refYear === (int)date('Y') && $refMonth === (int)date('n'));

        while ($curSun->format('m') === $targetMonth && $wIdx <= 6) {
            $sStr = $curSun->format('Y-m-d');
            // Jika bulan yang dipilih adalah bulan berjalan, jangan tampilkan minggu di masa depan
            if ($isCurrentMonthYear && $sStr > $todayStr) {
                break;
            }

            $curSat = clone $curSun;
            $curSat->modify('+6 days');

            $eStr = $curSat->format('Y-m-d');
            $sLabel = $curSun->format('d');
            $eLabel = $curSat->format('d');
            $sMonth = $curSun->format('M');
            $eMonth = $curSat->format('M');

            $lblText = ($sMonth === $eMonth) ? "W{$wIdx} ({$sLabel}-{$eLabel} {$sMonth})" : "W{$wIdx} ({$sLabel} {$sMonth}-{$eLabel} {$eMonth})";

            $weeklyBuckets["W{$wIdx}"] = [
                'label'      => $lblText,
                'shortLabel' => "W{$wIdx}",
                'start_date' => $sStr,
                'end_date'   => $eStr,
                'sample'     => 0,
                'ng'         => 0,
                'passed'     => 0,
                'rate'       => 0
            ];

            $curSun->modify('+7 days');
            $wIdx++;
        }

        if (!empty($weeklyBuckets)) {
            $lastBucketKey = 'W' . ($wIdx - 1);
            $wEffStart = $weeklyBuckets['W1']['start_date'];
            $wEffEnd   = $weeklyBuckets[$lastBucketKey]['end_date'];

            try {
                $pW = array_merge($baseParams, [
                    ':ws' => $wEffStart,
                    ':we' => $wEffEnd
                ]);
                $stmtW = $pdo->prepare("SELECT 
                                            summary_date,
                                            COALESCE(SUM(total_sample_size_lot), 0) AS total_sample_size_lot,
                                            COALESCE(SUM(total_sample_size_session), 0) AS total_sample_size_session,
                                            COALESCE(SUM(total_sample_size), 0) AS total_sample_size,
                                            COALESCE(SUM(total_lots), 0) AS total_lots,
                                            COALESCE(SUM(passed_lots), 0) AS passed_lots,
                                            COALESCE(SUM(rejected_lots), 0) AS rejected_lots,
                                            COALESCE(SUM(total_ng_pcs), 0) AS total_ng_pcs,
                                            COALESCE(SUM(total_ng_samples), 0) AS total_ng_samples
                                        FROM oqc_daily_summary
                                        WHERE summary_date >= :ws AND summary_date <= :we {$custWhere}
                                        GROUP BY summary_date");
                $stmtW->execute($pW);
                foreach ($stmtW->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sDate = $r['summary_date'];
                    if ($unit === 'lot') {
                        $s = (int)$r['total_lots'];
                        $ng = (int)$r['rejected_lots'];
                        $pass = (int)$r['passed_lots'];
                    } else {
                        $sLot  = (int)$r['total_sample_size_lot'] ?: (int)$r['total_sample_size'];
                        $sSess = (int)$r['total_sample_size_session'] ?: (int)$r['total_sample_size'];
                        $s = ($sampleBasis === 'session') ? $sSess : $sLot;
                        $ng = max((int)$r['total_ng_pcs'], (int)$r['total_ng_samples']);
                        $pass = max(0, $s - $ng);
                    }

                    foreach ($weeklyBuckets as $wbKey => &$wb) {
                        if ($sDate >= $wb['start_date'] && $sDate <= $wb['end_date']) {
                            $wb['sample'] += $s;
                            $wb['ng']     += $ng;
                            $wb['passed'] += $pass;
                            break;
                        }
                    }
                    unset($wb);
                }

                foreach ($weeklyBuckets as $wbKey => &$wb) {
                    $s = $wb['sample'];
                    $ng = $wb['ng'];
                    $pass = $wb['passed'];
                    if ($unit === 'lot') {
                        $wb['rate'] = $s > 0 ? round(($isPassed ? ($pass / $s) : ($ng / $s)) * 100, 2) : 0;
                    } else {
                        $wb['rate'] = $s > 0 ? round(($isPassed ? ($pass / $s) : ($ng / $s)) * 1000000, 1) : 0;
                    }
                }
                unset($wb);
            } catch (Exception $e) {}
        }

        $allLabels  = [];
        $allRates   = [];
        $allSamples = [];
        $allNGs     = [];
        $barColors  = [];
        $borderCols = [];
        $tooltips   = [];

        // Zona 1: Tahunan (Dark Navy / Slate)
        $idxStartYear = count($allLabels);
        foreach ($yearlyData as $yrStr => $yd) {
            $allLabels[]  = $yrStr;
            $allRates[]   = $yd['rate'];
            $allSamples[] = $yd['sample'];
            $allNGs[]     = $isPassed ? $yd['passed'] : $yd['ng'];
            $barColors[]  = '#1e293b';
            $borderCols[] = '#0f172a';
            $tooltips[]   = "Tahun {$yrStr}";
        }
        $idxEndYear = count($allLabels) - 1;

        // Zona 2: Bulanan (Royal Blue)
        $idxStartMonth = count($allLabels);
        $firstRollMonthLabel = '';
        $lastRollMonthLabel  = '';
        foreach ($targetRollingMonths as $ymKey => $mInfo) {
            $mLabel = $mInfo['monthName'];
            if (count($targetRollingMonths) > 1 && $mInfo['year'] !== $refYear) {
                $mLabel .= ' \'' . substr((string)$mInfo['year'], 2);
            }
            if ($firstRollMonthLabel === '') $firstRollMonthLabel = $mLabel;
            $lastRollMonthLabel = $mLabel;

            $allLabels[]  = $mLabel;
            $allRates[]   = $mInfo['rate'];
            $allSamples[] = $mInfo['sample'];
            $allNGs[]     = $isPassed ? $mInfo['passed'] : $mInfo['ng'];
            $barColors[]  = '#2563eb';
            $borderCols[] = '#1d4ed8';
            $tooltips[]   = "Bulan {$mInfo['monthName']} {$mInfo['year']}";
        }
        $idxEndMonth = count($allLabels) - 1;

        // Zona 3: Mingguan (Emerald / Teal)
        $idxStartMicro = count($allLabels);
        foreach ($weeklyBuckets as $wb) {
            $allLabels[]  = $wb['shortLabel'];
            $allRates[]   = $wb['rate'];
            $allSamples[] = $wb['sample'];
            $allNGs[]     = $isPassed ? $wb['passed'] : $wb['ng'];
            $barColors[]  = '#059669';
            $borderCols[] = '#047857';
            $tooltips[]   = $wb['label'];
        }
        $idxEndMicro = count($allLabels) - 1;

        $monthSectionSub = ($firstRollMonthLabel === $lastRollMonthLabel)
            ? "{$firstRollMonthLabel} {$refYear}"
            : "{$firstRollMonthLabel} – {$lastRollMonthLabel}";

        $subDesc = "Komparasi makro-ke-mikro: Tahunan (" . min($years) . "–" . max($years) . ") → Bulanan ({$monthSectionSub}) → Mingguan (" . ($mNames[$refMonth] ?? '') . ").";

        return [
            'success'        => true,
            'sub_resolution' => 'weekly',
            'ref_year'       => $refYear,
            'ref_month'      => $refMonth,
            'month_name'     => $mNames[$refMonth] ?? '',
            'labels'         => $allLabels,
            'rates'          => $allRates,
            'samples'        => $allSamples,
            'ngs'            => $allNGs,
            'barColors'      => $barColors,
            'borderColors'   => $borderCols,
            'tooltips'       => $tooltips,
            'sub_desc'       => $subDesc,
            'sections'       => [
                [
                    'name'       => 'TAHUNAN',
                    'sub'        => count($years) . ' Thn',
                    'startIndex' => $idxStartYear,
                    'endIndex'   => $idxEndYear,
                    'color'      => '#1e293b'
                ],
                [
                    'name'       => 'BULANAN',
                    'sub'        => $monthSectionSub,
                    'startIndex' => $idxStartMonth,
                    'endIndex'   => $idxEndMonth,
                    'color'      => '#2563eb'
                ],
                [
                    'name'       => 'MINGGUAN',
                    'sub'        => strtoupper($mNames[$refMonth] ?? ''),
                    'startIndex' => $idxStartMicro,
                    'endIndex'   => $idxEndMicro,
                    'color'      => '#059669'
                ]
            ]
        ];
    }
}

