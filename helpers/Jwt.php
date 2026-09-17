<?php
declare(strict_types=1);

namespace Helpers;

use Core\Env;

/**
 * Token admin, HS256.
 *
 * Ditulis sendiri karena kebutuhannya sempit dan pustaka JWT terkenal justru
 * pernah menjadi sumber celah klasik: token dengan alg "none" diterima, atau
 * algoritma diambil dari header token itu sendiri. Di sini algoritmanya
 * DITETAPKAN — nilai alg pada header dibaca hanya untuk ditolak bila berbeda.
 */
final class Jwt
{
    private const ALG = 'HS256';

    public static function buat(array $klaim, ?int $ttlMenit = null): string
    {
        $ttl = $ttlMenit ?? Env::int('JWT_TTL_MINUTES', 480);
        $now = time();

        $payload = $klaim + [
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + ($ttl * 60),
            'jti' => bin2hex(random_bytes(8)),
        ];

        $h = self::b64(json_encode(['alg' => self::ALG, 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $p = self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $s = self::b64(self::tandaTangan("{$h}.{$p}"));

        return "{$h}.{$p}.{$s}";
    }

    /** @return array<string,mixed> klaim yang sudah terbukti sah */
    public static function periksa(string $token): array
    {
        $bagian = explode('.', $token);
        if (count($bagian) !== 3) {
            throw new \RuntimeException('Bentuk token tidak sah.');
        }
        [$h, $p, $s] = $bagian;

        // Dibandingkan dengan waktu tetap; perbandingan biasa membocorkan
        // posisi ketidakcocokan lewat lama pemeriksaannya.
        if (!hash_equals(self::b64(self::tandaTangan("{$h}.{$p}")), $s)) {
            throw new \RuntimeException('Tanda tangan token tidak cocok.');
        }

        $header = json_decode(self::unb64($h), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== self::ALG) {
            throw new \RuntimeException('Algoritma token tidak diterima.');
        }

        $klaim = json_decode(self::unb64($p), true);
        if (!is_array($klaim)) {
            throw new \RuntimeException('Isi token tidak sah.');
        }

        $now = time();
        if (isset($klaim['nbf']) && $now < (int) $klaim['nbf']) {
            throw new \RuntimeException('Token belum berlaku.');
        }
        if (isset($klaim['exp']) && $now >= (int) $klaim['exp']) {
            throw new \RuntimeException('Sesi berakhir. Silakan masuk kembali.');
        }

        return $klaim;
    }

    private static function tandaTangan(string $data): string
    {
        $kunci = Env::wajib('JWT_SECRET');
        if (strlen($kunci) < 32) {
            throw new \RuntimeException(
                'JWT_SECRET terlalu pendek. Buat dengan: openssl rand -hex 32');
        }
        return hash_hmac('sha256', $data, $kunci, true);
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
