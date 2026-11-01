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

$title = trim($_POST['title'] ?? '');

if (empty($title)) {
    set_flash('danger', 'Nama / Judul dokumen wajib diisi.');
    redirect('modules/standard_oqc/create.php');
}

if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
    set_flash('danger', 'File PDF wajib diunggah.');
    redirect('modules/standard_oqc/create.php');
}

$file = $_FILES['pdf_file'];

// Validate extension & mime
$origName = basename($file['name']);
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

if ($ext !== 'pdf') {
    set_flash('danger', 'Hanya file dengan format .pdf yang diperbolehkan.');
    redirect('modules/standard_oqc/create.php');
}

// Target folder
$uploadDir = __DIR__ . '/../../uploads/standards/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename
$newFileName = 'std_oqc_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
$targetPath = $uploadDir . $newFileName;
$dbFilePath = 'uploads/standards/' . $newFileName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    set_flash('danger', 'Gagal memindahkan file yang diunggah ke server.');
    redirect('modules/standard_oqc/create.php');
}

$fileSize = filesize($targetPath);
$userId = current_user()['id'] ?? null;

// Save to database
$pdo = getDB();
if (!$pdo) {
    set_flash('danger', 'Koneksi database gagal.');
    redirect('modules/standard_oqc/index.php');
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO standard_oqc 
        (title, file_path, file_name_original, file_size, uploaded_by, created_at, updated_at)
        VALUES 
        (:title, :file_path, :orig_name, :file_size, :uploaded_by, NOW(), NOW())
    ");

    $stmt->execute([
        ':title'        => $title,
        ':file_path'    => $dbFilePath,
        ':orig_name'    => $origName,
        ':file_size'    => $fileSize,
        ':uploaded_by'  => $userId
    ]);

    set_flash('success', "Dokumen standard '{$title}' berhasil diunggah.");
} catch (PDOException $e) {
    // Delete file if DB insert fails
    if (file_exists($targetPath)) {
        @unlink($targetPath);
    }
    set_flash('danger', 'Gagal menyimpan ke database: ' . $e->getMessage());
}

redirect('modules/standard_oqc/index.php');
