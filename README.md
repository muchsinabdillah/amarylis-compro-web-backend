# Backend Website Klinik Pratama Andini

API dan CMS untuk situs company profile. PHP 8.2, PostgreSQL, tanpa framework
dan tanpa Composer — sisi server ini tidak memakai pustaka pihak ketiga.

## Hubungannya dengan SIMRS

Website punya **basis data sendiri** (`webcompro`), terpisah dari basis data
SIMRS, dan **tidak memegang kredensial SIMRS sama sekali**. Satu-satunya
tautan di antara keduanya adalah beberapa titik akhir baca-saja di API SIMRS:

```
Website (DB webcompro)  --HTTPS + X-Api-Key-->  SIMRS-API /api/website/*
```

Pemisahan ini yang membuat:

- **Pindah server mudah.** Yang perlu diikutkan hanya satu alamat dan satu
  kunci, bukan kredensial basis data.
- **Pencadangan berdiri sendiri.** Memulihkan cadangan SIMRS tidak menimpa
  isi website, dan sebaliknya.
- **Situs tidak ikut padam.** Bila SIMRS mati, halaman publik tetap tayang
  dari salinannya sendiri; hanya tombol "Sinkronkan" di CMS yang gagal, dan
  kegagalannya disebutkan apa adanya.
- **Kebocoran tidak menyeberang.** Kredensial website yang bocor tidak
  membuka apa pun di SIMRS — tidak untuk dibaca, apalagi ditulis.

### Yang dibuka SIMRS untuk website

Lima titik akhir, seluruhnya GET, di `SIMRS-API`:

```
GET /api/website/dokter    master dokter (tanpa NIK, telepon, dan email)
GET /api/website/jadwal    jadwal praktik, sudah satu baris per hari
GET /api/website/paket     paket layanan
GET /api/website/unit      unit layanan aktif
GET /api/website/profil    nama, alamat, kontak klinik
```

Dijaga middleware `website.kunci` (`app/Http/Middleware/KunciApiWebsite.php`):
kunci dibandingkan dengan `hash_equals`, metode selain GET ditolak, dan bila
`WEBSITE_SYNC_KEY` belum diisi seluruh titik akhir **tertutup** — bukan
terbuka.

Kunci dibuat sekali dan dipasang di dua tempat dengan nilai yang sama:

```
SIMRS-API/.env       WEBSITE_SYNC_KEY=<hasil openssl rand -hex 32>
COMPRO-WEB/.env      SIMRS_API_KEY=<nilai yang sama>
```

### Aturan sinkronisasi

Master dokter dan paket **disalin**, bukan dibaca setiap saat. Salinannya
punya kolom tambahan milik website (foto, bio, urutan tampil, status tayang)
dan aturan penggabungannya:

1. Data hasil sinkronisasi **tidak langsung tayang**. `is_published` bawaannya
   `false` — klinik memutuskan sendiri dokter mana yang muncul di situs.
2. Kolom yang disunting lewat CMS **terkunci** (`field_locks`) dan tidak
   ditimpa sinkronisasi berikutnya.
3. Baris yang hilang dari SIMRS **tidak dihapus**, hanya ditandai
   `sync_status = 'hilang_di_simrs'` — foto dan biografi yang sudah disusun
   tidak ikut hilang karena satu perubahan di master.

Profil klinik tidak disalin ke tabel: ia diambil lewat API dan disinggahkan
satu jam, supaya tidak pernah ada dua versi alamat klinik yang berbeda.

## Pemasangan

```bash
cp .env.example .env
# isi DB_PASS, JWT_SECRET, dan SIMRS_API_KEY
# JWT_SECRET dan kunci SIMRS dibuat dengan:  openssl rand -hex 32

# 1. Basis data milik website (dijalankan di database postgres)
psql -U postgres -d postgres -v sandi="'sandi_yang_kuat'" -f migrations/000_buat_database.sql

# 2. Sisanya di dalam basis data webcompro
psql -U postgres -d webcompro -f migrations/001_schema_webcompro.sql
psql -U postgres -d webcompro -f migrations/002_seed_webcompro.sql
psql -U postgres -d webcompro -f migrations/003_hak_akses.sql
psql -U postgres -d webcompro -f migrations/004_isi_kontak_klinik.sql

# 3. Hanya bila pemasangan lama pernah menaruh website di database SIMRS
psql -U postgres -d his -f migrations/005_lepas_akses_simrs.sql

# 4. Di sisi SIMRS-API: isi WEBSITE_SYNC_KEY pada .env lalu
php artisan config:clear

# 5. Akun CMS pertama, lalu tarik data SIMRS
php bin/pengguna.php buat "Nama Anda" admin@klinik.id SUPER_ADMIN
php bin/sync.php
```

Menjalankan untuk pengembangan:

```bash
php -S 127.0.0.1:8080 -t public public/index.php
```

Di production, arahkan document root ke `public/` saja. Folder `storage/`,
`.env`, dan seluruh kode berada di atasnya sehingga tidak dapat diminta lewat
URL.

## Perintah

| Perintah | Kegunaan |
|---|---|
| `php bin/sync.php` | Sinkronkan dokter, jadwal, dan paket dari SIMRS |
| `php bin/sync.php doctors` | Sinkronkan satu modul saja |
| `php bin/pengguna.php buat "Nama" email PERAN` | Buat akun CMS |
| `php bin/pengguna.php sandi email` | Ganti kata sandi |
| `php bin/pengguna.php daftar` | Lihat daftar akun |

