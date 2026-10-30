# Fiber Manager v2

Aplikasi web untuk mencatat jaringan FTTH (OLT → ODC → ODP → pelanggan) di atas peta, dipakai kantor dan teknisi lapangan, serta tim sales untuk cek coverage calon pelanggan.

Dibangun dengan Laravel 12, Inertia + Vue 3 (TypeScript), Tailwind, dan Leaflet/OpenStreetMap.

## Fitur

- **Peta jaringan** — ODC, ODP (warna sesuai okupansi port), jalur kabel feeder/distribusi/drop, peta satelit, pencarian kode, lokasi saya.
- **ODC & ODP** — tambah/edit dari peta atau GPS HP, ODC induk + port ODC, kapasitas & splitter, status aktif/rencana/rusak.
- **Port & pelanggan** — grid port per ODP, isi data pelanggan (ID/PPPoE, HP, paket, SN ONT).
- **Jalur kabel** — gambar di peta, ujung menempel otomatis ke ODC/ODP, panjang dihitung otomatis, ikut berpindah bila ODC/ODP dipindah.
- **Foto** — galeri foto per ODC/ODP; foto dikecilkan di browser sebelum diunggah.
- **Link Google Maps → koordinat** — tempel link Google Maps (termasuk link pendek `maps.app.goo.gl`) di Cek Coverage, kotak cari peta, atau panel *Terdekat*; lokasi dari link selalu diberi peringatan agar dicek, karena koordinat langsung lebih akurat. Link pendek dibuka oleh server, jadi server perlu akses internet.
- **Cek coverage** — untuk sales: masukkan alamat, koordinat, link Google Maps, atau GPS; hasil *tercover / ODP penuh / belum tercover* berdasarkan ODP aktif dengan port kosong dalam radius (default 250 m).
- **Ekspor & impor** — Excel (.xlsx) dan KML/KMZ (Google Earth / My Maps). Hasil ekspor bisa diedit lalu diimpor kembali; impor bersifat semua-atau-tidak-sama-sekali.
- **Multi area & role** — Super Admin, Admin Area, Teknisi, Viewer, Sales. User tanpa area dapat mengakses semua area.
- **Dashboard** — jumlah ODC/ODP/pelanggan, okupansi per area, ODP hampir penuh, total panjang kabel.

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

Catatan server produksi:

- GPS di browser hanya berfungsi lewat **HTTPS** (atau `localhost`).
- Foto disimpan di `storage/app/public/photos` — sertakan dalam backup.
- Foto dikompres di browser (maks. 1600 px), sehingga `upload_max_filesize` 2 MB sudah cukup untuk foto; impor KMZ besar mungkin perlu batas lebih tinggi.

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
