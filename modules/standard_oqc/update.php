<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

// Enforce permission
require_menu_access('standard_oqc');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/standard_oqc/index.php');
}

// Verify CSRF
csrf_verify();

$id    = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$title = trim($_POST['title'] ?? '');

if (!$id) {
    set_flash('danger', 'ID Dokumen tidak valid.');
    redirect('modules/standard_oqc/index.php');
}

if (empty($title)) {
    set_flash('danger', 'Nama / Judul dokumen wajib diisi.');
    redirect('modules/standard_oqc/edit.php?id=' . $id);
}

$pdo = getDB();
if (!$pdo) {
    set_flash('danger', 'Koneksi database gagal.');
    redirect('modules/standard_oqc/index.php');
}

// Fetch current doc to get old file path
$stmtCur = $pdo->prepare("SELECT * FROM standard_oqc WHERE id = :id");
$stmtCur->execute([':id' => $id]);
$currentDoc = $stmtCur->fetch(PDO::FETCH_ASSOC);

if (!$currentDoc) {
    set_flash('danger', 'Dokumen tidak ditemukan.');
    redirect('modules/standard_oqc/index.php');
}

$userId = current_user()['id'] ?? null;
$hasNewFile = isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK;

if ($hasNewFile) {
    $file = $_FILES['pdf_file'];
    $origName = basename($file['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    if ($ext !== 'pdf') {
        set_flash('danger', 'Hanya file format .pdf yang diperbolehkan.');
        redirect('modules/standard_oqc/edit.php?id=' . $id);
    }

    $uploadDir = __DIR__ . '/../../uploads/standards/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $newFileName = 'std_oqc_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
    $targetPath = $uploadDir . $newFileName;
    $dbFilePath = 'uploads/standards/' . $newFileName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        set_flash('danger', 'Gagal memindahkan file baru ke server.');
        redirect('modules/standard_oqc/edit.php?id=' . $id);
    }

    $fileSize = filesize($targetPath);

    // Update with new file
    try {
        $stmtUpdate = $pdo->prepare("
            UPDATE standard_oqc SET
                title = :title,
                file_path = :file_path,
                file_name_original = :orig_name,
                file_size = :file_size,
                uploaded_by = :uploaded_by,
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmtUpdate->execute([
            ':title'        => $title,
            ':file_path'    => $dbFilePath,
            ':orig_name'    => $origName,
            ':file_size'    => $fileSize,
            ':uploaded_by'  => $userId,
            ':id'           => $id
        ]);

        // HAPUS FILE FISIK LAMA DARI SERVER
        $oldFileServerPath = __DIR__ . '/../../' . $currentDoc['file_path'];
        if (!empty($currentDoc['file_path']) && file_exists($oldFileServerPath)) {
            @unlink($oldFileServerPath);
        }

        set_flash('success', "Dokumen dan file PDF '{$title}' berhasil diperbarui.");
    } catch (PDOException $e) {
        if (file_exists($targetPath)) {
            @unlink($targetPath);
        }
        set_flash('danger', 'Gagal memperbarui database: ' . $e->getMessage());
    }
} else {
    // Update metadata only (keep existing file)
    try {
        $stmtUpdate = $pdo->prepare("
            UPDATE standard_oqc SET
                title = :title,
                uploaded_by = :uploaded_by,
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmtUpdate->execute([
            ':title'        => $title,
            ':uploaded_by'  => $userId,
            ':id'           => $id
        ]);

        set_flash('success', "Informasi dokumen '{$title}' berhasil diperbarui.");
    } catch (PDOException $e) {
        set_flash('danger', 'Gagal memperbarui database: ' . $e->getMessage());
    }
}

redirect('modules/standard_oqc/index.php');
