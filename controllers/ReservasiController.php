<?php
declare(strict_types=1);

namespace Controllers;

use Core\Database;
use Core\HttpException;
use Core\Request;
use Core\Response;
use Helpers\Validator;
use Services\SimrsClient;

/**
 * Reservasi poliklinik.
 *
 * Compro hanya MENAMPUNG reservasi (status MENUNGGU); pembuatan rekam medik &
 * registrasi tetap dilakukan admin di SIMRS. Metode pasien dijaga
 * Middleware::authPasien(); metode admin dijaga Middleware::izin('reservation.manage').
 */
final class ReservasiController
{
    // -----------------------------------------------------------------
    //  Sisi pasien
    // -----------------------------------------------------------------

    public function buat(Request $req): void
    {
        $v = Validator::untuk($req)->teks('poli', true, 120)->selesai();

        $tgl = trim((string) $req->str('tanggal'));
        $d = \DateTime::createFromFormat('Y-m-d', $tgl);
        if (!$d || $d->format('Y-m-d') !== $tgl) {
            throw HttpException::validasi(['tanggal' => 'Tanggal tidak valid.']);
        }
        if ($tgl < date('Y-m-d')) {
            throw HttpException::validasi(['tanggal' => 'Tanggal reservasi tidak boleh di masa lalu.']);
        }

        // Dokter opsional; bila diisi harus dokter AKTIF (hasil sinkron SIMRS),
        // bukan bergantung penanda "tayang" CMS. Nama di-snapshot dari DB.
        $doctorId = $req->int('doctor_id');
        $namaDokter = null;
        if ($doctorId > 0) {
            $dok = Database::satu(
                'SELECT nama, gelar FROM doctors WHERE id = :i AND aktif_simrs',
                [':i' => $doctorId]);
            if ($dok === null) {
                throw HttpException::validasi(['doctor_id' => 'Dokter tidak tersedia.']);
            }
            $namaDokter = trim(($dok['gelar'] ? $dok['gelar'] . ' ' : '') . $dok['nama']);
        } else {
            $doctorId = null;
        }

        // Penjamin (grup T_TipePasien SIMRS). Default PRIBADI (1).
        $penjamin = trim((string) $req->str('jenis_penjamin'));
        if (!in_array($penjamin, ['1', '2', '3', '5'], true)) $penjamin = '1';
        $sudahPernah = $req->bool('sudah_pernah');

        $pid = (int) $req->userId();

        // Lengkapi profil pasien dari wizard (isi yang kosong / perbarui). Data
        // ini = bahan petugas membuat rekam medik saat check-in di SIMRS.
        Database::jalankan(
            'UPDATE pasien_akun SET
                nama = COALESCE(:nama, nama), nik = COALESCE(:nik, nik),
                jenis_kelamin = COALESCE(:jk, jenis_kelamin), tempat_lahir = COALESCE(:tl, tempat_lahir),
                tgl_lahir = COALESCE(:tgll, tgl_lahir), alamat = COALESCE(:al, alamat),
                agama = COALESCE(:ag, agama), status_nikah = COALESCE(:sn, status_nikah),
                jenis_id = COALESCE(:jid, jenis_id), kewarganegaraan = COALESCE(:wn, kewarganegaraan)
              WHERE id = :id',
            [
                ':nama' => self::kn($req->str('nama')), ':nik' => self::kn($req->str('nik')),
                ':jk' => self::kn($req->str('jenis_kelamin')), ':tl' => self::kn($req->str('tempat_lahir')),
                ':tgll' => self::kn($req->str('tgl_lahir')), ':al' => self::kn($req->str('alamat')),
                ':ag' => self::kn($req->str('agama')), ':sn' => self::kn($req->str('status_nikah')),
                ':jid' => self::kn($req->str('jenis_id')), ':wn' => self::kn($req->str('kewarganegaraan')),
                ':id' => $pid,
            ]);

        Database::jalankan(
            'INSERT INTO reservasi
                (pasien_id, doctor_id, nama_dokter, poli, tanggal, jam, keluhan, status,
                 jenis_penjamin, nama_penjamin, no_kartu, sudah_pernah, no_rm_lama)
             VALUES (:pid, :did, :nd, :poli, :tgl, :jam, :kel, \'MENUNGGU\',
                     :jp, :np, :nk, :sp, :rl)',
            [
                ':pid'  => $pid,
                ':did'  => $doctorId,
                ':nd'   => $namaDokter,
                ':poli' => $v['poli'],
                ':tgl'  => $tgl,
                ':jam'  => self::kn($req->str('jam')),
                ':kel'  => self::kn($req->str('keluhan')),
                ':jp'   => $penjamin,
                ':np'   => self::kn($req->str('nama_penjamin')),
                ':nk'   => self::kn($req->str('no_kartu')),
                ':sp'   => $sudahPernah ? 'true' : 'false',
                ':rl'   => self::kn($req->str('no_rm_lama')),
            ]);

        $id = (int) Database::nilai("SELECT currval(pg_get_serial_sequence('webcompro.reservasi','id'))");
        Response::sukses(['id' => $id, 'pesan' => 'Reservasi terkirim. Menunggu verifikasi klinik.'], [], 201);
    }

