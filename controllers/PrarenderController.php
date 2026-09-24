<?php
declare(strict_types=1);

namespace Controllers;

use Core\Database;
use Core\Env;
use Core\Request;
use Repositories\ContentRepository;
use Services\SettingsService;

/**
 * Menyajikan kerangka HTML situs dengan meta dan data terstruktur yang sudah
 * terisi sesuai alamat yang diminta.
 *
 * Situsnya SPA: seluruh halaman memakai satu index.html yang kosong, dan judul
 * beserta metanya baru diisi React setelah JavaScript berjalan. Mesin pencari
 * modern memang menjalankan JavaScript, tetapi tidak segera dan tidak selalu —
 * sementara perayap pratinjau tautan (WhatsApp, Facebook, X, Telegram) sama
 * sekali tidak. Akibatnya setiap artikel yang dibagikan ke WhatsApp muncul
 * dengan judul yang sama: "Klinik Pratama Andini".
 *
 * Di sini metanya disisipkan di server, sebelum halaman dikirim. React tetap
 * memasang metanya sendiri sesudahnya; keduanya menghasilkan nilai yang sama,
 * jadi yang terjadi hanyalah penulisan ulang yang tidak terlihat.
 *
 * Alamat situs SELALU dibaca dari SITE_URL, tidak pernah ditulis di kode.
 * Ketika domainnya berganti, satu baris .env mengubah seluruh tautan kanonik,
 * og:url, breadcrumb, dan data terstruktur sekaligus — tanpa menyentuh kode
 * dan tanpa membangun ulang frontend.
 */
final class PrarenderController
{
    /** Jalur publik tiap modul, harus sama dengan JALUR di SeoController. */
    private const JALUR_MODUL = [
        'artikel'  => 'articles',
        'berita'   => 'news',
        'video'    => 'videos',
        'layanan'  => 'services',
        'mcu'      => 'mcu',
        'homecare' => 'homecare',
    ];

    /** Halaman daftar: jalur => [judul, deskripsi singkat]. */
    private const HALAMAN_DAFTAR = [
        ''         => null,                 // beranda, memakai deskripsi pengaturan
        'layanan'  => ['Layanan', 'Daftar lengkap layanan pemeriksaan dan perawatan di %s.'],
        'mcu'      => ['Medical Check Up', 'Paket medical check up berkala di %s — pilihan paket, isi pemeriksaan, dan biayanya.'],
        'homecare' => ['Homecare', 'Layanan perawatan kesehatan di rumah oleh tenaga medis %s.'],
        'dokter'   => ['Dokter', 'Daftar dokter dan jadwal praktik di %s.'],
        'fasilitas'=> ['Fasilitas', 'Fasilitas pemeriksaan dan penunjang yang tersedia di %s.'],
        'artikel'  => ['Artikel Kesehatan', 'Artikel kesehatan yang ditulis tim medis %s.'],
        'berita'   => ['Berita & Kegiatan', 'Berita dan kegiatan terbaru %s.'],
        'video'    => ['Video', 'Video edukasi kesehatan dari %s.'],
        'kontak'   => ['Kontak & Lokasi', 'Alamat, nomor telepon, jam layanan, dan peta lokasi %s.'],
    ];

    public function sajikan(Request $req): void
    {
        /*
         * Jalur datang sebagai parameter, bukan dibaca dari REQUEST_URI.
         *
         * Permintaannya diteruskan nginx dari domain situs ke aplikasi ini,
         * jadi REQUEST_URI di sini adalah alamat internal, bukan alamat yang
         * diketik pengunjung. Menyebutkannya terang-terangan membuat kedua
         * sisi tidak bisa bergeser diam-diam.
         */
        $jalur = (string) ($req->str('jalur') ?? '/');
        $jalur = '/' . trim(parse_url($jalur, PHP_URL_PATH) ?: '/', '/');

        $kerangka = $this->kerangka();
        if ($kerangka === null) {
            // Kerangka tidak terbaca bukan alasan menampilkan halaman kosong;
            // biarkan nginx menyajikan berkas statisnya seperti biasa.
            http_response_code(404);
            echo "Kerangka situs tidak ditemukan.";
            return;
        }

        $situs = $this->pengaturan();
        $basis = $this->basis();
        $m     = $this->metaUntuk($jalur, $situs, $basis);

        http_response_code($m['status']);
        header('Content-Type: text/html; charset=utf-8');
        /* Sebentar saja: isi CMS bisa berubah kapan pun, dan halaman yang
           ter-cache lama membuat penyuntingan tampak tidak berpengaruh. */
        header('Cache-Control: public, max-age=300, must-revalidate');

        echo $this->sisipkan($kerangka, $m);
    }

