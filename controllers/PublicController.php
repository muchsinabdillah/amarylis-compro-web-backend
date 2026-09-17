<?php
declare(strict_types=1);

namespace Controllers;

use Core\Database;
use Core\HttpException;
use Core\Request;
use Core\Response;
use Repositories\ContentRepository;
use Services\SettingsService;

/**
 * Titik akhir yang dibaca situs publik.
 *
 * Seluruhnya hanya membaca. Satu-satunya penulisan adalah penghitung
 * tampilan dan pencatatan minat WhatsApp — keduanya tidak menerima data
 * dari pengunjung selain identitas kontennya.
 */
final class PublicController
{
    public function pengaturan(Request $req): void
    {
        Response::sukses(SettingsService::untukPublik());
    }

    public function daftarKonten(Request $req, array $args): void
    {
        $modul = $args['modul'];
        [$halaman, $perHalaman, $offset] = $req->paginasi();

        $hasil = ContentRepository::daftarPublik(
            $modul, $perHalaman, $offset,
            $req->str('kategori'), $req->str('cari'),
            $req->ada('unggulan') ? $req->bool('unggulan') : null);

        Response::halaman(
            array_map(fn($b) => $this->rapikan($modul, $b), $hasil['baris']),
            $hasil['total'], $halaman, $perHalaman);
    }

    public function detailKonten(Request $req, array $args): void
    {
        $modul = $args['modul'];
        $baris = ContentRepository::satuPublik($modul, $args['slug']);
        if ($baris === null) {
            throw HttpException::takDitemukan('Halaman yang Anda cari tidak ditemukan.');
        }

        ContentRepository::catatTampilan($modul, (int) $baris['id'], $req->ip(), $req->userAgent());

        $data = $this->rapikan($modul, $baris, true);

        // Isi tambahan yang hanya ada pada modul tertentu.
        if ($modul === 'mcu') {
            $data['manfaat'] = Database::semua(
                'SELECT teks, keterangan FROM mcu_benefits WHERE package_id = :i ORDER BY urutan, id',
                [':i' => $baris['id']]);
        }
        if ($modul === 'news') {
            $data['galeri'] = Database::semua(
                'SELECT image, caption FROM news_gallery WHERE news_id = :i ORDER BY urutan, id',
                [':i' => $baris['id']]);
        }

        $data['terkait'] = array_map(
            fn($t) => [
                'slug'      => $t['slug'],
                'judul'     => $t['judul_tampil'],
                'thumbnail' => $t['thumbnail'],
                'kategori'  => $t['kategori_nama'],
            ],
            ContentRepository::terkait($modul, (int) $baris['id'],
                $baris['category_id'] !== null ? (int) $baris['category_id'] : null));

        Response::sukses($data);
    }

    public function kategori(Request $req, array $args): void
    {
        Response::sukses(Database::semua(
            'SELECT id, nama, slug FROM categories
              WHERE tipe = :t AND is_active ORDER BY urutan, nama',
            [':t' => $args['tipe']]));
    }

