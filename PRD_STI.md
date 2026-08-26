# Product Requirements Document (PRD)
# Sistem OQC (Outgoing Quality Control) — PT Surya Technology Industri

**Versi:** 0.1 (Draft Awal)
**Tanggal:** 16 Agustus 2026
**Status:** Draft — masih ada beberapa bagian TBD (To Be Defined) yang akan dilengkapi bertahap per fitur

---

## 1. Overview & Background

PT Surya Technology Industri (STI) saat ini menjalankan proses OQC (Outgoing Quality Control) secara manual/semi-manual: penjadwalan inspeksi via kanban fisik, pengecekan dimensi part, perbandingan part aktual dengan drawing, hingga pelaporan performa kualitas dilakukan terpisah-pisah dan sebagian besar manual.

Proyek ini bertujuan membangun **sistem OQC berbasis web** yang mendigitalisasi dan mengotomatisasi alur inspeksi outgoing quality control, mulai dari penjadwalan, validasi kesiapan part, proses inspeksi (compare ke drawing, sampling, deteksi NG), hingga pelaporan performa secara real-time.

## 2. Objectives

- Mendigitalisasi proses OQC yang saat ini manual (kanban, pencatatan hasil cek) agar lebih konsisten dan terlacak (traceable).
- Memvalidasi otomatis apakah suatu lot sudah melalui pengecekan dimensi sebelum masuk proses OQC.
- Mempercepat proses inspeksi lewat otomatisasi sampling (AQL), deteksi NG, dan pencetakan rejection sheet.
- Menyediakan dashboard performa kualitas secara real-time untuk monitoring manajemen.
- Mengurangi human error dalam pencatatan data inspeksi.

## 3. Scope

### 3.1 In Scope (Fase 1 — Full end-to-end alur OQC)

- Modul **Kanban** (jadwal part yang perlu dikirim/diperiksa)
- Modul **Daily Inspection Data (DID)** — dulu bernama "SPARQ", di-rename atas permintaan klien (status cek dimensi per lot)
- Modul **Scan & Inspeksi OQC** (scan/validasi part → compare drawing → sampling AQL → deteksi NG → rejection sheet, dalam 1 halaman)
- Modul **Master Data Part** (referensi utama Part Code & Part Name)
- Modul **Master Data Drawing** (2D & 3D)
- Modul **Packing / Close Inspection**
- Modul **Dashboard Performance**
- Modul **User Management & Role Access**

### 3.2 Out of Scope / Belum Diputuskan (Fase 1)

- Integrasi dengan ERP/MES existing (sistem dibangun **standalone**)
- Digitalisasi checklist 5S area (masih manual, status: *TBD — perlu konfirmasi apakah tetap manual selamanya*)
- Integrasi langsung dengan alat ukur dimensi (CMM, dsb.) — *TBD*

## 4. Platform & Arsitektur

| Aspek | Keputusan |
|---|---|
| Platform | Web application (browser-based) |
| Akses device | PC & tablet di line produksi |
| Arsitektur | Standalone, dibangun dari nol (bukan integrasi ERP/MES) |
| Hardware pendukung | QR/Barcode scanner (opsional — input manual juga didukung) |
| Format file drawing | 2D: PDF, 3D: `.STP` |

### 4.1 Struktur Navigasi (Referensi Mockup)

Berdasarkan mockup desain yang sudah dibuat, struktur sidebar aplikasi:

- **Data Referensi**
  - Upload DID *(dulu "Upload SPARQ")* — lihat FR-2
  - Upload Kanban — lihat FR-1
- **Operasional**
  - Scan Inspeksi — lihat FR-3
  - Dashboard Laporan — lihat FR-7

> Menu untuk Master Data Part (FR-4), Master Data Drawing (FR-5), dan User Management (FR-8) belum muncul di mockup yang ada — **TBD**, kemungkinan masuk kategori terpisah (misal "Pengaturan"/"Master Data") saat desain menu lain dibuat.

Layout umum tiap halaman: sidebar kiri (fixed, dark theme) + breadcrumb + judul halaman + konten utama (card, tabel, atau chart sesuai kebutuhan modul).

### 4.2 Tech Stack

