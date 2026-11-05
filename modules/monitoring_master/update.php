<?php
/**
 * Action Handler Update Data Master Template Monitoring OQC
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('monitoring_master');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/monitoring_master/index.php');
}

$submittedCsrf = $_POST['csrf_token'] ?? '';
$sessionCsrf   = $_SESSION['csrf_token'] ?? '';
if (!hash_equals($sessionCsrf, $submittedCsrf)) {
    set_flash('danger', 'Sesi kedaluwarsa atau token tidak valid.');
    redirect('modules/monitoring_master/index.php');
}

$pdo = getDB();
if (!$pdo) {
    set_flash('danger', 'Koneksi database gagal.');
    redirect('modules/monitoring_master/index.php');
}

$type = sanitize($_POST['type'] ?? '');
$id   = (int)($_POST['id'] ?? 0);
$redirectPhase = (int)($_POST['redirect_phase'] ?? 0);

if ($id <= 0) {
    set_flash('danger', 'ID data tidak valid.');
    redirect('modules/monitoring_master/index.php');
}

try {
    if ($type === 'phase') {
        $name         = trim(sanitize($_POST['name'] ?? ''));
        $phaseCode    = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['phase_code'] ?? '')));
        $triggerEvent = sanitize($_POST['trigger_event'] ?? 'post_login');
        $description  = trim(sanitize($_POST['description'] ?? ''));
        $sortOrder    = (int)($_POST['sort_order'] ?? 1);
        $isActive     = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

        if (empty($name) || empty($phaseCode)) {
            set_flash('danger', 'Nama Tahap dan Kode Unik wajib diisi.');
            redirect("modules/monitoring_master/edit.php?type=phase&id={$id}");
        }

        // Check unique code excluding this id
        $stmtChk = $pdo->prepare("SELECT id FROM oqc_monitoring_phases WHERE phase_code = :c AND id != :id LIMIT 1");
        $stmtChk->execute([':c' => $phaseCode, ':id' => $id]);
        if ($stmtChk->fetch()) {
            set_flash('danger', "Kode tahap '{$phaseCode}' sudah digunakan di tahap lain.");
            redirect("modules/monitoring_master/edit.php?type=phase&id={$id}");
        }

        $stmt = $pdo->prepare("UPDATE oqc_monitoring_phases 
                               SET phase_code = :c, name = :n, description = :d, trigger_event = :t, sort_order = :s, is_active = :a 
                               WHERE id = :id");
        $stmt->execute([
            ':c'  => $phaseCode,
            ':n'  => $name,
            ':d'  => $description,
            ':t'  => in_array($triggerEvent, ['post_login', 'in_process', 'pre_logout']) ? $triggerEvent : 'post_login',
            ':s'  => $sortOrder,
            ':a'  => $isActive,
            ':id' => $id
        ]);

        set_flash('success', "Tahap SOP '{$name}' berhasil diperbarui.");

    } elseif ($type === 'group') {
        $phaseId     = (int)($_POST['phase_id'] ?? 0);
        $title       = trim(sanitize($_POST['title'] ?? ''));
        $description = trim(sanitize($_POST['description'] ?? ''));
        $ruleType    = sanitize($_POST['rule_type'] ?? 'all_required');
        $minRequired = (int)($_POST['min_required'] ?? 0);
        $sortOrder   = (int)($_POST['sort_order'] ?? 1);
        $isActive    = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

        if ($phaseId <= 0 || empty($title)) {
            set_flash('danger', 'Tahap SOP dan Judul Kategori wajib diisi.');
            redirect("modules/monitoring_master/edit.php?type=group&id={$id}");
        }

        if ($ruleType === 'all_required') {
            $minRequired = 0;
        } elseif ($minRequired < 1) {
            $minRequired = 1;
        }

        $stmt = $pdo->prepare("UPDATE oqc_monitoring_groups 
                               SET phase_id = :p, title = :t, description = :d, rule_type = :r, min_required = :m, sort_order = :s, is_active = :a 
                               WHERE id = :id");
        $stmt->execute([
            ':p'  => $phaseId,
            ':t'  => $title,
            ':d'  => $description,
            ':r'  => $ruleType === 'representative' ? 'representative' : 'all_required',
            ':m'  => $minRequired,
            ':s'  => $sortOrder,
            ':a'  => $isActive,
            ':id' => $id
        ]);

        // Jika grup ini merupakan poin mandiri (hanya memiliki 1 butir item), sinkronkan teksnya
        $stmtChkSingle = $pdo->prepare("SELECT id FROM oqc_monitoring_items WHERE group_id = :gid");
        $stmtChkSingle->execute([':gid' => $id]);
        $existingItems = $stmtChkSingle->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($existingItems) === 1) {
            $stmtUpdSingle = $pdo->prepare("UPDATE oqc_monitoring_items SET item_text = :t, hint_guide = :h WHERE id = :iid");
            $stmtUpdSingle->execute([
                ':t'   => $title,
                ':h'   => !empty($description) ? $description : null,
                ':iid' => $existingItems[0]['id']
            ]);
        }

        set_flash('success', "Grup / Kategori '{$title}' berhasil diperbarui.");

    } elseif ($type === 'item') {
        $groupId    = (int)($_POST['group_id'] ?? 0);
        $itemText   = trim(sanitize($_POST['item_text'] ?? ''));
        $hintGuide  = trim(sanitize($_POST['hint_guide'] ?? ''));
        $isRequired = isset($_POST['is_required']) ? (int)$_POST['is_required'] : 1;
        $sortOrder  = (int)($_POST['sort_order'] ?? 1);
        $isActive   = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

        if ($groupId <= 0 || empty($itemText)) {
            set_flash('danger', 'Kategori 5S dan Teks Butir Checklist wajib diisi.');
            redirect("modules/monitoring_master/edit.php?type=item&id={$id}");
        }

        $stmt = $pdo->prepare("UPDATE oqc_monitoring_items 
                               SET group_id = :g, item_text = :txt, hint_guide = :h, is_required = :req, sort_order = :s, is_active = :a 
                               WHERE id = :id");
        $stmt->execute([
            ':g'   => $groupId,
            ':txt' => $itemText,
            ':h'   => $hintGuide,
            ':req' => $isRequired,
            ':s'   => $sortOrder,
            ':a'   => $isActive,
            ':id'  => $id
        ]);

        set_flash('success', "Butir checklist berhasil diperbarui.");
    } else {
        set_flash('danger', 'Tipe entitas tidak valid.');
    }
} catch (PDOException $e) {
    set_flash('danger', 'Gagal memperbarui data: ' . $e->getMessage());
}

$targetUrl = 'modules/monitoring_master/index.php';
if ($redirectPhase > 0) {
    $targetUrl .= '?phase_id=' . $redirectPhase;
}
redirect($targetUrl);