    // ══════════════════════════ penentuan meta ══════════════════════════

    private function metaUntuk(string $jalur, array $situs, string $basis): array
    {
        $nama  = $situs['klinik']['nama'] ?? 'Klinik';
        $bagian = array_values(array_filter(explode('/', trim($jalur, '/')), fn($x) => $x !== ''));

        $dasar = [
            'status'    => 200,
            'judul'     => $nama,
            'deskripsi' => $situs['seo']['description'] ?? '',
            'gambar'    => $situs['seo']['og_image'] ?? ($situs['merek']['logo'] ?? ''),
            'kanonik'   => $basis . $jalur,
            'tipe'      => 'website',
            'robots'    => 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1',
            'remah'     => [],
            'jsonld'    => [],
        ];

        // ── beranda ──
        if (!$bagian) {
            $dasar['kanonik'] = $basis . '/';
            if (!empty($situs['seo']['title'])) $dasar['judul'] = $situs['seo']['title'];
            $dasar['jsonld'][] = $this->ldKlinik($situs, $basis);
            $dasar['jsonld'][] = $this->ldSitus($situs, $basis);
            return $dasar;
        }

        // ── detail konten: /artikel/{slug} dan sejenisnya ──
        if (count($bagian) === 2 && isset(self::JALUR_MODUL[$bagian[0]])) {
            $isi = $this->konten(self::JALUR_MODUL[$bagian[0]], $bagian[1]);
            if ($isi) return $this->metaKonten($bagian[0], $isi, $dasar, $situs, $basis, $nama);
            return $this->metaHilang($dasar, $nama);
        }

        // ── detail dokter ──
        if (count($bagian) === 2 && $bagian[0] === 'dokter') {
            $d = Database::satu(
                'SELECT nama, slug, spesialisasi, foto, profil FROM doctors
                  WHERE lower(slug) = lower(?) AND is_published LIMIT 1', [$bagian[1]]);
            if (!$d) return $this->metaHilang($dasar, $nama);

            $spes = trim((string) ($d['spesialisasi'] ?? ''));
            $dasar['judul']     = trim($d['nama']) . ($spes !== '' ? ' — ' . $spes : '') . ' | ' . $nama;
            $dasar['deskripsi'] = $this->ringkas($d['profil'] ?? '',
                'Jadwal praktik dan profil ' . $d['nama'] . ($spes !== '' ? ', ' . $spes : '') . ' di ' . $nama . '.');
            if (!empty($d['foto'])) $dasar['gambar'] = $d['foto'];
            $dasar['tipe']   = 'profile';
            $dasar['remah']  = [['Dokter', '/dokter'], [$d['nama'], '/dokter/' . $d['slug']]];
            $dasar['jsonld'][] = $this->ldDokter($d, $situs, $basis);
            $dasar['jsonld'][] = $this->ldRemah($dasar['remah'], $basis);
            return $dasar;
        }

        // ── halaman daftar yang dikenal ──
        if (count($bagian) === 1 && isset(self::HALAMAN_DAFTAR[$bagian[0]])) {
            [$judul, $pola] = self::HALAMAN_DAFTAR[$bagian[0]];
            $dasar['judul']     = $judul . ' | ' . $nama;
            $dasar['deskripsi'] = sprintf($pola, $nama);
            $dasar['remah']     = [[$judul, '/' . $bagian[0]]];
            $dasar['jsonld'][]  = $this->ldRemah($dasar['remah'], $basis);
            return $dasar;
        }

        // ── halaman statis dari CMS: /tentang-kami dan sejenisnya ──
        if (count($bagian) === 1) {
            $h = Database::satu(
                'SELECT judul, slug, konten, seo_title, seo_description FROM pages
                  WHERE lower(slug) = lower(?) AND status = \'published\' LIMIT 1', [$bagian[0]]);
            if ($h) {
                $dasar['judul']     = ($h['seo_title'] ?: $h['judul']) . ' | ' . $nama;
                $dasar['deskripsi'] = $h['seo_description'] ?: $this->ringkas($h['konten'] ?? '', '');
                $dasar['remah']     = [[$h['judul'], '/' . $h['slug']]];
                $dasar['jsonld'][]  = $this->ldRemah($dasar['remah'], $basis);
                return $dasar;
            }
        }

        // ── halaman portal & pencarian: tidak perlu diindeks ──
        if (in_array($bagian[0], ['pasien', 'perusahaan', 'admin', 'cari'], true)) {
            $dasar['robots'] = 'noindex,follow';
            $dasar['judul']  = ucfirst($bagian[0]) . ' | ' . $nama;
            return $dasar;
        }

        return $this->metaHilang($dasar, $nama);
    }

    private function metaKonten(string $jalur, array $isi, array $dasar, array $situs, string $basis, string $nama): array
    {
        $judul = trim((string) ($isi['judul'] ?? ''));
        $dasar['judul']     = trim((string) ($isi['seo_title'] ?: $judul)) . ' | ' . $nama;
        $dasar['deskripsi'] = trim((string) ($isi['seo_description'] ?: $isi['excerpt'] ?? ''))
                              ?: $this->ringkas($isi['konten'] ?? '', $judul . ' — ' . $nama . '.');
        $gambar = $isi['og_image'] ?: ($isi['thumbnail'] ?? '');
        if ($gambar) $dasar['gambar'] = $gambar;

        $label = ['artikel' => 'Artikel Kesehatan', 'berita' => 'Berita & Kegiatan', 'video' => 'Video',
                  'layanan' => 'Layanan', 'mcu' => 'Medical Check Up', 'homecare' => 'Homecare'][$jalur] ?? ucfirst($jalur);
        $dasar['remah'] = [[$label, '/' . $jalur], [$judul, '/' . $jalur . '/' . $isi['slug']]];

        $tulisan = in_array($jalur, ['artikel', 'berita'], true);
        $dasar['tipe'] = $tulisan ? 'article' : 'website';
        if ($tulisan) {
            $dasar['terbit']  = $isi['published_at'] ?? null;
            $dasar['diubah']  = $isi['updated_at'] ?? null;
        }

        $dasar['jsonld'][] = $this->ldKonten($jalur, $isi, $situs, $basis, $nama);
        $dasar['jsonld'][] = $this->ldRemah($dasar['remah'], $basis);
        return $dasar;
    }

    private function metaHilang(array $dasar, string $nama): array
    {
        /*
         * Alamat yang tidak ada harus menjawab 404, bukan 200.
         *
         * Sebelumnya seluruh alamat — termasuk salah ketik dan tautan mati —
         * dijawab 200 berisi kerangka aplikasi. Mesin pencari menyebutnya soft
         * 404: halaman yang mengaku ada padahal kosong. Ribuan alamat semacam
         * itu mengencerkan anggaran perayapan dan menurunkan penilaian situs.
         */
        $dasar['status'] = 404;
        $dasar['robots'] = 'noindex,follow';
        $dasar['judul']  = 'Halaman tidak ditemukan | ' . $nama;
        $dasar['deskripsi'] = 'Halaman yang Anda cari tidak ada atau sudah dipindahkan.';
        return $dasar;
    }

    // ══════════════════════════ data terstruktur ══════════════════════════

    /**
     * Identitas klinik.
     *
     * MedicalClinic, bukan sekadar LocalBusiness: jenis itu yang dipahami
     * Google sebagai fasilitas kesehatan, dan yang memungkinkan kartu
     * informasi klinik muncul di hasil pencarian.
     */
    private function ldKlinik(array $situs, string $basis): array
    {
        $k  = $situs['klinik'] ?? [];
        $wa = $situs['whatsapp'] ?? [];
        $ld = [
            '@context' => 'https://schema.org',
            '@type'    => 'MedicalClinic',
            '@id'      => $basis . '/#klinik',
            'name'     => $k['nama'] ?? '',
            'url'      => $basis . '/',
        ];
        if (!empty($k['singkat']))  $ld['alternateName'] = $k['singkat'];
        if (!empty($situs['merek']['logo'])) $ld['logo'] = $this->mutlak($situs['merek']['logo'], $basis);
        if (!empty($situs['seo']['og_image'])) $ld['image'] = $this->mutlak($situs['seo']['og_image'], $basis);
        if (!empty($k['telepon']))  $ld['telephone'] = $k['telepon'];
        if (!empty($k['email']))    $ld['email'] = $k['email'];

        if (!empty($k['alamat'])) {
            $ld['address'] = array_filter([
                '@type'           => 'PostalAddress',
                'streetAddress'   => $k['alamat'],
                'addressLocality' => $k['kota'] ?? null,
                'postalCode'      => $k['kodepos'] ?? null,
                'addressCountry'  => 'ID',
            ]);
        }
        if (!empty($k['jam']))      $ld['openingHours'] = $k['jam'];
        $peta = $situs['peta'] ?? [];
        if (!empty($peta['lat']) && !empty($peta['lng'])) {
            $ld['geo'] = ['@type' => 'GeoCoordinates',
                          'latitude' => (float) $peta['lat'], 'longitude' => (float) $peta['lng']];
        }
        if (!empty($peta['buka'])) $ld['hasMap'] = $peta['buka'];

        $sosial = [];
        foreach (['instagram', 'facebook', 'youtube', 'tiktok'] as $s) {
            if (!empty($situs['sosial'][$s])) $sosial[] = $situs['sosial'][$s];
        }
        if (!empty($wa['nomor'])) $sosial[] = 'https://wa.me/' . preg_replace('/\D/', '', (string) $wa['nomor']);
        if ($sosial) $ld['sameAs'] = array_values(array_unique($sosial));

        return $ld;
    }

    /** Situsnya sendiri, berikut kotak pencarian yang boleh dipakai Google. */
    private function ldSitus(array $situs, string $basis): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            '@id'      => $basis . '/#situs',
            'name'     => $situs['klinik']['nama'] ?? '',
            'url'      => $basis . '/',
            'inLanguage' => 'id-ID',
            'publisher'  => ['@id' => $basis . '/#klinik'],
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => $basis . '/cari?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    private function ldKonten(string $jalur, array $isi, array $situs, string $basis, string $nama): array
    {
        $url   = $basis . '/' . $jalur . '/' . $isi['slug'];
        $judul = (string) ($isi['judul'] ?? '');
        $gbr   = $isi['og_image'] ?: ($isi['thumbnail'] ?? '');

        $jenis = ['artikel' => 'Article', 'berita' => 'NewsArticle', 'video' => 'VideoObject',
                  'layanan' => 'MedicalProcedure', 'mcu' => 'MedicalTest', 'homecare' => 'MedicalProcedure'][$jalur] ?? 'WebPage';

        $ld = [
            '@context' => 'https://schema.org',
            '@type'    => $jenis,
            'name'     => $judul,
            'url'      => $url,
            'inLanguage' => 'id-ID',
        ];
        if ($gbr) $ld['image'] = $this->mutlak($gbr, $basis);

        if (in_array($jenis, ['Article', 'NewsArticle'], true)) {
            $ld['headline']         = mb_substr($judul, 0, 110);
            $ld['description']      = (string) ($isi['seo_description'] ?: $isi['excerpt'] ?? '');
            $ld['mainEntityOfPage'] = ['@type' => 'WebPage', '@id' => $url];
            $ld['publisher']        = ['@id' => $basis . '/#klinik'];
            if (!empty($isi['published_at'])) $ld['datePublished'] = date(DATE_ATOM, strtotime((string) $isi['published_at']));
            if (!empty($isi['updated_at']))   $ld['dateModified']  = date(DATE_ATOM, strtotime((string) $isi['updated_at']));
            $ld['author'] = ['@type' => 'Organization', 'name' => $nama];
        } elseif ($jenis === 'VideoObject') {
            $ld['description'] = (string) ($isi['seo_description'] ?: $isi['excerpt'] ?? '');
            if (!empty($isi['published_at'])) $ld['uploadDate'] = date(DATE_ATOM, strtotime((string) $isi['published_at']));
            if ($gbr) $ld['thumbnailUrl'] = $this->mutlak($gbr, $basis);
        } else {
            $ld['description'] = (string) ($isi['seo_description'] ?: $isi['excerpt'] ?? '');
            $ld['provider']    = ['@id' => $basis . '/#klinik'];
        }
        return $ld;
    }

    private function ldDokter(array $d, array $situs, string $basis): array
    {
        $ld = [
            '@context'  => 'https://schema.org',
            '@type'     => 'Physician',
            'name'      => $d['nama'],
            'url'       => $basis . '/dokter/' . $d['slug'],
            'worksFor'  => ['@id' => $basis . '/#klinik'],
            'memberOf'  => ['@id' => $basis . '/#klinik'],
        ];
        if (!empty($d['spesialisasi'])) $ld['medicalSpecialty'] = $d['spesialisasi'];
        if (!empty($d['foto']))         $ld['image'] = $this->mutlak($d['foto'], $basis);
        return $ld;
    }

    /** Remah roti — yang tampil sebagai jalur di bawah judul hasil pencarian. */
    private function ldRemah(array $remah, string $basis): array
    {
        $item = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Beranda', 'item' => $basis . '/']];
        foreach ($remah as $i => [$nama, $jalur]) {
            $item[] = ['@type' => 'ListItem', 'position' => $i + 2, 'name' => $nama, 'item' => $basis . $jalur];
        }
        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $item];
    }