| Layer | Teknologi | Catatan |
|---|---|---|
| Backend | **PHP Native** (tanpa framework MVC) | Boleh tetap pakai library spesifik via Composer (misal untuk Excel) — tidak dianggap melanggar prinsip "native" |
| CSS | **Tailwind CSS** (Standalone CLI) | Di-compile jadi 1 file CSS statis. Tidak butuh Node.js/npm saat runtime, dan **tidak pakai CDN** |
| JavaScript | **jQuery** | File di-hosting lokal (self-hosted), tidak pakai CDN |
| Baca/tulis Excel | **PhpSpreadsheet** (via Composer) | Dipakai untuk fitur import Excel di FR-1 (Kanban) & FR-2 (DID) |
| Render file PDF (2D drawing) | Native browser (`<embed>`/`<iframe>`) atau **PDF.js** (self-hosted) | Browser modern sudah bisa render PDF secara native tanpa library tambahan |
| Render file `.STP` (3D drawing) | CAD kernel berbasis **WebAssembly** (self-hosted) + **Three.js** untuk rendering | **Full interactive** (rotate/zoom/pan) di browser — lihat catatan risiko di bawah |
| Print Rejection Sheet (auto) | **Browser kiosk mode** (Chrome, flag `--kiosk-printing`) | Print langsung ke printer default tanpa dialog konfirmasi — perlu konfigurasi khusus di tiap PC line produksi (lihat FR-3) |
| Deployment aset | Seluruh CSS/JS/library **di-host lokal** | **Tidak ada dependency ke CDN eksternal** — sesuai kebutuhan sistem yang beroperasi di line produksi |

**Catatan Risiko Teknis — Viewer 3D (`.STP`):**
Render STEP file secara *full-interactive* di browser (rotate/zoom/pan layaknya CAD viewer) adalah bagian **paling kompleks secara teknis** dari sistem ini. Dibutuhkan CAD kernel (umumnya berbasis WebAssembly) untuk mem-parsing geometri BREP dari file STEP, lalu men-tessellate-nya jadi mesh untuk dirender lewat WebGL (mis. via Three.js). Library semacam ini bisa di-*self-host* penuh (tanpa CDN/server eksternal), sehingga tetap sesuai requirement offline. Namun perlu dialokasikan waktu riset, development, dan testing ekstra dibanding modul lain — terutama untuk memastikan performa render tetap responsif untuk file STEP berukuran besar.

## 5. User Roles & Permissions

| Role | Akses |
|---|---|
| **Admin** | Full access — kelola master data (part, drawing, user, kanban), lihat seluruh laporan, konfigurasi sistem |
| **QC Inspector** | Input/scan hasil inspeksi, akses modul kanban & daily inspection data terkait tugas hariannya |
| **Supervisor / Viewer** | Read-only — akses dashboard performance & laporan saja |

> Detail matrix permission per fitur (create/read/update/delete) — **TBD**, akan dirinci saat masuk tahap desain fitur masing-masing.

## 6. Functional Requirements

### FR-1 — Modul Planning Inspeksi (Kanban & Safety Stock)
- **Deskripsi:** Mengelola daftar/jadwal part yang direncanakan untuk diinspeksi oleh QC. Terbagi menjadi 2 Tipe Planning:
  1. **Kanban (Pengiriman Customer):** Sumber data dari import file *"eProcurement Request Item"* atau manual entry untuk pengiriman barang ke customer.
  2. **Safety Stock (Restock Internal):** Di-create oleh Admin/Supervisor saat waktu lengang (fill-in shift) untuk restock part cadangan yang belum ada Kanban kirimnya. Total Qty diisi sesuai Total Qty pada Lot tersebut untuk acuan sampel AQL.
- **Input Method:** Manual entry atau import Excel.
- **Multi-import per hari:** Sistem mendukung **import berkali-kali dalam 1 hari** — data dari tiap sesi import **terakumulasi** menjadi satu list, tidak saling menimpa (replace).
- **Auto-Match / Claim Stock:** Ketika file Kanban baru di-import, sistem secara otomatis mengecek apakah Part Code tersebut sudah diinspeksi dan berstatus `PASSED (Safety Stock Ready)`. Jika ada, Kanban tersebut otomatis ditandai **`READY TO SHIP (Auto-fulfilled by Safety Stock)`** tanpa perlu inspeksi ulang oleh QC.

**Struktur Field (per baris item):**

