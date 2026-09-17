<?php
declare(strict_types=1);

namespace Middleware;

use Core\Database;
use Core\Env;
use Core\HttpException;
use Core\Request;
use Helpers\Jwt;

/**
 * Penjaga yang dipasang pada rute.
 *
 * Ditulis sebagai closure agar daftar rute memperlihatkan penjaganya
 * berdampingan dengan titik akhirnya — penjaga yang tersembunyi di dalam
 * controller mudah lupa dipasang pada titik akhir berikutnya.
 */
final class Middleware
{
    /**
     * CORS dengan daftar putih.
     *
     * Memantulkan Origin apa pun sama saja dengan tidak memasang CORS: situs
     * mana pun lalu boleh memanggil API ini memakai kredensial pengunjung.
     */
    public static function cors(): callable
    {
        return static function (Request $req): void {
            $izin = array_filter(array_map('trim',
                explode(',', (string) Env::get('CORS_ORIGINS', ''))));
            $asal = $req->origin();

            if ($asal !== null && in_array($asal, $izin, true)) {
                header('Access-Control-Allow-Origin: ' . $asal);
                header('Vary: Origin');
                header('Access-Control-Allow-Credentials: true');
            }
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
            header('Access-Control-Max-Age: 86400');
        };
    }

    /**
     * Pembatas laju sederhana berbasis berkas.
     *
     * Cukup untuk satu server. Bila kelak berjalan di beberapa server,
     * penyimpanannya harus pindah ke Redis — hitungan per server membuat
     * batasnya berlipat sebanyak jumlah servernya.
     */
    public static function batasLaju(string $keranjang, ?int $maksPerMenit = null): callable
    {
        return static function (Request $req) use ($keranjang, $maksPerMenit): void {
            $maks = $maksPerMenit ?? Env::int('RATE_LIMIT_PUBLIC', 120);
            if ($maks <= 0) {
                return;
            }

            $dir = BASE_PATH . '/storage/ratelimit';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $kunci  = hash('sha256', $keranjang . '|' . $req->ip() . '|' . date('YmdHi'));
            $berkas = $dir . '/' . $kunci;

            $n = 0;
            $fh = @fopen($berkas, 'c+');
            if ($fh === false) {
                return;                          // gagal membatasi tidak boleh memblokir layanan
            }
            if (flock($fh, LOCK_EX)) {
                $isi = stream_get_contents($fh);
                $n   = ((int) $isi) + 1;
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, (string) $n);
                fflush($fh);
                flock($fh, LOCK_UN);
            }
            fclose($fh);

            // Berkas menit-menit lampau dibersihkan sesekali, bukan setiap
            // permintaan — pembersihan yang terlalu rajin justru jadi beban.
            if (random_int(1, 200) === 1) {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    if (is_file($f) && filemtime($f) < time() - 3600) {
                        @unlink($f);
                    }
                }
            }

