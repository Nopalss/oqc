# OQC Project (PHP Native + jQuery + Tailwind CSS Offline)

Proyek ini dibangun menggunakan **PHP Native**, **jQuery (Offline)**, dan **Tailwind CSS (Offline)** dengan struktur folder modular yang rapi, profesional, dan mudah dikembangkan (*scalable*).

---

## 📁 Struktur Folder Proyek

```text
oqc/
├── assets/                       # Aset Statis Lokal (100% Offline)
│   ├── css/
│   │   ├── input.css            # Source CSS Tailwind
│   │   └── tailwind.css         # Hasil kompilasi Tailwind CSS
│   ├── js/
│   │   ├── vendor/
│   │   │   └── jquery.min.js    # Library jQuery lokal
│   │   └── app.js               # Utility JavaScript & Modal Confirmation
│   └── images/                  # Folder gambar / logo
│
├── config/                       # Konfigurasi Aplikasi & Helper
│   ├── app.php                  # Base URL auto detect & Metadata
│   ├── database.php             # Koneksi PDO Database (MySQL)
│   └── helper.php               # Utility functions (sanitize, redirect, flash, formatting)
│
├── layouts/                      # Component Template Reusable
│   ├── header.php               # Meta head & CSS import
│   ├── sidebar.php              # Navigasi sidebar menu utama
│   ├── navbar.php               # Top navbar & profile info
│   └── footer.php               # Footer HTML & Script JavaScript import
│
├── modules/                      # Modul Halaman & CRUD (Modular Per Menu)
│   ├── dashboard/
│   │   └── index.php            # Dashboard utama
│   └── users/                   # Contoh Modul CRUD User
│       ├── index.php            # [Read] Tabel daftar user
│       ├── create.php           # [Create] Form tambah user
│       ├── store.php            # [Action] Proses simpan user baru
│       ├── edit.php             # [Update] Form edit user
│       ├── update.php           # [Action] Proses simpan perubahan user
│       └── delete.php           # [Delete] Proses hapus user
│
├── schema.sql                    # SQL Script database MySQL
├── index.php                     # Entry point redirect ke dashboard
└── README.md                     # Dokumentasi Proyek
```

---

## 🛠️ Cara Menambah Menu/Modul Baru

Jika Anda ingin membuat modul menu baru (misal modul `products`):

1. Buat folder baru di `modules/products/`.
2. Didalam folder `products/`, buat file-file CRUD berikut:
   - `index.php` (Tampilan daftar produk)
   - `create.php` (Form tambah produk)
   - `store.php` (Proses simpan produk)
   - `edit.php` (Form edit produk)
   - `update.php` (Proses update produk)
   - `delete.php` (Proses hapus produk)
3. Tambahkan link menu baru di `layouts/sidebar.php`:
   ```php
   <a href="<?= base_url('modules/products/index.php') ?>" class="...">
       Produk
   </a>
   ```

---

## 🗄️ Inisialisasi Database (Opsional)
Aplikasi secara otomatis menyediakan *fallback data (mock)* jika database belum dibuat.
Untuk menghubungkan ke MySQL Laragon:
1. Buka phpMyAdmin / HeidiSQL di Laragon.
2. Import file `schema.sql`.
3. Sesuaikan konfigurasi database di `config/database.php` jika menggunakan password.
