<?php
declare(strict_types=1);

namespace Services;

use Core\Database;

/**
 * Pengaturan situs, digabung dengan profil klinik dari SIMRS.
 *
 * Nama, alamat, kota, telepon, dan email klinik sudah tersimpan di Data RS
 * pada SIMRS dan dipakai seluruh cetakannya. Menyalinnya ke
 * CMS akan menciptakan versi kedua yang cepat atau lambat berbeda — dan
 * alamat klinik yang berbeda antara nota dan website adalah kekeliruan yang
 * baru ketahuan dari pasien yang tersesat.
 *
 * Karena itu urutannya: nilai CMS dipakai bila diisi, selebihnya jatuh ke
 * Data RS SIMRS. CMS tetap bisa menimpa bila klinik memang ingin menampilkan
 * sesuatu yang berbeda di website.
 */
final class SettingsService
{
    private static ?array $cache = null;

    /** @return array<string,string> seluruh pengaturan mentah */
    public static function mentah(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $baris = Database::semua('SELECT key, value FROM settings');
        return self::$cache = array_column($baris, 'value', 'key');
    }

    public static function get(string $kunci, ?string $bawaan = null): ?string
    {
        $v = self::mentah()[$kunci] ?? null;
        return ($v === null || $v === '') ? $bawaan : $v;
    }

    /**
     * Profil klinik dari SIMRS, lewat API.
     *
     * Website tidak menyambung ke basis data SIMRS; SimrsClient yang
     * memanggilnya, menyimpan salinannya satu jam, dan tetap memakai salinan
     * lama bila SIMRS sedang tidak terjangkau. Halaman publik karena itu
     * tidak ikut padam ketika SIMRS sedang dipelihara.
     */
    public static function profilKlinik(): array
    {
        $rs = SimrsClient::profil();

        $ambil = static fn(string $k): string => trim((string) ($rs[$k] ?? ''));

        return [
            'nama'         => $ambil('nama'),
            'nama_singkat' => $ambil('nama_singkat'),
            'alamat'       => $ambil('alamat'),
            'kota'         => $ambil('kota'),
            'kodepos'      => $ambil('kodepos'),
            'telepon'      => $ambil('telepon'),
            'email'        => $ambil('email'),
            'website'      => $ambil('website'),
        ];
    }

    /**
     * Bentuk yang dipakai frontend — sudah digabung dan siap tampil.
     *
     * WhatsApp sengaja dikembalikan sebagai objek dengan penanda `aktif`.
     * Selama nomornya belum dipastikan, situs menampilkan tombol Telepon
     * alih-alih tombol WhatsApp yang mengarah ke nomor tak terdaftar —
     * kegagalan seperti itu tidak terlihat oleh klinik, hanya oleh calon
     * pasien yang lalu pergi.
     */
    public static function untukPublik(): array
    {
        $s  = self::mentah();
        $rs = self::profilKlinik();

        $waMentah = trim((string) ($s['whatsapp_number'] ?? ''));
        $waAngka  = preg_replace('/\D+/', '', $waMentah) ?? '';
        $waAktif  = $waMentah !== ''
                 && !str_contains($waMentah, 'BELUM TERSEDIA')
                 && strlen($waAngka) >= 10;

        $ambil = static fn(string $k, string $cadangan = '') =>
            (isset($s[$k]) && trim((string) $s[$k]) !== '') ? trim((string) $s[$k]) : $cadangan;

        return [
            'klinik' => [
                'nama'     => $rs['nama'] !== '' ? $rs['nama'] : 'Klinik Pratama Andini',
                'singkat'  => $rs['nama_singkat'],
                'alamat'   => $rs['alamat'],
                'kota'     => $rs['kota'],
                'kodepos'  => $rs['kodepos'],
                'telepon'  => $ambil('telepon', $rs['telepon']),
                'email'    => $ambil('email_publik', $rs['email']),
                'website'  => $rs['website'],
                'jam'      => $ambil('jam_operasional'),
            ],
            'whatsapp' => [
                'aktif'  => $waAktif,
                'nomor'  => $waAktif ? $waAngka : null,
                'salam'  => $ambil('whatsapp_greeting',
                    'Hallo Klinik Pratama Andini, saya ingin mendapatkan informasi mengenai'),
                // Alasan disertakan supaya CMS dapat menampilkannya sebagai
                // peringatan, bukan hanya diam-diam menyembunyikan tombol.
                'alasan' => $waAktif ? null
                    : 'Nomor WhatsApp belum diisi di CMS. Tombol WhatsApp digantikan tombol Telepon.',
            ],
            'peta' => [
                'embed' => self::petaSah($ambil('maps_embed_url')),
                'lat'   => $ambil('maps_lat'),
                'lng'   => $ambil('maps_lng'),
                'buka'  => $ambil('maps_place_url'),
            ],
            'sosial' => array_filter([
                'instagram' => $ambil('sosial_instagram'),
                'facebook'  => $ambil('sosial_facebook'),
                'youtube'   => $ambil('sosial_youtube'),
                'tiktok'    => $ambil('sosial_tiktok'),
            ]),
            'merek' => [
                'logo'    => $ambil('logo'),
                'favicon' => $ambil('favicon'),
            ],
            'beranda' => [
                'hero_judul'    => $ambil('hero_judul'),
                'hero_subjudul' => $ambil('hero_subjudul'),
                'hero_image'    => $ambil('hero_image'),
            ],
            'seo' => [
                'title'       => $ambil('seo_title_default'),
                'description' => $ambil('seo_description_default'),
                'og_image'    => $ambil('og_image_default'),
            ],
        ];
    }

    /**
     * URL peta hanya diteruskan bila benar-benar dari Google Maps.
     *
     * Nilainya berasal dari CMS, dan CMS adalah masukan dari luar. URL
     * sembarang yang lolos ke dalam iframe berarti halaman klinik memuat
     * halaman orang lain di dalamnya.
     */
    private static function petaSah(string $url): ?string
    {
        if ($url === '' || str_contains($url, 'BELUM TERSEDIA')) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $ok   = in_array($host, ['www.google.com', 'google.com', 'maps.google.com'], true)
             && str_starts_with(strtolower($url), 'https://');
        return $ok ? $url : null;
    }

    /** Pesan WhatsApp lengkap untuk satu konten. Null bila WhatsApp belum aktif. */
    public static function tautanWhatsapp(string $judul, ?string $url = null, ?string $pesanKhusus = null): ?string
    {
        $p = self::untukPublik()['whatsapp'];
        if (!$p['aktif']) {
            return null;
        }
        $pesan = $pesanKhusus !== null && $pesanKhusus !== ''
            ? $pesanKhusus
            : trim($p['salam'] . ' ' . $judul) . '.';
        if ($url !== null && $url !== '') {
            $pesan .= "\n\n" . $url;
        }
        return 'https://wa.me/' . $p['nomor'] . '?text=' . rawurlencode($pesan);
    }

    public static function simpan(string $kunci, ?string $nilai, string $olehSiapa): void
    {
        Database::jalankan(
            'UPDATE settings SET value = :v, updated_at = now(), updated_by = :u WHERE key = :k',
            [':v' => $nilai, ':u' => $olehSiapa, ':k' => $kunci]);
        self::$cache = null;
    }

    /** Untuk CMS: seluruh pengaturan beserta label dan keterangannya. */
    public static function untukAdmin(): array
    {
        return Database::semua(
            'SELECT key, value, grup, label, keterangan, tipe, urutan, updated_at, updated_by
               FROM settings ORDER BY grup, urutan, key');
    }
}