    public function milikSaya(Request $req): void
    {
        $rows = Database::semua(
            'SELECT id, poli, nama_dokter, tanggal, jam, keluhan, status,
                    no_registrasi, no_antrian, no_trs_booking, catatan_admin, created_at, checkin_at,
                    jenis_penjamin, nama_penjamin, sudah_pernah
               FROM reservasi WHERE pasien_id = :p ORDER BY created_at DESC LIMIT 100',
            [':p' => (int) $req->userId()]);
        Response::sukses($rows);
    }

    public function batal(Request $req, array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        $r = Database::satu(
            'SELECT status FROM reservasi WHERE id = :i AND pasien_id = :p',
            [':i' => $id, ':p' => (int) $req->userId()]);
        if ($r === null) {
            throw HttpException::takDitemukan('Reservasi tidak ditemukan.');
        }
        if (!in_array($r['status'], ['MENUNGGU', 'DIVERIFIKASI'], true)) {
            throw HttpException::validasi(['status' => 'Reservasi ini tidak dapat dibatalkan lagi.']);
        }
        // Bila sudah jadi booking di SIMRS, batalkan di SIMRS lebih dulu — agar
        // kedua sisi konsisten. SIMRS menolak bila pasien sudah check-in.
        $v = $this->batalBookingSimrs($id, 'Dibatalkan oleh pasien');
        if (!$v['ok']) {
            throw HttpException::validasi(['batal' =>
                'Gagal membatalkan booking di SIMRS: ' . $v['pesan'] . ' Coba lagi atau hubungi klinik.']);
        }
        Database::jalankan("UPDATE reservasi SET status = 'BATAL' WHERE id = :i", [':i' => $id]);
        Response::sukses(['pesan' => 'Reservasi dibatalkan.']);
    }

