# QC FiberMap

QC FiberMap (repo `fiber-manager-v2`) adalah aplikasi web untuk mencatat jaringan FTTH (OLT → ODC → ODP → pelanggan) di atas peta, dipakai kantor dan teknisi lapangan, serta tim sales untuk cek coverage calon pelanggan.

Dibangun dengan Laravel 13, Inertia + Vue 3 (TypeScript), Tailwind, dan Leaflet/OpenStreetMap.

## Fitur

- **Peta jaringan** — ODC, ODP (warna sesuai okupansi port), jalur kabel feeder/distribusi/drop, peta satelit, pencarian global (kode, nama, alamat, catatan, no. tiang, SN ONT, kabel), lokasi saya.
- **ODC & ODP** — tambah/edit dari peta atau GPS HP, ODC induk + port ODC, kapasitas & splitter, status aktif/rencana/rusak.
- **Port & pelanggan** — grid port per ODP, isi data pelanggan (ID/PPPoE, HP, paket, SN ONT).
- **Pelanggan** — koordinat rumah (klik peta, GPS, atau tempel link Google Maps), cari pelanggan berdasarkan nama/ID/no. HP, pindah ke ODP/port lain, dan foto. Layer *Pelanggan* di peta mati secara default dan baru dimuat saat diperbesar (zoom ≥ 15) supaya peta tetap ringan.
- **Jalur kabel** — gambar di peta, ujung menempel otomatis ke ODC/ODP, panjang dihitung otomatis, ikut berpindah bila ODC/ODP dipindah.
- **Foto** — galeri foto per ODC/ODP; foto dikecilkan di browser sebelum diunggah.
- **Link Google Maps → koordinat** — tempel link Google Maps (termasuk link pendek `maps.app.goo.gl`) di Cek Coverage, kotak cari peta, atau panel *Terdekat*; lokasi dari link selalu diberi peringatan agar dicek, karena koordinat langsung lebih akurat. Link pendek dibuka oleh server, jadi server perlu akses internet.
- **Cek coverage** — untuk sales: masukkan alamat, koordinat, link Google Maps, atau GPS; hasil *tercover / ODP penuh / belum tercover* berdasarkan ODP aktif dengan port kosong dalam radius (default 250 m).
- **Ekspor & impor** — Excel (.xlsx) dan KML/KMZ (Google Earth / My Maps). Hasil ekspor bisa diedit lalu diimpor kembali; impor bersifat semua-atau-tidak-sama-sekali.
- **Multi area & role** — Super Admin, Admin Area, Teknisi, Viewer, Sales. User tanpa area dapat mengakses semua area.
- **Dashboard** — ringkasan ODC/ODP/pelanggan/port PON/kabel, okupansi port ODP per status, sebaran isi ODP, grafik pelanggan baru per bulan, kelengkapan data (pelanggan tanpa lokasi/port, ODP tanpa ODC/foto), okupansi per area & POP, ODP hampir penuh, dan aktivitas terbaru.
- **Backup database otomatis** — dump harian ke `.sql.gz`, backup lama dihapus otomatis, bisa disalin ke folder kedua (lihat [Backup database](#backup-database)).

## Kebutuhan

- PHP 8.2+ dengan ekstensi `pdo_mysql`, `gd`, `zip`, `intl`, `xml`/`dom`, `fileinfo`, `mbstring`
- MySQL 8 atau MariaDB 10.4+
- Composer 2, Node.js 20+

## Instalasi

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Atur koneksi database di `.env` (`DB_CONNECTION=mariadb` atau `mysql`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`), lalu:

```bash
php artisan migrate
php artisan storage:link
npm run build
```

Data contoh (2 area fiktif + akun `admin@example.com` / `password`) — **hanya untuk percobaan**:

```bash
php artisan db:seed
```

Pengembangan lokal: `composer run dev` (server Laravel + Vite).

## Konfigurasi

| `.env` | Default | Keterangan |
|---|---|---|
| `COVERAGE_RADIUS` | `250` | Jarak maksimal (meter, garis lurus) ODP ke pelanggan untuk dianggap tercover |
| `BACKUP_PATH` | `storage/app/backup/otomatis` | Folder file backup database |
| `BACKUP_COPY_TO` | — | Folder kedua untuk salinan backup (mis. folder Google Drive/OneDrive yang tersinkron, atau disk lain) |
| `BACKUP_INTERVAL_HOURS` | `24` | Backup baru dibuat bila backup terakhir lebih tua dari ini |
| `BACKUP_KEEP_DAYS` | `30` | Backup yang lebih tua dari ini dihapus… |
| `BACKUP_KEEP_MIN` | `7` | …tetapi sejumlah backup terbaru ini selalu disimpan |
| `BACKUP_MYSQLDUMP` | `C:
mpp\mysql\bin\mysqldump.exe` (bila ada), selain itu `mysqldump` | Lokasi program mysqldump |
| `BACKUP_TIMEZONE` | `Asia/Jakarta` | Zona waktu untuk nama file backup |

Catatan server produksi:

- GPS di browser hanya berfungsi lewat **HTTPS** (atau `localhost`).
- Foto disimpan di `storage/app/public/photos` — sertakan dalam backup (backup otomatis hanya mencakup database).
- Foto dikompres di browser (maks. 1600 px), sehingga `upload_max_filesize` 2 MB sudah cukup untuk foto; impor KMZ besar mungkin perlu batas lebih tinggi.

## Backup database

Backup manual kapan saja:

```bash
php artisan backup:database
```

Hasilnya `backup-<database>-YYYYMMDD-HHMMSS.sql.gz` di `storage/app/backup/otomatis` (bisa diubah lewat `BACKUP_PATH`). Isi dump diperiksa; bila mysqldump gagal atau hasilnya terpotong, file dibuang dan perintah keluar dengan error. Setelah backup, file yang lebih tua dari `BACKUP_KEEP_DAYS` dihapus, tetapi `BACKUP_KEEP_MIN` file terbaru selalu disimpan.

**Otomatis.** Penjadwal Laravel memeriksa setiap jam dan membuat backup bila backup terakhir sudah lebih dari `BACKUP_INTERVAL_HOURS` (default 24 jam) — jadi hari ketika komputer mati langsung disusul begitu komputer menyala. Penjadwal ini juga menghapus riwayat aktivitas yang lebih tua dari 24 bulan. Penjadwal harus selalu berjalan:

- **Windows (XAMPP)** — jalankan sekali untuk memasang task *AplikasiOdp Penjadwal* di Task Scheduler. Task ini menjalankan `php artisan schedule:work` tanpa jendela setiap kali user login dan menyalakannya kembali bila berhenti:

  ```powershell
  powershell -ExecutionPolicy Bypass -File scripts\windows\pasang-penjadwal.ps1
  ```

  PHP diambil dari `C:\php\php.exe`; ubah dengan variabel lingkungan `ODP_PHP` bila berbeda. Cek dengan `Get-ScheduledTask -TaskName 'AplikasiOdp Penjadwal'`; hapus dengan `Unregister-ScheduledTask -TaskName 'AplikasiOdp Penjadwal'`.
- **Linux** — tambahkan ke crontab: `* * * * * cd /path/ke/aplikasi && php artisan schedule:run >> /dev/null 2>&1`

**Simpan salinan di tempat lain.** Backup di disk yang sama dengan database ikut hilang bila disk rusak. Isi `BACKUP_COPY_TO` dengan folder yang tersinkron ke cloud atau disk lain; setiap backup disalin ke sana dan dibersihkan dengan aturan yang sama.

**Memulihkan** (menimpa isi database — buat backup dulu bila masih bisa):

```bash
gzip -dc backup-aplikasi_odp-20261104-171946.sql.gz | mysql -u root -p aplikasi_odp
```

Di Windows tanpa `gzip`, ekstrak dulu dengan 7-Zip lalu: `C:\xampp\mysql\bin\mysql.exe -u root -p aplikasi_odp < backup-....sql`.

Foto **tidak** termasuk backup database — salin juga folder `storage/app/public/photos`.

## Migrasi dari aplikasi fiber-manager lama

1. Muat dump database lama ke database terpisah, misalnya `fiber_manager_import`.
2. Pada database aplikasi yang masih kosong:

   ```bash
   php artisan migrasi:fiber-manager --database=fiber_manager_import
   php artisan migrasi:fiber-manager-foto "D:\path\ke\fiber-manager\uploads" --dry-run
   php artisan migrasi:fiber-manager-foto "D:\path\ke\fiber-manager\uploads"
   ```

POP menjadi area, akun lama tetap bisa login dengan username & password lamanya. Laporan dan peta ID lama→baru ditulis ke `storage/app/private/migrasi-fiber-manager/`.

## Pengujian

```bash
php artisan test
```

## Keamanan data

Jangan commit dump database (`*.sql`), file `.env`, atau isi folder `storage/` — berisi data pelanggan, hash password, dan foto lapangan. Semuanya sudah dikecualikan lewat `.gitignore`.
