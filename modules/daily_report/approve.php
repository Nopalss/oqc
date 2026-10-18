<?php
/**
 * Action Handler: Supervisor Approval (ACC) for Inspection Sessions
 * 
 * PT. Surya Technology Industri — Outgoing Quality Control Division
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$pdo = getDB();
if (!$pdo) {
    if (isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Koneksi basis data gagal.']);
        exit;
    }
    set_flash('error', 'Koneksi basis data gagal.');
    redirect('modules/daily_report/index.php');
}

$currentUser = current_user();
$userId   = (int)($currentUser['id'] ?? 1);
$userName = $currentUser['name'] ?? 'Supervisor';

$isAjax = isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
$action = sanitize($_POST['action'] ?? '');
$redirectDate = sanitize($_POST['date'] ?? date('Y-m-d'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Metode permintaan tidak valid.']);
        exit;
    }
    redirect('modules/daily_report/detail.php?date=' . urlencode($redirectDate));
}

try {
    switch ($action) {
        case 'approve_single':
            $sessionId = (int)($_POST['session_id'] ?? 0);
            $notes     = sanitize($_POST['notes'] ?? 'Disetujui oleh supervisor');

            if ($sessionId <= 0) {
                throw new Exception('ID sesi inspeksi tidak valid.');
            }

            $stmt = $pdo->prepare("
                UPDATE inspection_sessions 
                SET is_approved = 1,
                    approved_by = :uid,
                    approved_at = NOW(),
                    approval_notes = :notes
                WHERE id = :sid
            ");
            $stmt->execute([
                ':uid'   => $userId,
                ':notes' => $notes,
                ':sid'   => $sessionId
            ]);

            $msg = "Sesi #{$sessionId} berhasil disetujui (ACC).";
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success'         => true,
                    'message'         => $msg,
                    'session_id'      => $sessionId,
                    'supervisor_name' => $userName,
                    'approved_at'     => date('d M Y H:i') . ' WIB'
                ]);
                exit;
            }
            set_flash('success', $msg);
            break;

        case 'unapprove_single':
            $sessionId = (int)($_POST['session_id'] ?? 0);

            if ($sessionId <= 0) {
                throw new Exception('ID sesi inspeksi tidak valid.');
            }

            $stmt = $pdo->prepare("
                UPDATE inspection_sessions 
                SET is_approved = 0,
                    approved_by = NULL,
                    approved_at = NULL,
                    approval_notes = NULL
                WHERE id = :sid
            ");
            $stmt->execute([':sid' => $sessionId]);

            $msg = "Persetujuan untuk Sesi #{$sessionId} berhasil dibatalkan.";
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success'    => true,
                    'message'    => $msg,
                    'session_id' => $sessionId
                ]);
                exit;
            }
            set_flash('info', $msg);
            break;

        case 'approve_all_date':
            $date = sanitize($_POST['date'] ?? '');
            $notes = sanitize($_POST['notes'] ?? 'Persetujuan massal harian oleh supervisor');

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new Exception('Format tanggal inspeksi tidak valid.');
            }

            $stmt = $pdo->prepare("
                UPDATE inspection_sessions ss
                SET ss.is_approved = 1,
                    ss.approved_by = :uid,
                    ss.approved_at = NOW(),
                    ss.approval_notes = :notes
                WHERE ss.is_approved = 0
                  AND (DATE(ss.started_at) = :tgl OR EXISTS (
                      SELECT 1 FROM inspection_session_lots isl_chk 
                      WHERE isl_chk.inspection_session_id = ss.id AND DATE(isl_chk.created_at) = :tgl_chk
                  ))
            ");
            $stmt->execute([
                ':uid'     => $userId,
                ':notes'   => $notes,
                ':tgl'     => $date,
                ':tgl_chk' => $date
            ]);
            $affected = $stmt->rowCount();

            $msg = "Seluruh sesi inspeksi tanggal " . date('d M Y', strtotime($date)) . " berhasil disetujui ({$affected} sesi diperbarui).";
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success'         => true,
                    'message'         => $msg,
                    'affected_count'  => $affected,
                    'supervisor_name' => $userName,
                    'approved_at'     => date('d M Y H:i') . ' WIB'
                ]);
                exit;
            }
            break;

        case 'approve_all_filtered':
            $notes = sanitize($_POST['notes'] ?? 'Persetujuan massal oleh supervisor');
            $sessionIdsRaw = $_POST['session_ids'] ?? '';
            $startDate = sanitize($_POST['start_date'] ?? '');
            $endDate = sanitize($_POST['end_date'] ?? '');
            $inspId = (int)($_POST['inspector_id'] ?? 0);

            // Jika daftar session_ids spesifik dikirim via comma-separated string atau array
            $targetIds = [];
            if (is_array($sessionIdsRaw)) {
                $targetIds = array_filter(array_map('intval', $sessionIdsRaw));
            } elseif (is_string($sessionIdsRaw) && trim($sessionIdsRaw) !== '') {
                $targetIds = array_filter(array_map('intval', explode(',', $sessionIdsRaw)));
            }

            if (!empty($targetIds)) {
                $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
                $stmt = $pdo->prepare("
                    UPDATE inspection_sessions 
                    SET is_approved = 1,
                        approved_by = ?,
                        approved_at = NOW(),
                        approval_notes = ?
                    WHERE is_approved = 0 AND id IN ($placeholders)
                ");
                $params = array_merge([$userId, $notes], $targetIds);
                $stmt->execute($params);
                $affected = $stmt->rowCount();
            } else {
                // Berdasarkan rentang tanggal & opsional inspector_id
                $whereApprove = ["ss.is_approved = 0"];
                $paramsApprove = [
                    ':uid'   => $userId,
                    ':notes' => $notes
                ];

                if (!empty($startDate) && !empty($endDate)) {
                    $whereApprove[] = "DATE(ss.started_at) BETWEEN :sd AND :ed";
                    $paramsApprove[':sd'] = $startDate;
                    $paramsApprove[':ed'] = $endDate;
                }

                if ($inspId > 0) {
                    $whereApprove[] = "ss.inspector_id = :insp_id";
                    $paramsApprove[':insp_id'] = $inspId;
                }

                $whereApproveStr = implode(' AND ', $whereApprove);
                $stmt = $pdo->prepare("
                    UPDATE inspection_sessions ss
                    SET ss.is_approved = 1,
                        ss.approved_by = :uid,
                        ss.approved_at = NOW(),
                        ss.approval_notes = :notes
                    WHERE {$whereApproveStr}
                ");
                $stmt->execute($paramsApprove);
                $affected = $stmt->rowCount();
            }

            $msg = "Sebanyak {$affected} sesi inspeksi berhasil disetujui (ACC All).";
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success'         => true,
                    'message'         => $msg,
                    'affected_count'  => $affected,
                    'supervisor_name' => $userName,
                    'approved_at'     => date('d M Y H:i') . ' WIB'
                ]);
                exit;
            }
            set_flash('success', $msg);
            break;

        default:
            throw new Exception('Aksi yang diminta tidak dikenali.');
    }
} catch (Exception $e) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
    set_flash('error', 'Gagal memproses persetujuan: ' . $e->getMessage());
}

redirect('modules/daily_report/detail.php?date=' . urlencode($redirectDate));
