-- =====================================================================
-- Lepas seluruh hak website di basis data SIMRS
-- =====================================================================
--
-- Dijalankan HANYA bila pemasangan sebelumnya pernah menaruh website di
-- basis data SIMRS. Sekarang website berdiri di basis data sendiri dan
-- mengambil data lewat API, jadi tidak ada lagi alasan akun website punya
-- hak apa pun di sini — bahkan hak baca.
--
-- Hak baca yang tidak lagi dipakai bukan hal netral: ia tetap hidup di
-- daftar hak akses, tetap terbawa setiap kali kredensialnya bocor, dan tidak
-- akan diperiksa siapa pun karena tidak ada yang memakainya.
--
-- CARA PAKAI
--   psql -U postgres -d his -f 005_lepas_akses_simrs.sql
--
-- CATATAN
--   Berkas ini TIDAK menghapus skema webcompro lama di basis data his.
--   Menghapusnya adalah tindakan yang tidak dapat dibatalkan dan harus
--   dilakukan sendiri, setelah memastikan isinya sudah dipindahkan:
--
--     pg_dump -U postgres -n webcompro his > cadangan_webcompro.sql
--     psql -U postgres -d webcompro -f cadangan_webcompro.sql
--     -- baru setelah diperiksa:
--     DROP SCHEMA webcompro CASCADE;
-- =====================================================================

\set ON_ERROR_STOP on

DO $$
DECLARE s text;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'webcompro_app') THEN
        RAISE NOTICE 'Akun webcompro_app tidak ada di server ini. Tidak ada yang perlu dilepas.';
        RETURN;
    END IF;

    FOREACH s IN ARRAY ARRAY['MasterdataSQL', 'PerawatanSQL'] LOOP
        IF EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = s) THEN
            EXECUTE format('REVOKE ALL ON ALL TABLES IN SCHEMA %I FROM webcompro_app', s);
            EXECUTE format('REVOKE ALL ON ALL SEQUENCES IN SCHEMA %I FROM webcompro_app', s);
            EXECUTE format('REVOKE ALL ON SCHEMA %I FROM webcompro_app', s);
        END IF;
    END LOOP;

    -- Hak menyambung ke basis data SIMRS ikut dicabut: website tidak
    -- membutuhkannya lagi sama sekali.
    EXECUTE 'REVOKE ALL ON DATABASE his FROM webcompro_app';
END $$;

\echo ''
\echo '===== Bukti: sisa hak website pada skema SIMRS ====='
\echo '(skema webcompro lama sengaja tidak disentuh; lihat catatan di atas)'

SELECT coalesce(string_agg(DISTINCT table_schema || '.' || table_name, ', '),
                '(tidak ada — benar)') AS sisa_hak_di_skema_simrs
  FROM information_schema.table_privileges
 WHERE grantee = 'webcompro_app'
   AND table_schema <> 'webcompro';

\echo ''
\echo 'Skema webcompro lama yang masih tertinggal di basis data ini:'

SELECT coalesce(string_agg(schema_name, ', '), '(sudah tidak ada)') AS skema_lama
  FROM information_schema.schemata WHERE schema_name = 'webcompro';
