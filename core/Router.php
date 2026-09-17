<?php
declare(strict_types=1);

namespace Core;

/**
 * Perute sederhana dengan dukungan parameter dan middleware.
 *
 * Rute didaftarkan sebagai data, bukan rangkaian if — sehingga daftar
 * seluruh titik akhir beserta penjaganya dapat dibaca sekali lihat pada
 * routes/, dan tidak ada titik akhir yang lolos tanpa penjaga karena
 * terselip di cabang kondisi.
 */
final class Router
{
    /** @var array<int,array{metode:string,pola:string,regex:string,params:string[],aksi:callable,middleware:array}> */
    private array $rute = [];

    /** @var array<int,callable> middleware yang berlaku untuk grup berjalan */
    private array $grupMiddleware = [];
    private string $grupAwalan = '';

    public function grup(string $awalan, array $middleware, callable $daftar): void
    {
        $awalanLama     = $this->grupAwalan;
        $middlewareLama = $this->grupMiddleware;

        $this->grupAwalan     = rtrim($awalanLama . $awalan, '/');
        $this->grupMiddleware = array_merge($middlewareLama, $middleware);

        $daftar($this);

        $this->grupAwalan     = $awalanLama;
        $this->grupMiddleware = $middlewareLama;
    }

    public function get(string $pola, callable $aksi, array $mw = []): void    { $this->tambah('GET', $pola, $aksi, $mw); }
    public function post(string $pola, callable $aksi, array $mw = []): void   { $this->tambah('POST', $pola, $aksi, $mw); }
    public function put(string $pola, callable $aksi, array $mw = []): void    { $this->tambah('PUT', $pola, $aksi, $mw); }
    public function delete(string $pola, callable $aksi, array $mw = []): void { $this->tambah('DELETE', $pola, $aksi, $mw); }

    private function tambah(string $metode, string $pola, callable $aksi, array $mw): void
    {
        $penuh  = rtrim($this->grupAwalan . '/' . ltrim($pola, '/'), '/');
        $penuh  = $penuh === '' ? '/' : $penuh;

        // {slug} -> tangkapan bernama. Titik dua tidak dipakai agar pola
        // tetap terbaca sama dengan yang tertulis di dokumentasi API.
        $params = [];
        $regex  = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $penuh) ?? $penuh;

        $this->rute[] = [
            'metode'     => $metode,
            'pola'       => $penuh,
            'regex'      => '#^' . $regex . '$#',
            'params'     => $params,
            'aksi'       => $aksi,
            'middleware' => array_merge($this->grupMiddleware, $mw),
        ];
    }

    public function jalankan(Request $req): void
    {
        $jalur      = $req->jalur();
        $metode     = $req->metode();
        $jalurCocok = false;

        foreach ($this->rute as $r) {
            if (!preg_match($r['regex'], $jalur, $cocok)) {
                continue;
            }
            $jalurCocok = true;
            if ($r['metode'] !== $metode) {
                continue;
            }

            $args = [];
            foreach ($r['params'] as $i => $nama) {
                $args[$nama] = urldecode($cocok[$i + 1]);
            }

            // Parameter jalur ikut diberikan ke middleware: penjaga wewenang
            // untuk /konten/{modul} perlu tahu modul mana yang diminta.
            foreach ($r['middleware'] as $mw) {
                $mw($req, $args);               // melempar HttpException bila menolak
            }

            ($r['aksi'])($req, $args);
            return;
        }

        // Jalur ada tetapi metodenya salah dijawab 405, bukan 404: keduanya
        // menuntut perbaikan yang berbeda dari pemanggilnya.
        if ($jalurCocok) {
            throw new HttpException(405, 'Metode HTTP tidak didukung untuk alamat ini.');
        }
        throw HttpException::takDitemukan('Alamat API tidak dikenal.');
    }
}