| Field | Keterangan |
|---|---|
| Plan Type | `kanban` (Pengiriman) atau `safety_stock` (Restock) |
| Kanban No. | Nomor kanban (contoh: `0004573892`), opsional untuk Safety Stock |
| Item Code | Kode item/part (Part Code) |
| Item Description | Nama/deskripsi item |
| **Customer** | Tujuan pengiriman (opsional/default "INTERNAL STOCK" untuk Safety Stock) |
| Req. Date | Tanggal request / tanggal rencana inspeksi |
| Qty | Jumlah yang diminta / Total Qty pada Lot (acuan AQL) |
| ETA | Estimated Time Arrival (datetime, opsional) |
| Str.Loc | Kode lokasi penyimpanan (opsional) |
| Supply Area | Kode area suplai (opsional) |
| Remark | Catatan tambahan |

**Metadata per Batch Import:**
Info header dari file Excel disimpan per batch import (bukan per baris item), untuk keperluan histori:
- Vendor
- Nomor Dokumen (contoh: `228746`)
- Print Date Time
- Plan Type (`kanban` / `safety_stock`)

**Alur Create (Manual & Import):** Mengikuti pola yang sama seperti Daily Inspection Data (FR-2) — input manual atau import Excel dengan tahap review/preview sebelum data final disimpan.

**Halaman Index / Riwayat & CRUD:** Menampilkan seluruh data historis dengan tab filter (Kanban vs Safety Stock), filter tanggal, dan full CRUD (create/read/update/delete) tersedia langsung dari halaman index.

**Catatan:** Kanban bersifat independen dari Daily Inspection Data — tidak ada relasi permanen antar data, tapi digunakan sebagai referensi saat proses validasi OQC (lihat FR-3).

### FR-2 — Modul Daily Inspection Data (DID)
- **Deskripsi:** Mencatat status pengecekan dimensi per lot, dilakukan setelah barang selesai produksi dan masuk gudang. Fokus utama: status "sudah dicek" per lot — bukan lokasi gudang.
- **Nama sebelumnya:** "SPARQ" — di-rename menjadi **DID (Daily Inspection Data)** atas permintaan klien. Menu di sidebar: **"Upload DID"** (kategori *Data Referensi*).
- **Sumber data:** Data induk berasal dari laporan harian departemen produksi (referensi: file *"Daily Report Input SPARQ Produksi"*), diinput ke sistem OQC lewat modul ini.
- **Input method:** Manual entry atau import file **Excel**.

**Struktur Field:**

| Field | Wajib? | Keterangan |
|---|---|---|
| Part Code | Wajib | Kode part |
| Part Name | Wajib | Nama part |
| Lot Number | Wajib | Nomor lot produksi |
| Cavity (Cav) | Wajib | Nomor cavity mold — wajib diisi untuk semua part |
| Inspecting Date | Wajib | Tanggal inspeksi, format `MM/DD/YYYY` |
| Status Inspect | Wajib | Hanya 2 kemungkinan value: **OK** / **NG** |
| PIC | Wajib | Nama inspector/petugas yang melakukan cek |
| Remark | Opsional | Catatan tambahan |

**Alur Create (Manual & Import):**
1. User memilih input manual **atau** upload file Excel.
2. Jika import Excel → sistem menampilkan **halaman review/preview** sebelum data final disimpan; user bisa mengedit baris yang salah di tahap ini.
3. **Validasi duplikat otomatis:** sistem mengecek kombinasi **Part Code + Lot Number**. Jika kombinasi tersebut sudah ada di Daily Inspection Data, baris tersebut **di-block/reject** (tidak boleh tersimpan dobel).
4. Setelah direview dan tidak ada error/duplikat, user konfirmasi → data tersimpan ke Daily Inspection Data.

**Halaman Index / Riwayat:**
- Menampilkan **seluruh data historis** yang pernah diupload/diinput (bukan hanya hari ini), dengan kolom tanggal untuk filter.
- **Full CRUD tersedia langsung dari halaman index** — user bisa edit maupun hapus data yang sudah tersimpan, tidak terbatas hanya di alur create.

**Catatan:** Waktu pengecekan tidak harus sinkron dengan jadwal kirim di Kanban — sebuah lot bisa dicek kapan saja, tidak selalu langsung dikirim.

### FR-3 — Modul Scan & Inspeksi OQC (Halaman Tunggal)
Digabung dari alur validasi & proses inspeksi menjadi **1 halaman** — inspector tidak perlu pindah halaman dari scan sampai selesai mencatat hasil.

