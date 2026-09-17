-- =====================================================================
--  Nomor dari SIMRS saat "Konfirmasi booking": No. Antrian & No. Transaksi
--  Booking (mis. No. Registrasi). Diisi ketika booking dibuat di SIMRS.
--  Jalankan: psql -U postgres -d webcompro -f migrations/009_booking_simrs.sql
--  Idempoten. Tak menghapus data.
-- =====================================================================
SET search_path TO webcompro, public;

ALTER TABLE webcompro.reservasi
    ADD COLUMN IF NOT EXISTS no_antrian     varchar(40),
    ADD COLUMN IF NOT EXISTS no_trs_booking varchar(40);
