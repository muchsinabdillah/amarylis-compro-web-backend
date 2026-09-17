<?php
declare(strict_types=1);

/**
 * Titik muat bersama untuk permintaan web maupun perintah baris.
 *
 * Autoload PSR-4 sederhana tanpa Composer: sisi server proyek ini tidak
 * memakai pustaka pihak ketiga, dan menambah satu langkah pemasangan hanya
 * untuk memuat berkas sendiri tidak sepadan.
 */

define('BASE_PATH', __DIR__);

spl_autoload_register(static function (string $kelas): void {
    $peta = [
        'Core\\'         => __DIR__ . '/core/',
        'Services\\'     => __DIR__ . '/services/',
        'Repositories\\' => __DIR__ . '/repositories/',
        'Controllers\\'  => __DIR__ . '/controllers/',
        'Middleware\\'   => __DIR__ . '/middleware/',
        'Helpers\\'      => __DIR__ . '/helpers/',
    ];

    foreach ($peta as $awalan => $dir) {
        if (!str_starts_with($kelas, $awalan)) {
            continue;
        }
        $sisa   = substr($kelas, strlen($awalan));
        $berkas = $dir . str_replace('\\', '/', $sisa) . '.php';
        if (is_file($berkas)) {
            require_once $berkas;
        }
        return;
    }
});

\Core\Env::muat(__DIR__ . '/.env');
date_default_timezone_set(\Core\Env::get('APP_TIMEZONE', 'Asia/Jakarta'));
