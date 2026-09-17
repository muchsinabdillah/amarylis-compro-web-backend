<?php
declare(strict_types=1);

namespace Services;

use Core\Database;
use Core\HttpException;
use Helpers\Jwt;

/**
 * Masuk & pendaftaran akun PASIEN (portal reservasi).
 *
 * Terpisah dari AuthService (akun admin CMS): pasien memakai tabel
 * `pasien_akun`, identitasnya nomor HP, dan tokennya diberi klaim
 * `tipe = 'pasien'` supaya token pasien tidak pernah sah di rute admin dan
 * sebaliknya (id yang kebetulan sama tidak bisa saling menyamar).
 */
final class PasienAuthService
{
    /** Normalkan nomor HP Indonesia ke bentuk 62xxxxxxxxxx. */
    public static function normalHp(string $hp): string
    {
        $d = preg_replace('/\D+/', '', $hp) ?? '';
        if ($d === '') return '';
        if (str_starts_with($d, '620')) $d = '62' . substr($d, 3);
        elseif (str_starts_with($d, '0')) $d = '62' . substr($d, 1);
        elseif (!str_starts_with($d, '62')) $d = '62' . $d;
        return $d;
    }

    public static function daftar(array $in): array
    {
        $hp = self::normalHp((string) ($in['no_hp'] ?? ''));
        if (strlen($hp) < 10 || strlen($hp) > 15) {
            throw HttpException::validasi(['no_hp' => 'Nomor HP tidak valid.']);
        }
        $nama = trim((string) ($in['nama'] ?? ''));
        if ($nama === '') {
            throw HttpException::validasi(['nama' => 'Nama wajib diisi.']);
        }
        self::periksaSandi((string) ($in['password'] ?? ''));

        if (Database::nilai('SELECT 1 FROM pasien_akun WHERE no_hp = :h', [':h' => $hp]) !== null) {
            throw HttpException::validasi(['no_hp' => 'Nomor HP ini sudah terdaftar. Silakan masuk.']);
        }

        $jk = strtoupper(trim((string) ($in['jenis_kelamin'] ?? '')));
        if (!in_array($jk, ['L', 'P'], true)) $jk = null;

        Database::jalankan(
            'INSERT INTO pasien_akun (no_hp, nama, password, email, nik, tgl_lahir, jenis_kelamin, alamat)
             VALUES (:h, :n, :p, :e, :k, :t, :j, :a)',
            [
                ':h' => $hp,
                ':n' => $nama,
                ':p' => password_hash((string) $in['password'], PASSWORD_BCRYPT, ['cost' => 12]),
                ':e' => self::kn($in['email'] ?? null),
                ':k' => self::kn($in['nik'] ?? null),
                ':t' => self::kn($in['tgl_lahir'] ?? null),
                ':j' => $jk,
                ':a' => self::kn($in['alamat'] ?? null),
            ]);

        $id = (int) Database::nilai("SELECT currval(pg_get_serial_sequence('webcompro.pasien_akun','id'))");
        return self::sesi($id);
    }

    public static function masuk(string $hp, string $sandi): array
    {
        $hpN = self::normalHp($hp);
        $u = Database::satu(
            'SELECT id, nama, no_hp, password, is_active FROM pasien_akun WHERE no_hp = :h',
            [':h' => $hpN]);

        // Sandi tiruan tetap diperiksa saat HP tak dikenal agar lama jawabannya
        // seragam — jawaban lebih cepat membocorkan HP mana yang terdaftar.
        $hashUji = $u['password']
            ?? '$2y$12$............................................................';
        $cocok = password_verify($sandi, $hashUji);

        if ($u === null || !$cocok) {
            throw HttpException::takSah('Nomor HP atau kata sandi salah.');
        }
        if (!$u['is_active']) {
            throw HttpException::terlarang('Akun Anda dinonaktifkan. Hubungi klinik.');
        }
        return self::sesi((int) $u['id']);
    }

    public static function gantiSandi(int $id, string $lama, string $baru): void
    {
        $u = Database::satu('SELECT password FROM pasien_akun WHERE id = :i', [':i' => $id]);
        if ($u === null || !password_verify($lama, $u['password'])) {
            throw HttpException::validasi(['sandi_lama' => 'Kata sandi lama tidak cocok.']);
        }
        self::periksaSandi($baru);
        Database::jalankan('UPDATE pasien_akun SET password = :p WHERE id = :i', [
            ':p' => password_hash($baru, PASSWORD_BCRYPT, ['cost' => 12]),
            ':i' => $id,
        ]);
    }

    public static function profil(int $id): ?array
    {
        return Database::satu(
            'SELECT id, no_hp, nama, email, nik, tgl_lahir, jenis_kelamin, alamat, no_mr,
                    tempat_lahir, agama, status_nikah, jenis_id, kewarganegaraan
               FROM pasien_akun WHERE id = :i AND is_active', [':i' => $id]);
    }

    private static function sesi(int $id): array
    {
        $p = self::profil($id);
        return [
            'token'  => Jwt::buat(['sub' => $id, 'tipe' => 'pasien', 'nama' => $p['nama'] ?? '']),
            'pasien' => $p,
        ];
    }

    /** Pasien awam: cukup 8 karakter (lebih ringan dari admin), tetap di-hash. */
    private static function periksaSandi(string $s): void
    {
        if (mb_strlen($s) < 8) {
            throw HttpException::validasi(['password' => 'Kata sandi minimal 8 karakter.']);
        }
    }

    private static function kn($v)
    {
        $v = is_string($v) ? trim($v) : $v;
        return ($v === '' || $v === null) ? null : $v;
    }
}
