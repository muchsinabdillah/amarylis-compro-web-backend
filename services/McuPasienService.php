<?php

declare(strict_types=1);

namespace Services;

use Core\HttpException;

/**
 * Hasil MCU untuk pasien — penerus ke SIMRS.
 *
 * Tidak ada satu pun hasil pemeriksaan yang disimpan di basis data website.
 * Semuanya tinggal di SIMRS dan dibaca saat diminta. Menyalinnya ke sini
 * berarti dua versi hasil pemeriksaan yang bisa berbeda, dan yang dibaca pasien
 * belum tentu yang dipegang kliniknya.
 *
 * no_mr SELALU diambil dari sesi, tidak pernah dari badan permintaan. Kalau ia
 * boleh dikirim pemanggil, satu orang bisa membaca seluruh riwayat kesehatan
 * orang lain hanya dengan menukar satu nomor.
 */
final class McuPasienService
{
    private static function panggil(string $jalur, array $data): array
    {
        if (!SimrsClient::terpasang()) {
            throw new HttpException(503, 'Layanan belum tersambung ke SIMRS. Hubungi klinik.');
        }
        return SimrsClient::kirim($jalur, $data);
    }

    /**
     * No. RM pemilik sesi.
     *
     * Akun yang belum ditautkan ke rekam medik tidak punya apa pun untuk
     * ditampilkan. Ditolak dengan pesan yang menunjukkan jalan keluarnya,
     * bukan daftar kosong yang membuat orang mengira hasilnya hilang.
     */
    private static function noMr(array $sesi): string
    {
        $noMr = trim((string) ($sesi['no_mr'] ?? ''));
        if ($noMr === '') {
            throw new HttpException(409,
                'Akun Anda belum tertaut ke nomor rekam medik. Lakukan verifikasi rekam medik '
                . 'lebih dulu, atau hubungi pendaftaran klinik.');
        }
        return $noMr;
    }

    /** Daftar MCU milik pasien ini, terbaru lebih dulu. */
    public static function daftar(array $sesi): array
    {
        $r = self::panggil('/website/pasien/mcu/hasil', ['no_mr' => self::noMr($sesi)]);
        if (empty($r['ok'])) throw new HttpException(502, $r['pesan'] ?: 'SIMRS tidak menjawab.');
        return is_array($r['data']) ? $r['data'] : [];
    }

    /** Isi lengkap satu berkas MCU. Kepemilikannya diperiksa di SIMRS. */
    public static function detail(array $sesi, string $noMcu): array
    {
        $noMcu = trim($noMcu);
        if ($noMcu === '') throw HttpException::validasi(['no_mcu' => 'Nomor MCU wajib diisi.']);

        $r = self::panggil('/website/pasien/mcu/hasil/detail', [
            'no_mr'  => self::noMr($sesi),
            'no_mcu' => $noMcu,
        ]);
        // Pesannya tidak membedakan "tidak ada" dari "bukan milik Anda" —
        // membedakannya memberi tahu penebak bahwa nomornya benar.
        if (empty($r['ok'])) throw new HttpException(404, $r['pesan'] ?: 'Hasil tidak ditemukan.');
        return is_array($r['data']) ? $r['data'] : [];
    }

    /**
     * Laporan MCU sebagai berkas PDF.
     *
     * Berkasnya dibangkitkan SIMRS dan diteruskan apa adanya; portal tidak
     * menyimpan salinannya. Sertifikat kelayakan kerja sengaja TIDAK
     * disediakan di sini — isinya ditujukan kepada pemberi kerja, dan yang
     * dibutuhkan peserta adalah laporannya sendiri yang jauh lebih lengkap.
     *
     * @return array{nama:string,isi:string}
     */
    public static function laporanPdf(array $sesi, string $noMcu): array
    {
        $noMcu = trim($noMcu);
        if ($noMcu === '') throw HttpException::validasi(['no_mcu' => 'Nomor MCU wajib diisi.']);

        if (!SimrsClient::terpasang()) {
            throw new HttpException(503, 'Layanan belum tersambung ke SIMRS. Hubungi klinik.');
        }
        $r = SimrsClient::berkas('/website/pasien/mcu/laporan-pdf', [
            'no_mr'  => self::noMr($sesi),
            'no_mcu' => $noMcu,
        ]);
        if (empty($r['ok'])) throw new HttpException(404, $r['pesan'] ?: 'Hasil tidak ditemukan.');

        return ['nama' => $r['nama'], 'isi' => $r['isi']];
    }

    /** Angka riwayat untuk dasbor: tekanan darah, IMT, berat, kelayakan. */
    public static function tren(array $sesi): array
    {
        $r = self::panggil('/website/pasien/mcu/tren', ['no_mr' => self::noMr($sesi)]);
        if (empty($r['ok'])) throw new HttpException(502, $r['pesan'] ?: 'SIMRS tidak menjawab.');
        return is_array($r['data']) ? $r['data'] : [];
    }
}