    /** Check-in mandiri oleh pasien — hanya booking terkonfirmasi & pada hari kunjungan. */
    public function checkinSendiri(Request $req, array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        $r = Database::satu(
            'SELECT status, tanggal, no_trs_booking FROM reservasi WHERE id = :i AND pasien_id = :p',
            [':i' => $id, ':p' => (int) $req->userId()]);
        if ($r === null) {
            throw HttpException::takDitemukan('Reservasi tidak ditemukan.');
        }
        if ($r['status'] !== 'DIVERIFIKASI') {
            throw HttpException::validasi(['status' => 'Check-in hanya untuk booking yang sudah dikonfirmasi klinik.']);
        }
        if ($r['tanggal'] !== date('Y-m-d')) {
            throw HttpException::validasi(['tanggal' => 'Check-in hanya bisa pada hari kunjungan (' . $r['tanggal'] . ').']);
        }
        // Tanpa booking SIMRS, check-in mandiri tak bisa menjadikannya registrasi.
        if (empty($r['no_trs_booking'])) {
            throw HttpException::validasi(['checkin' =>
                'Booking Anda belum terdaftar di sistem antrean klinik. Silakan hubungi klinik atau daftar di loket.']);
        }
        // Check-in ke SIMRS: mengubah booking → registrasi. SIMRS menolak bila
        // rekam medik belum ada/lengkap (pasien baru diarahkan ke counter) —
        // pesannya diteruskan apa adanya, dan status lokal TIDAK diubah.
        $c = $this->checkinKeSimrs((string) $r['no_trs_booking']);
        if (!$c['ok']) {
            throw HttpException::validasi(['checkin' => $c['pesan']]);
        }
        Database::jalankan(
            "UPDATE reservasi SET status = 'CHECKIN', checkin_at = now(),
                    no_registrasi = COALESCE(:nr, no_registrasi), no_mr = COALESCE(:nm, no_mr)
              WHERE id = :i",
            [':nr' => self::kn($c['no_registrasi']), ':nm' => self::kn($c['no_mr']), ':i' => $id]);
        Response::sukses([
            'pesan'         => 'Check-in berhasil. No. Registrasi: ' . ($c['no_registrasi'] ?: '-') . '. Silakan menuju poli.',
            'no_registrasi' => $c['no_registrasi'],
        ]);
    }

