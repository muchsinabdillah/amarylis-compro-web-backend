<?php
declare(strict_types=1);

namespace Controllers;

use Core\Request;
use Core\Response;
use Services\McuPasienService;

/**
 * Hasil MCU milik pasien sendiri.
 *
 * Seluruh titik akhir di sini berada di belakang Middleware::authPasien().
 * no_mr diambil dari sesi — yang membacanya ulang dari basis data pada setiap
 * permintaan — dan tidak pernah dari badan permintaan.
 */
final class McuPasienController
{
    /** Identitas pemilik sesi; satu-satunya sumber no_mr. */
    private function sesi(Request $req): array
    {
        $a = $req->auth() ?? [];
        return [
            'sub'   => (int) ($a['sub'] ?? 0),
            'nama'  => (string) ($a['nama'] ?? ''),
            'no_mr' => (string) ($a['no_mr'] ?? ''),
        ];
    }

    public function daftar(Request $req): void
    {
        Response::sukses(McuPasienService::daftar($this->sesi($req)));
    }

    public function detail(Request $req, array $args): void
    {
        // Nomor MCU boleh datang dari jalur atau badan permintaan; yang TIDAK
        // boleh datang dari luar hanyalah no_mr, dan itu diambil dari sesi.
        $noMcu = (string) ($args['no_mcu'] ?? $req->str('no_mcu', '') ?? '');
        Response::sukses(McuPasienService::detail($this->sesi($req), $noMcu));
    }

    public function laporanPdf(Request $req, array $args): void
    {
        $noMcu = (string) ($args['no_mcu'] ?? $req->str('no_mcu', '') ?? '');
        self::kirimPdf(McuPasienService::laporanPdf($this->sesi($req), $noMcu));
    }

    /**
     * Kirim berkas apa adanya ke peramban.
     *
     * Tidak lewat Response::sukses(): PDF bukan JSON, dan membungkusnya
     * base64 di dalam JSON hanya membesarkan berkas sepertiga tanpa alasan.
     */
    public static function kirimPdf(array $berkas): void
    {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $berkas['nama'] . '"');
        header('Content-Length: ' . strlen($berkas['isi']));
        header('X-Content-Type-Options: nosniff');
        echo $berkas['isi'];
    }

    public function tren(Request $req): void
    {
        Response::sukses(McuPasienService::tren($this->sesi($req)));
    }
}
