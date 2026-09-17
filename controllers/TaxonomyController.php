<?php
declare(strict_types=1);

namespace Controllers;

use Core\Database;
use Core\HttpException;
use Core\Request;
use Core\Response;
use Helpers\Slug;
use Helpers\Validator;
use Repositories\TabelRepository;

/**
 * Kategori dan tag.
 *
 * Satu tabel kategori untuk seluruh jenis konten, dibedakan kolom `tipe`.
 */
final class TaxonomyController
{
    private const TIPE = ['article', 'news', 'video', 'service', 'mcu', 'homecare'];

    /**
     * Jumlah pemakaian ikut dihitung.
     *
     * Tanpa angka itu, orang menghapus kategori untuk mencari tahu apakah ada
     * isinya — dan baru tahu setelahnya.
     */
    private const PEMAKAIAN = "
        SELECT 'article'  AS tipe, category_id FROM articles          UNION ALL
        SELECT 'news',     category_id FROM news                      UNION ALL
        SELECT 'video',    category_id FROM videos                    UNION ALL
        SELECT 'service',  category_id FROM services                  UNION ALL
        SELECT 'mcu',      category_id FROM mcu_packages              UNION ALL
        SELECT 'homecare', category_id FROM homecare_services";

    public function daftar(Request $req): void
    {
        $tipe = $req->str('tipe');
        if ($tipe !== null && !in_array($tipe, self::TIPE, true)) {
            throw HttpException::validasi(['tipe' => 'Jenis kategori tidak dikenal.']);
        }

        Response::sukses(Database::semua(
            'SELECT c.id, c.tipe, c.nama, c.slug, c.keterangan, c.urutan, c.is_active,
                    coalesce(p.jumlah, 0) AS dipakai
               FROM categories c
               LEFT JOIN (SELECT tipe, category_id, count(*) AS jumlah
                            FROM (' . self::PEMAKAIAN . ') x
                           WHERE category_id IS NOT NULL
                           GROUP BY tipe, category_id) p
                      ON p.category_id = c.id AND p.tipe = c.tipe
              WHERE (:tipe::text IS NULL OR c.tipe = :tipe)
              ORDER BY c.tipe, c.urutan, c.nama',
            [':tipe' => $tipe]));
    }

    public function simpan(Request $req, array $args): void
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;

        $v = Validator::untuk($req)
            ->teks('nama', $id === null, 120)
            ->pilihan('tipe', self::TIPE, $id === null)
            ->teks('keterangan', false, 300)
            ->selesai();

        $data = [];
        foreach (['nama', 'keterangan'] as $k) {
            if (array_key_exists($k, $v)) {
                $data[$k] = $v[$k];
            }
        }
        if (isset($v['tipe'])) {
            $data['tipe'] = $v['tipe'];
        }
        if ($req->ada('urutan')) {
            $data['urutan'] = $req->int('urutan', 0);
        }
        if ($req->ada('is_active')) {
            $data['is_active'] = $req->bool('is_active') ? 'true' : 'false';
        }

        $tipeUntukSlug = $data['tipe'] ?? (string) Database::nilai(
            'SELECT tipe FROM categories WHERE id = :i', [':i' => $id]);

        if ($id === null) {
            $data['slug'] = Slug::unik('categories', $req->str('slug') ?? $v['nama'], null,
                ['tipe' => $tipeUntukSlug]);
            Response::sukses(['id' => TabelRepository::sisip('categories', $data)], [], 201);
            return;
        }

        if ($req->ada('slug')) {
            $data['slug'] = Slug::unik('categories', (string) $req->str('slug'), $id,
                ['tipe' => $tipeUntukSlug]);
        }
        TabelRepository::perbarui('categories', $id, $data);
        Response::sukses(['id' => $id]);
    }

    /**
     * Kategori yang masih dipakai tidak dihapus.
     *
     * Kunci asingnya ON DELETE SET NULL, jadi penghapusan akan berhasil dan
     * diam-diam melepaskan puluhan artikel dari kategorinya. Lebih baik
     * ditolak dengan menyebut jumlahnya.
     */
    public function hapus(Request $req, array $args): void
    {
        $id = (int) $args['id'];
        $k  = Database::satu('SELECT tipe FROM categories WHERE id = :i', [':i' => $id]);
        if ($k === null) {
            throw HttpException::takDitemukan('Kategori tidak ditemukan.');
        }

        $tabel = match ($k['tipe']) {
            'article'  => 'articles',
            'news'     => 'news',
            'video'    => 'videos',
            'service'  => 'services',
            'mcu'      => 'mcu_packages',
            'homecare' => 'homecare_services',
            default    => null,
        };
        if ($tabel !== null) {
            $dipakai = (int) Database::nilai(
                "SELECT count(*) FROM {$tabel} WHERE category_id = :i", [':i' => $id]);
            if ($dipakai > 0) {
                throw HttpException::validasi(['kategori' => sprintf(
                    'Kategori ini masih dipakai oleh %d konten. Pindahkan dulu, atau nonaktifkan saja.',
                    $dipakai)]);
            }
        }

        Database::jalankan('DELETE FROM categories WHERE id = :i', [':i' => $id]);
        Response::takAdaIsi();
    }

    // ----------------------------------------------------------------- tag
    public function daftarTag(Request $req): void
    {
        Response::sukses(Database::semua(
            'SELECT t.id, t.nama, t.slug, count(g.tag_id) AS dipakai
               FROM tags t LEFT JOIN taggables g ON g.tag_id = t.id
              GROUP BY t.id ORDER BY t.nama'));
    }

    public function simpanTag(Request $req, array $args): void
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $v  = Validator::untuk($req)->teks('nama', $id === null, 80)->selesai();

        $data = ['nama' => $v['nama'] ?? null];
        $data = array_filter($data, static fn($x) => $x !== null);
        if (isset($data['nama'])) {
            $data['slug'] = Slug::unik('tags', $data['nama'], $id);
        }

        if ($id === null) {
            Response::sukses(['id' => TabelRepository::sisip('tags', $data)], [], 201);
            return;
        }
        TabelRepository::perbarui('tags', $id, $data);
        Response::sukses(['id' => $id]);
    }

    public function hapusTag(Request $req, array $args): void
    {
        $n = Database::jalankan('DELETE FROM tags WHERE id = :i', [':i' => (int) $args['id']]);
        if ($n === 0) {
            throw HttpException::takDitemukan('Tag tidak ditemukan.');
        }
        Response::takAdaIsi();
    }
}
