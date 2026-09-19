<?php
declare(strict_types=1);

namespace Controllers;

use Core\Request;
use Core\Response;
use Helpers\Validator;
use Services\PerusahaanAuthService;

/** Masuk & profil portal MCU perusahaan. */
final class PerusahaanAuthController
{
    public function masuk(Request $req): void
    {
        $v = Validator::untuk($req)
            ->teks('email', true, 120)
            ->teks('kata_sandi', true, 200)
            ->selesai();
        Response::sukses(PerusahaanAuthService::masuk($v['email'], $v['kata_sandi']));
    }

    /** Profil sesi berjalan — diambil dari token, tanpa memanggil SIMRS lagi. */
    public function saya(Request $req): void
    {
        $a = $req->auth();
        Response::sukses([
            'akun_id'         => $a['sub'] ?? null,
            'perusahaan_id'   => $a['perusahaan_id'] ?? null,
            'nama_perusahaan' => $a['nama_perusahaan'] ?? null,
            'email'           => $a['email'] ?? null,
        ]);
    }
}
