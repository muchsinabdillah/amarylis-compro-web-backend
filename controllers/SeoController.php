<?php
declare(strict_types=1);

namespace Controllers;

use Core\Database;
use Core\Env;
use Core\Request;
use Repositories\ContentRepository;

/**
 * Peta situs dan robots.txt.
 *
 * Dibuat dari basis data, bukan berkas statis yang harus diingat untuk
 * diperbarui: peta situs yang basi menyesatkan mesin pencari ke halaman yang
 * sudah tidak ada, dan menyembunyikan halaman yang baru terbit.
 */
final class SeoController
{
    /** Jalur publik tiap modul di sisi frontend. */
    private const JALUR = [
        'articles' => 'artikel',
        'news'     => 'berita',
        'videos'   => 'video',
        'services' => 'layanan',
        'mcu'      => 'mcu',
        'homecare' => 'homecare',
    ];

    private const HALAMAN_TETAP = [
        ['', '1.0', 'daily'],
        ['tentang-kami', '0.8', 'monthly'],
        ['layanan', '0.9', 'weekly'],
        ['mcu', '0.9', 'weekly'],
        ['homecare', '0.8', 'weekly'],
        ['dokter', '0.8', 'weekly'],
        ['fasilitas', '0.6', 'monthly'],
        ['artikel', '0.7', 'daily'],
        ['berita', '0.7', 'daily'],
        ['video', '0.6', 'weekly'],
        ['kontak', '0.6', 'monthly'],
    ];

    public function sitemap(Request $req): void
    {
        $basis = rtrim((string) Env::get('SITE_URL', (string) Env::get('APP_URL', '')), '/');

        $baris = [];
        foreach (self::HALAMAN_TETAP as [$jalur, $prioritas, $ubah]) {
            $baris[] = [$basis . ($jalur === '' ? '/' : '/' . $jalur), null, $prioritas, $ubah];
        }

        foreach (self::JALUR as $modul => $jalur) {
            $m     = ContentRepository::modul($modul);
            $tabel = $m['tabel'];
            $waktu = in_array($tabel, ['articles', 'news', 'videos'], true)
                ? 'coalesce(published_at, updated_at)' : 'updated_at';

            foreach (Database::semua(
                "SELECT slug, {$waktu} AS diubah FROM {$tabel}
                  WHERE status = 'published' ORDER BY 2 DESC NULLS LAST") as $r) {
                $baris[] = [$basis . '/' . $jalur . '/' . $r['slug'], $r['diubah'], '0.6', 'monthly'];
            }
        }

        foreach (Database::semua(
            "SELECT slug FROM doctors WHERE is_published ORDER BY urutan") as $d) {
            $baris[] = [$basis . '/dokter/' . $d['slug'], null, '0.6', 'monthly'];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($baris as [$loc, $mod, $prio, $freq]) {
            $xml .= '  <url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>';
            if ($mod !== null) {
                $xml .= '<lastmod>' . date('Y-m-d', strtotime((string) $mod)) . '</lastmod>';
            }
            $xml .= '<changefreq>' . $freq . '</changefreq>'
                  . '<priority>' . $prio . '</priority></url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";

        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo $xml;
    }

    public function robots(Request $req): void
    {
        $basis = rtrim((string) Env::get('SITE_URL', (string) Env::get('APP_URL', '')), '/');
        $prod  = Env::get('APP_ENV') === 'production';

        header('Content-Type: text/plain; charset=utf-8');

        // Lingkungan selain production tidak boleh terindeks. Salinan uji coba
        // yang muncul di hasil pencarian bersaing dengan situs aslinya.
        if (!$prod) {
            echo "User-agent: *\nDisallow: /\n";
            return;
        }

        echo "User-agent: *\n"
           . "Allow: /\n"
           . "Disallow: /admin\n"
           . "Disallow: /api/\n\n"
           . 'Sitemap: ' . $basis . "/sitemap.xml\n";
    }
}