Peran: `SUPER_ADMIN`, `ADMIN_CONTENT`, `ADMIN_KLINIK`.

Sinkronisasi berkala cukup sekali sehari:

```
0 5 * * *  cd /var/www/compro/backend && php bin/sync.php >> storage/sync.log 2>&1
```

Bila SIMRS sedang tidak terjangkau, perintahnya berhenti dengan pesan yang
menyebut penyebabnya dan kode keluar bukan nol — sehingga cron yang gagal
terlihat, bukan diam.

## Susunan berkas

```
bootstrap.php        autoload PSR-4 + muat .env
core/                Env, Database, Request, Response, Router, HttpException
helpers/             Jwt, Html (pembersih), Validator, Slug
middleware/          cors, batasLaju, auth, izin, izinKonten
repositories/        ContentRepository (6 modul konten), TabelRepository
services/            AuthService, SettingsService, SyncService, MediaService,
                     SimrsClient (satu-satunya jalan menuju SIMRS)
controllers/         Public, Auth, Admin, Media, Taxonomy, Page, Facility, User, Seo
routes/api.php       seluruh titik akhir beserta penjaganya
public/index.php     satu-satunya berkas yang boleh diakses dari luar
```

## Titik akhir

Publik (tanpa token, `/api/publik`):

```
GET  /pengaturan                 profil klinik + kontak + peta + SEO
GET  /konten/{modul}             daftar; ?kategori= ?cari= ?unggulan= ?halaman=
GET  /konten/{modul}/{slug}      detail + konten terkait
GET  /kategori/{tipe}            kategori aktif
GET  /dokter                     dokter yang ditandai tayang + jadwalnya
GET  /fasilitas
GET  /halaman/{slug}             halaman statis
GET  /cari?q=                    lintas modul
POST /lead                       jejak klik tombol WhatsApp
```

`{modul}`: `articles`, `news`, `videos`, `services`, `mcu`, `homecare`.

Di luar `/api`: `GET /sitemap.xml`, `GET /robots.txt`, dan `GET /media/{path}`.

Admin (`/api/admin`, wajib `Authorization: Bearer <token>`):

```
POST   /masuk                    tanpa token, dibatasi 8 percobaan/menit
GET    /saya                     periksa token masih sah
POST   /ganti-sandi
GET    /dasbor
GET    /konten/{modul}           + POST, GET/PUT/DELETE /{id}
GET    /pengaturan               + PUT
GET    /dokter                   + PUT /{id}
POST   /sinkron                  + GET (riwayat)
GET    /halaman /fasilitas /kategori /tag /media /pengguna /peran
```

## Catatan keamanan

- **Sandi** disimpan dengan `password_hash` bcrypt cost 12, dan di-*rehash*
  diam-diam saat pemiliknya masuk bila biayanya dinaikkan. Pesan gagal masuk
  selalu sama untuk email tak dikenal maupun sandi salah, dan sandi tiruan
  tetap diperiksa agar lamanya jawaban tidak membocorkan email mana yang
  terdaftar.
- **HTML dari editor** dibersihkan dengan daftar putih (`helpers/Html.php`).
  Isi `<script>` dan `<style>` dibuang berikut isinya; `img`/`iframe` yang
  kehilangan `src` ikut dibuang; `iframe` hanya boleh dari YouTube, Vimeo, dan
  Google Maps.
- **Unggahan** ditentukan tipenya dari isi berkas, lalu dibuka ulang dan
  ditulis kembali oleh GD sehingga muatan yang menumpang di dalam gambar tidak
  ikut tersimpan. Nama berkas dibuat acak; nama asli tidak pernah dipakai.
- **Hak akses** dibaca ulang dari basis data pada setiap permintaan, bukan
  dipercaya dari isi token — peran yang dicabut langsung berlaku.
- **CORS** memakai daftar putih `CORS_ORIGINS`; asal yang tidak terdaftar
  tidak dipantulkan.
- Jangan pernah menaruh kredensial di dalam kode. Seluruhnya lewat `.env`,
  dan `.env` tidak masuk git.

## Terpasang dan teruji

Diuji lokal pada 8 September 2026 terhadap basis data `webcompro` yang
terpisah, dengan data SIMRS diambil lewat API:

- Seluruh titik akhir publik dan admin menjawab benar.
- RBAC menolak peran yang tidak berhak (403 dengan menyebut wewenang yang
  kurang).
- Pembersih HTML menangkis sebelas pola serangan, termasuk `<script>`,
  `onerror=`, `javascript:`, `<svg onload>`, dan iframe dari host asing.
- Unggahan PHP yang menyamar sebagai `.jpg` ditolak; `../` pada `/media/`
  dijawab 404.
- Titik akhir SIMRS menolak permintaan tanpa kunci dan dengan kunci salah
  (401), dan menolak metode selain GET.
- Sinkronisasi lewat API menarik 9 dokter, 54 jadwal, dan 1 paket dalam
  1,3 detik.
- Dengan SIMRS dimatikan, halaman publik tetap menjawab 200; permintaan
  pertama 2,1 detik lalu 0,09 detik setelah penanda gagal aktif.

Data uji sudah dihapus kembali.
