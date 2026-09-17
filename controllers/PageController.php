<?php
declare(strict_types=1);

namespace Controllers;

use Core\Database;
use Core\HttpException;
use Core\Request;
use Core\Response;
use Helpers\Html;
use Helpers\Slug;
use Helpers\Validator;
use Repositories\TabelRepository;

/**
 * Halaman statis: Beranda, Tentang Kami, dan sejenisnya.
 *
 * Kolom `seksi` menampung blok bebas (visi, misi, nilai, keunggulan) sebagai
 * JSON sehingga penambahan blok baru tidak menuntut kolom baru.
 */
final class PageController
{
    public function daftar(Request $req): void
    {
        Response::sukses(Database::semua(
            'SELECT id, slug, judul, status, updated_at FROM pages ORDER BY id'));
    }

    public function ambil(Request $req, array $args): void
    {
        $p = Database::satu('SELECT * FROM pages WHERE id = :i', [':i' => (int) $args['id']]);
        if ($p === null) {
            throw HttpException::takDitemukan('Halaman tidak ditemukan.');
        }
        $p['seksi'] = $p['seksi'] !== null ? json_decode((string) $p['seksi'], true) : null;
        Response::sukses($p);
    }

    public function simpan(Request $req, array $args): void
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;

        $v = Validator::untuk($req)
            ->teks('judul', $id === null, 200)
            ->pilihan('status', ['draft', 'published', 'archived'], false, 'draft')
            ->teks('seo_title', false, 200)
            ->teks('seo_description', false, 400)
            ->selesai();

        $data = [];
        if (isset($v['judul'])) {
            $data['judul'] = $v['judul'];
        }
        foreach (['seo_title', 'seo_description'] as $k) {
            if (array_key_exists($k, $v)) {
                $data[$k] = $v[$k];
            }
        }
        foreach (['hero_image', 'og_image'] as $k) {
            if ($req->ada($k)) {
                $data[$k] = $req->str($k);
            }
        }
        if ($req->ada('konten')) {
            $data['konten'] = Html::bersihkan($req->str('konten'));
        }
        if ($req->ada('status')) {
            $data['status'] = $v['status'];
        }

        $cast = [];
        if ($req->ada('seksi')) {
            $data['seksi'] = json_encode($this->bersihkanSeksi($req->arr('seksi')),
                JSON_UNESCAPED_UNICODE);
            $cast['seksi'] = 'jsonb';
        }

        if ($id === null) {
            $data['slug'] = Slug::unik('pages', $req->str('slug') ?? $v['judul']);
            $data['status'] ??= 'draft';
            $cast['status'] = 'webcompro.status_konten';
            Response::sukses(['id' => TabelRepository::sisip('pages', $data, $cast)], [], 201);
            return;
        }

        /*
         * Slug halaman statis tidak diubah lewat CMS.
         *
         * Frontend memanggilnya dengan nama tetap ("beranda", "tentang-kami").
         * Mengubah slug di sini akan membuat menu utama menunjuk halaman yang
         * tidak ada, dan kegagalannya baru terlihat oleh pengunjung.
         */
        if (isset($data['status'])) {
            $cast['status'] = 'webcompro.status_konten';
        }
        TabelRepository::perbarui('pages', $id, $data, $cast);
        Response::sukses(['id' => $id]);
    }

    /**
     * Blok bebas tetap dibersihkan.
     *
     * JSON tidak membuat isinya aman: nilainya berakhir di HTML yang sama.
     */
    private function bersihkanSeksi(array $seksi): array
    {
        $hasil = [];
        foreach ($seksi as $kunci => $nilai) {
            $k = is_string($kunci) ? mb_substr($kunci, 0, 60) : (string) $kunci;

            if (is_array($nilai)) {
                $hasil[$k] = $this->bersihkanSeksi($nilai);
            } elseif (is_string($nilai)) {
                $hasil[$k] = str_contains($nilai, '<')
                    ? Html::bersihkan($nilai)
                    : mb_substr($nilai, 0, 2000);
            } elseif (is_scalar($nilai) || $nilai === null) {
                $hasil[$k] = $nilai;
            }
        }
        return $hasil;
    }
}