**A. Tahap Scan & Validasi:**
1. Inspector melakukan **scan QR/barcode** pada label part, atau **input manual** kode label.
2. Sistem mengecek apakah kombinasi **part code + lot number** sudah tercatat di **Daily Inspection Data (FR-2)** — validasi bahwa lot ini sudah melalui cek dimensi.
   - Jika tidak ditemukan → sistem menampilkan notifikasi bahwa lot belum tercatat/dicek.
   - Jika ditemukan → lanjut.
3. Sistem mengecek apakah **part code** (tanpa lot number) terdaftar di **Kanban (FR-1)** — untuk mengambil info **Total Qty** (jumlah yang direncanakan dikirim) sebagai referensi tampilan.
   - **Aturan matching:** Jika ada lebih dari satu Kanban No. yang cocok dengan part code yang sama, sistem otomatis memilih entri **paling lama (FIFO — First In First Out)**. Hasil inspeksi ini kemudian **tertaut ke Kanban No. spesifik** tersebut (dipakai untuk pelaporan/filter di Dashboard, FR-7).
4. Sistem mengambil & menampilkan drawing terkait dari **Master Data Drawing (FR-5)** — tab **Gambar 2D (PDF)** dan **Tampilan 3D (`.STP`)**, sesuai yang tersedia untuk part tersebut.

**B. Info yang Ditampilkan (Header Halaman):**
- Part Code, Lot Number, Total Qty (dari Kanban)
- Acuan sampling: SPL Code / Level AQL, Sample Size, dan Reject Number (lihat bagian D)

