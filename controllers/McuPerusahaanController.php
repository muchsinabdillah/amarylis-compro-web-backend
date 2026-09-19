<?php
declare(strict_types=1);

namespace Controllers;

use Core\HttpException;
use Core\Request;
use Core\Response;
use Services\McuPerusahaanService;

/**
 * Unggahan peserta MCU oleh perusahaan.
 *
 * Seluruh titik akhir di sini berada di belakang Middleware::authPerusahaan().
 * perusahaan_id diambil dari token, tidak pernah dari badan permintaan.
 */
final class McuPerusahaanController
{
    /** Identitas pemilik sesi — satu-satunya sumber perusahaan_id. */
    private function sesi(Request $req): array
    {
        $a = $req->auth() ?? [];
        $pid = (int) ($a['perusahaan_id'] ?? 0);
        if ($pid <= 0) throw new HttpException(403, 'Sesi tidak tertaut ke perusahaan.');

        return [
            'perusahaan_id'   => $pid,
            'email'           => (string) ($a['email'] ?? ''),
            'nama_perusahaan' => (string) ($a['nama_perusahaan'] ?? ''),
        ];
    }

    public function paket(Request $req): void
    {
        $this->sesi($req);
        Response::sukses(McuPerusahaanService::paket());
    }

    public function unggah(Request $req): void
    {
        Response::sukses(McuPerusahaanService::unggah($this->sesi($req), [
            'tgl_mcu'  => $req->str('tgl_mcu', ''),
            'id_paket' => $req->int('id_paket', 0),
            'catatan'  => $req->str('catatan', ''),
            'peserta'  => $req->arr('peserta'),
        ]));
    }

    public function daftar(Request $req): void
    {
        Response::sukses(McuPerusahaanService::daftar($this->sesi($req), [
            'status' => $req->str('status'),
            'dari'   => $req->str('dari'),
            'sampai' => $req->str('sampai'),
        ]));
    }

    public function hasil(Request $req): void
    {
        Response::sukses(McuPerusahaanService::hasil($this->sesi($req), [
            'no_batch' => $req->str('no_batch'),
            'dari'     => $req->str('dari'),
            'sampai'   => $req->str('sampai'),
            'cari'     => $req->str('cari'),
        ]));
    }

    public function hasilDetail(Request $req, array $args): void
    {
        Response::sukses(McuPerusahaanService::hasilDetail(
            $this->sesi($req), (int) ($args['id'] ?? 0)));
    }

    public function ringkas(Request $req): void
    {
        Response::sukses(McuPerusahaanService::ringkas($this->sesi($req), [
            'dari'   => $req->str('dari'),
            'sampai' => $req->str('sampai'),
        ]));
    }

    public function detail(Request $req, array $args): void
    {
        Response::sukses(McuPerusahaanService::detail(
            $this->sesi($req), trim((string) ($args['no'] ?? ''))));
    }

    public function batal(Request $req, array $args): void
    {
        Response::sukses(McuPerusahaanService::batal(
            $this->sesi($req), trim((string) ($args['no'] ?? '')),
            (string) $req->str('alasan', '')));
    }
}
