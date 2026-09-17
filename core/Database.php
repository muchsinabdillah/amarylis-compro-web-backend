<?php
declare(strict_types=1);

namespace Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Sambungan ke basis data milik website.
 *
 * Website memakai basis datanya SENDIRI — bukan menumpang di basis data
 * SIMRS, dan tidak memegang kredensialnya sama sekali. Data SIMRS yang
 * dibutuhkan diambil lewat API (lihat Services\SimrsClient).
 *
 * Pemisahan itu yang membuat situs dapat dipindah ke server lain,
 * dicadangkan, atau dipulihkan tanpa menyentuh SIMRS — dan sebaliknya,
 * pemulihan SIMRS tidak menimpa isi website. Bila SIMRS mati, halaman
 * publik tetap tayang; hanya sinkronisasi yang tertunda.
 *
 * Seluruh kueri memakai pernyataan tersiapkan dengan parameter terikat —
 * bukan sekadar kebiasaan, melainkan satu-satunya cara yang benar-benar
 * menutup penyuntikan SQL. Merangkai kueri dengan penyambungan string,
 * betapapun nilainya "sudah dibersihkan", cepat atau lambat akan meleset.
 *
 * Nama skema TIDAK BOLEH berasal dari masukan pengguna: ia tidak bisa
 * diikat sebagai parameter, jadi nilainya hanya boleh dari .env.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $skema = (string) Env::get('DB_SCHEMA', 'webcompro');

        // Hanya huruf, angka, dan garis bawah — penjaga terhadap .env yang
        // salah isi, bukan terhadap pengguna.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $skema)) {
            throw new RuntimeException('DB_SCHEMA tidak sah.');
        }

        self::$pdo = self::sambung(
            (string) Env::get('DB_HOST', '127.0.0.1'),
            (string) Env::get('DB_PORT', '5432'),
            Env::wajib('DB_NAME'),
            Env::wajib('DB_USER'),
            (string) (Env::get('DB_PASS', '') ?? ''),
            'basis data website');

        self::$pdo->exec('SET search_path TO ' . $skema . ', public');
        self::$pdo->exec("SET TIME ZONE 'Asia/Jakarta'");

        return self::$pdo;
    }

    private static function sambung(
        string $host, string $port, string $nama, string $user, string $sandi, string $sebutan
    ): PDO {
        try {
            return new PDO("pgsql:host={$host};port={$port};dbname={$nama}", $user, $sandi, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Pernyataan benar-benar disiapkan di server, bukan ditiru
                // di sisi klien; peniruan menyusun ulang kueri sebagai string.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // Pesan asli memuat host, nama basis data, dan nama pengguna.
            throw new RuntimeException('Tidak dapat terhubung ke ' . $sebutan . '.', 0, $e);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function semua(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function satu(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $baris = $st->fetch();
        return $baris === false ? null : $baris;
    }

    public static function nilai(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function jalankan(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /**
     * Jalankan sekumpulan perubahan sebagai satu kesatuan.
     *
     * Dipakai sinkronisasi: bila satu baris gagal di tengah jalan, seluruh
     * jalannya dibatalkan — daftar dokter yang setengah tersinkron lebih
     * membingungkan daripada daftar yang belum tersinkron sama sekali.
     */
    public static function transaksi(callable $kerja): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $hasil = $kerja($pdo);
            $pdo->commit();
            return $hasil;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