    // ══════════════════════════ penyisipan ══════════════════════════

    private function sisipkan(string $html, array $m): string
    {
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $gambar = $m['gambar'] ? $this->mutlak($m['gambar'], $this->basis()) : '';

        $tag = [];
        $tag[] = '<title>' . $e($m['judul']) . '</title>';
        $tag[] = '<meta name="description" content="' . $e($m['deskripsi']) . '">';
        $tag[] = '<meta name="robots" content="' . $e($m['robots']) . '">';
        $tag[] = '<link rel="canonical" href="' . $e($m['kanonik']) . '">';
        $tag[] = '<meta property="og:type" content="' . $e($m['tipe']) . '">';
        $tag[] = '<meta property="og:title" content="' . $e($m['judul']) . '">';
        $tag[] = '<meta property="og:description" content="' . $e($m['deskripsi']) . '">';
        $tag[] = '<meta property="og:url" content="' . $e($m['kanonik']) . '">';
        $tag[] = '<meta property="og:locale" content="id_ID">';
        if ($gambar) {
            $tag[] = '<meta property="og:image" content="' . $e($gambar) . '">';
            $tag[] = '<meta name="twitter:image" content="' . $e($gambar) . '">';
        }
        $tag[] = '<meta name="twitter:card" content="' . ($gambar ? 'summary_large_image' : 'summary') . '">';
        $tag[] = '<meta name="twitter:title" content="' . $e($m['judul']) . '">';
        $tag[] = '<meta name="twitter:description" content="' . $e($m['deskripsi']) . '">';
        if (!empty($m['terbit'])) $tag[] = '<meta property="article:published_time" content="' . $e(date(DATE_ATOM, strtotime((string) $m['terbit']))) . '">';
        if (!empty($m['diubah'])) $tag[] = '<meta property="article:modified_time" content="' . $e(date(DATE_ATOM, strtotime((string) $m['diubah']))) . '">';

        foreach ($m['jsonld'] as $ld) {
            $tag[] = '<script type="application/ld+json">'
                   . json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                   . '</script>';
        }

        /*
         * Judul dan deskripsi bawaan dibuang lebih dulu. Menambah tag kedua
         * tanpa membuang yang pertama membuat halaman punya dua <title>, dan
         * yang dipakai mesin pencari belum tentu yang kita maksud.
         */
        $html = preg_replace('#<title>.*?</title>#is', '', $html, 1);
        $html = preg_replace('#<meta\s+name=["\']description["\'][^>]*>#i', '', $html, 1);

        $sisip = "\n    " . implode("\n    ", $tag) . "\n  ";
        return preg_replace('#</head>#i', $sisip . '</head>', $html, 1);
    }

