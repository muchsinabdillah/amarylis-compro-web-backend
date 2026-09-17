<?php
declare(strict_types=1);

namespace Controllers;

use Core\Database;
use Core\HttpException;
use Core\Request;
use Core\Response;
use Helpers\Validator;
use Services\AuthService;

/**
 * Pengguna CMS dan perannya.
 */
final class UserController
{
    public function daftar(Request $req): void
    {
        /*
         * Daftar kode peran dikembalikan sebagai JSON, bukan array Postgres.
         * PDO menyerahkan array Postgres apa adanya berupa teks "{A,B}", dan
         * frontend yang menerimanya akan mengurainya sendiri dengan cara yang
         * cepat atau lambat keliru.
         */
        Response::sukses(array_map(
            static function (array $u): array {
                $u['kode_peran'] = json_decode((string) $u['kode_peran'], true) ?: [];
                $u['is_active']  = (bool) $u['is_active'];
                return $u;
            },
            Database::semua(
                "SELECT u.id, u.nama, u.email, u.is_active, u.last_login, u.created_at,
                        coalesce(string_agg(DISTINCT r.nama, ', '), '-') AS peran,
                        coalesce(json_agg(DISTINCT r.kode)
                                 FILTER (WHERE r.kode IS NOT NULL), '[]') AS kode_peran
                   FROM users u
                   LEFT JOIN user_roles ur ON ur.user_id = u.id
                   LEFT JOIN roles r       ON r.id = ur.role_id
                  GROUP BY u.id ORDER BY u.nama")));
    }

    public function peran(Request $req): void
    {
        Response::sukses([
            'peran' => array_map(
                static function (array $r): array {
                    $r['izin'] = json_decode((string) $r['izin'], true) ?: [];
                    return $r;
                },
                Database::semua(
                    "SELECT r.id, r.kode, r.nama, r.keterangan,
                            coalesce(json_agg(DISTINCT p.kode)
                                     FILTER (WHERE p.kode IS NOT NULL), '[]') AS izin
                       FROM roles r
                       LEFT JOIN role_permissions rp ON rp.role_id = r.id
                       LEFT JOIN permissions p       ON p.id = rp.permission_id
                      GROUP BY r.id ORDER BY r.id")),
            'izin' => Database::semua(
                'SELECT kode, modul, nama FROM permissions ORDER BY modul, kode'),
        ]);
    }

    public function buat(Request $req): void
    {
        $v = Validator::untuk($req)
            ->teks('nama', true, 120)
            ->email('email')
            ->teks('password', true, 200)
            ->selesai();

        $peran = array_values(array_filter($req->arr('peran'), 'is_string'));
        if ($peran === []) {
            throw HttpException::validasi(['peran' => 'Pilih minimal satu peran.']);
        }

        $id = AuthService::buatPengguna($v['nama'], $v['email'], $v['password'], $peran);
        Response::sukses(['id' => $id], [], 201);
    }

    public function ubah(Request $req, array $args): void
    {
        $id = (int) $args['id'];
        if (Database::nilai('SELECT 1 FROM users WHERE id = :i', [':i' => $id]) === null) {
            throw HttpException::takDitemukan('Pengguna tidak ditemukan.');
        }

        $v = Validator::untuk($req)->teks('nama', false, 120)->selesai();

        if (isset($v['nama'])) {
            Database::jalankan('UPDATE users SET nama = :n WHERE id = :i',
                [':n' => $v['nama'], ':i' => $id]);
        }

        if ($req->ada('is_active')) {
            $this->pastikanBukanDiriSendiri($req, $id,
                'Anda tidak dapat menonaktifkan akun Anda sendiri.');
            $aktif = $req->bool('is_active');
            $this->pastikanMasihAdaSuperAdmin($id, $aktif ? null : false);
            Database::jalankan('UPDATE users SET is_active = :a WHERE id = :i',
                [':a' => $aktif ? 'true' : 'false', ':i' => $id]);
        }

        if ($req->ada('peran')) {
            $this->pastikanBukanDiriSendiri($req, $id,
                'Anda tidak dapat mengubah peran akun Anda sendiri.');
            $peran = array_values(array_filter($req->arr('peran'), 'is_string'));

            // Pemeriksaan sisa Super Admin dilakukan DI DALAM transaksi:
            // di luar, penghapusan peran sudah tersimpan dan penolakannya
            // datang terlambat.
            Database::transaksi(function () use ($id, $peran) {
                Database::jalankan('DELETE FROM user_roles WHERE user_id = :i', [':i' => $id]);
                foreach ($peran as $kode) {
                    Database::jalankan(
                        'INSERT INTO user_roles (user_id, role_id)
                         SELECT :u, r.id FROM roles r WHERE upper(r.kode) = upper(:k)
                         ON CONFLICT DO NOTHING',
                        [':u' => $id, ':k' => $kode]);
                }
                $this->pastikanMasihAdaSuperAdmin(null, null);
            });
        }

        Response::sukses(['id' => $id]);
    }

    /**
     * Setel ulang sandi orang lain.
     *
     * Sandi lama tidak diminta — Super Admin memang tidak mengetahuinya.
     * Tindakan ini tercatat sebagai perubahan pada baris penggunanya.
     */
    public function setelSandi(Request $req, array $args): void
    {
        $v = Validator::untuk($req)->teks('password', true, 200)->selesai();
        $id = (int) $args['id'];

        if (Database::nilai('SELECT 1 FROM users WHERE id = :i', [':i' => $id]) === null) {
            throw HttpException::takDitemukan('Pengguna tidak ditemukan.');
        }
        AuthService::setelSandi($id, $v['password']);
        Response::sukses(['pesan' => 'Kata sandi pengguna berhasil disetel ulang.']);
    }

    public function hapus(Request $req, array $args): void
    {
        $id = (int) $args['id'];
        $this->pastikanBukanDiriSendiri($req, $id, 'Anda tidak dapat menghapus akun Anda sendiri.');
        $this->pastikanMasihAdaSuperAdmin($id, false);

        if (Database::jalankan('DELETE FROM users WHERE id = :i', [':i' => $id]) === 0) {
            throw HttpException::takDitemukan('Pengguna tidak ditemukan.');
        }
        Response::takAdaIsi();
    }

    private function pastikanBukanDiriSendiri(Request $req, int $id, string $pesan): void
    {
        if ($req->userId() === $id) {
            throw HttpException::validasi(['pengguna' => $pesan]);
        }
    }

    /**
     * Selalu sisakan satu Super Admin yang aktif.
     *
     * Tanpa penjaga ini, satu klik dapat mengunci semua orang keluar dari CMS
     * dan pemulihannya hanya mungkin lewat akses langsung ke basis data.
     */
    private function pastikanMasihAdaSuperAdmin(?int $terdampak, ?bool $jadiAktif): void
    {
        $sisa = (int) Database::nilai(
            "SELECT count(*) FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r       ON r.id = ur.role_id
              WHERE upper(r.kode) = 'SUPER_ADMIN' AND u.is_active
                AND (:t::bigint IS NULL OR u.id <> :t)",
            [':t' => $terdampak]);

        if ($sisa === 0 && $jadiAktif !== true) {
            throw HttpException::validasi(
                ['pengguna' => 'Harus tersisa minimal satu Super Admin yang aktif.']);
        }
    }
}
