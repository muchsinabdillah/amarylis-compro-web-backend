<?php
declare(strict_types=1);

namespace Core;

/**
 * Jawaban JSON dengan bentuk yang selalu sama.
 *
 * Frontend cukup memeriksa satu bentuk untuk seluruh titik akhir; jawaban
 * yang berbeda-beda memaksa setiap pemanggil menebak, dan penanganan galat
 * jadi tersebar dan tidak seragam.
 *
 *   { "sukses": true,  "data": ..., "meta": {...} }
 *   { "sukses": false, "pesan": "...", "galat": {...} }
 */
final class Response
{
    private const HEADER_AMAN = [
        // Peramban tidak boleh menebak tipe isi; tebakan yang salah pada
        // berkas unggahan bisa berubah menjadi eksekusi skrip.
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
    ];

    public static function json(mixed $data, int $status = 200, array $header = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach (self::HEADER_AMAN + $header as $k => $v) {
            header("{$k}: {$v}");
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function sukses(mixed $data = null, array $meta = [], int $status = 200): void
    {
        $isi = ['sukses' => true, 'data' => $data];
        if ($meta !== []) {
            $isi['meta'] = $meta;
        }
        self::json($isi, $status);
    }

    public static function gagal(string $pesan, int $status = 400, array $galat = []): void
    {
        $isi = ['sukses' => false, 'pesan' => $pesan];
        if ($galat !== []) {
            $isi['galat'] = $galat;
        }
        self::json($isi, $status);
    }

    /** Daftar berpaginasi — bentuk meta-nya sama di seluruh titik akhir. */
    public static function halaman(array $baris, int $total, int $halaman, int $perHalaman): void
    {
        self::sukses($baris, [
            'total'       => $total,
            'halaman'     => $halaman,
            'per_halaman' => $perHalaman,
            'jml_halaman' => $perHalaman > 0 ? (int) ceil($total / $perHalaman) : 0,
        ]);
    }

    public static function takAdaIsi(): void
    {
        http_response_code(204);
        foreach (self::HEADER_AMAN as $k => $v) {
            header("{$k}: {$v}");
        }
    }
}