            if ($n > $maks) {
                header('Retry-After: 60');
                throw HttpException::terlaluSering();
            }
        };
    }

    /** Wajib membawa token admin yang sah. */
    public static function auth(): callable
    {
        return static function (Request $req): void {
            $header = $req->header('Authorization') ?? '';
            if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
                throw HttpException::takSah('Token tidak disertakan.');
            }

            try {
                $klaim = Jwt::periksa(trim($m[1]));
            } catch (\Throwable $e) {
                throw HttpException::takSah($e->getMessage());
            }

            // Token pasien tak boleh sah di rute admin: id pasien & id admin
            // bisa kebetulan sama, jadi tanpa penolakan ini token pasien dapat
            // menyamar sebagai admin ber-id sama.
            if (($klaim['tipe'] ?? null) === 'pasien') {
                throw HttpException::takSah('Token bukan untuk area admin.');
            }

            // Hak akses dibaca ulang dari basis data, bukan dipercaya dari
            // token: peran yang dicabut harus langsung berlaku, tidak
            // menunggu token lama kedaluwarsa dengan sendirinya.
            $pengguna = Database::satu(
                'SELECT id, nama, email, is_active FROM users WHERE id = :id',
                [':id' => (int) ($klaim['sub'] ?? 0)]);

            if ($pengguna === null || !$pengguna['is_active']) {
                throw HttpException::takSah('Akun tidak aktif.');
            }

            $izin = Database::semua(
                'SELECT DISTINCT p.kode
                   FROM user_roles ur
                   JOIN role_permissions rp ON rp.role_id = ur.role_id
                   JOIN permissions p       ON p.id = rp.permission_id
                  WHERE ur.user_id = :id',
                [':id' => $pengguna['id']]);

            $peran = Database::semua(
                'SELECT r.kode FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                  WHERE ur.user_id = :id', [':id' => $pengguna['id']]);
            $kodePeran = array_column($peran, 'kode');

            $req->setAuth([
                'sub'   => (int) $pengguna['id'],
                'nama'  => $pengguna['nama'],
                'email' => $pengguna['email'],
                'peran' => $kodePeran,
                // Super Admin diberi tanda bintang agar hak akses yang
                // ditambahkan kelak otomatis ikut, tanpa perlu diberikan ulang.
                'izin'  => in_array('SUPER_ADMIN', $kodePeran, true)
                    ? ['*'] : array_column($izin, 'kode'),
            ]);
        };
    }

    /**
     * Wajib membawa token PASIEN yang sah (portal reservasi).
     *
     * Terpisah dari auth(): pasien ada di tabel `pasien_akun`, dan token pasien
     * ditandai klaim `tipe = 'pasien'`. Token admin (tanpa tanda itu) ditolak
     * di sini, sama seperti token pasien ditolak di auth().
     */
    public static function authPasien(): callable
    {
        return static function (Request $req): void {
            $header = $req->header('Authorization') ?? '';
            if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
                throw HttpException::takSah('Token tidak disertakan.');
            }
            try {
                $klaim = Jwt::periksa(trim($m[1]));
            } catch (\Throwable $e) {
                throw HttpException::takSah($e->getMessage());
            }
            if (($klaim['tipe'] ?? null) !== 'pasien') {
                throw HttpException::takSah('Token bukan untuk akun pasien.');
            }

            $pasien = Database::satu(
                'SELECT id, nama, no_hp, is_active FROM pasien_akun WHERE id = :id',
                [':id' => (int) ($klaim['sub'] ?? 0)]);
            if ($pasien === null || !$pasien['is_active']) {
                throw HttpException::takSah('Akun tidak aktif.');
            }

            $req->setAuth([
                'sub'   => (int) $pasien['id'],
                'nama'  => $pasien['nama'],
                'no_hp' => $pasien['no_hp'],
                'tipe'  => 'pasien',
                'izin'  => [],
            ]);
        };
    }

    /**
     * Wewenang yang ditentukan modul pada jalur.
     *
     * Rute konten dipakai bersama enam modul; menuliskan enam rute yang sama
     * hanya untuk berbeda kode izin akan membuat satu di antaranya cepat atau
     * lambat tertinggal saat rute berubah — dan yang tertinggal itu menjadi
     * pintu yang tidak terjaga.
     */
    public static function izinKonten(): callable
    {
        $peta = [
            'articles' => 'article.manage',
            'news'     => 'news.manage',
            'videos'   => 'video.manage',
            'services' => 'service.manage',
            'mcu'      => 'mcu.manage',
            'homecare' => 'homecare.manage',
        ];

        return static function (Request $req, array $args = []) use ($peta): void {
            $kode = $peta[$args['modul'] ?? ''] ?? null;
            if ($kode === null) {
                throw HttpException::takDitemukan('Jenis konten tidak dikenal.');
            }
            if (!$req->punyaIzin($kode)) {
                throw HttpException::terlarang(
                    'Peran Anda tidak mencakup wewenang: ' . $kode . '.');
            }
        };
    }

    /** Wajib punya hak akses tertentu. Dipasang setelah auth(). */
    public static function izin(string $kode): callable
    {
        return static function (Request $req) use ($kode): void {
            if ($req->auth() === null) {
                throw HttpException::takSah();
            }
            if (!$req->punyaIzin($kode)) {
                throw HttpException::terlarang(
                    'Peran Anda tidak mencakup wewenang: ' . $kode . '.');
            }
        };
    }
}
