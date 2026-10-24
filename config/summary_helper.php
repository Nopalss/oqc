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

                // 1. Clear existing summary rows for the target date
                $stmtDel1 = $pdo->prepare("DELETE FROM oqc_daily_summary WHERE summary_date = :dt");
                $stmtDel1->execute([':dt' => $targetDate]);

                $stmtDel2 = $pdo->prepare("DELETE FROM oqc_daily_defect_summary WHERE summary_date = :dt");
                $stmtDel2->execute([':dt' => $targetDate]);

                // 2. Fetch all finished inspection sessions for this operational date
                // Memakai pre-filter index range (sd_min & sd_max) agar tidak full table scan
                $sqlSess = "SELECT ss.id, ss.started_at, ss.closed_at, ss.inspection_type, ss.sample_size, ss.total_scanned_qty, ss.use_safety_stock_qty, ss.auto_fulfilled_by_session_id, ss.batch_sample_size, ss.status,
                                   ss.ng_count, ss.part_id, mp.model_id, mp.aql_level,
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
                                      WHEN ss.closed_at IS NOT NULL AND DATE(ss.closed_at) > DATE(ss.started_at) THEN DATE(ss.closed_at)
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

                            $isNg = ($lot['lot_result'] !== 'passed')
                                 && (($lot['lot_result'] === 'rejected')
                                     || in_array($lot['lot_status'], ['ng_found', 'ng_quarantine', 'replaced'])
                                     || ((int)$lot['active_ng_cnt'] > 0));

                            if ($isNg) {
                                $summaryBuckets[$key]['rejected_lots']++;
                            } else {
                                $summaryBuckets[$key]['passed_lots']++;
                            }

                            // Hitung Beban Kerja Fisik untuk Putaran Re-Inspeksi (Sortir & Uji Ulang)
                            $lid = (int)$lot['id'];
                            $reinspectCount = $reinspectByLot[$lid] ?? 0;

                            if ($reinspectCount > 0) {
                                for ($r = 0; $r < $reinspectCount; $r++) {
                                    $summaryBuckets[$key]['total_lots']++;
                                    $summaryBuckets[$key]['rejected_lots']++;
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

                            // Count distinct lot for this defect type
                            $lotIdentity = $slotId > 0 ? "lot_{$slotId}" : "sess_{$sid}";
                            if (!isset($defectBuckets[$defKey]['_lot_ids'][$lotIdentity])) {
                                $defectBuckets[$defKey]['_lot_ids'][$lotIdentity] = true;
                                $defectBuckets[$defKey]['lot_count']++;
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

                // Insert into oqc_daily_summary
                $stmtInsSum = $pdo->prepare("INSERT INTO oqc_daily_summary (
                    summary_date, customer, part_id, model_id, inspection_type,
                    total_sessions, passed_sessions, rejected_sessions,
                    total_lots, passed_lots, rejected_lots,
                    total_sample_size, total_sample_size_lot, total_sample_size_session,
                    total_ng_samples, total_ng_pcs
                ) VALUES (
                    :summary_date, :customer, :part_id, :model_id, :inspection_type,
                    :total_sessions, :passed_sessions, :rejected_sessions,
                    :total_lots, :passed_lots, :rejected_lots,
                    :total_sample_size, :total_sample_size_lot, :total_sample_size_session,
                    :total_ng_samples, :total_ng_pcs
                )");

                foreach ($summaryBuckets as $row) {
                    $stmtInsSum->execute($row);
                }

                // Insert into oqc_daily_defect_summary (including model_id)
                $stmtInsDef = $pdo->prepare("INSERT INTO oqc_daily_defect_summary (
                    summary_date, customer, part_id, model_id, defect_type_id, lot_count, qty_ng
                ) VALUES (
                    :summary_date, :customer, :part_id, :model_id, :defect_type_id, :lot_count, :qty_ng
                )");

                foreach ($defectBuckets as $dRow) {
                    unset($dRow['_lot_ids']);
                    $stmtInsDef->execute($dRow);
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
