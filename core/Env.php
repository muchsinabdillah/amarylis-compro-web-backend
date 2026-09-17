<?php
declare(strict_types=1);

namespace Core;

/**
 * Pembaca berkas .env.
 *
 * Kredensial basis data, kunci JWT, dan kunci layanan luar tidak pernah
 * ditulis di dalam kode: berkas kode masuk git, dan rahasia yang pernah
 * masuk riwayat git tidak bisa benar-benar dihapus lagi.
 */
final class Env
{
    private static array $data = [];
    private static bool $dimuat = false;

    public static function muat(string $path): void
    {
        if (self::$dimuat) {
            return;
        }
        self::$dimuat = true;

        if (!is_readable($path)) {
            return;                       // biarkan getenv() dari server yang berlaku
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $baris) {
            $baris = trim($baris);
            if ($baris === '' || str_starts_with($baris, '#')) {
                continue;
            }
            $pos = strpos($baris, '=');
            if ($pos === false) {
                continue;
            }
            $kunci = trim(substr($baris, 0, $pos));
            $nilai = trim(substr($baris, $pos + 1));

            // Komentar di belakang nilai, tetapi hanya bila nilainya tidak dikutip
            // — URL Google Maps memuat '#' dan tidak boleh terpotong.
            if ($nilai !== '' && $nilai[0] !== '"' && $nilai[0] !== "'") {
                $hash = strpos($nilai, ' #');
                if ($hash !== false) {
                    $nilai = rtrim(substr($nilai, 0, $hash));
                }
            }
            if (strlen($nilai) >= 2
                && (($nilai[0] === '"' && str_ends_with($nilai, '"'))
                 || ($nilai[0] === "'" && str_ends_with($nilai, "'")))) {
                $nilai = substr($nilai, 1, -1);
            }
            self::$data[$kunci] = $nilai;
        }
    }

    public static function get(string $kunci, ?string $bawaan = null): ?string
    {
        if (array_key_exists($kunci, self::$data)) {
            return self::$data[$kunci];
        }
        $dariServer = getenv($kunci);
        return $dariServer !== false ? $dariServer : $bawaan;
    }

    public static function bool(string $kunci, bool $bawaan = false): bool
    {
        $v = self::get($kunci);
        if ($v === null || $v === '') {
            return $bawaan;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $kunci, int $bawaan = 0): int
    {
        $v = self::get($kunci);
        return ($v === null || $v === '') ? $bawaan : (int) $v;
    }

    /** Nilai wajib — ketiadaannya dihentikan saat start, bukan saat dipakai. */
    public static function wajib(string $kunci): string
    {
        $v = self::get($kunci);
        if ($v === null || $v === '') {
            throw new \RuntimeException(
                "Pengaturan {$kunci} belum diisi di .env. Salin .env.example lalu lengkapi."
            );
        }
        return $v;
    }
}
