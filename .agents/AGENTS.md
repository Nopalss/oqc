# Workspace Guidelines & Project Architecture: OQC Project

Proyek ini adalah aplikasi web manajemen **OQC System** yang dibangun dengan ketentuan teknis sebagai berikut:

## 1. Teknologi & Arsitektur Utama
- **Backend**: PHP Native (PDO Prepared Statements, tanpa framework).
- **Frontend Logic & DOM**: jQuery (v3.7.1 lokal) & JavaScript ES6+.
- **Styling & UI**: Tailwind CSS (Offline-first compiled CSS at `assets/css/tailwind.css`).
- **Mode Aset**: **100% Offline**. Tidak boleh menggunakan CDN eksternal (Google Fonts / cdnjs / tailwindcdn).
- **Deployment**: Server produksi adalah web server PHP biasa (Apache/Nginx) **tanpa Node.js**.

## 2. Struktur Folder Modular
Seluruh fitur/halaman menu wajib diletakkan di dalam folder `modules/` secara terisolasi dengan struktur file CRUD standar:
- `modules/<nama_menu>/index.php` (Read / Listing Data)
- `modules/<nama_menu>/create.php` (Form Create)
- `modules/<nama_menu>/store.php` (Action Handler Simpan Baru)
- `modules/<nama_menu>/edit.php` (Form Edit)
- `modules/<nama_menu>/update.php` (Action Handler Update Data)
- `modules/<nama_menu>/delete.php` (Action Handler Hapus Data)

## 3. Tata Cara Konfigurasi & Layout
- **Aset Statis**: Selalu disimpan di `assets/css/` dan `assets/js/`.
- **Global Helper**: Fungsi helper global berada di `config/helper.php` (`base_url()`, `sanitize()`, `redirect()`, `set_flash()`, `get_flash()`).
- **Koneksi Database**: `config/database.php` menggunakan PDO. Selalu gunakan prepared statements (`$stmt->prepare()`). Jika DB belum tersambung, gunakan session mock fallback.
- **Layout Template**: Komponen bersama di-include dari `layouts/header.php`, `layouts/sidebar.php`, `layouts/navbar.php`, dan `layouts/footer.php`.

## 4. Konvensi Koding
- Bebas dari SQL Injection (selalu via PDO parameter binding).
- Bebas dari XSS (selalu bungkus output string pengguna dengan `sanitize()` atau `htmlspecialchars()`).
- Selalu gunakan class utility Tailwind CSS yang terkompilasi offline di `assets/css/tailwind.css`.
