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

final class FacilityController
{
    public function daftar(Request $req): void
    {
        Response::sukses(Database::semua(
            'SELECT id, nama, slug, deskripsi, image, is_active, urutan
               FROM facilities ORDER BY urutan, nama'));
    }

    public function simpan(Request $req, array $args): void
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;

        $v = Validator::untuk($req)->teks('nama', $id === null, 160)->selesai();

        $data = [];
        if (isset($v['nama'])) {
            $data['nama'] = $v['nama'];
        }
        if ($req->ada('deskripsi')) {
            $data['deskripsi'] = Html::bersihkan($req->str('deskripsi'));
        }
        if ($req->ada('image')) {
            $data['image'] = $req->str('image');
        }
        if ($req->ada('urutan')) {
            $data['urutan'] = $req->int('urutan', 0);
        }
        if ($req->ada('is_active')) {
            $data['is_active'] = $req->bool('is_active') ? 'true' : 'false';
        }

        if ($id === null) {
            $data['slug'] = Slug::unik('facilities', $v['nama']);
            Response::sukses(['id' => TabelRepository::sisip('facilities', $data)], [], 201);
            return;
        }
        if ($req->ada('slug')) {
            $data['slug'] = Slug::unik('facilities', (string) $req->str('slug'), $id);
        }
        TabelRepository::perbarui('facilities', $id, $data);
        Response::sukses(['id' => $id]);
    }

    public function hapus(Request $req, array $args): void
    {
        TabelRepository::hapus('facilities', (int) $args['id'], 'Fasilitas tidak ditemukan.');
        Response::takAdaIsi();
    }

    /** Pengurutan massal dari layar seret-lepas. */
    public function urutkan(Request $req): void
    {
        $urutan = $req->arr('urutan');
        if ($urutan === []) {
            throw HttpException::validasi(['urutan' => 'Kirim daftar id sesuai urutan baru.']);
        }
        Database::transaksi(static function () use ($urutan) {
            foreach (array_values($urutan) as $i => $id) {
                Database::jalankan('UPDATE facilities SET urutan = :u WHERE id = :i',
                    [':u' => $i + 1, ':i' => (int) $id]);
            }
        });
        Response::sukses(['tersimpan' => count($urutan)]);
    }
}
