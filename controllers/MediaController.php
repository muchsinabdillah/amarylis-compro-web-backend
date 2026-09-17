<?php
declare(strict_types=1);

namespace Controllers;

use Core\HttpException;
use Core\Request;
use Core\Response;
use Services\MediaService;

final class MediaController
{
    public function daftar(Request $req): void
    {
        [$halaman, $perHalaman, $offset] = $req->paginasi(24, 100);
        $h = MediaService::daftar($perHalaman, $offset);
        Response::halaman($h['baris'], $h['total'], $halaman, $perHalaman);
    }

    public function unggah(Request $req): void
    {
        $berkas = $req->file('file');
        if ($berkas === null) {
            throw HttpException::validasi(['file' => 'Pilih berkas gambar terlebih dahulu.']);
        }
        Response::sukses(MediaService::unggah($berkas, $req->str('alt'), (int) $req->userId()), [], 201);
    }

    public function ubah(Request $req, array $args): void
    {
        MediaService::ubahAlt((int) $args['id'], $req->str('alt'));
        Response::sukses(['id' => (int) $args['id']]);
    }

    public function hapus(Request $req, array $args): void
    {
        MediaService::hapus((int) $args['id']);
        Response::takAdaIsi();
    }
}
