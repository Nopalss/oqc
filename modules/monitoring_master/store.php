<?php
/**
 * Action Handler Simpan Data Baru Master Template Monitoring OQC
 * Mendukung penyimpanan Tahap (Phase), Grup beserta Butir Item Sekaligus, atau Item Tunggal
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/../../config/monitoring_helper.php';

require_menu_access('monitoring_master');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/monitoring_master/index.php');
}

// Check CSRF
$submittedCsrf = $_POST['csrf_token'] ?? '';
$sessionCsrf   = $_SESSION['csrf_token'] ?? '';
if (!hash_equals($sessionCsrf, $submittedCsrf)) {
    set_flash('danger', 'Sesi kedaluwarsa atau token tidak valid. Silakan coba lagi.');
    redirect('modules/monitoring_master/index.php');
}

$pdo = getDB();
if (!$pdo) {
    set_flash('danger', 'Koneksi database gagal.');
    redirect('modules/monitoring_master/index.php');
}

$type = sanitize($_POST['type'] ?? '');
$redirectPhase = 0;

try {
    if ($type === 'phase') {
        $name         = trim(sanitize($_POST['name'] ?? ''));
        $phaseCode    = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['phase_code'] ?? '')));
        $triggerEvent = sanitize($_POST['trigger_event'] ?? 'post_login');
        $description  = trim(sanitize($_POST['description'] ?? ''));
        $isActive     = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

        if (empty($name) || empty($phaseCode)) {
            set_flash('danger', 'Nama Tahap dan Kode Unik wajib diisi.');
            redirect('modules/monitoring_master/create.php?type=phase');
        }

        // Check unique code
        $stmtChk = $pdo->prepare("SELECT id FROM oqc_monitoring_phases WHERE phase_code = :c LIMIT 1");
        $stmtChk->execute([':c' => $phaseCode]);
        if ($stmtChk->fetch()) {
            set_flash('danger', "Kode tahap '{$phaseCode}' sudah digunakan. Silakan gunakan kode unik lain.");
            redirect('modules/monitoring_master/create.php?type=phase');
        }

        // Urutan otomatis: MAX + 1
        $stmtOrder = $pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM oqc_monitoring_phases");
        $sortOrder = (int)$stmtOrder->fetchColumn();

        $stmt = $pdo->prepare("INSERT INTO oqc_monitoring_phases (phase_code, name, description, trigger_event, sort_order, is_active) 
                               VALUES (:c, :n, :d, :t, :s, :a)");
        $stmt->execute([
            ':c' => $phaseCode,
            ':n' => $name,
            ':d' => $description,
            ':t' => in_array($triggerEvent, ['post_login', 'in_process', 'pre_logout']) ? $triggerEvent : 'post_login',
            ':s' => $sortOrder,
            ':a' => $isActive
        ]);

        $redirectPhase = (int)$pdo->lastInsertId();
        set_flash('success', "Tahap SOP '{$name}' (Urutan #{$sortOrder}) berhasil ditambahkan.");

    } elseif ($type === 'group') {
        $phaseId     = (int)($_POST['phase_id'] ?? 0);
        $title       = trim(sanitize($_POST['title'] ?? ''));
        $description = trim(sanitize($_POST['description'] ?? ''));
        $ruleType    = sanitize($_POST['rule_type'] ?? 'all_required');
        $minRequired = (int)($_POST['min_required'] ?? 0);
        $isActive    = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
        $redirectPhase = $phaseId;

        if ($phaseId <= 0 || empty($title)) {
            set_flash('danger', 'Pilihan Tahap SOP dan Judul Kategori wajib diisi.');
            redirect('modules/monitoring_master/create.php?type=group' . ($phaseId > 0 ? '&phase_id=' . $phaseId : ''));
        }

        if ($ruleType === 'all_required') {
            $minRequired = 0;
        } elseif ($minRequired < 1) {
            $minRequired = 1;
        }

        // Urutan otomatis grup dalam tahap ini: MAX + 1
        $stmtOrder = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM oqc_monitoring_groups WHERE phase_id = :p");
        $stmtOrder->execute([':p' => $phaseId]);
        $sortOrder = (int)$stmtOrder->fetchColumn();

        $pdo->beginTransaction();

        // 1. Simpan Header Grup
        $stmt = $pdo->prepare("INSERT INTO oqc_monitoring_groups (phase_id, title, description, rule_type, min_required, sort_order, is_active) 
                               VALUES (:p, :t, :d, :r, :m, :s, :a)");
        $stmt->execute([
            ':p' => $phaseId,
            ':t' => $title,
            ':d' => $description,
            ':r' => $ruleType === 'representative' ? 'representative' : 'all_required',
            ':m' => $minRequired,
            ':s' => $sortOrder,
            ':a' => $isActive
        ]);

        $newGroupId = (int)$pdo->lastInsertId();

        // 2. Simpan Seluruh Butir Item Checklist di Bawah Grup Ini
        $rawItems = $_POST['items'] ?? [];
        $itemOrder = 1;
        $insertedCount = 0;

        if (is_array($rawItems) && !empty($rawItems)) {
            $stmtItem = $pdo->prepare("INSERT INTO oqc_monitoring_items 
                                      (group_id, item_text, hint_guide, is_required, sort_order, is_active) 
                                      VALUES (:g, :txt, :h, :req, :s, 1)");
            
            foreach ($rawItems as $itm) {
                $txt = trim(sanitize($itm['item_text'] ?? ''));
                if (empty($txt)) {
                    continue; // Lewati jika baris kosong
                }
                $hint = trim(sanitize($itm['hint_guide'] ?? ''));
                $req  = isset($itm['is_required']) ? (int)$itm['is_required'] : 1;

                $stmtItem->execute([
                    ':g'   => $newGroupId,
                    ':txt' => $txt,
                    ':h'   => $hint,
                    ':req' => $req,
                    ':s'   => $itemOrder++
                ]);
                $insertedCount++;
            }
        }

        // Jika kategori dibuat tanpa sub-anak (poin mandiri), buat 1 butir checklist default
        if ($insertedCount === 0) {
            ensure_group_standalone_item($pdo, $newGroupId);
        }

        $pdo->commit();

        $msg = "Kategori '{$title}' berhasil disimpan pada urutan #{$sortOrder}";
        if ($insertedCount > 0) {
            $msg .= " beserta {$insertedCount} poin pemeriksaan.";
        } else {
            $msg .= " sebagai poin pemeriksaan mandiri (langsung dapat diceklis).";
        }
        set_flash('success', $msg);

    } elseif ($type === 'item') {
        $groupId    = (int)($_POST['group_id'] ?? 0);
        $itemText   = trim(sanitize($_POST['item_text'] ?? ''));
        $hintGuide  = trim(sanitize($_POST['hint_guide'] ?? ''));
        $isRequired = isset($_POST['is_required']) ? (int)$_POST['is_required'] : 1;
        $isActive   = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

        if ($groupId <= 0 || empty($itemText)) {
            set_flash('danger', 'Kategori dan Teks Poin Pemeriksaan wajib diisi.');
            redirect('modules/monitoring_master/create.php?type=item' . ($groupId > 0 ? '&group_id=' . $groupId : ''));
        }

        // Ambil phase_id dari grup untuk redirect
        $stmtP = $pdo->prepare("SELECT phase_id FROM oqc_monitoring_groups WHERE id = :g LIMIT 1");
        $stmtP->execute([':g' => $groupId]);
        $grpRow = $stmtP->fetch(PDO::FETCH_ASSOC);
        if ($grpRow) {
            $redirectPhase = (int)$grpRow['phase_id'];
        }

        // Urutan otomatis butir item dalam grup ini: MAX + 1
        $stmtOrder = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM oqc_monitoring_items WHERE group_id = :g");
        $stmtOrder->execute([':g' => $groupId]);
        $sortOrder = (int)$stmtOrder->fetchColumn();

        $stmt = $pdo->prepare("INSERT INTO oqc_monitoring_items (group_id, item_text, hint_guide, is_required, sort_order, is_active) 
                               VALUES (:g, :txt, :h, :req, :s, :a)");
        $stmt->execute([
            ':g'   => $groupId,
            ':txt' => $itemText,
            ':h'   => $hintGuide,
            ':req' => $isRequired,
            ':s'   => $sortOrder,
            ':a'   => $isActive
        ]);

        set_flash('success', "Poin pemeriksaan berhasil ditambahkan pada urutan #{$sortOrder}.");
    } else {
        set_flash('danger', 'Tipe entitas tidak valid.');
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[OQC Monitoring Master] Error store.php: ' . $e->getMessage());
    set_flash('danger', 'Gagal menyimpan data master: ' . $e->getMessage());
}

$targetUrl = 'modules/monitoring_master/index.php';
if ($redirectPhase > 0) {
    $targetUrl .= '?phase_id=' . $redirectPhase;
}
redirect($targetUrl);
