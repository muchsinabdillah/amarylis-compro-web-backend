-- =====================================================================
--  Pesanan paket (MCU & layanan) oleh pasien.
--
--  Master paket TIDAK diketik ulang di sini — pesanan menunjuk ke
--  service_packages (hasil sinkron SIMRS) lewat package_simrs_id, dan
--  menyalin nama/harga/jml_kunjungan sebagai snapshot saat dipesan (agar
--  riwayat tetap benar walau master berubah). Pembayaran dilakukan di klinik.
--
--  Jalankan: psql -U postgres -d webcompro -f migrations/010_pesanan_paket.sql
--  Idempoten. Tak menghapus data.
-- =====================================================================
SET search_path TO webcompro, public;

CREATE TABLE IF NOT EXISTS webcompro.package_orders (
    id               bigserial PRIMARY KEY,
    pasien_id        bigint NOT NULL REFERENCES webcompro.pasien_akun(id) ON DELETE CASCADE,
    package_id       bigint REFERENCES webcompro.service_packages(id) ON DELETE SET NULL,
    package_simrs_id integer,                       -- service_packages.simrs_id (snapshot)
    kode_paket       varchar(40),
    nama_paket       varchar(160) NOT NULL,
    jenis            varchar(20),                   -- mcu|homecare|lainnya
    harga            numeric(18,2),
    jml_kunjungan    integer NOT NULL DEFAULT 1,
    tanggal          date,                          -- rencana kunjungan pertama
    catatan          varchar(400),
    status           varchar(20) NOT NULL DEFAULT 'MENUNGGU'
                       CHECK (status IN ('MENUNGGU','DIKONFIRMASI','SELESAI','BATAL')),
    no_order_simrs   varchar(60),                   -- referensi order/registrasi SIMRS
    catatan_admin    varchar(400),
    diproses_oleh    varchar(120),
    diproses_at      timestamptz,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ix_orders_pasien ON webcompro.package_orders (pasien_id, created_at DESC);
CREATE INDEX IF NOT EXISTS ix_orders_status ON webcompro.package_orders (status, created_at DESC);

-- pemicu updated_at (fungsi sudah ada dari migrasi 001)
DROP TRIGGER IF EXISTS trg_package_orders_updated ON webcompro.package_orders;
CREATE TRIGGER trg_package_orders_updated
    BEFORE UPDATE ON webcompro.package_orders
    FOR EACH ROW EXECUTE FUNCTION webcompro.sentuh_updated_at();
