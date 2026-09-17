<?php
declare(strict_types=1);

/**
 * Sinkronisasi SIMRS -> website dari baris perintah.
 *
 * Dipakai penjadwal (cron) maupun saat memeriksa keadaan secara manual.
 * Panel admin memanggil layanan yang sama, sehingga tidak ada dua jalur
 * yang bisa berbeda perilakunya.
 *
 *   php bin/sync.php            seluruh modul
 *   php bin/sync.php doctors    satu modul saja
 */

require __DIR__ . '/../bootstrap.php';

$modul = $argv[1] ?? 'semua';
$svc   = new \Services\SyncService();
$mulai = microtime(true);

try {
    $hasil = match ($modul) {
        'semua'     => $svc->semua('cli'),
        'doctors'   => ['doctors'   => $svc->dokter('cli')],
        'schedules' => ['schedules' => $svc->jadwal('cli')],
        'packages'  => ['packages'  => $svc->paket('cli')],
        default     => throw new InvalidArgumentException(
            "Modul '{$modul}' tidak dikenal. Pilih: semua|doctors|schedules|packages"),
    };
} catch (Throwable $e) {
    fwrite(STDERR, "GAGAL: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

foreach ($hasil as $nama => $h) {
    $rinci = implode('  ', array_map(
        static fn($k, $v) => "{$k}={$v}", array_keys($h), array_values($h)));
    printf("  %-10s %s%s", $nama, $rinci, PHP_EOL);
}
printf("Selesai dalam %.2f detik.%s", microtime(true) - $mulai, PHP_EOL);
