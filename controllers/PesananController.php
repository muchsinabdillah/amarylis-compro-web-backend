<?php
declare(strict_types=1);

namespace Controllers;

use Core\Database;
use Core\HttpException;
use Core\Request;
use Core\Response;
use Services\SimrsClient;

/**
 * Pesanan paket (MCU & layanan) oleh pasien.
 *
 * Master paket TIDAK diketik ulang — pesanan menunjuk `service_packages`
 * (hasil sinkron SIMRS) dan menyalin nama/harga/jml_kunjungan sebagai snapshot.
 * Pembayaran di klinik. Paket dijual ke SIMRS oleh admin saat pasien sudah
 * punya registrasi (No. Registrasi hasil check-in) — jumlah kunjungan terkunci
 * dari master paket (A_PAKET.JumlahKunjungan → A_PAKET_JUAL.KuotaTotal).
 */
final class PesananController
{
    // ------------------------------------------------------------ pasien
    public function buat(Request $req): void
    {
        $paketId = $req->int('package_id');
        if ($paketId <= 0) {
            throw HttpException::validasi(['package_id' => 'Paket wajib dipilih.']);
        }
        $paket = Database::satu(
            "SELECT id, simrs_id, kode, nama, harga_simrs, jml_kunjungan,
                    lower(coalesce(jenis,'lainnya')) AS jenis
               FROM service_packages
              WHERE id = :i AND aktif_simrs = true AND sync_status <> 'hilang_di_simrs'",
            [':i' => $paketId]);
        if ($paket === null) {
            throw HttpException::validasi(['package_id' => 'Paket tidak tersedia.']);
        }

        $tgl = trim((string) $req->str('tanggal'));
        if ($tgl !== '') {
            $d = \DateTime::createFromFormat('Y-m-d', $tgl);
            if (!$d || $d->format('Y-m-d') !== $tgl) {
                throw HttpException::validasi(['tanggal' => 'Tanggal tidak valid.']);
            }
            if ($tgl < date('Y-m-d')) {
                throw HttpException::validasi(['tanggal' => 'Tanggal tidak boleh di masa lalu.']);
            }
        }

        Database::jalankan(
            'INSERT INTO package_orders
                (pasien_id, package_id, package_simrs_id, kode_paket, nama_paket, jenis,
                 harga, jml_kunjungan, tanggal, catatan, status)
             VALUES (:pid, :pkg, :sid, :kode, :nama, :jenis,
                     :harga, :jml, :tgl, :cat, \'MENUNGGU\')',
            [
                ':pid'   => (int) $req->userId(),
                ':pkg'   => (int) $paket['id'],
                ':sid'   => $paket['simrs_id'],
                ':kode'  => $paket['kode'],
                ':nama'  => $paket['nama'],
                ':jenis' => $paket['jenis'],
                ':harga' => $paket['harga_simrs'],
                ':jml'   => (int) ($paket['jml_kunjungan'] ?: 1),
                ':tgl'   => $tgl !== '' ? $tgl : null,
                ':cat'   => self::kn($req->str('catatan')),
            ]);

        $id = (int) Database::nilai("SELECT currval(pg_get_serial_sequence('webcompro.package_orders','id'))");
        Response::sukses(['id' => $id, 'pesan' => 'Pesanan paket terkirim. Pembayaran dilakukan di klinik.'], [], 201);
    }

    public function milikSaya(Request $req): void
    {
        Response::sukses(Database::semua(
            'SELECT id, nama_paket, jenis, harga, jml_kunjungan, tanggal, status,
                    no_order_simrs, catatan, catatan_admin, created_at
               FROM package_orders WHERE pasien_id = :p ORDER BY created_at DESC LIMIT 100',
            [':p' => (int) $req->userId()]));
    }

    public function batal(Request $req, array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        $r = Database::satu(
            'SELECT status FROM package_orders WHERE id = :i AND pasien_id = :p',
            [':i' => $id, ':p' => (int) $req->userId()]);
        if ($r === null) {
            throw HttpException::takDitemukan('Pesanan tidak ditemukan.');
        }
        if (!in_array($r['status'], ['MENUNGGU', 'DIKONFIRMASI'], true)) {
            throw HttpException::validasi(['status' => 'Pesanan ini tidak dapat dibatalkan lagi.']);
        }
        Database::jalankan("UPDATE package_orders SET status = 'BATAL' WHERE id = :i", [':i' => $id]);
        Response::sukses(['pesan' => 'Pesanan dibatalkan.']);
    }

    // ------------------------------------------------------------- admin
    public function daftarAdmin(Request $req): void
    {
        [$hal, $per, $off] = $req->paginasi(20, 100);
        $where = '';
        $p = [];
        $status = strtoupper(trim((string) $req->str('status')));
        if (in_array($status, ['MENUNGGU', 'DIKONFIRMASI', 'SELESAI', 'BATAL'], true)) {
            $where = ' WHERE o.status = :s';
            $p[':s'] = $status;
        }
        $total = (int) Database::nilai("SELECT count(*) FROM package_orders o{$where}", $p);
        $rows = Database::semua(
            "SELECT o.id, o.nama_paket, o.kode_paket, o.jenis, o.harga, o.jml_kunjungan,
                    o.tanggal, o.status, o.package_simrs_id, o.no_order_simrs,
                    o.catatan, o.catatan_admin, o.diproses_oleh, o.diproses_at, o.created_at,
                    pa.nama AS nama_pasien, pa.no_hp, pa.nik, pa.no_mr
               FROM package_orders o JOIN pasien_akun pa ON pa.id = o.pasien_id
               {$where} ORDER BY o.created_at DESC LIMIT {$per} OFFSET {$off}", $p);
        Response::halaman($rows, $total, $hal, $per);
    }

    /**
     * Ubah status pesanan. Bila `no_registrasi` diisi dan paket punya simrs_id,
     * paket dijual ke SIMRS (A_PAKET_JUAL, kuota = jml kunjungan) lebih dulu;
     * gagal jual = status tak berubah, pesan SIMRS diteruskan.
     */
    public function ubahStatus(Request $req, array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        $status = strtoupper(trim((string) $req->str('status')));
        if (!in_array($status, ['DIKONFIRMASI', 'SELESAI', 'BATAL'], true)) {
            throw HttpException::validasi(['status' => 'Status tidak sah.']);
        }
        $o = Database::satu(
            'SELECT o.package_simrs_id, o.no_order_simrs, o.nama_paket, pa.nama AS nama_pasien
               FROM package_orders o JOIN pasien_akun pa ON pa.id = o.pasien_id
              WHERE o.id = :i', [':i' => $id]);
        if ($o === null) {
            throw HttpException::takDitemukan('Pesanan tidak ditemukan.');
        }

        $catatan = self::kn($req->str('catatan_admin'));
        $noOrder = $o['no_order_simrs'];
        $noReg   = trim((string) $req->str('no_registrasi'));

        // Jual ke SIMRS hanya bila diminta (ada No. Registrasi), paket ber-simrs_id,
        // dan belum pernah terjual.
        if ($noReg !== '' && empty($o['no_order_simrs'])) {
            if (empty($o['package_simrs_id'])) {
                throw HttpException::validasi(['no_registrasi' => 'Paket ini tidak tertaut ke SIMRS, tak bisa dijual otomatis.']);
            }
            $j = SimrsClient::kirim('website/paket/jual', [
                'id_paket'       => (string) $o['package_simrs_id'],
                'no_registrasi'  => $noReg,
                'nama'           => (string) $o['nama_pasien'],
                'tebus_pertama'  => 1,
            ]);
            if (!$j['ok']) {
                throw HttpException::validasi(['no_registrasi' => 'Gagal menjual paket di SIMRS: ' . $j['pesan']]);
            }
            $noOrder = $j['data']['no_paket'] ?? null;
            $catatan = trim((string) (($catatan ? $catatan . ' | ' : '') . 'SIMRS: ' . $j['pesan']));
        }

        Database::jalankan(
            "UPDATE package_orders
                SET status = :s, catatan_admin = :c, no_order_simrs = COALESCE(:no, no_order_simrs),
                    diproses_oleh = :by, diproses_at = now()
              WHERE id = :i",
            [':s' => $status, ':c' => $catatan, ':no' => $noOrder, ':by' => $req->namaPengguna(), ':i' => $id]);

        Response::sukses([
            'pesan'          => $noOrder && $noReg !== ''
                ? 'Pesanan dikonfirmasi & paket terjual di SIMRS. No. Paket: ' . $noOrder
                : 'Status pesanan diperbarui.',
            'no_order_simrs' => $noOrder,
        ]);
    }

    private static function kn($v)
    {
        $v = is_string($v) ? trim($v) : $v;
        return ($v === '' || $v === null) ? null : $v;
    }
}
