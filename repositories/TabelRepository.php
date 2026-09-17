<?php
declare(strict_types=1);

namespace Repositories;

use Core\Database;
use Core\HttpException;

/**
 * Sisip / perbarui / hapus sederhana untuk satu baris.
 *
 * Hampir setiap layar CMS melakukan tiga hal yang sama, dan menuliskannya
 * ulang di setiap controller berarti setiap perbaikan harus diingat di
 * belasan tempat.
 *
 * NAMA TABEL DAN NAMA KOLOM DISISIPKAN LANGSUNG KE KUERI — keduanya tidak
 * dapat diikat sebagai parameter. Karena itu keduanya wajib berasal dari
 * tetapan di dalam kode, tidak pernah dari badan permintaan. Nilai selalu
 * diikat.
 */
final class TabelRepository
{
    /** Kolom yang boleh memakai penanda cast khusus, mis. jsonb. */
    public static function sisip(string $tabel, array $data, array $cast = []): int
    {
        if ($data === []) {
            throw HttpException::validasi(['data' => 'Tidak ada isian yang dikirim.']);
        }
        self::periksaKolom(array_keys($data));

        $kolom = array_keys($data);
        $ph    = array_map(
            static fn($k) => ':' . $k . (isset($cast[$k]) ? '::' . $cast[$k] : ''), $kolom);

        Database::jalankan(
            "INSERT INTO {$tabel} (" . implode(', ', $kolom) . ')'
            . ' VALUES (' . implode(', ', $ph) . ')',
            array_combine(array_map(static fn($k) => ':' . $k, $kolom), array_values($data)));

        return (int) Database::nilai(
            "SELECT currval(pg_get_serial_sequence('webcompro.{$tabel}','id'))");
    }

    public static function perbarui(string $tabel, int $id, array $data, array $cast = []): void
    {
        if ($data === []) {
            return;
        }
        self::periksaKolom(array_keys($data));

        $set    = [];
        $params = [':id' => $id];
        foreach ($data as $k => $v) {
            $set[]           = $k . ' = :' . $k . (isset($cast[$k]) ? '::' . $cast[$k] : '');
            $params[':' . $k] = $v;
        }

        // updated_at diurus pemicu basis data bila ada; bila tidak, kolomnya
        // memang tidak dimiliki tabel tersebut.
        $n = Database::jalankan(
            "UPDATE {$tabel} SET " . implode(', ', $set) . ' WHERE id = :id', $params);

        if ($n === 0) {
            throw HttpException::takDitemukan();
        }
    }

    public static function hapus(string $tabel, int $id, string $pesan = 'Data tidak ditemukan.'): void
    {
        if (Database::jalankan("DELETE FROM {$tabel} WHERE id = :i", [':i' => $id]) === 0) {
            throw HttpException::takDitemukan($pesan);
        }
    }

    /**
     * Jaring pengaman terakhir.
     *
     * Seluruh pemanggil sudah menyaring kolom lewat daftar putihnya sendiri.
     * Pemeriksaan ini hanya memastikan tidak ada daftar putih yang kelak
     * ditulis longgar dan meloloskan sesuatu yang bukan nama kolom.
     */
    private static function periksaKolom(array $kolom): void
    {
        foreach ($kolom as $k) {
            if (!is_string($k) || preg_match('/^[a-z_][a-z0-9_]*$/', $k) !== 1) {
                throw new HttpException(500, 'Nama kolom tidak sah.');
            }
        }
    }
}
