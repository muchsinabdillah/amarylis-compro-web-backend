<?php
declare(strict_types=1);

/**
 * Pembuat akun CMS dari baris perintah.
 *
 *   php bin/pengguna.php buat "Nama Lengkap" email@klinik.id SUPER_ADMIN
 *   php bin/pengguna.php sandi email@klinik.id
 *   php bin/pengguna.php daftar
 *
 * Akun pertama dibuat lewat perintah ini, bukan lewat migrasi berisi sandi
 * bawaan. Sandi bawaan yang tertulis di berkas migrasi akan ikut masuk ke git,
 * terbaca siapa pun yang punya salinan repositori, dan hampir tidak pernah
 * diganti setelah pemasangan.
 *
 * Sandi diminta lewat prompt, tidak lewat argumen: argumen tersimpan di
 * riwayat shell dan terlihat pada daftar proses.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Core\Database;
use Core\HttpException;
use Services\AuthService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$perintah = $argv[1] ?? '';

try {
    match ($perintah) {
        'buat'   => perintahBuat($argv),
        'sandi'  => perintahSandi($argv),
        'daftar' => perintahDaftar(),
        default  => bantuan(),
    };
} catch (HttpException $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . PHP_EOL);
    foreach ($e->rincian() as $k => $v) {
        fwrite(STDERR, '  - ' . $k . ': ' . (is_string($v) ? $v : json_encode($v)) . PHP_EOL);
    }
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function bantuan(): void
{
    echo <<<TEKS
    Pengelolaan akun CMS website Klinik Pratama Andini

      php bin/pengguna.php buat "Nama Lengkap" email@klinik.id [PERAN]
      php bin/pengguna.php sandi email@klinik.id
      php bin/pengguna.php daftar

    PERAN: SUPER_ADMIN | ADMIN_CONTENT | ADMIN_KLINIK   (bawaan: ADMIN_CONTENT)

    TEKS;
}

function perintahBuat(array $argv): void
{
    $nama  = $argv[2] ?? '';
    $email = $argv[3] ?? '';
    $peran = strtoupper($argv[4] ?? 'ADMIN_CONTENT');

    if ($nama === '' || $email === '') {
        bantuan();
        exit(1);
    }

    $adaPeran = Database::nilai('SELECT 1 FROM roles WHERE upper(kode) = :k', [':k' => $peran]);
    if ($adaPeran === null) {
        fwrite(STDERR, 'Peran "' . $peran . '" tidak ada. Jalankan migrasi 002 terlebih dahulu.' . PHP_EOL);
        exit(1);
    }

    $sandi = mintaSandi();
    $id    = AuthService::buatPengguna($nama, $email, $sandi, [$peran]);

    echo 'Akun dibuat. id=' . $id . ' peran=' . $peran . PHP_EOL;
}

function perintahSandi(array $argv): void
{
    $email = $argv[2] ?? '';
    if ($email === '') {
        bantuan();
        exit(1);
    }

    $u = Database::satu('SELECT id, nama FROM users WHERE lower(email) = :e',
        [':e' => strtolower($email)]);
    if ($u === null) {
        fwrite(STDERR, 'Email tidak terdaftar.' . PHP_EOL);
        exit(1);
    }

    AuthService::setelSandi((int) $u['id'], mintaSandi());
    echo 'Kata sandi ' . $u['nama'] . ' berhasil diganti.' . PHP_EOL;
}

function perintahDaftar(): void
{
    $baris = Database::semua(
        "SELECT u.id, u.nama, u.email, u.is_active,
                coalesce(string_agg(r.kode, ',' ORDER BY r.kode), '-') AS peran
           FROM users u
           LEFT JOIN user_roles ur ON ur.user_id = u.id
           LEFT JOIN roles r       ON r.id = ur.role_id
          GROUP BY u.id ORDER BY u.id");

    if ($baris === []) {
        echo 'Belum ada akun. Buat dengan: php bin/pengguna.php buat "Nama" email@klinik.id SUPER_ADMIN' . PHP_EOL;
        return;
    }
    printf("%-4s %-28s %-32s %-8s %s\n", 'ID', 'NAMA', 'EMAIL', 'AKTIF', 'PERAN');
    foreach ($baris as $b) {
        printf("%-4s %-28s %-32s %-8s %s\n",
            $b['id'], mb_substr($b['nama'], 0, 28), mb_substr($b['email'], 0, 32),
            $b['is_active'] ? 'ya' : 'tidak', $b['peran']);
    }
}

/**
 * Ambil sandi tanpa menampilkannya.
 *
 * Windows tidak punya `stty`, jadi gemanya tidak selalu dapat dimatikan. Bila
 * memang tidak bisa, hal itu dikatakan apa adanya alih-alih diam-diam
 * menampilkan ketikan yang dikira tersembunyi.
 */
function mintaSandi(): string
{
    $bisaSembunyi = stripos(PHP_OS_FAMILY, 'Windows') === false
        && shell_exec('command -v stty 2>/dev/null') !== null;

    if (!$bisaSembunyi) {
        echo 'Catatan: ketikan sandi akan terlihat di layar ini.' . PHP_EOL;
    }

    while (true) {
        echo 'Kata sandi baru (min. 12 karakter): ';
        $a = bacaSandi($bisaSembunyi);
        echo 'Ulangi kata sandi: ';
        $b = bacaSandi($bisaSembunyi);

        if ($a !== $b) {
            echo 'Kedua isian tidak sama. Coba lagi.' . PHP_EOL;
            continue;
        }
        if (mb_strlen($a) < 12) {
            echo 'Terlalu pendek. Rangkaian beberapa kata lebih aman dan mudah diingat.' . PHP_EOL;
            continue;
        }
        return $a;
    }
}

function bacaSandi(bool $sembunyi): string
{
    if ($sembunyi) {
        shell_exec('stty -echo');
    }
    $isi = trim((string) fgets(STDIN));
    if ($sembunyi) {
        shell_exec('stty echo');
        echo PHP_EOL;
    }
    return $isi;
}