**C. Tahap Inspeksi Sample (per 1 pcs):**
- Sample diperiksa satu per satu hingga mencapai Sample Size.
- **Tombol OK** — wajib diklik kalau sample sesuai drawing, lanjut ke sample berikutnya.
- **Tombol NG** — diklik kalau ditemukan ketidaksesuaian. Muncul form pencatatan:
  - **Jenis Defect** (dropdown, dengan opsi menambah jenis defect baru)
  - **Qty NG** (jumlah kemunculan defect tersebut pada sample yang sama)
  - **Remark** (opsional)
  - Satu sample **bisa punya lebih dari satu catatan defect** (beberapa jenis NG ditemukan sekaligus pada 1 pcs yang sama) — dicatat sebagai entri terpisah (NG #1, NG #2, dst).
- Progress ditampilkan real-time: "Sample Diperiksa: x / [Sample Size]" dan "NG Ditemukan: x / [Reject Number]".
- Riwayat NG yang ditemukan pada sesi inspeksi part ini ditampilkan sebagai list ("Riwayat NG Inspeksi Ini").

**D. Standar Sampling AQL (Fixed, Berlaku untuk Semua Part):**
- Level: **G-II**, AQL: **0,4** — standar tunggal perusahaan, **tidak berbeda per part**.
- Sample Size & Reject Number ditentukan otomatis berdasarkan **Total Qty** (dari Kanban) dikombinasikan dengan tabel AQL standar tersebut.
- **Catatan:** Tabel lengkap Sample Size & Reject Number per range Qty (referensi ANSI/ASQ Z1.4 atau tabel internal perusahaan) — **TBD**, perlu data tabel asli dari klien untuk implementasi.

**E. Rejection Sheet (Auto-Print & Reprint):**
- Begitu jumlah NG mencapai **Reject Number**, sistem **otomatis mencetak Rejection Sheet** untuk ditempel di box NG.
- **Pendekatan teknis print:** Browser dijalankan dalam **kiosk mode** (flag khusus Chrome yang mencetak langsung ke printer default tanpa dialog konfirmasi) di tiap PC line produksi — lihat catatan di Section 4.2.
- Tersedia tombol **Print Ulang** (manual) sebagai fallback kalau auto-print gagal keluar.
- Format & layout Rejection Sheet — **TBD**, akan ditentukan bersama klien saat masuk tahap development.

**F. Halaman Index / Riwayat Inspeksi:**
- Tabel riwayat scan/pengecekan yang sudah dilakukan, menampilkan: part yang diperiksa, status, total kasus NG ditemukan per part, dibandingkan terhadap total part yang direncanakan dikirim hari itu (dari Kanban).

### FR-4 — Modul Master Data Part
- **Deskripsi:** Referensi utama Part Code & Part Name yang dipakai sebagai basis di seluruh sistem — termasuk sebagai sumber pilihan part saat membuat Master Data Drawing (FR-5).
- **Sumber data:** Kombinasi dua cara:
  1. Input manual oleh Admin.
  2. Otomatis ter-generate dari part code/part name baru yang muncul lewat import DID (FR-2) atau Kanban (FR-1).
  - **Catatan:** Mekanisme auto-generate — apakah part baru langsung tersimpan otomatis, atau masuk semacam antrian approval Admin dulu — **TBD**.
- **Struktur Field:** Part Code, Part Name.
- **Halaman & CRUD:** Index (daftar part terdaftar) dengan CRUD lengkap — Create (manual), Read, Update, Delete.
- **Akses:** Admin only.
- **Catatan:** Behavior delete part yang sudah pernah dipakai di transaksi lain (DID/Kanban/Drawing) — **TBD**.

### FR-5 — Modul Master Data Drawing (2D/3D)
- **Deskripsi:** Halaman untuk mengelola file drawing per part, digunakan sebagai acuan pembanding saat inspeksi (FR-3).
- **Prasyarat:** Part harus sudah terdaftar di **Master Data Part (FR-4)**. Saat create drawing, user **select part** dari Master Data Part — bukan input ulang Part Code/Name secara manual.
- **Format file:** 2D dalam PDF, 3D dalam `.STP`.
- **Partial upload:** Sebuah part **boleh cuma punya salah satu jenis drawing** (2D saja atau 3D saja) atau dua-duanya. Upload bisa dilakukan **bertahap** — misal isi 2D dulu, 3D menyusul kapan saja lewat edit.
- **Struktur Halaman:**
  - **Index:** Daftar part yang sudah terdaftar drawing-nya, dengan indikator ketersediaan 2D dan/atau 3D per part.
  - **Create:** Pilih part dari Master Data Part → upload file 2D dan/atau 3D.
  - **Detail:** Menampilkan info part + viewer 2D (PDF) + viewer 3D (`.STP`, full-interactive — lihat Section 4.2) jika tersedia.
  - **Edit:** Update/replace file drawing yang sudah ada (termasuk melengkapi drawing yang tadinya partial).
  - **Delete:** Hapus drawing (part tetap ada di Master Data Part, hanya file drawing-nya yang dihapus).
- **Akses:** Admin only.
- **Fungsi:** Saat inspector input/scan part code (FR-3), sistem otomatis menampilkan drawing terkait dari modul ini untuk proses inspeksi (FR-3).
- **Catatan:** Versioning drawing (jika ada revisi drawing dari klien, bagaimana histori versi lama disimpan) — **TBD**.

### FR-6 — Modul Packing / Close Inspection
- **Deskripsi:** Bukan halaman/CRUD terpisah — ini adalah **status akhir** dari proses inspeksi di FR-3, otomatis terbentuk begitu inspector selesai memeriksa seluruh sample.
- **Status Lot (otomatis, berdasarkan hasil inspeksi FR-3):**
  - **Passed / Siap Packing** — jika jumlah NG **tidak mencapai** Reject Number sampai seluruh Sample Size selesai diperiksa.
  - **Rejected** — jika jumlah NG **mencapai** Reject Number (Rejection Sheet sudah auto-print, lihat FR-3E).
  - **Closed** — status final, lot dianggap selesai dari proses OQC (baik Passed maupun Rejected), otomatis tersimpan begitu proses inspeksi berakhir.
- **Tampilan:** Status ini muncul sebagai kolom "Status" di Halaman Index/Riwayat Inspeksi (FR-3F) — tidak ada halaman terpisah untuk modul ini.
- **Asumsi:** Ini interpretasi default berdasarkan alur yang sudah dibangun di FR-3. Kalau ternyata "Packing" itu proses fisik terpisah yang perlu dicatat sendiri (misal qty yang di-pack, nomor box, siapa yang packing) — kabari, nanti dipisah jadi modul sendiri.

### FR-7 — Modul Dashboard Performance
Sesuai mockup desain yang sudah dibuat:

**Filter:**
- Preset periode: **Hari Ini / Mingguan / Bulanan / Tahunan** (tab pilihan).
- Custom date range: pilih tanggal awal — akhir, lalu tombol "Terapkan".
- Filter tambahan **Customer** — sumber data dari field **Customer** di Kanban (FR-1, per baris item), ditautkan ke hasil inspeksi lewat relasi Kanban No. ↔ Inspection Session (hasil matching FIFO di FR-3).

**KPI Cards (4):**
- Total Inspected (pcs) — dengan % perubahan vs periode sebelumnya
- Pass Rate (%) — dengan % perubahan vs periode sebelumnya
- Total NG (pcs) — dengan % perubahan vs periode sebelumnya
- Total Lot — dengan jumlah perubahan vs periode sebelumnya

**Grafik & Breakdown:**
- **Tren Total NG** — line chart, jumlah NG per tanggal dalam periode yang difilter.
- **Top 5 Part Paling Banyak NG** — ranked list (part name, part code, jumlah NG, bar visual) untuk periode terpilih.
- **Breakdown Jenis Defect** — donut chart distribusi berdasarkan tipe NG, dengan Total NG di tengah.
- **Distribusi NG per Part** — pie chart berdasarkan nama part, masing-masing part menampilkan jenis defect "Terbanyak".

**Export Excel:**
- Export **ringkasan** sesuai tampilan dashboard (angka KPI + data breakdown chart dalam bentuk tabel) untuk periode/filter yang sedang aktif — bukan data mentah per baris inspeksi.

**Catatan:**
- Sumber data & formula perhitungan tiap metrik (Pass Rate, dsb.), serta frekuensi update (real-time vs berkala) — **TBD**.
- Dapat diakses oleh semua role, terutama ditujukan untuk Supervisor/Viewer.

### FR-8 — Modul User Management
- **Deskripsi:** Kelola akun user sistem, mengikuti pola Index + CRUD yang sama seperti modul lain (DID, Kanban, Master Data Part).
- **Akses:** Admin only.

**Struktur Field:**

| Field | Keterangan |
|---|---|
| Nama | Nama lengkap user |
| Username / Email | Dipakai untuk login |
| Password | Di-hash (bcrypt) saat disimpan — Admin set password awal saat create |
| Role | Admin / QC Inspector / Supervisor-Viewer |
| Status | Active / Inactive |

**Halaman & CRUD:**
- **Index:** Daftar user (Nama, Username, Role, Status).
- **Create:** Admin input user baru, assign role.
- **Edit:** Update data user, ganti role, reset password.
- **Delete:** **Soft-delete** (nonaktifkan / set status *Inactive*) — bukan hard delete, supaya histori transaksi yang mereferensikan user (misal field PIC di Daily Inspection Data, FR-2) tetap valid. User yang dinonaktifkan tidak bisa login lagi, tapi datanya tetap tersimpan.

**Catatan:**
- Self-service ganti password oleh user sendiri (bukan cuma Admin yang reset) — **TBD**.
- Detail permission granular per role per fitur — **TBD** (baseline role sudah dicatat di Section 5).

## 7. Non-Functional Requirements (Draft)

- **Ketersediaan:** Sistem digunakan di line produksi sehingga perlu uptime tinggi selama jam operasional.
- **Audit trail:** Perubahan data inspeksi (terutama hasil NG) idealnya tercatat historinya.
- **Keamanan:** Role-based access control sesuai Section 5.
- **Skalabilitas & performa:** Detail beban transaksi harian (jumlah part/lot per hari) — **TBD**, akan mempengaruhi desain database & infrastruktur.
- Detail lebih lanjut (deployment: on-premise/cloud, backup policy, dll.) — **TBD**.

## 8. Open Items / Perlu Didiskusikan Lebih Lanjut

- [x] Detail field lengkap Daily Inspection Data (DID) — *selesai, lihat FR-2*
- [x] Detail field lengkap untuk Kanban — *selesai, lihat FR-1*
- [ ] Riset & uji coba library WASM CAD kernel untuk viewer 3D `.STP` (full-interactive, self-hosted) — pilih library spesifik & tes performa dengan file STEP ukuran nyata dari klien
- [ ] Logika auto-generate Master Data Part dari data DID/Kanban (langsung otomatis tersimpan, atau perlu approval Admin dulu)
- [ ] Behavior delete Master Data Part/Drawing yang sudah pernah dipakai di transaksi lain
- [ ] Versioning drawing kalau ada revisi dari klien
- [ ] Tabel lengkap Sample Size & Reject Number untuk standar AQL G-II 0,4 (per range Total Qty) — perlu data tabel asli dari klien
- [ ] Format dan layout Rejection Sheet — menyusul dari klien saat development
- [ ] Konfigurasi teknis kiosk-printing di tiap PC line produksi (setup awal, bukan lagi masalah pendekatan)
- [ ] Sumber data & formula dashboard (Pass Rate, Tren NG, Breakdown Defect, dll)
- [ ] Status digitalisasi checklist 5S area
- [ ] Matrix permission detail per role per modul
- [ ] Timeline & milestone proyek
- [ ] Success metrics / KPI keberhasilan proyek
- [ ] Kebutuhan infrastruktur (deployment on-premise vs cloud, mengingat ini sistem line produksi)

## 10. Rancangan Database (ERD)

**Asumsi:** Database menggunakan **MySQL/MariaDB** — pairing standar dengan PHP native. *(Belum eksplisit dikonfirmasi klien — kalau beda, kabari.)*

### 10.1 Master & Referensi

**`users`** (FR-8)
| Field | Tipe | Keterangan |
|---|---|---|
| id | BIGINT PK AI | |
| name | VARCHAR(150) | |
| username | VARCHAR(100) UNIQUE | Login |
| password_hash | VARCHAR(255) | Bcrypt |
| role | ENUM(admin, qc_inspector, supervisor_viewer) | |
| status | ENUM(active, inactive) DEFAULT active | Soft-delete |
| created_at, updated_at | DATETIME | |

**`master_parts`** (FR-4)
| Field | Tipe | Keterangan |
|---|---|---|
| id | BIGINT PK AI | |
| part_code | VARCHAR(50) UNIQUE | |
| part_name | VARCHAR(255) | |
| source | ENUM(manual, auto_generated) | |
| created_by | BIGINT FK→users.id NULLABLE | |
| created_at, updated_at | DATETIME | |

**`master_drawings`** (FR-5)
| Field | Tipe | Keterangan |
|---|---|---|
| id | BIGINT PK AI | |
| part_id | BIGINT FK→master_parts.id UNIQUE | 1 part = 1 record drawing |
| drawing_2d_path | VARCHAR(500) NULLABLE | File PDF |
| drawing_3d_path | VARCHAR(500) NULLABLE | File `.STP` |
| uploaded_by | BIGINT FK→users.id | |
| created_at, updated_at | DATETIME | |

**`aql_standards`** (FR-3 — tabel AQL G-II 0,4, isi data **TBD**)
| Field | Tipe | Keterangan |
|---|---|---|
| id | BIGINT PK AI | |
| qty_min | INT | Batas bawah range Total Qty |
| qty_max | INT | Batas atas range Total Qty |
| sample_size | INT | |
| reject_number | INT | Batas NG (Re) |

**`defect_types`** (FR-3 — bisa ditambah inspector saat inspeksi)
| Field | Tipe | Keterangan |
|---|---|---|
| id | BIGINT PK AI | |
| name | VARCHAR(150) UNIQUE | Contoh: Scratch/Baret, Dent/Penyok |
| created_at | DATETIME | |

### 10.2 Kanban & Daily Inspection Data

**`kanban_batches`** (FR-1 — metadata per import)
| Field | Tipe | Keterangan |
|---|---|---|
| id | BIGINT PK AI | |
| vendor | VARCHAR(255) NULLABLE | Field asli, bukan customer |
| document_number | VARCHAR(100) NULLABLE | Contoh: `228746` |
| print_datetime | DATETIME NULLABLE | |
| import_method | ENUM(manual, excel_import) | |
| imported_by | BIGINT FK→users.id | |
| imported_at | DATETIME | |

**`kanban_items`** (FR-1 — per baris item)
| Field | Tipe | Keterangan |
|---|---|---|
| id | BIGINT PK AI | |
| batch_id | BIGINT FK→kanban_batches.id | |
| kanban_no | VARCHAR(50) | |
| item_code | VARCHAR(50) | = Part Code |
| item_description | VARCHAR(255) | |
| customer | VARCHAR(255) NULLABLE | **Baru** — tujuan pengiriman |
| req_date | DATETIME | |
| qty | INT | |
| eta | DATETIME | |
| str_loc | VARCHAR(50) | |
| supply_area | VARCHAR(50) | |
| remark | TEXT NULLABLE | |
| created_at, updated_at | DATETIME | |

**`daily_inspection_data`** (FR-2 / DID)
| Field | Tipe | Keterangan |
|---|---|---|
| id | BIGINT PK AI | |
| part_code | VARCHAR(50) | |
| part_name | VARCHAR(255) | |
| lot_number | VARCHAR(100) | |
| cavity | VARCHAR(20) | |
| inspecting_date | DATE | |
| status_inspect | ENUM(OK, NG) | |
| pic | VARCHAR(150) | |
| remark | TEXT NULLABLE | |
| created_by | BIGINT FK→users.id | |
| created_at, updated_at | DATETIME | |
| *Constraint* | UNIQUE(part_code, lot_number) | Validasi duplikat (FR-2) |

### 10.3 Proses Inspeksi (FR-3 + FR-6)

**`inspection_sessions`** (header 1 sesi scan/inspeksi per part+lot)
| Field | Tipe | Keterangan |
|---|---|---|
| id | BIGINT PK AI | |
| did_id | BIGINT FK→daily_inspection_data.id | Lot yang divalidasi |
| kanban_item_id | BIGINT FK→kanban_items.id NULLABLE | Hasil matching FIFO |
| part_id | BIGINT FK→master_parts.id | |
| sample_size | INT | Snapshot dari aql_standards saat sesi dimulai |
| reject_number | INT | Snapshot |
| samples_checked | INT DEFAULT 0 | |
| ng_count | INT DEFAULT 0 | |
| status | ENUM(in_progress, passed, rejected) | = status Packing/Close (FR-6) |
| inspector_id | BIGINT FK→users.id | |
| started_at | DATETIME | |
| closed_at | DATETIME NULLABLE | |

**`inspection_samples`** (tiap 1 pcs yang dicek)
| Field | Tipe | Keterangan |
|---|---|---|
| id | BIGINT PK AI | |
| inspection_session_id | BIGINT FK→inspection_sessions.id | |
| sample_number | INT | Urutan sample ke berapa |
| result | ENUM(OK, NG) | |
| checked_at | DATETIME | |

**`inspection_ng_records`** (detail defect per sample NG — bisa >1 per sample)
| Field | Tipe | Keterangan |
|---|---|---|
| id | BIGINT PK AI | |
| inspection_sample_id | BIGINT FK→inspection_samples.id | |
| defect_type_id | BIGINT FK→defect_types.id | |
| qty_ng | INT | Jumlah kemunculan defect ini di sample tsb |
| remark | TEXT NULLABLE | |
| created_at | DATETIME | |

**`rejection_sheet_prints`** (log cetak, termasuk reprint — FR-3E)
| Field | Tipe | Keterangan |
|---|---|---|
| id | BIGINT PK AI | |
| inspection_session_id | BIGINT FK→inspection_sessions.id | |
| print_type | ENUM(auto, manual_reprint) | |
| printed_by | BIGINT FK→users.id NULLABLE | NULL kalau auto-print |
| printed_at | DATETIME | |

### 10.4 Relasi Utama

- `master_parts` 1—0/1 `master_drawings`
- `master_parts` 1—* `daily_inspection_data` (via `part_code`, tidak strict FK — data DID hasil import bebas format)
- `kanban_batches` 1—* `kanban_items`
- `daily_inspection_data` 1—1 `inspection_sessions` (per sesi inspeksi)
- `kanban_items` 0/1—1 `inspection_sessions` (hasil matching FIFO, FR-3)
- `inspection_sessions` 1—* `inspection_samples`
- `inspection_samples` 1—* `inspection_ng_records`
- `inspection_sessions` 1—* `rejection_sheet_prints`
- `users` 1—* (`kanban_batches`, `master_drawings`, `inspection_sessions`, `rejection_sheet_prints`, `daily_inspection_data`)

> **Catatan:** `part_code`/`item_code` di tabel import (Kanban, DID) sengaja disimpan sebagai teks bebas (tidak strict FK ke `master_parts`) karena data-nya diimport dari file eksternal yang formatnya di luar kendali sistem. Modul turunan yang lebih terkurasi (Drawing, Inspection Session) baru mengikat ke `master_parts.id` sebagai FK yang solid.

---

## 11. Success Metrics

*Belum didefinisikan — akan diisi setelah diskusi lebih lanjut dengan klien mengenai baseline proses manual saat ini (waktu inspeksi rata-rata, tingkat human error, dll).*

---

*Dokumen ini adalah draft awal dan akan diperbarui secara bertahap seiring pembahasan detail tiap fitur.*
