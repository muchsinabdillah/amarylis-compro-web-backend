<?php
declare(strict_types=1);

namespace Repositories;

use Core\Database;
use Core\HttpException;

/**
 * Repositori bersama untuk seluruh jenis konten.
 *
 * Artikel, berita, video, layanan, paket MCU, dan homecare memiliki bentuk
 * yang hampir sama: slug, status, unggulan, kategori, penghitung tampilan,
 * dan SEO. Menulis enam repositori yang isinya sama berarti setiap perbaikan
 * harus diingat enam kali — dan yang terlupa satu akan menjadi bug yang
 * hanya muncul di satu modul.
 *
 * NAMA TABEL DAN KOLOM TIDAK PERNAH BERASAL DARI MASUKAN PENGGUNA. Keduanya
 * tidak bisa diikat sebagai parameter, jadi hanya nilai dari daftar tetap di
 * bawah yang boleh masuk ke kueri.
 */
final class ContentRepository
{
    /**
     * Daftar putih modul. Kolom `urut` menentukan pengurutan bawaan daftar
     * publik; konten redaksi diurutkan waktu terbit, katalog layanan
     * diurutkan manual karena urutannya keputusan pemasaran.
     */
    private const MODUL = [
        'articles' => [
            'tabel' => 'articles',   'tipe' => 'article',
            'urut'  => 't.published_at DESC NULLS LAST, t.id DESC',
            'cari'  => ['judul', 'excerpt'],
        ],
        'news' => [
            'tabel' => 'news',       'tipe' => 'news',
            'urut'  => 'coalesce(t.event_date::timestamptz, t.published_at) DESC NULLS LAST, t.id DESC',
            'cari'  => ['judul', 'excerpt', 'lokasi'],
        ],
        'videos' => [
            'tabel' => 'videos',     'tipe' => 'video',
            'urut'  => 't.urutan, t.published_at DESC NULLS LAST, t.id DESC',
            'cari'  => ['judul', 'deskripsi'],
        ],
        'services' => [
            'tabel' => 'services',   'tipe' => 'service',
            'urut'  => 't.urutan, t.nama',
            'cari'  => ['nama', 'ringkas'],
        ],
        'mcu' => [
            'tabel' => 'mcu_packages', 'tipe' => 'mcu',
            'urut'  => 't.urutan, t.nama',
            'cari'  => ['nama', 'ringkas'],
        ],
        'homecare' => [
            'tabel' => 'homecare_services', 'tipe' => 'homecare',
            'urut'  => 't.urutan, t.nama',
            'cari'  => ['nama', 'ringkas'],
        ],
    ];

    public static function modul(string $nama): array
    {
        if (!isset(self::MODUL[$nama])) {
            throw HttpException::takDitemukan('Jenis konten tidak dikenal.');
        }
        return self::MODUL[$nama];
    }

    public static function daftarModul(): array
    {
        return array_keys(self::MODUL);
    }

    /** Judul konten: artikel/berita/video memakai `judul`, katalog memakai `nama`. */
    private static function kolomJudul(string $tabel): string
    {
        return in_array($tabel, ['articles', 'news', 'videos'], true) ? 'judul' : 'nama';
    }

