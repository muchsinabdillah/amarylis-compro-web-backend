<?php
declare(strict_types=1);

namespace Controllers;

use Core\Request;
use Core\Response;
use Helpers\Validator;
use Services\AuthService;

final class AuthController
{
    public function masuk(Request $req): void
    {
        $v = Validator::untuk($req)->email('email')->teks('password', true, 200)->selesai();
        Response::sukses(AuthService::masuk($v['email'], $v['password']));
    }

    /** Dipakai frontend saat memuat ulang halaman, untuk memastikan token masih sah. */
    public function saya(Request $req): void
    {
        Response::sukses($req->auth());
    }

    public function gantiSandi(Request $req): void
    {
        $v = Validator::untuk($req)
            ->teks('sandi_lama', true, 200)
            ->teks('sandi_baru', true, 200)
            ->selesai();

        AuthService::gantiSandi((int) $req->userId(), $v['sandi_lama'], $v['sandi_baru']);
        Response::sukses(['pesan' => 'Kata sandi berhasil diganti.']);
    }
}