    // ══════════════════════════ pembantu ══════════════════════════

    /** Alamat situs, selalu dari .env — tidak pernah ditulis di kode. */
    private function basis(): string
    {
        return rtrim((string) Env::get('SITE_URL', (string) Env::get('APP_URL', '')), '/');
    }

    private function mutlak(string $url, string $basis): string
    {
        if ($url === '') return '';
        if (preg_match('#^https?://#i', $url)) return $url;
        return $basis . '/' . ltrim($url, '/');
    }

    private function kerangka(): ?string
    {
        $dir = rtrim((string) Env::get('FRONTEND_PATH', '/var/www/compro-klinik'), '/');
        $f   = $dir . '/index.html';
        if (!is_file($f) || !is_readable($f)) return null;
        $isi = file_get_contents($f);
        return $isi === false ? null : $isi;
    }

    private function pengaturan(): array
    {
        try { return SettingsService::untukPublik(); }
        catch (\Throwable) { return ['klinik' => ['nama' => 'Klinik']]; }
    }

    private function konten(string $tabel, string $slug): ?array
    {
        $m = ContentRepository::modul($tabel);
        $t = $m['tabel'];
        $kolomJudul = in_array($t, ['articles', 'news', 'videos'], true) ? 'judul' : 'nama';
        $kolom = "{$kolomJudul} AS judul, slug, excerpt, konten, thumbnail,
                  seo_title, seo_description, og_image, updated_at";
        $kolom .= in_array($t, ['articles', 'news', 'videos'], true) ? ', published_at' : ', NULL AS published_at';

        return Database::satu(
            "SELECT {$kolom} FROM {$t} WHERE lower(slug) = lower(?) AND status = 'published' LIMIT 1",
            [$slug]);
    }

    /** Ringkasan dari HTML konten, dipotong pada batas kata. */
    private function ringkas(string $html, string $cadangan): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
        if ($t === '') return $cadangan;
        if (mb_strlen($t) <= 158) return $t;
        $potong = mb_substr($t, 0, 158);
        $spasi  = mb_strrpos($potong, ' ');
        return rtrim($spasi ? mb_substr($potong, 0, $spasi) : $potong, " ,.;:-") . '…';
    }
}
