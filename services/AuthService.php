<?php
declare(strict_types=1);

namespace Services;

use Core\Database;
use Core\HttpException;
use Helpers\Jwt;

/**
 * Masuk dan pengelolaan akun admin.
 */
final class AuthService
{
    public static function masuk(string $email, string $sandi): array
    {
        $u = Database::satu(
            'SELECT id, nama, email, password, is_active FROM users WHERE lower(email) = :e',
            [':e' => strtolower($email)]);

        /*
         * Pesan yang sama untuk email tak dikenal maupun sandi salah.
         * Membedakannya memberi tahu penyerang alamat mana yang terdaftar,
         * dan daftar alamat yang sah adalah separuh pekerjaan menebak sandi.
         *
         * Sandi tiruan tetap diperiksa saat penggunanya tidak ada supaya lama
         * jawabannya sama; jawaban yang lebih cepat untuk email tak dikenal
         * membocorkan hal yang sama lewat waktu.
         */
        $hashUji = $u['password']
            ?? '$2y$12$............................................................';

        $cocok = password_verify($sandi, $hashUji);

        if ($u === null || !$cocok) {
            throw HttpException::takSah('Email atau kata sandi salah.');
        }
        if (!$u['is_active']) {
            throw HttpException::terlarang('Akun Anda dinonaktifkan. Hubungi Super Admin.');
        }

        // Biaya hashing dapat dinaikkan seiring waktu; sandi lama diperbarui
        // diam-diam saat pemiliknya masuk, tanpa meminta apa pun darinya.
        if (password_needs_rehash($u['password'], PASSWORD_BCRYPT, ['cost' => 12])) {
            Database::jalankan('UPDATE users SET password = :p WHERE id = :i', [
                ':p' => password_hash($sandi, PASSWORD_BCRYPT, ['cost' => 12]),
                ':i' => $u['id'],
            ]);
        }

        Database::jalankan('UPDATE users SET last_login = now() WHERE id = :i', [':i' => $u['id']]);

        $peran = array_column(Database::semua(
            'SELECT r.kode, r.nama FROM user_roles ur JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = :i', [':i' => $u['id']]), 'kode');

        $izin = in_array('SUPER_ADMIN', $peran, true)
            ? ['*']
            : array_column(Database::semua(
                'SELECT DISTINCT p.kode FROM user_roles ur
                   JOIN role_permissions rp ON rp.role_id = ur.role_id
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE ur.user_id = :i', [':i' => $u['id']]), 'kode');

        return [
            'token'    => Jwt::buat(['sub' => (int) $u['id'], 'nama' => $u['nama']]),
            'pengguna' => [
                'id'    => (int) $u['id'],
                'nama'  => $u['nama'],
                'email' => $u['email'],
                'peran' => $peran,
                'izin'  => $izin,
            ],
        ];
    }

    public static function gantiSandi(int $userId, string $lama, string $baru): void
    {
        $u = Database::satu('SELECT password FROM users WHERE id = :i', [':i' => $userId]);
        if ($u === null || !password_verify($lama, $u['password'])) {
            throw HttpException::validasi(['sandi_lama' => 'Kata sandi lama tidak cocok.']);
        }
        self::periksaKekuatan($baru);
        Database::jalankan('UPDATE users SET password = :p WHERE id = :i', [
            ':p' => password_hash($baru, PASSWORD_BCRYPT, ['cost' => 12]),
            ':i' => $userId,
        ]);
    }

    /**
     * Setel ulang sandi pengguna lain (khusus pengelola pengguna).
     *
     * Sandi lama tidak diminta karena pengelola memang tidak mengetahuinya;
     * wewenangnya sudah dijaga di lapisan rute.
     */
    public static function setelSandi(int $userId, string $baru): void
    {
        self::periksaKekuatan($baru);
        $n = Database::jalankan('UPDATE users SET password = :p WHERE id = :i', [
            ':p' => password_hash($baru, PASSWORD_BCRYPT, ['cost' => 12]),
            ':i' => $userId,
        ]);
        if ($n === 0) {
            throw HttpException::takDitemukan('Pengguna tidak ditemukan.');
        }
    }

    public static function buatPengguna(string $nama, string $email, string $sandi, array $kodePeran): int
    {
        self::periksaKekuatan($sandi);

        $adaEmail = Database::nilai('SELECT 1 FROM users WHERE lower(email) = :e',
            [':e' => strtolower($email)]);
        if ($adaEmail !== null) {
            throw HttpException::validasi(['email' => 'Email ini sudah dipakai.']);
        }

        return (int) Database::transaksi(static function () use ($nama, $email, $sandi, $kodePeran) {
            Database::jalankan(
                'INSERT INTO users (nama, email, password) VALUES (:n, :e, :p)',
                [':n' => $nama, ':e' => strtolower($email),
                 ':p' => password_hash($sandi, PASSWORD_BCRYPT, ['cost' => 12])]);

            $id = (int) Database::nilai(
                "SELECT currval(pg_get_serial_sequence('webcompro.users','id'))");

            foreach ($kodePeran as $kode) {
                Database::jalankan(
                    'INSERT INTO user_roles (user_id, role_id)
                     SELECT :u, r.id FROM roles r WHERE upper(r.kode) = upper(:k)
                     ON CONFLICT DO NOTHING',
                    [':u' => $id, ':k' => $kode]);
            }
            return $id;
        });
    }

    /**
     * Syarat sandi.
     *
     * Panjang lebih menentukan daripada campuran simbol: "kucing-oranye-di-atap"
     * jauh lebih sulit ditebak daripada "P@ss1!" dan lebih mudah diingat,
     * sehingga tidak berakhir ditempel di bawah papan ketik.
     */
    private static function periksaKekuatan(string $sandi): void
    {
        if (mb_strlen($sandi) < 12) {
            throw HttpException::validasi(
                ['password' => 'Minimal 12 karakter. Rangkaian beberapa kata lebih aman dan lebih mudah diingat.']);
        }
        $umum = ['password', 'admin', '123456', 'qwerty', 'klinik', 'andini'];
        foreach ($umum as $kata) {
            if (stripos($sandi, $kata) !== false && mb_strlen($sandi) < 20) {
                throw HttpException::validasi(
                    ['password' => 'Hindari kata yang mudah ditebak seperti "' . $kata . '".']);
            }
        }
    }
}