    /**
     * Daftar publik — hanya yang sudah terbit.
     *
     * Konten berjadwal (published_at di masa depan) ikut disaring di sini,
     * bukan hanya di CMS: status 'published' dengan tanggal besok berarti
     * belum boleh terbaca hari ini.
     */
    public static function daftarPublik(
        string $modul, int $limit, int $offset,
        ?string $kategoriSlug = null, ?string $cari = null, ?bool $unggulan = null
    ): array {
        $m      = self::modul($modul);
        $tabel  = $m['tabel'];
        $judul  = self::kolomJudul($tabel);

        [$where, $params] = self::syaratPublik($m, $kategoriSlug, $cari, $unggulan);

        $total = (int) Database::nilai(
            "SELECT count(*) FROM {$tabel} t
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE {$where}", $params);

        $baris = Database::semua(
            "SELECT t.*, t.{$judul} AS judul_tampil,
                    c.nama AS kategori_nama, c.slug AS kategori_slug
               FROM {$tabel} t
               LEFT JOIN categories c ON c.id = t.category_id
              WHERE {$where}
              ORDER BY {$m['urut']}
              LIMIT :limit OFFSET :offset",
            $params + [':limit' => $limit, ':offset' => $offset]);

        return ['baris' => $baris, 'total' => $total];
    }

    /** @return array{0:string,1:array} */
    private static function syaratPublik(array $m, ?string $kategori, ?string $cari, ?bool $unggulan): array
    {
        $where  = ["t.status = 'published'"];
        $params = [];

        if (in_array($m['tabel'], ['articles', 'news', 'videos'], true)) {
            $where[] = '(t.published_at IS NULL OR t.published_at <= now())';
        }
        if ($kategori !== null && $kategori !== '') {
            $where[] = 'lower(c.slug) = :kategori';
            $params[':kategori'] = strtolower($kategori);
        }
        if ($unggulan === true) {
            $where[] = 't.is_featured = true';
        }
        if ($cari !== null && $cari !== '') {
            $bagian = [];
            foreach ($m['cari'] as $i => $kol) {
                $bagian[] = "t.{$kol} ILIKE :cari{$i}";
                $params[":cari{$i}"] = '%' . $cari . '%';
            }
            $where[] = '(' . implode(' OR ', $bagian) . ')';
        }
        return [implode(' AND ', $where), $params];
    }

    public static function satuPublik(string $modul, string $slug): ?array
    {
        $m     = self::modul($modul);
        $tabel = $m['tabel'];
        $judul = self::kolomJudul($tabel);

        $tambahan = in_array($tabel, ['articles', 'news', 'videos'], true)
            ? ' AND (t.published_at IS NULL OR t.published_at <= now())' : '';

        return Database::satu(
            "SELECT t.*, t.{$judul} AS judul_tampil,
                    c.nama AS kategori_nama, c.slug AS kategori_slug
               FROM {$tabel} t
               LEFT JOIN categories c ON c.id = t.category_id
              WHERE lower(t.slug) = :slug AND t.status = 'published'{$tambahan}
              LIMIT 1",
            [':slug' => strtolower($slug)]);
    }

    /**
     * Konten sejenis untuk blok "Terkait".
     *
     * Sekategori didahulukan; bila belum cukup, dilengkapi dari kategori mana
     * pun. Blok terkait yang kosong terlihat seperti halaman rusak, dan itu
     * lebih merugikan daripada saran yang kurang tepat sasaran.
     */
    public static function terkait(string $modul, int $id, ?int $kategoriId, int $limit = 3): array
    {
        $m     = self::modul($modul);
        $tabel = $m['tabel'];
        $judul = self::kolomJudul($tabel);

        $gambar = self::kolomGambar($tabel);

        // Tabel katalog (layanan/MCU/homecare) tidak punya kolom published_at;
        // urutannya memang manual, jadi pengurutan cadangannya pun berbeda.
        $urut = in_array($tabel, ['articles', 'news', 'videos'], true)
            ? 't.published_at DESC NULLS LAST, t.id DESC'
            : 't.urutan, t.id DESC';

        return Database::semua(
            "SELECT t.id, t.slug, t.{$judul} AS judul_tampil, t.{$gambar} AS thumbnail,
                    c.nama AS kategori_nama
               FROM {$tabel} t
               LEFT JOIN categories c ON c.id = t.category_id
              WHERE t.status = 'published' AND t.id <> :id
              ORDER BY (t.category_id IS NOT DISTINCT FROM :kat::bigint) DESC, {$urut}
              LIMIT :limit",
            [':id' => $id, ':kat' => $kategoriId, ':limit' => $limit]);
    }

    /** Nama kolom gambar berbeda antar modul; dipetakan di satu tempat. */
    public static function kolomGambar(string $tabel): string
    {
        return match ($tabel) {
            'articles', 'news', 'videos', 'mcu_packages' => 'thumbnail',
            default => 'image',
        };
    }

    /**
     * Naikkan penghitung tampilan, sekali per pengunjung per hari.
     *
     * Menaikkannya setiap kali halaman dibuka membuat angka itu bisa
     * digelembungkan hanya dengan menekan F5. Sidiknya memuat garam harian
     * sehingga tidak dapat dipakai melacak pengunjung antar hari.
     */
    public static function catatTampilan(string $modul, int $id, string $ip, string $userAgent): void
    {
        $m     = self::modul($modul);
        $sidik = hash('sha256', $ip . '|' . $userAgent . '|' . date('Ymd'));

        $baru = Database::jalankan(
            'INSERT INTO view_logs (konten_tipe, konten_id, sidik)
             VALUES (:t, :i, :s) ON CONFLICT DO NOTHING',
            [':t' => $m['tipe'], ':i' => $id, ':s' => $sidik]);

        if ($baru > 0) {
            Database::jalankan(
                "UPDATE {$m['tabel']} SET view_count = view_count + 1 WHERE id = :i",
                [':i' => $id]);
        }
    }

    /** Slug unik untuk modul tertentu. */
    public static function slugUnik(string $modul, string $dasar, ?int $kecualiId = null): string
    {
        $m     = self::modul($modul);
        $tabel = $m['tabel'];

        $slug = trim(preg_replace('/-+/', '-',
            preg_replace('/[^a-z0-9]+/', '-', strtolower($dasar))) ?? '', '-');
        if ($slug === '') {
            $slug = 'item';
        }

        $asal = $slug;
        $n    = 1;
        while (true) {
            $ada = Database::nilai(
                "SELECT id FROM {$tabel} WHERE lower(slug) = :s AND (:id::bigint IS NULL OR id <> :id)",
                [':s' => $slug, ':id' => $kecualiId]);
            if ($ada === null) {
                return $slug;
            }
            $slug = $asal . '-' . (++$n);
        }
    }

    /** Ringkasan untuk dasbor CMS. */
    public static function ringkasan(): array
    {
        $hasil = [];
        foreach (self::MODUL as $nama => $m) {
            $r = Database::satu(
                "SELECT count(*) FILTER (WHERE status = 'published') AS terbit,
                        count(*) FILTER (WHERE status = 'draft')     AS draf,
                        count(*) FILTER (WHERE status = 'archived')  AS arsip,
                        count(*) AS total
                   FROM {$m['tabel']}");
            $hasil[$nama] = array_map('intval', $r ?? []);
        }
        return $hasil;
    }
}