    /** Dokter yang memang ditandai tayang — bukan seluruh isi master SIMRS. */
    public function dokter(Request $req): void
    {
        $baris = Database::semua(
            'SELECT id, slug, nama, gelar, spesialis, foto, bio, pendidikan, pengalaman
               FROM doctors WHERE is_published ORDER BY urutan, nama');

        foreach ($baris as &$d) {
            $d['jadwal'] = Database::semua(
                'SELECT hari, jam_mulai, jam_selesai, unit
                   FROM doctor_schedules WHERE doctor_id = :i ORDER BY urutan',
                [':i' => $d['id']]);
        }
        Response::sukses($baris);
    }

    /**
     * Poli/dokter/jadwal untuk FORM RESERVASI.
     *
     * Beda dengan dokter(): ini memakai dokter AKTIF hasil sinkron SIMRS
     * (aktif_simrs), BUKAN penanda "tayang" CMS. Reservasi adalah kebutuhan
     * fungsional — pasien harus bisa memesan dokter yang praktik tanpa menunggu
     * redaksi menayangkan profilnya. Nama pasien tak dibutuhkan; ini publik.
     */
    public function jadwalReservasi(Request $req): void
    {
        $baris = Database::semua(
            'SELECT id, nama, gelar, spesialis
               FROM doctors WHERE aktif_simrs ORDER BY nama');

        foreach ($baris as &$d) {
            $d['jadwal'] = Database::semua(
                'SELECT hari, jam_mulai, jam_selesai, unit
                   FROM doctor_schedules WHERE doctor_id = :i ORDER BY urutan',
                [':i' => $d['id']]);
        }
        Response::sukses($baris);
    }

    /**
     * Paket layanan (MCU & lainnya) untuk landing/pemesanan.
     *
     * Sumbernya `service_packages` — hasil SINKRON dari SIMRS (A_PAKET), bukan
     * master yang diketik ulang di CMS. Jadi harga & jumlah kunjungan selalu
     * ikut SIMRS. Filter ?jenis=mcu|homecare|lainnya opsional.
     */
    public function paket(Request $req): void
    {
        $jenis = strtolower(trim((string) $req->str('jenis')));
        $p = [];
        $where = "WHERE aktif_simrs = true AND sync_status <> 'hilang_di_simrs'";
        if (in_array($jenis, ['mcu', 'homecare', 'lainnya'], true)) {
            $where .= ' AND lower(coalesce(jenis, \'lainnya\')) = :j';
            $p[':j'] = $jenis;
        }

        $baris = Database::semua(
            "SELECT id, simrs_id, kode, nama, harga_simrs, jml_kunjungan,
                    lower(coalesce(jenis, 'lainnya')) AS jenis
               FROM service_packages {$where}
              ORDER BY lower(coalesce(jenis,'lainnya')), nama", $p);

        foreach ($baris as &$b) {
            $b['whatsapp'] = SettingsService::tautanWhatsapp('Paket ' . $b['nama'], null, null);
        }
        Response::sukses($baris);
    }

    public function fasilitas(Request $req): void
    {
        Response::sukses(Database::semua(
            'SELECT nama, deskripsi, image FROM facilities WHERE is_active ORDER BY urutan, nama'));
    }

    public function halaman(Request $req, array $args): void
    {
        $p = Database::satu(
            "SELECT slug, judul, konten, seksi, hero_image, seo_title, seo_description, og_image
               FROM pages WHERE lower(slug) = :s AND status = 'published'",
            [':s' => strtolower($args['slug'])]);
        if ($p === null) {
            throw HttpException::takDitemukan('Halaman tidak ditemukan.');
        }
        $p['seksi'] = $p['seksi'] !== null ? json_decode((string) $p['seksi'], true) : null;
        Response::sukses($p);
    }

    /**
     * Pencarian lintas modul.
     *
     * Hasilnya dikelompokkan per jenis, bukan dicampur dan diurut relevansi:
     * pengunjung yang mencari "MCU" biasanya mencari paketnya, dan artikel
     * yang kebetulan menyebut MCU tidak boleh menenggelamkannya.
     */
    public function cari(Request $req): void
    {
        $kata = $req->str('q');
        if ($kata === null || mb_strlen($kata) < 2) {
            throw HttpException::validasi(['q' => 'Ketik minimal 2 huruf.']);
        }

        $hasil = [];
        foreach (['services', 'mcu', 'homecare', 'articles', 'news', 'videos'] as $modul) {
            $r = ContentRepository::daftarPublik($modul, 5, 0, null, $kata);
            if ($r['total'] > 0) {
                $hasil[$modul] = [
                    'total' => $r['total'],
                    'baris' => array_map(fn($b) => $this->rapikan($modul, $b), $r['baris']),
                ];
            }
        }
        Response::sukses($hasil, ['kata' => $kata]);
    }

    /** Jejak minat dari tombol WhatsApp. Tidak menyimpan data pribadi. */
    public function catatLead(Request $req): void
    {
        Database::jalankan(
            'INSERT INTO whatsapp_leads (konten_tipe, konten_id, judul, halaman, referrer)
             VALUES (:t, :i, :j, :h, :r)',
            [
                ':t' => $req->str('tipe'),
                ':i' => $req->int('id'),
                ':j' => mb_substr((string) $req->str('judul', ''), 0, 220),
                ':h' => mb_substr((string) $req->str('halaman', ''), 0, 400),
                ':r' => mb_substr((string) ($req->header('Referer') ?? ''), 0, 400),
            ]);
        Response::takAdaIsi();
    }

    /**
     * Bentuk seragam untuk seluruh modul.
     *
     * Tautan WhatsApp dirakit di server, bukan di peramban: nomornya hanya
     * ada di satu tempat, dan bila belum diisi maka `whatsapp` bernilai null
     * sehingga tombolnya memang tidak muncul — bukan muncul lalu gagal.
     */
    private function rapikan(string $modul, array $b, bool $lengkap = false): array
    {
        $m      = ContentRepository::modul($modul);
        $gambar = ContentRepository::kolomGambar($m['tabel']);
        $judul  = (string) ($b['judul_tampil'] ?? $b['judul'] ?? $b['nama'] ?? '');

        $data = [
            'id'        => (int) $b['id'],
            'slug'      => $b['slug'],
            'judul'     => $judul,
            'ringkas'   => $b['excerpt'] ?? $b['ringkas'] ?? null,
            'thumbnail' => $b[$gambar] ?? null,
            'kategori'  => $b['kategori_nama'] ?? null,
            'kategori_slug' => $b['kategori_slug'] ?? null,
            'unggulan'  => (bool) ($b['is_featured'] ?? false),
            'dilihat'   => (int) ($b['view_count'] ?? 0),
            'terbit'    => $b['published_at'] ?? null,
        ];

        foreach (['harga', 'jenis_harga', 'durasi', 'area_layanan', 'event_date',
                  'lokasi', 'reading_minutes', 'video_type', 'embed_url'] as $k) {
            if (array_key_exists($k, $b)) {
                $data[$k] = $b[$k];
            }
        }

        if ($lengkap) {
            $data['konten']   = $b['konten'] ?? $b['deskripsi'] ?? null;
            $data['persiapan'] = $b['persiapan'] ?? null;
            $data['seo'] = [
                'title'       => $b['seo_title'] ?: $judul,
                'description' => $b['seo_description'] ?: ($data['ringkas'] ?? null),
                'og_image'    => $b['og_image'] ?: $data['thumbnail'],
            ];
        }

        // Tombol WhatsApp hanya untuk yang memang ditawarkan; artikel dan
        // berita dibagikan, bukan dipesan.
        if (in_array($modul, ['services', 'mcu', 'homecare'], true)) {
            $data['whatsapp'] = SettingsService::tautanWhatsapp(
                $judul, null, $b['whatsapp_message'] ?? null);
        }

        return $data;
    }
}
