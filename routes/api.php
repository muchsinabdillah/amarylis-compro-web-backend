<?php
declare(strict_types=1);

/**
 * Seluruh titik akhir API, beserta penjaganya.
 *
 * Berkas ini sengaja dibuat satu dan dapat dibaca sekali duduk: daftar rute
 * yang tersebar membuat orang harus memburu ke mana penjaga sebuah alamat
 * dipasang — dan alamat yang penjaganya tidak ditemukan biasanya memang tidak
 * punya penjaga.
 *
 * Aturan tetap:
 *   - /api/publik/*  hanya membaca, tanpa token, dibatasi laju permintaan.
 *   - /api/admin/*   wajib token, dan setiap tulisan wajib punya izin.
 */

use Controllers\AdminController;
use Controllers\AuthController;
use Controllers\FacilityController;
use Controllers\McuPerusahaanController;
use Controllers\MediaController;
use Controllers\PageController;
use Controllers\PasienAuthController;
use Controllers\PerusahaanAuthController;
use Controllers\PesananController;
use Controllers\PublicController;
use Controllers\ReservasiController;
use Controllers\SeoController;
use Controllers\TaxonomyController;
use Controllers\UserController;
use Core\Env;
use Core\Request;
use Core\Router;
use Middleware\Middleware;

