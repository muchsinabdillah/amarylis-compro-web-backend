-- =====================================================================
-- Basis data dan akun milik website — dijalankan PALING DULU
-- =====================================================================
--
-- Website memakai BASIS DATA SENDIRI, terpisah dari SIMRS.
--
-- Menumpang di basis data SIMRS memang lebih ringkas dipasang, tetapi
-- mengikat keduanya selamanya: memindahkan situs ke server lain berarti
-- memindahkan basis data SIMRS, memulihkan cadangan SIMRS berarti menimpa
-- isi website, dan satu kueri website yang berat ikut membebani pelayanan
-- pasien. Pemisahan ini menghapus ketiganya sekaligus.
--
-- Data SIMRS yang dibutuhkan situs — dokter, jadwal, paket, profil klinik —
-- diambil lewat API SIMRS (grup rute /api/website), bukan lewat sambungan
-- basis data. Website karena itu tidak memegang kredensial SIMRS sama
-- sekali, dan tidak punya cara apa pun untuk menulis ke sana.
--
-- CARA PAKAI
--   psql -U postgres -d postgres -v sandi="'sandi_yang_kuat'" -f 000_buat_database.sql
--
--   Lalu seluruh migrasi berikutnya dijalankan DI DALAM basis data ini:
--   psql -U postgres -d webcompro -f 001_schema_webcompro.sql
--
-- CATATAN
--   CREATE DATABASE tidak dapat berjalan di dalam transaksi, jadi berkas ini
--   berdiri sendiri dan tidak memakai BEGIN/COMMIT.
--
-- ROLLBACK
--   DROP DATABASE webcompro;
--   DROP ROLE webcompro_app;
-- =====================================================================

\set ON_ERROR_STOP on

\if :{?sandi}
\else
  \set sandi '''ubah_sandi_ini'''
  \echo '!! Sandi tidak diberikan. Memakai sandi sementara — WAJIB diganti.'
  \echo '!! Jalankan dengan:  -v sandi="''sandi_yang_kuat''"'
\endif

-- Variabel psql tidak tersubstitusi di dalam blok dollar-quoted, jadi
-- perintahnya dirakit lebih dulu lalu dijalankan dengan \gexec.
SELECT format('CREATE ROLE webcompro_app LOGIN PASSWORD %L', :sandi)
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'webcompro_app')
\gexec

SELECT format('ALTER ROLE webcompro_app PASSWORD %L', :sandi)
 WHERE EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'webcompro_app')
\gexec

-- Akun website tidak boleh membuat basis data atau akun lain.
ALTER ROLE webcompro_app NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;

SELECT 'CREATE DATABASE webcompro
          WITH OWNER = postgres
               ENCODING = ''UTF8''
               TEMPLATE = template0
               LC_COLLATE = ''C''
               LC_CTYPE = ''C'''
 WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'webcompro')
\gexec

-- Hanya akun website yang boleh menyambung; akun lain tidak diberi hak.
REVOKE ALL ON DATABASE webcompro FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE webcompro TO webcompro_app;

\echo ''
\echo '===== Selesai ====='
\echo 'Basis data "webcompro" dan akun "webcompro_app" siap.'
\echo 'Lanjutkan:  psql -U postgres -d webcompro -f 001_schema_webcompro.sql'