    /**
     * Verifikasi "pasien lama": cocokkan NIK + tgl lahir ke SIMRS. Bila cocok,
     * simpan No. RM terverifikasi ke akun (sinkron) dan kembalikan — supaya
     * booking memakai No. RM yang sah, bukan ketikan bebas. Bila tak cocok,
     * pasien diperlakukan baru.
     */
    public function cariRekamMedik(Request $req): void
    {
        $nik = trim((string) $req->str('nik'));
        $tgl = trim((string) $req->str('tgl_lahir'));
        if ($nik === '' || $tgl === '') {
            throw HttpException::validasi(['nik' => 'NIK dan tanggal lahir wajib diisi.']);
        }

        $res = SimrsClient::kirim('website/pasien/cari', ['nik' => $nik, 'tgl_lahir' => $tgl]);
        if (!$res['ok']) {
            throw HttpException::validasi(['nik' => $res['pesan'] ?: 'Gagal memverifikasi ke SIMRS.']);
        }
        $d = $res['data'];
        if (empty($d['ditemukan'])) {
            Response::sukses([
                'ditemukan' => false,
                'pesan'     => 'Data tidak ditemukan di SIMRS. Anda akan didaftarkan sebagai pasien baru.',
            ]);
            return;
        }

        // Sinkron ke akun: No. RM + NIK dipastikan; identitas kosong diisi dari SIMRS.
        Database::jalankan(
            'UPDATE pasien_akun
                SET no_mr = :mr, nik = :nik,
                    tgl_lahir     = COALESCE(tgl_lahir, :tgl),
                    jenis_kelamin = COALESCE(jenis_kelamin, :jk),
                    nama          = COALESCE(NULLIF(nama, \'\'), :nama)
              WHERE id = :id',
            [
                ':mr'   => $d['no_mr'], ':nik' => $nik, ':tgl' => $tgl,
                ':jk'   => self::kn($d['jenis_kelamin'] ?? null),
                ':nama' => self::kn($d['nama'] ?? null),
                ':id'   => (int) $req->userId(),
            ]);

        $lengkap = (bool) ($d['lengkap'] ?? false);
        Response::sukses([
            'ditemukan' => true,
            'no_mr'     => $d['no_mr'],
            'nama'      => $d['nama'] ?? null,
            'lengkap'   => $lengkap,
            'pesan'     => $lengkap
                ? 'Terverifikasi sebagai pasien lama. No. RM Anda: ' . $d['no_mr'] . '.'
                : 'Terverifikasi (No. RM ' . $d['no_mr'] . '), tapi rekam medik belum lengkap — '
                    . 'lengkapi di counter agar bisa check-in mandiri.',
        ]);
    }

    /**
     * Check-in booking di SIMRS (booking → registrasi). Best-effort mapping:
     * mengembalikan {ok, pesan, no_registrasi, no_mr}. ok=false membawa pesan
     * SIMRS (mis. "rekam medik belum lengkap", "sudah check-in").
     *
     * @return array{ok:bool,pesan:string,no_registrasi:?string,no_mr:?string}
     */
    private function checkinKeSimrs(string $kodebooking): array
    {
        $res = SimrsClient::kirim('website/booking/checkin', ['kodebooking' => $kodebooking]);
        return [
            'ok'            => $res['ok'],
            'pesan'         => $res['pesan'],
            'no_registrasi' => $res['data']['NoRegistrasi'] ?? null,
            'no_mr'         => $res['data']['NOMR'] ?? null,
        ];
    }

    // -----------------------------------------------------------------
    //  Sisi admin (CMS)
    // -----------------------------------------------------------------

    public function daftarAdmin(Request $req): void
    {
        [$hal, $per, $off] = $req->paginasi(20, 100);

        $where = '';
        $p = [];
        $status = strtoupper(trim((string) $req->str('status')));
        if (in_array($status, ['MENUNGGU', 'DIVERIFIKASI', 'DITOLAK', 'SELESAI', 'BATAL'], true)) {
            $where = ' WHERE r.status = :s';
            $p[':s'] = $status;
        }

        $total = (int) Database::nilai("SELECT count(*) FROM reservasi r{$where}", $p);
        $rows = Database::semua(
            "SELECT r.id, r.poli, r.nama_dokter, r.tanggal, r.jam, r.keluhan, r.status,
                    r.no_registrasi, r.no_mr, r.no_antrian, r.no_trs_booking,
                    r.catatan_admin, r.diproses_oleh, r.diproses_at, r.created_at, r.checkin_at,
                    r.jenis_penjamin, r.nama_penjamin, r.no_kartu, r.sudah_pernah, r.no_rm_lama,
                    pa.nama AS nama_pasien, pa.no_hp, pa.nik, pa.tgl_lahir, pa.jenis_kelamin, pa.alamat,
                    pa.tempat_lahir, pa.agama, pa.status_nikah, pa.jenis_id, pa.kewarganegaraan,
                    pa.no_mr AS pasien_no_mr
               FROM reservasi r JOIN pasien_akun pa ON pa.id = r.pasien_id
               {$where} ORDER BY r.created_at DESC LIMIT {$per} OFFSET {$off}", $p);

        Response::halaman($rows, $total, $hal, $per);
    }

    public function ubahStatus(Request $req, array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        $status = strtoupper(trim((string) $req->str('status')));
        if (!in_array($status, ['DIVERIFIKASI', 'DITOLAK', 'CHECKIN', 'SELESAI'], true)) {
            throw HttpException::validasi(['status' => 'Status tidak sah.']);
        }
        if (Database::nilai('SELECT 1 FROM reservasi WHERE id = :i', [':i' => $id]) === null) {
            throw HttpException::takDitemukan('Reservasi tidak ditemukan.');
        }

        // Saat KONFIRMASI: buat booking di SIMRS (best-effort). Bila berhasil,
        // simpan No. Antrian + No. Transaksi Booking. Bila gagal (BPJS, kuota,
        // SIMRS mati, endpoint belum ada), booking tetap terkonfirmasi dan
        // alasannya dicatat — petugas daftar manual di loket. Tak boleh
        // menggagalkan konfirmasi.
        $catatan   = self::kn($req->str('catatan_admin'));
        $noAntrian = null;
        $noTrs     = null;
        if ($status === 'DIVERIFIKASI') {
            $b = $this->bookingKeSimrs($id);
            if ($b['ok']) {
                $noAntrian = $b['no_antrian'];
                $noTrs     = $b['no_trs'];
            } elseif ($b['pesan'] !== '') {
                $catatan = trim(($catatan ? $catatan . ' | ' : '') . 'SIMRS: ' . $b['pesan']);
            }
        }

        // Saat TOLAK: bila reservasi sudah punya booking SIMRS (pernah
        // dikonfirmasi), batalkan juga di SIMRS. Menolak konsisten dua sisi;
        // SIMRS sendiri menolak void bila pasien sudah check-in.
        if ($status === 'DITOLAK') {
            $v = $this->batalBookingSimrs($id, (string) ($catatan ?: 'Ditolak oleh admin'));
            if (!$v['ok']) {
                throw HttpException::validasi(['status' =>
                    'Gagal membatalkan booking di SIMRS: ' . $v['pesan']]);
            }
        }

        $capCheckin = $status === 'CHECKIN' ? ', checkin_at = now()' : '';
        $capBooking = ($noAntrian !== null || $noTrs !== null) ? ', no_antrian = :na, no_trs_booking = :nt' : '';
        $bind = [
            ':s'  => $status,
            ':c'  => $catatan,
            ':nr' => self::kn($req->str('no_registrasi')),
            ':nm' => self::kn($req->str('no_mr')),
            ':by' => $req->namaPengguna(),
            ':i'  => $id,
        ];
        if ($capBooking !== '') { $bind[':na'] = $noAntrian; $bind[':nt'] = $noTrs; }

        Database::jalankan(
            "UPDATE reservasi
                SET status = :s, catatan_admin = :c, no_registrasi = :nr, no_mr = :nm,
                    diproses_oleh = :by, diproses_at = now(){$capCheckin}{$capBooking}
              WHERE id = :i", $bind);

        $pesan = $status === 'DIVERIFIKASI'
            ? ($noAntrian
                ? 'Booking dikonfirmasi & terdaftar di SIMRS. No. Antrian: ' . $noAntrian
                : 'Booking dikonfirmasi. Registrasi SIMRS dilakukan di loket.')
            : 'Status reservasi diperbarui.';
        Response::sukses(['pesan' => $pesan, 'no_antrian' => $noAntrian, 'no_trs_booking' => $noTrs]);
    }

    /**
     * Buat booking rawat jalan di SIMRS untuk satu reservasi (dipanggil saat
     * konfirmasi). Best-effort: mengembalikan {ok, pesan, no_antrian, no_trs}.
     * Butuh dokter (kodedokter = doctors.simrs_id) & jadwalnya.
     */
    private function bookingKeSimrs(int $id): array
    {
        $r = Database::satu(
            'SELECT r.tanggal, r.poli, r.doctor_id, r.jenis_penjamin, r.nama_penjamin,
                    r.sudah_pernah, r.no_rm_lama,
                    pa.nama, pa.nik, pa.tgl_lahir, pa.jenis_kelamin, pa.alamat, pa.no_hp,
                    pa.email, pa.status_nikah,
                    d.simrs_id AS dok_simrs
               FROM reservasi r
               JOIN pasien_akun pa ON pa.id = r.pasien_id
               LEFT JOIN doctors d ON d.id = r.doctor_id
              WHERE r.id = :i', [':i' => $id]);
        if ($r === null) return ['ok' => false, 'pesan' => 'Reservasi tidak ditemukan.', 'no_antrian' => null, 'no_trs' => null];

        if (empty($r['doctor_id']) || empty($r['dok_simrs'])) {
            return ['ok' => false, 'pesan' => 'Tanpa dokter tertentu — registrasi dilakukan di loket.', 'no_antrian' => null, 'no_trs' => null];
        }

        $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][(int) date('w', strtotime($r['tanggal']))];
        $jad = Database::satu(
            'SELECT jam_mulai, jam_selesai FROM doctor_schedules
              WHERE doctor_id = :d AND unit = :u AND hari = :h
              ORDER BY urutan LIMIT 1',
            [':d' => (int) $r['doctor_id'], ':u' => $r['poli'], ':h' => $hari]);

        $res = SimrsClient::kirim('website/booking', [
            'kodedokter'     => (string) $r['dok_simrs'],
            'poli'           => (string) $r['poli'],
            'tanggal'        => (string) $r['tanggal'],
            'jam_mulai'      => (string) ($jad['jam_mulai'] ?? ''),
            'jam_selesai'    => (string) ($jad['jam_selesai'] ?? ''),
            'jenis_penjamin' => (string) ($r['jenis_penjamin'] ?: '1'),
            'nama_penjamin'  => (string) ($r['nama_penjamin'] ?? ''),
            'no_mr'          => $r['sudah_pernah'] ? (string) ($r['no_rm_lama'] ?: '-') : '-',
            'nama'           => (string) $r['nama'],
            'nik'            => (string) ($r['nik'] ?? ''),
            'tgl_lahir'      => (string) ($r['tgl_lahir'] ?? ''),
            'jenis_kelamin'  => (string) ($r['jenis_kelamin'] ?? ''),
            'status_nikah'   => (string) ($r['status_nikah'] ?? ''),
            'alamat'         => (string) ($r['alamat'] ?? ''),
            'no_hp'          => (string) ($r['no_hp'] ?? ''),
            'email'          => (string) ($r['email'] ?? ''),
        ]);

        return [
            'ok'         => $res['ok'],
            'pesan'      => $res['pesan'],
            'no_antrian' => $res['data']['nomorantrean'] ?? null,
            'no_trs'     => $res['data']['kodebooking'] ?? null,
        ];
    }

    /**
     * Batalkan booking di SIMRS untuk satu reservasi (dipanggil saat pasien
     * membatalkan atau admin menolak reservasi terkonfirmasi).
     *
     * Bila reservasi belum pernah jadi booking SIMRS (tak ada no_trs_booking),
     * ini no-op yang berhasil — tak ada yang perlu dibatalkan di sana.
     * Bila ada, memanggil endpoint void SIMRS; SIMRS menolak bila pasien sudah
     * check-in (StatusAntrian > 0), jadi aturan "sudah check-in tak bisa batal"
     * ditegakkan di sisi SIMRS juga.
     *
     * @return array{ok:bool,pesan:string}
     */
    private function batalBookingSimrs(int $id, string $keterangan): array
    {
        $r = Database::satu('SELECT no_trs_booking FROM reservasi WHERE id = :i', [':i' => $id]);
        if ($r === null || empty($r['no_trs_booking'])) {
            return ['ok' => true, 'pesan' => ''];   // tak ada booking SIMRS → tak perlu void
        }
        $res = SimrsClient::kirim('website/booking/batal', [
            'kodebooking' => (string) $r['no_trs_booking'],
            'keterangan'  => $keterangan !== '' ? $keterangan : 'Dibatalkan lewat website',
            'petugas'     => 'WEBSITE',
        ]);
        if ($res['ok']) {
            return ['ok' => true, 'pesan' => $res['pesan']];
        }

        // Void gagal. Bedakan: bila booking memang SUDAH TAK ADA di SIMRS
        // (sudah dibatalkan di loket / tak ditemukan), pembatalan di website
        // aman diteruskan — justru menyinkronkan kembali kedua sisi. Selain itu
        // (mis. "sudah check-in", SIMRS mati) TETAP diblokir agar tak divergen.
        $p = strtolower($res['pesan']);
        $sudahHilang = str_contains($p, 'tidak ditemukan') || str_contains($p, 'sudah dibatalkan');
        $sudahCheckin = str_contains($p, 'checkin') || str_contains($p, 'check-in') || str_contains($p, 'melakukan');
        if ($sudahHilang && !$sudahCheckin) {
            return ['ok' => true, 'pesan' => $res['pesan']];
        }
        return ['ok' => false, 'pesan' => $res['pesan']];
    }

    private static function kn($v)
    {
        $v = is_string($v) ? trim($v) : $v;
        return ($v === '' || $v === null) ? null : $v;
    }
}
