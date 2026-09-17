-- =====================================================================
--  Reservasi wizard: data identitas pasien (standar RM SIMRS) + penjamin.
--  Identitas menempel di pasien_akun (dipakai ulang antar reservasi);
--  pilihan penjamin & status "sudah pernah berobat" menempel di reservasi.
--
--  Field administratif berat (provinsi/kab/kec/kel berkode, dll) TIDAK diminta
--  di form publik — dilengkapi petugas saat check-in di SIMRS. Yang disimpan di
--  sini adalah yang bisa diisi pasien & mempercepat pembuatan RM saat check-in.
--
--  Jalankan: psql -U postgres -d webcompro -f migrations/007_reservasi_lanjutan.sql
--  Idempoten (ADD COLUMN IF NOT EXISTS). Tak menghapus data.
-- =====================================================================
SET search_path TO webcompro, public;

-- Identitas RM tambahan pada akun pasien.
ALTER TABLE webcompro.pasien_akun
    ADD COLUMN IF NOT EXISTS tempat_lahir    varchar(80),
    ADD COLUMN IF NOT EXISTS agama           varchar(30),
    ADD COLUMN IF NOT EXISTS status_nikah    varchar(30),
    ADD COLUMN IF NOT EXISTS jenis_id        varchar(20),   -- KTP/SIM/PASPOR/KIA/...
    ADD COLUMN IF NOT EXISTS kewarganegaraan varchar(5);    -- WNI/WNA

-- Penjamin & riwayat pada reservasi.
ALTER TABLE webcompro.reservasi
    ADD COLUMN IF NOT EXISTS jenis_penjamin varchar(20),    -- ID T_TipePasien: 1 PRIBADI,2 ASURANSI,3 BPJS,5 PERUSAHAAN
    ADD COLUMN IF NOT EXISTS nama_penjamin  varchar(160),   -- nama asuransi/perusahaan (grup 2/5)
    ADD COLUMN IF NOT EXISTS no_kartu       varchar(40),    -- no kartu BPJS / no peserta (grup 3)
    ADD COLUMN IF NOT EXISTS sudah_pernah   boolean NOT NULL DEFAULT false,
    ADD COLUMN IF NOT EXISTS no_rm_lama     varchar(30);    -- No. RM lama bila pasien pernah berobat
