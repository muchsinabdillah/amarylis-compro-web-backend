<?php
declare(strict_types=1);

/**
 * Titik masuk tunggal API website.
 *
 * Hanya folder ini yang boleh terbuka dari luar. Kode, .env, dan berkas
 * unggahan berada di atasnya sehingga tidak dapat diminta langsung lewat URL
 * meskipun konfigurasi web server kelak berubah.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Core\Env;
use Core\HttpException;
use Core\Request;
use Core\Response;
use Core\Router;
use Middleware\Middleware;

$debug = Env::bool('APP_DEBUG', false);

/*
 * Galat tidak pernah dicetak ke keluaran.
 *
 * Satu peringatan PHP yang tercetak sebelum header akan merusak seluruh
 * jawaban JSON — dan bila memuat jalur berkas atau kueri, ia sekaligus
 * memberi tahu penyerang bentuk dalam aplikasi.
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$req = new Request();

// Permintaan pramuji CORS dijawab sebelum apa pun yang lain: ia tidak
// membawa token dan tidak boleh dinilai sebagai permintaan biasa.
$cors = Middleware::cors();
$cors($req);
if ($req->metode() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Berkas unggahan. Di production sebaiknya dilayani langsung oleh web server;
// penanganan di sini menjaga agar server bawaan PHP tetap dapat dipakai saat
// pengembangan tanpa konfigurasi tambahan.
if (str_starts_with($req->jalur(), '/media/')) {
    sajikanMedia(substr($req->jalur(), strlen('/media/')));
    exit;
}

try {
    $router = new Router();
    (require dirname(__DIR__) . '/routes/api.php')($router);
    $router->jalankan($req);

} catch (HttpException $e) {
    Response::gagal($e->getMessage(), $e->status(), $e->rincian());

} catch (Throwable $e) {
    // Rincian galat tak terduga masuk ke catatan server, bukan ke jawaban.
    error_log(sprintf('[%s] %s in %s:%d%s%s',
        date('c'), $e->getMessage(), $e->getFile(), $e->getLine(), PHP_EOL, $e->getTraceAsString()));

    Response::gagal(
        'Terjadi kesalahan pada server. Silakan coba beberapa saat lagi.',
        500,
        $debug ? ['debug' => $e->getMessage(), 'berkas' => $e->getFile() . ':' . $e->getLine()] : []);
}

/**
 * Kirimkan berkas unggahan.
 *
 * Jalurnya dibatasi ketat: hanya nama yang persis berbentuk `TAHUN/BULAN/heks.ext`
 * yang dilayani. Menyaring "../" saja tidak cukup — penyandian ganda dan
 * tautan simbolik pernah meloloskannya berkali-kali di banyak aplikasi.
 */
function sajikanMedia(string $relatif): void
{
    $tipe = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',  'webp' => 'image/webp',
    ];

    if (preg_match('#^\d{4}/\d{2}/[0-9a-f]{32}\.(jpg|jpeg|png|webp)$#', $relatif) !== 1) {
        http_response_code(404);
        exit;
    }

    $berkas = \Services\MediaService::direktori() . '/' . $relatif;
    if (!is_file($berkas)) {
        http_response_code(404);
        exit;
    }

    $ext = strtolower((string) pathinfo($berkas, PATHINFO_EXTENSION));

    header('Content-Type: ' . $tipe[$ext]);
    header('Content-Length: ' . filesize($berkas));
    header('Cache-Control: public, max-age=2592000, immutable');
    header('X-Content-Type-Options: nosniff');
    readfile($berkas);
}
