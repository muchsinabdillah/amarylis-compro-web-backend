-- =====================================================================
-- Hak akses akun website di dalam basis datanya sendiri
-- =====================================================================
--
-- Berkas ini menggantikan 003_role_webcompro.sql yang lama. Versi lama
-- memberi akun website hak SELECT pada beberapa tabel SIMRS karena keduanya
-- masih berbagi satu basis data. Sekarang tidak ada lagi yang perlu dibuka:
-- website berada di basis data terpisah dan mengambil data SIMRS lewat API.
--
-- Hilangnya hak baca itu bukan kemunduran, melainkan penyempitan permukaan
-- yang paling besar sejauh ini. Kredensial website yang bocor kini tidak
-- membuka apa pun di SIMRS — tidak untuk dibaca, apalagi ditulis.
--
-- CARA PAKAI
--   psql -U postgres -d webcompro -f 003_hak_akses.sql
--
-- ROLLBACK
--   REVOKE ALL ON ALL TABLES IN SCHEMA webcompro FROM webcompro_app;
-- =====================================================================

\set ON_ERROR_STOP on

-- Tidak boleh membuat objek di skema publik.
REVOKE ALL ON SCHEMA public FROM PUBLIC;
REVOKE ALL ON SCHEMA public FROM webcompro_app;

GRANT USAGE ON SCHEMA webcompro TO webcompro_app;

GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES    IN SCHEMA webcompro TO webcompro_app;
GRANT USAGE, SELECT                  ON ALL SEQUENCES IN SCHEMA webcompro TO webcompro_app;
GRANT EXECUTE                        ON ALL FUNCTIONS IN SCHEMA webcompro TO webcompro_app;

-- Berlaku juga untuk tabel yang dibuat migrasi berikutnya, supaya hak akses
-- tidak perlu diberikan ulang setiap kali ada tabel baru.
ALTER DEFAULT PRIVILEGES IN SCHEMA webcompro
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO webcompro_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA webcompro
    GRANT USAGE, SELECT ON SEQUENCES TO webcompro_app;

-- Sengaja TIDAK diberikan: CREATE pada skema. Akun aplikasi tidak perlu
-- membuat atau mengubah tabel; migrasi dijalankan dengan akun postgres.
-- Akun aplikasi yang bisa DROP TABLE membuat setiap celah SQL injection
-- berpotensi menjadi kehilangan data, bukan sekadar kebocoran.

\echo ''
\echo '===== Bukti: hak akun website ====='

SELECT table_name,
       string_agg(DISTINCT privilege_type, ', ' ORDER BY privilege_type) AS hak
  FROM information_schema.table_privileges
 WHERE grantee = 'webcompro_app' AND table_schema = 'webcompro'
 GROUP BY 1 ORDER BY 1 LIMIT 5;

\echo ''
\echo 'Dan pastikan TIDAK ADA hak apa pun di luar skema webcompro:'

SELECT coalesce(string_agg(DISTINCT table_schema, ', '), '(tidak ada — benar)') AS skema_lain
  FROM information_schema.table_privileges
 WHERE grantee = 'webcompro_app' AND table_schema <> 'webcompro';