return static function (Router $r): void {

    $publik   = new PublicController();
    $auth     = new AuthController();
    $admin    = new AdminController();
    $media    = new MediaController();
    $taksonom = new TaxonomyController();
    $halaman  = new PageController();
    $fasilit  = new FacilityController();
    $penggun  = new UserController();
    $seo      = new SeoController();
    $pasienAuth = new PasienAuthController();
    $perusahaanAuth = new PerusahaanAuthController();
    $mcuPerusahaan  = new McuPerusahaanController();
    $reservasi  = new ReservasiController();
    $pesanan    = new PesananController();

    // =================================================================
    //  Publik — tanpa token
    // =================================================================
    $r->grup('/api/publik', [Middleware::batasLaju('publik')], static function (Router $r)
        use ($publik) {

        $r->get('/pengaturan',            [$publik, 'pengaturan']);
        $r->get('/dokter',                [$publik, 'dokter']);
        $r->get('/jadwal-reservasi',      [$publik, 'jadwalReservasi']);
        $r->get('/paket',                 [$publik, 'paket']);
        $r->get('/fasilitas',             [$publik, 'fasilitas']);
        $r->get('/halaman/{slug}',        [$publik, 'halaman']);
        $r->get('/kategori/{tipe}',       [$publik, 'kategori']);
        $r->get('/cari',                  [$publik, 'cari']);
        $r->get('/konten/{modul}',        [$publik, 'daftarKonten']);
        $r->get('/konten/{modul}/{slug}', [$publik, 'detailKonten']);

        // Satu-satunya tulisan dari sisi publik. Dibatasi lebih ketat karena
        // titik akhir yang menulis selalu lebih menarik untuk disalahgunakan.
        $r->post('/lead', [$publik, 'catatLead'], [Middleware::batasLaju('lead', 20)]);
    });

    $r->get('/sitemap.xml', [$seo, 'sitemap'], [Middleware::batasLaju('publik')]);
    $r->get('/robots.txt',  [$seo, 'robots'],  [Middleware::batasLaju('publik')]);

    // =================================================================
    //  Masuk — tanpa token, dibatasi ketat
    // =================================================================
    $r->post('/api/admin/masuk', [$auth, 'masuk'], [Middleware::batasLaju('masuk', Env::int('RATE_LIMIT_LOGIN', 8))]);

    // =================================================================
    //  Pasien (portal reservasi) — daftar & masuk tanpa token
    // =================================================================
    $r->post('/api/pasien/daftar', [$pasienAuth, 'daftar'], [Middleware::batasLaju('masuk', Env::int('RATE_LIMIT_LOGIN', 8))]);
    $r->post('/api/pasien/masuk',  [$pasienAuth, 'masuk'],  [Middleware::batasLaju('masuk', Env::int('RATE_LIMIT_LOGIN', 8))]);

    // =================================================================
    //  Portal MCU perusahaan
    //
    //  Kredensialnya diperiksa di SIMRS (masternya di sana), website hanya
    //  menerbitkan sesi. Laju masuk dibatasi sama ketatnya dengan portal
    //  pasien — ini pintu tebak sandi.
    // =================================================================
    $r->post('/api/perusahaan/masuk', [$perusahaanAuth, 'masuk'], [Middleware::batasLaju('masuk', Env::int('RATE_LIMIT_LOGIN', 8))]);

    $r->grup('/api/perusahaan', [Middleware::batasLaju('perusahaan', 300), Middleware::authPerusahaan()],
        static function (Router $r) use ($perusahaanAuth, $mcuPerusahaan) {
        $r->get('/saya', [$perusahaanAuth, 'saya']);

        // Unggahan peserta MCU. perusahaan_id diambil dari token di dalam
        // controller — tidak pernah dari badan permintaan.
        $r->get('/mcu/paket',              [$mcuPerusahaan, 'paket']);
        $r->get('/mcu/batch',              [$mcuPerusahaan, 'daftar']);
        $r->post('/mcu/batch',             [$mcuPerusahaan, 'unggah']);
        $r->get('/mcu/batch/{no}',         [$mcuPerusahaan, 'detail']);
        $r->post('/mcu/batch/{no}/batal',  [$mcuPerusahaan, 'batal']);

        // Hasil MCU / lab / radiologi karyawan, dan angka ringkas untuk dasbor.
        $r->get('/mcu/ringkas',            [$mcuPerusahaan, 'ringkas']);
        $r->get('/mcu/hasil',              [$mcuPerusahaan, 'hasil']);
        $r->get('/mcu/hasil/{id}',         [$mcuPerusahaan, 'hasilDetail']);
    });

    // Pasien — wajib token pasien (tabel pasien_akun; token admin ditolak).
    $r->grup('/api/pasien', [Middleware::batasLaju('pasien', 300), Middleware::authPasien()],
        static function (Router $r) use ($pasienAuth, $reservasi, $pesanan) {
        $r->get('/saya',                 [$pasienAuth, 'saya']);
        $r->post('/ganti-sandi',         [$pasienAuth, 'gantiSandi']);
        $r->post('/cari-rm',             [$reservasi, 'cariRekamMedik']);
        $r->get('/reservasi',            [$reservasi, 'milikSaya']);
        $r->post('/reservasi',           [$reservasi, 'buat']);
        $r->post('/reservasi/{id}/batal', [$reservasi, 'batal']);
        $r->post('/reservasi/{id}/checkin', [$reservasi, 'checkinSendiri']);
        // Pesanan paket (MCU & layanan)
        $r->get('/pesanan',              [$pesanan, 'milikSaya']);
        $r->post('/pesanan',             [$pesanan, 'buat']);
        $r->post('/pesanan/{id}/batal',  [$pesanan, 'batal']);
    });

    // =================================================================
    //  Admin — wajib token
    // =================================================================
    $r->grup('/api/admin', [Middleware::batasLaju('admin', 300), Middleware::auth()],
        static function (Router $r)
        use ($auth, $admin, $media, $taksonom, $halaman, $fasilit, $penggun, $reservasi, $pesanan) {

        // -- akun sendiri: tidak butuh izin khusus
        $r->get('/saya',        [$auth, 'saya']);
        $r->post('/ganti-sandi', [$auth, 'gantiSandi']);
        $r->get('/dasbor',      [$admin, 'dasbor']);

        // -- konten: izin ditentukan modul pada jalur
        $r->get('/konten/{modul}',         [$admin, 'daftar'], [Middleware::izinKonten()]);
        $r->post('/konten/{modul}',        [$admin, 'simpan'], [Middleware::izinKonten()]);
        $r->get('/konten/{modul}/{id}',    [$admin, 'ambil'],  [Middleware::izinKonten()]);
        $r->put('/konten/{modul}/{id}',    [$admin, 'simpan'], [Middleware::izinKonten()]);
        $r->delete('/konten/{modul}/{id}', [$admin, 'hapus'],  [Middleware::izinKonten()]);

        // -- pengaturan situs
        $r->get('/pengaturan',  [$admin, 'pengaturan'],       [Middleware::izin('setting.view')]);
        $r->put('/pengaturan',  [$admin, 'simpanPengaturan'], [Middleware::izin('setting.update')]);

        // -- dokter & sinkronisasi
        $r->get('/dokter',      [$admin, 'daftarDokter'], [Middleware::izin('doctor.manage')]);
        $r->put('/dokter/{id}', [$admin, 'simpanDokter'], [Middleware::izin('doctor.manage')]);
        $r->post('/sinkron',    [$admin, 'sinkron'],      [Middleware::izin('doctor.manage')]);
        $r->get('/sinkron',     [$admin, 'riwayatSinkron'], [Middleware::izin('doctor.manage')]);

        // Paket sinkron SIMRS — untuk isi-otomatis form paket MCU/homecare (read-only).
        $r->get('/paket-simrs',      [$admin, 'paketSimrs']);
        $r->get('/paket-simrs/{id}', [$admin, 'paketSimrsDetail']);

        // -- halaman statis
        $r->get('/halaman',         [$halaman, 'daftar'], [Middleware::izin('page.manage')]);
        $r->post('/halaman',        [$halaman, 'simpan'], [Middleware::izin('page.manage')]);
        $r->get('/halaman/{id}',    [$halaman, 'ambil'],  [Middleware::izin('page.manage')]);
        $r->put('/halaman/{id}',    [$halaman, 'simpan'], [Middleware::izin('page.manage')]);

        // -- fasilitas
        $r->get('/fasilitas',         [$fasilit, 'daftar'],   [Middleware::izin('facility.manage')]);
        $r->post('/fasilitas',        [$fasilit, 'simpan'],   [Middleware::izin('facility.manage')]);
        $r->put('/fasilitas/urutan',  [$fasilit, 'urutkan'],  [Middleware::izin('facility.manage')]);
        $r->put('/fasilitas/{id}',    [$fasilit, 'simpan'],   [Middleware::izin('facility.manage')]);
        $r->delete('/fasilitas/{id}', [$fasilit, 'hapus'],    [Middleware::izin('facility.manage')]);

        // -- kategori & tag
        $r->get('/kategori',         [$taksonom, 'daftar'], [Middleware::izin('category.manage')]);
        $r->post('/kategori',        [$taksonom, 'simpan'], [Middleware::izin('category.manage')]);
        $r->put('/kategori/{id}',    [$taksonom, 'simpan'], [Middleware::izin('category.manage')]);
        $r->delete('/kategori/{id}', [$taksonom, 'hapus'],  [Middleware::izin('category.manage')]);
        $r->get('/tag',              [$taksonom, 'daftarTag'], [Middleware::izin('category.manage')]);
        $r->post('/tag',             [$taksonom, 'simpanTag'], [Middleware::izin('category.manage')]);
        $r->put('/tag/{id}',         [$taksonom, 'simpanTag'], [Middleware::izin('category.manage')]);
        $r->delete('/tag/{id}',      [$taksonom, 'hapusTag'],  [Middleware::izin('category.manage')]);

        // -- pustaka media
        $r->get('/media',         [$media, 'daftar'], [Middleware::izin('media.manage')]);
        $r->post('/media',        [$media, 'unggah'], [Middleware::izin('media.manage')]);
        $r->put('/media/{id}',    [$media, 'ubah'],   [Middleware::izin('media.manage')]);
        $r->delete('/media/{id}', [$media, 'hapus'],  [Middleware::izin('media.manage')]);

        // -- reservasi pasien (verifikasi → registrasi di SIMRS)
        $r->get('/reservasi',      [$reservasi, 'daftarAdmin'], [Middleware::izin('reservation.manage')]);
        $r->put('/reservasi/{id}', [$reservasi, 'ubahStatus'],  [Middleware::izin('reservation.manage')]);

        // -- pesanan paket (MCU & layanan) → jual ke SIMRS
        $r->get('/pesanan',      [$pesanan, 'daftarAdmin'], [Middleware::izin('reservation.manage')]);
        $r->put('/pesanan/{id}', [$pesanan, 'ubahStatus'],  [Middleware::izin('reservation.manage')]);

        // -- pengguna & peran
        $r->get('/pengguna',              [$penggun, 'daftar'],     [Middleware::izin('user.manage')]);
        $r->get('/peran',                 [$penggun, 'peran'],      [Middleware::izin('user.manage')]);
        $r->post('/pengguna',             [$penggun, 'buat'],       [Middleware::izin('user.manage')]);
        $r->put('/pengguna/{id}',         [$penggun, 'ubah'],       [Middleware::izin('user.manage')]);
        $r->put('/pengguna/{id}/sandi',   [$penggun, 'setelSandi'], [Middleware::izin('user.manage')]);
        $r->delete('/pengguna/{id}',      [$penggun, 'hapus'],      [Middleware::izin('user.manage')]);
    });

    // Pemeriksaan kesehatan; dipakai pemantauan, tidak membocorkan apa pun.
    $r->get('/api/status', static function (Request $req): void {
        \Core\Response::sukses(['ok' => true, 'waktu' => date('c')]);
    });
};
