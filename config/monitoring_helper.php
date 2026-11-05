<?php
/**
 * OQC Monitoring & 5S Checklist Helper Functions
 * Menangani logika audit kepatuhan, kalkulasi rule grup, dan lifecycle checklist inspektur.
 */

if (!function_exists('ensure_group_standalone_item')) {
    /**
     * Memastikan grup / kategori tanpa anak memiliki 1 poin item checklist mandiri
     */
    function ensure_group_standalone_item($pdo, $groupId) {
        if (!$pdo || $groupId <= 0) return null;
        try {
            $stmtG = $pdo->prepare("SELECT * FROM oqc_monitoring_groups WHERE id = :gid LIMIT 1");
            $stmtG->execute([':gid' => $groupId]);
            $group = $stmtG->fetch(PDO::FETCH_ASSOC);
            if (!$group) return null;

            // Cek apakah sudah ada item
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM oqc_monitoring_items WHERE group_id = :gid");
            $stmtCount->execute([':gid' => $groupId]);
            if ($stmtCount->fetchColumn() > 0) {
                return null; // Sudah punya anak
            }

            // Buat 1 item default mandiri dengan nama yang sama dengan grup
            $stmtIns = $pdo->prepare("INSERT INTO oqc_monitoring_items 
                (group_id, item_text, hint_guide, is_required, sort_order, is_active) 
                VALUES (:gid, :txt, :hint, 1, 1, 1)");
            $stmtIns->execute([
                ':gid'  => $groupId,
                ':txt'  => $group['title'],
                ':hint' => !empty($group['description']) ? $group['description'] : null
            ]);
            return (int)$pdo->lastInsertId();
        } catch (Exception $e) {
            error_log('[OQC Monitoring] Error in ensure_group_standalone_item: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('sync_all_standalone_groups')) {
    /**
     * Sinkronkan seluruh kategori aktif yang belum memiliki anak agar memiliki poin pemeriksaan mandiri
     */
    function sync_all_standalone_groups($pdo) {
        if (!$pdo) return;
        try {
            $stmt = $pdo->query("SELECT g.id FROM oqc_monitoring_groups g 
                                 LEFT JOIN oqc_monitoring_items i ON g.id = i.group_id 
                                 WHERE g.is_active = 1 
                                 GROUP BY g.id 
                                 HAVING COUNT(i.id) = 0");
            $emptyGroupIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($emptyGroupIds as $gid) {
                ensure_group_standalone_item($pdo, (int)$gid);
            }
        } catch (Exception $e) {
            error_log('[OQC Monitoring] Error in sync_all_standalone_groups: ' . $e->getMessage());
        }
    }
}

if (!function_exists('get_or_create_inspector_checklist')) {
    /**
     * Ambil atau buat header checklist inspektur untuk user, tahap, dan tanggal tertentu.
     */
    function get_or_create_inspector_checklist($pdo, $userId, $phaseId, $date = null, $shift = 'Shift 1') {
        if (!$pdo || $userId <= 0 || $phaseId <= 0) return null;
        $date = $date ?: date('Y-m-d');

        try {
            $stmt = $pdo->prepare("SELECT * FROM oqc_inspector_checklists 
                                   WHERE user_id = :u AND phase_id = :p AND check_date = :d 
                                   ORDER BY id DESC LIMIT 1");
            $stmt->execute([':u' => $userId, ':p' => $phaseId, ':d' => $date]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                return $existing;
            }

            $ins = $pdo->prepare("INSERT INTO oqc_inspector_checklists 
                                 (user_id, phase_id, check_date, shift, status, total_items, checked_items, compliance_rate) 
                                 VALUES (:u, :p, :d, :s, 'in_progress', 0, 0, 0.00)");
            $ins->execute([
                ':u' => $userId,
                ':p' => $phaseId,
                ':d' => $date,
                ':s' => $shift
            ]);
            $newId = (int)$pdo->lastInsertId();

            $stmt2 = $pdo->prepare("SELECT * FROM oqc_inspector_checklists WHERE id = :id LIMIT 1");
            $stmt2->execute([':id' => $newId]);
            return $stmt2->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('[OQC Monitoring] Error in get_or_create_inspector_checklist: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('calculate_monitoring_compliance')) {
    /**
     * Hitung tingkat kepatuhan dan validasi aturan grup untuk sebuah checklist
     */
    function calculate_monitoring_compliance($pdo, $checklistId) {
        if (!$pdo || $checklistId <= 0) {
            return ['is_compliant' => false, 'compliance_rate' => 0, 'total_items' => 0, 'checked_items' => 0, 'missing_groups' => []];
        }

        try {
            $stmtH = $pdo->prepare("SELECT * FROM oqc_inspector_checklists WHERE id = :id LIMIT 1");
            $stmtH->execute([':id' => $checklistId]);
            $header = $stmtH->fetch(PDO::FETCH_ASSOC);
            if (!$header) {
                return ['is_compliant' => false, 'compliance_rate' => 0, 'total_items' => 0, 'checked_items' => 0, 'missing_groups' => []];
            }

            $phaseId = (int)$header['phase_id'];

            // Pastikan kategori mandiri (tanpa anak) memiliki butir pemeriksaan
            sync_all_standalone_groups($pdo);

            // Ambil semua grup aktif
            $stmtG = $pdo->prepare("SELECT * FROM oqc_monitoring_groups WHERE phase_id = :p AND is_active = 1 ORDER BY sort_order ASC, id ASC");
            $stmtG->execute([':p' => $phaseId]);
            $groups = $stmtG->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Ambil seluruh centang
            $stmtC = $pdo->prepare("SELECT item_id, is_checked FROM oqc_inspector_checklist_items WHERE checklist_id = :cid");
            $stmtC->execute([':cid' => $checklistId]);
            $checks = $stmtC->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

            $totalActiveItems = 0;
            $checkedCount = 0;
            $allGroupsValid = true;
            $missingGroups = [];

            foreach ($groups as $grp) {
                $gid = (int)$grp['id'];
                $rule = $grp['rule_type'];
                $minReq = max(1, (int)$grp['min_required']);

                $stmtI = $pdo->prepare("SELECT id, is_required FROM oqc_monitoring_items WHERE group_id = :g AND is_active = 1");
                $stmtI->execute([':g' => $gid]);
                $items = $stmtI->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $groupItemCount = count($items);
                $groupCheckedCount = 0;
                $groupRequiredCount = 0;
                $groupRequiredChecked = 0;

                foreach ($items as $itm) {
                    $iid = (int)$itm['id'];
                    $totalActiveItems++;
                    $isCh = !empty($checks[$iid]);

                    if ($isCh) {
                        $checkedCount++;
                        $groupCheckedCount++;
                    }

                    if ($itm['is_required']) {
                        $groupRequiredCount++;
                        if ($isCh) $groupRequiredChecked++;
                    }
                }

                // Evaluasi aturan grup
                $groupPassed = false;
                if ($rule === 'all_required') {
                    $groupPassed = ($groupRequiredCount > 0) ? ($groupRequiredChecked >= $groupRequiredCount) : ($groupCheckedCount >= $groupItemCount);
                } else {
                    $groupPassed = ($groupCheckedCount >= min($minReq, $groupItemCount));
                }

                if (!$groupPassed && $groupItemCount > 0) {
                    $allGroupsValid = false;
                    $missingGroups[] = $grp['title'];
                }
            }

            $rate = ($totalActiveItems > 0) ? round(($checkedCount / $totalActiveItems) * 100, 2) : 100.00;

            // Update status di header: jika seluruh grup valid dan (ada butir selesai dicek atau belum ada item SOP yang diatur)
            $isComplete = ($allGroupsValid && ($totalActiveItems > 0 || empty($groups)));
            $upd = $pdo->prepare("UPDATE oqc_inspector_checklists 
                                 SET total_items = :tot, checked_items = :chk, compliance_rate = :rt, 
                                     status = :st 
                                 WHERE id = :id");
            $upd->execute([
                ':tot' => $totalActiveItems,
                ':chk' => $checkedCount,
                ':rt'  => $rate,
                ':st'  => $isComplete ? 'completed' : 'in_progress',
                ':id'  => $checklistId
            ]);

            return [
                'is_compliant'    => $allGroupsValid,
                'compliance_rate' => $rate,
                'total_items'     => $totalActiveItems,
                'checked_items'   => $checkedCount,
                'missing_groups'  => $missingGroups
            ];
        } catch (Exception $e) {
            error_log('[OQC Monitoring] Error in calculate_monitoring_compliance: ' . $e->getMessage());
            return ['is_compliant' => false, 'compliance_rate' => 0, 'total_items' => 0, 'checked_items' => 0, 'missing_groups' => []];
        }
    }
}

if (!function_exists('calculate_combined_monitoring_compliance')) {
    /**
     * Hitung tingkat kepatuhan gabungan dari beberapa sesi checklist (misal: Tahap Awal + Tahap Proses)
     */
    function calculate_combined_monitoring_compliance($pdo, array $checklistIds) {
        if (!$pdo || empty($checklistIds)) {
            return [
                'is_compliant'    => false,
                'compliance_rate' => 0.00,
                'total_items'     => 0,
                'checked_items'   => 0,
                'missing_groups'  => []
            ];
        }

        $totalActiveItems = 0;
        $totalChecked = 0;
        $allCompliant = true;
        $missingGroups = [];

        foreach ($checklistIds as $cid) {
            $cid = (int)$cid;
            if ($cid <= 0) continue;
            $res = calculate_monitoring_compliance($pdo, $cid);
            $totalActiveItems += (int)($res['total_items'] ?? 0);
            $totalChecked += (int)($res['checked_items'] ?? 0);

            if (empty($res['is_compliant'])) {
                $allCompliant = false;
            }
            if (!empty($res['missing_groups'])) {
                $missingGroups = array_merge($missingGroups, $res['missing_groups']);
            }
        }

        $rate = ($totalActiveItems > 0) ? round(($totalChecked / $totalActiveItems) * 100, 2) : 100.00;

        return [
            'is_compliant'    => $allCompliant,
            'compliance_rate' => $rate,
            'total_items'     => $totalActiveItems,
            'checked_items'   => $totalChecked,
            'missing_groups'  => array_values(array_unique($missingGroups))
        ];
    }
}

if (!function_exists('is_monitoring_phase_completed')) {
    /**
     * Cek apakah user inspektur sudah menyelesaikan tahap tertentu hari ini
     */
    function is_monitoring_phase_completed($pdo, $userId, $phaseCode, $date = null) {
        if (!$pdo || $userId <= 0) return true;
        $date = $date ?: date('Y-m-d');

        try {
            $stmtP = $pdo->prepare("SELECT id FROM oqc_monitoring_phases WHERE phase_code = :c AND is_active = 1 LIMIT 1");
            $stmtP->execute([':c' => $phaseCode]);
            $phase = $stmtP->fetch(PDO::FETCH_ASSOC);
            if (!$phase) {
                return true; // Jika tahap tidak ada atau nonaktif, jangan blokir
            }

            $stmtH = $pdo->prepare("SELECT id, status FROM oqc_inspector_checklists 
                                    WHERE user_id = :u AND phase_id = :p AND check_date = :d 
                                    ORDER BY id DESC LIMIT 1");
            $stmtH->execute([':u' => $userId, ':p' => $phase['id'], ':d' => $date]);
            $h = $stmtH->fetch(PDO::FETCH_ASSOC);

            if (!$h) {
                return false;
            }

            return ($h['status'] === 'completed');
        } catch (Exception $e) {
            return true; // Failsafe
        }
    }
}
