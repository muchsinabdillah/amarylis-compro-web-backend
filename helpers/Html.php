<?php
declare(strict_types=1);

namespace Helpers;

/**
 * Pembersih HTML untuk isi artikel & berita.
 *
 * Editor teks kaya mengirimkan HTML, dan HTML dari editor tetap masukan dari
 * luar: siapa pun yang bisa masuk CMS — atau menemukan celah di sana — dapat
 * menitipkan skrip yang kemudian berjalan di peramban SETIAP pengunjung.
 *
 * Cara kerjanya menyaring dengan DAFTAR PUTIH: hanya tag dan atribut yang
 * disebut di sini yang lolos. Daftar hitam selalu kalah — bentuk serangannya
 * bertambah lebih cepat daripada daftarnya.
 */
final class Html
{
    private const TAG_DIIZINKAN = [
        'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup',
        'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'a', 'img', 'figure', 'figcaption',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
        'iframe',                                  // hanya untuk sematan video
    ];

    private const ATRIBUT_DIIZINKAN = [
        'a'      => ['href', 'title', 'target', 'rel'],
        'img'    => ['src', 'alt', 'title', 'width', 'height', 'loading'],
        'iframe' => ['src', 'width', 'height', 'title', 'allow', 'allowfullscreen', 'loading'],
        'td'     => ['colspan', 'rowspan'],
        'th'     => ['colspan', 'rowspan', 'scope'],
    ];

    /**
     * Tag yang dibuang BESERTA isinya.
     *
     * Tag lain hanya dilepas pembungkusnya karena isinya memang teks yang
     * ingin dipertahankan. Di sini sebaliknya: isi <script> bukan bacaan,
     * dan membiarkannya menjadi teks akan menaruh "alert(1)" di tengah
     * artikel — sekaligus ikut terbawa ke ringkasan dan meta description.
     */
    private const TAG_BUANG_ISI = [
        'script', 'style', 'noscript', 'template', 'title', 'head',
        'svg', 'math', 'object', 'embed', 'applet', 'link', 'meta',
    ];

    /** Sematan hanya dari penyedia video yang dikenal. */
    private const HOST_IFRAME = [
        'www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com',
        'player.vimeo.com', 'www.google.com',
    ];

    public static function bersihkan(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $sebelumnya = libxml_use_internal_errors(true);
        // Meta charset dipasang karena loadHTML menganggap masukan sebagai
        // ISO-8859-1 dan akan merusak huruf beraksen tanpa itu.
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="akar">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($sebelumnya);

        $akar = $doc->getElementById('akar');
        if ($akar === null) {
            return '';
        }

        self::sapu($akar);

        $keluar = '';
        foreach (iterator_to_array($akar->childNodes) as $anak) {
            $keluar .= $doc->saveHTML($anak);
        }
        return trim($keluar);
    }

    private static function sapu(\DOMNode $simpul): void
    {
        // Disalin ke larik lebih dulu: menghapus simpul saat menelusuri
        // koleksi langsung membuat penelusurannya melompati elemen.
        foreach (iterator_to_array($simpul->childNodes) as $anak) {
            if ($anak instanceof \DOMComment) {
                $anak->parentNode?->removeChild($anak);
                continue;
            }
            if (!$anak instanceof \DOMElement) {
                continue;                                   // teks dibiarkan
            }

            $tag = strtolower($anak->tagName);

            if (in_array($tag, self::TAG_BUANG_ISI, true)) {
                $anak->parentNode?->removeChild($anak);
                continue;
            }

            if (!in_array($tag, self::TAG_DIIZINKAN, true)) {
                // Tag dibuang tetapi ISINYA dipertahankan: <div> pembungkus
                // dari editor tidak seharusnya ikut membuang paragrafnya.
                $induk = $anak->parentNode;
                while ($anak->firstChild !== null) {
                    $induk?->insertBefore($anak->firstChild, $anak);
                }
                $induk?->removeChild($anak);
                continue;
            }

            self::bersihkanAtribut($anak, $tag);

            // Gambar dan sematan yang kehilangan src pada penyaringan di atas
            // tidak menyisakan apa pun untuk ditampilkan; yang tertinggal
            // hanya kotak kosong atau ikon gambar rusak.
            if (in_array($tag, ['img', 'iframe'], true) && $anak->getAttribute('src') === '') {
                $anak->parentNode?->removeChild($anak);
                continue;
            }

            self::sapu($anak);
        }
    }

    private static function bersihkanAtribut(\DOMElement $el, string $tag): void
    {
        $boleh = self::ATRIBUT_DIIZINKAN[$tag] ?? [];

        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            $nama = strtolower($attr->nodeName);

            // Seluruh penangan kejadian (onclick, onerror, ...) dibuang.
            if (!in_array($nama, $boleh, true)) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }

            if (in_array($nama, ['href', 'src'], true)
                && !self::urlAman($attr->nodeValue ?? '', $tag)) {
                $el->removeAttribute($attr->nodeName);
            }
        }

        // Tautan keluar tidak boleh memberi halaman tujuan kendali atas
        // jendela pembukanya (serangan tabnabbing).
        if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
        if ($tag === 'iframe') {
            $el->setAttribute('loading', 'lazy');
        }
        if ($tag === 'img') {
            $el->setAttribute('loading', 'lazy');
        }
    }

    private static function urlAman(string $url, string $tag): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        // javascript: dan data: adalah dua jalan lama menjalankan skrip lewat
        // atribut yang tampak tidak berbahaya.
        if (preg_match('/^\s*(javascript|vbscript|data)\s*:/i', $url)) {
            return false;
        }
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return true;                                    // tautan internal
        }
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }
        if ($tag === 'iframe') {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            return in_array($host, self::HOST_IFRAME, true);
        }
        return true;
    }

    /** Ringkasan otomatis dari isi HTML, untuk excerpt dan meta description. */
    public static function keTeks(?string $html, int $maks = 0): string
    {
        $teks = trim(preg_replace('/\s+/u', ' ',
            html_entity_decode(strip_tags((string) $html), ENT_QUOTES, 'UTF-8')) ?? '');
        if ($maks > 0 && mb_strlen($teks) > $maks) {
            $teks = rtrim(mb_substr($teks, 0, $maks - 1)) . '…';
        }
        return $teks;
    }

    /** Perkiraan lama baca, dipakai kartu artikel. */
    public static function menitBaca(?string $html): int
    {
        $kata = str_word_count(self::keTeks($html), 0, 'àáâãäåçèéêëìíîïñòóôõöùúûüýÿ');
        return max(1, (int) ceil($kata / 200));             // ~200 kata per menit
    }
}
