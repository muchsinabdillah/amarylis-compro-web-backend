<?php
declare(strict_types=1);

namespace Controllers;

use Core\Request;
use Core\Response;
use Helpers\Validator;
use Services\PasienAuthService;

final class PasienAuthController
{
    public function daftar(Request $req): void
    {
        $v = Validator::untuk($req)
            ->teks('no_hp', true, 20)
            ->teks('nama', true, 160)
            ->teks('password', true, 200)
            ->selesai();

        Response::sukses(PasienAuthService::daftar([
            'no_hp'         => $v['no_hp'],
            'nama'          => $v['nama'],
            'password'      => $v['password'],
            'email'         => $req->str('email'),
            'nik'           => $req->str('nik'),
            'tgl_lahir'     => $req->str('tgl_lahir'),
            'jenis_kelamin' => $req->str('jenis_kelamin'),
            'alamat'        => $req->str('alamat'),
        ]), [], 201);
    }

    public function masuk(Request $req): void
    {
        $v = Validator::untuk($req)
            ->teks('no_hp', true, 20)
            ->teks('password', true, 200)
            ->selesai();
        Response::sukses(PasienAuthService::masuk($v['no_hp'], $v['password']));
    }

    /** Dipakai frontend saat memuat ulang, untuk memastikan token masih sah. */
    public function saya(Request $req): void
    {
        Response::sukses(PasienAuthService::profil((int) $req->userId()));
    }

    public function gantiSandi(Request $req): void
    {
        $v = Validator::untuk($req)
            ->teks('sandi_lama', true, 200)
            ->teks('sandi_baru', true, 200)
            ->selesai();
        PasienAuthService::gantiSandi((int) $req->userId(), $v['sandi_lama'], $v['sandi_baru']);
        Response::sukses(['pesan' => 'Kata sandi berhasil diganti.']);
    }
}
