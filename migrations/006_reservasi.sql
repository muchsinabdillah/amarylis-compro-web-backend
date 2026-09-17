-- =====================================================================
--  Fase 1 portal pasien: akun pasien (login HP + kata sandi) & reservasi
--  poliklinik. Reservasi ditampung di webcompro sebagai "front office";
--  verifikasi & pembuatan rekam medik/registrasi tetap di SIMRS.
--
--  Jalankan: psql -U postgres -d webcompro -f migrations/006_reservasi.sql
--  Idempoten (IF NOT EXISTS / ON CONFLICT). Tak menghapus data.
-- =====================================================================
SET search_path TO webcompro, public;

-- ---------------------------------------------------------------------
-- 1. Akun pasien
-- ---------------------------------------------------------------------
-- Identitas = nomor HP (dinormalkan). Data diri minimal disimpan agar saat
-- reservasi tak perlu diketik ulang, dan agar admin SIMRS punya cukup bahan
-- untuk membuat rekam medik ketika memverifikasi. no_mr diisi bila akun ini
-- kelak ditautkan ke pasien SIMRS yang sudah ada.
CREATE TABLE IF NOT EXISTS webcompro.pasien_akun (
    id            bigserial PRIMARY KEY,
    no_hp         varchar(20)  NOT NULL,             -- disimpan sudah dinormalkan (62xxxx)
    nama          varchar(160) NOT NULL,
    password      varchar(255) NOT NULL,             -- password_hash(), tak pernah plaintext
    email         varchar(160),
    nik           varchar(20),
    tgl_lahir     date,
    jenis_kelamin varchar(1),                        -- 'L' | 'P'
    alamat        text,
    no_mr         varchar(30),                       -- tautan ke pasien SIMRS bila ada
    is_active     boolean      NOT NULL DEFAULT true,
    created_at    timestamptz  NOT NULL DEFAULT now(),
    updated_at    timestamptz  NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_pasien_akun_hp ON webcompro.pasien_akun (no_hp);

-- ---------------------------------------------------------------------
-- 2. Reservasi poliklinik
-- ---------------------------------------------------------------------
-- Poli disimpan sebagai TEKS (sama seperti doctor_schedules.unit di SIMRS —
-- poli tak punya id sendiri). doctor_id merujuk salinan dokter tersinkron;
-- ON DELETE SET NULL agar riwayat reservasi tak hilang bila dokter di-sinkron
-- ulang. Nama poli/dokter di-snapshot ke kolom teks supaya reservasi tetap
-- terbaca apa adanya meski master berubah kemudian.
CREATE TABLE IF NOT EXISTS webcompro.reservasi (
    id            bigserial PRIMARY KEY,
    pasien_id     bigint       NOT NULL REFERENCES webcompro.pasien_akun(id) ON DELETE CASCADE,
    doctor_id     bigint       REFERENCES webcompro.doctors(id) ON DELETE SET NULL,
    nama_dokter   varchar(160),
    poli          varchar(120) NOT NULL,
    tanggal       date         NOT NULL,
    jam           varchar(60),                       -- jendela praktik (teks), mis. "08:00–12:00"
    keluhan       text,
    status        varchar(20)  NOT NULL DEFAULT 'MENUNGGU',
        -- MENUNGGU | DIVERIFIKASI | DITOLAK | SELESAI | BATAL
    no_registrasi varchar(30),                       -- diisi SIMRS saat registrasi dibuat
    no_mr         varchar(30),
    catatan_admin text,
    diproses_oleh varchar(160),
    diproses_at   timestamptz,
    created_at    timestamptz  NOT NULL DEFAULT now(),
    updated_at    timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT reservasi_status_sah CHECK
        (status IN ('MENUNGGU','DIVERIFIKASI','DITOLAK','SELESAI','BATAL'))
);
CREATE INDEX IF NOT EXISTS ix_reservasi_pasien ON webcompro.reservasi (pasien_id, created_at DESC);
CREATE INDEX IF NOT EXISTS ix_reservasi_kerja  ON webcompro.reservasi (status, tanggal);

-- ---------------------------------------------------------------------
-- 3. updated_at otomatis (pola sama seperti 001)
-- ---------------------------------------------------------------------
DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['pasien_akun','reservasi'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_%1$s_updated ON webcompro.%1$s;', t);
        EXECUTE format('
            CREATE TRIGGER trg_%1$s_updated BEFORE UPDATE ON webcompro.%1$s
            FOR EACH ROW EXECUTE FUNCTION webcompro.sentuh_updated_at();', t);
    END LOOP;
END $$;

-- ---------------------------------------------------------------------
-- 4. Izin CMS untuk mengelola reservasi (pola sama seperti 002)
-- ---------------------------------------------------------------------
INSERT INTO webcompro.permissions (kode, modul, nama)
SELECT v.kode, v.modul, v.nama FROM (VALUES
    ('reservation.manage', 'reservasi', 'Kelola reservasi pasien')
) AS v(kode, modul, nama)
WHERE NOT EXISTS (SELECT 1 FROM webcompro.permissions p WHERE lower(p.kode) = v.kode);

-- Super Admin tetap mendapatkannya (middleware pun memberi '*', ini agar
-- muncul juga di daftar izin efektif).
INSERT INTO webcompro.role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM webcompro.roles r JOIN webcompro.permissions p ON true
 WHERE upper(r.kode) = 'SUPER_ADMIN' AND p.kode = 'reservation.manage'
ON CONFLICT DO NOTHING;
