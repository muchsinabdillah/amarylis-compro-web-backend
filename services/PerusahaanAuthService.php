<?php

declare(strict_types=1);

namespace Services;

use Core\HttpException;
use Helpers\Jwt;

/**
 * Sesi portal MCU perusahaan.
 *
 * Kredensialnya TIDAK disimpan di sini. Masternya dikelola petugas di SIMRS,
 * jadi website hanya meneruskan email+sandi ke SIMRS untuk diperiksa, lalu
 * menerbitkan sesinya sendiri. Dengan begitu tidak ada salinan sandi kedua yang
 * harus dijaga dan dicabut.
 */
final class PerusahaanAuthService
{
    /**
     * Umur sesi dibuat lebih pendek daripada portal pasien.
     *
     * Token ini dipercaya apa adanya selama berlaku — bila petugas
     * menonaktifkan akun di tengah jalan, pencabutannya baru berlaku saat token
     * kedaluwarsa. Satu akun perusahaan memegang data seluruh karyawannya, jadi
     * jendela itu sengaja dipersempit.
     */
    private const TTL_MENIT = 120;

    public static function masuk(string $email, string $sandi): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || $sandi === '') {
            throw HttpException::takSah('Email dan kata sandi wajib diisi.');
        }

        if (!SimrsClient::terpasang()) {
            throw new HttpException(503, 'Layanan belum tersambung ke SIMRS. Hubungi klinik.');
        }

        $r = SimrsClient::kirim('/website/perusahaan/login', [
            'email'      => $email,
            'kata_sandi' => $sandi,
        ]);

        if (empty($r['ok'])) {
            // Pesan dari SIMRS sudah seragam ("Email atau kata sandi salah"),
            // sengaja tidak diperkaya di sini supaya tidak membocorkan email
            // mana yang terdaftar.
            throw HttpException::takSah($r['pesan'] ?: 'Email atau kata sandi salah.');
        }

        $d = $r['data'] ?? [];
        if (empty($d['perusahaan_id'])) {
            throw new HttpException(409, 'Akun tidak tertaut ke perusahaan mana pun. Hubungi klinik.');
        }

        return self::sesi($d);
    }

    /** Bentuk sesi + token. Dipanggil setelah SIMRS memastikan kredensialnya. */
    private static function sesi(array $d): array
    {
        $profil = [
            'akun_id'         => (int) ($d['id'] ?? 0),
            'perusahaan_id'   => (int) $d['perusahaan_id'],
            'nama_perusahaan' => (string) ($d['nama_perusahaan'] ?? ''),
            'email'           => (string) ($d['email'] ?? ''),
            'nama_kontak'     => $d['nama_kontak'] ?? null,
        ];

        $token = Jwt::buat([
            'sub'             => $profil['akun_id'],
            'tipe'            => 'perusahaan',
            'perusahaan_id'   => $profil['perusahaan_id'],
            'nama_perusahaan' => $profil['nama_perusahaan'],
            'email'           => $profil['email'],
        ], self::TTL_MENIT);

        return ['token' => $token, 'profil' => $profil, 'kedaluwarsa_menit' => self::TTL_MENIT];
    }
}
