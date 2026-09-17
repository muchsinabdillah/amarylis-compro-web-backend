-- =====================================================================
--  Tambah status CHECKIN pada reservasi (pasien datang & terdaftar di SIMRS).
--  Lifecycle: MENUNGGU → DIVERIFIKASI → CHECKIN. (DITOLAK/BATAL/SELESAI tetap.)
--  Jalankan: psql -U postgres -d webcompro -f migrations/008_reservasi_checkin.sql
--  Idempoten. Tak menghapus data.
-- =====================================================================
SET search_path TO webcompro, public;

ALTER TABLE webcompro.reservasi
    ADD COLUMN IF NOT EXISTS checkin_at timestamptz;

ALTER TABLE webcompro.reservasi DROP CONSTRAINT IF EXISTS reservasi_status_sah;
ALTER TABLE webcompro.reservasi ADD CONSTRAINT reservasi_status_sah CHECK
    (status IN ('MENUNGGU','DIVERIFIKASI','DITOLAK','CHECKIN','SELESAI','BATAL'));
