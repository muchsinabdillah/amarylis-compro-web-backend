<?php

declare(strict_types=1);

namespace Services;

use Core\HttpException;

/**
 * Unggahan peserta MCU — penerus ke SIMRS.
 *
 * Tidak ada tabel peserta di basis data website. No. RM, registrasi, dan order
 * semuanya lahir di SIMRS; menyimpan salinan calon peserta di sini berarti dua
 * daftar pasien yang harus disamakan, dan perbedaan sekecil apa pun di antara
 * keduanya berujung pada pasien yang tertukar.
 *
 * perusahaan_id SELALU diambil dari token sesi, tidak pernah dari badan
 * permintaan. Kalau ia boleh dikirim pemanggil, satu perusahaan bisa membaca
 * dan membatalkan batch perusahaan lain hanya dengan menukar satu angka.
 */
final class McuPerusahaanService
{
    /** Batas satu unggahan. Lebih dari ini dibagi menjadi beberapa berkas. */
    public const MAKS_PESERTA = 1000;

    private static function panggil(string $jalur, array $data): array
    {
        if (!SimrsClient::terpasang()) {
            throw new HttpException(503, 'Layanan belum tersambung ke SIMRS. Hubungi klinik.');
        }
        return SimrsClient::kirim($jalur, $data);
    }

    /**
     * Paket MCU yang boleh dipilih perusahaan.
     *
     * ambil() mengembalikan isi "data" apa adanya (berbeda dari kirim() yang
     * membungkusnya dalam {ok, pesan, data}) — jadi hasilnya sudah berupa
     * daftar paket.
     */
    public static function paket(): array
    {
        $isi = SimrsClient::ambil('/website/paket');

        // Yang tidak aktif tidak ditawarkan — memilihnya hanya berujung
        // penolakan saat unggah.
        return array_values(array_filter($isi,
            static fn ($p) => !empty($p['aktif_simrs'])));
    }

    public static function unggah(array $sesi, array $in): array
    {
        $peserta = $in['peserta'] ?? [];
        if (!is_array($peserta) || count($peserta) === 0) {
            throw HttpException::validasi(['peserta' => 'Daftar peserta kosong.']);
        }
        if (count($peserta) > self::MAKS_PESERTA) {
            throw HttpException::validasi(['peserta' => 'Maksimal ' . self::MAKS_PESERTA
                . ' peserta per unggahan. Bagi menjadi beberapa berkas.']);
        }

        $r = self::panggil('/website/perusahaan/mcu/unggah', [
            'perusahaan_id' => $sesi['perusahaan_id'],
            'diunggah_oleh' => $sesi['email'],
            'tgl_mcu'       => trim((string) ($in['tgl_mcu'] ?? '')),
            'id_paket'      => (int) ($in['id_paket'] ?? 0),
            'catatan'       => trim((string) ($in['catatan'] ?? '')),
            'peserta'       => array_map(static fn ($p) => [
                'baris'         => isset($p['baris']) ? (int) $p['baris'] : null,
                'nama'          => (string) ($p['nama'] ?? ''),
                'nik'           => (string) ($p['nik'] ?? ''),
                'tgl_lahir'     => (string) ($p['tgl_lahir'] ?? ''),
                'alamat'        => (string) ($p['alamat'] ?? ''),
                'jenis_kelamin' => (string) ($p['jenis_kelamin'] ?? ''),
            ], array_values($peserta)),
        ]);

        // 422, bukan 401: ini penolakan atas ISI unggahan. Menjawab 401 akan
        // membuat portal menganggap sesinya mati dan mengeluarkan pengguna
        // hanya karena salah mengisi tanggal.
        if (empty($r['ok'])) throw HttpException::validasi(['unggahan' => $r['pesan'] ?: 'Unggahan ditolak SIMRS.']);
        return $r['data'] + ['pesan' => $r['pesan']];
    }

    public static function daftar(array $sesi, array $f = []): array
    {
        $r = self::panggil('/website/perusahaan/mcu/daftar', [
            'perusahaan_id' => $sesi['perusahaan_id'],
            'status'        => $f['status'] ?? null,
            'dari'          => $f['dari'] ?? null,
            'sampai'        => $f['sampai'] ?? null,
        ]);
        if (empty($r['ok'])) throw new HttpException(502, $r['pesan'] ?: 'SIMRS tidak menjawab.');

        return array_map(static fn ($b) => self::saringBatch((array) $b), $r['data']);
    }

    public static function detail(array $sesi, string $noBatch): array
    {
        if ($noBatch === '') throw HttpException::validasi(['no_batch' => 'Nomor batch wajib diisi.']);

        $r = self::panggil('/website/perusahaan/mcu/detail', [
            'perusahaan_id' => $sesi['perusahaan_id'],
            'no_batch'      => $noBatch,
        ]);
        if (empty($r['ok'])) throw new HttpException(404, $r['pesan'] ?: 'Batch tidak ditemukan.');

        return self::saring($r['data']);
    }

    public static function batal(array $sesi, string $noBatch, string $alasan): array
    {
        if ($noBatch === '') throw HttpException::validasi(['no_batch' => 'Nomor batch wajib diisi.']);

        $r = self::panggil('/website/perusahaan/mcu/batal', [
            'perusahaan_id' => $sesi['perusahaan_id'],
            'no_batch'      => $noBatch,
            'alasan'        => $alasan,
            'oleh'          => $sesi['email'],
        ]);
        if (empty($r['ok'])) throw HttpException::validasi(['batal' => $r['pesan'] ?: 'Pembatalan ditolak.']);
        return ['pesan' => $r['pesan']];
    }

    /* ================================================================
       Hasil MCU

       Yang dikirim SIMRS sudah dibatasi di sana: hanya peserta yang masuk
       lewat batch perusahaan ini, hanya hasil yang sudah divalidasi, tanpa
       No. RM dan tanpa riwayat medis. Di sini tidak ada penyaringan tambahan
       supaya tidak ada dua tempat yang mengaku memegang batasnya — kalau
       batasnya berubah, ia berubah di satu tempat.
       ================================================================ */

    public static function hasil(array $sesi, array $f = []): array
    {
        $r = self::panggil('/website/perusahaan/mcu/hasil', [
            'perusahaan_id' => $sesi['perusahaan_id'],
            'no_batch'      => $f['no_batch'] ?? null,
            'dari'          => $f['dari'] ?? null,
            'sampai'        => $f['sampai'] ?? null,
            'cari'          => $f['cari'] ?? null,
        ]);
        if (empty($r['ok'])) throw new HttpException(502, $r['pesan'] ?: 'SIMRS tidak menjawab.');
        return $r['data'];
    }

    public static function hasilDetail(array $sesi, int $idPeserta): array
    {
        if ($idPeserta <= 0) throw HttpException::validasi(['id_peserta' => 'Peserta wajib dipilih.']);

        $r = self::panggil('/website/perusahaan/mcu/hasil/detail', [
            'perusahaan_id' => $sesi['perusahaan_id'],
            'id_peserta'    => $idPeserta,
        ]);
        if (empty($r['ok'])) throw new HttpException(404, $r['pesan'] ?: 'Hasil belum tersedia.');
        return $r['data'];
    }

    /** Angka agregat untuk dasbor. Tidak memuat nama orang. */
    public static function ringkas(array $sesi, array $f = []): array
    {
        $r = self::panggil('/website/perusahaan/mcu/ringkas', [
            'perusahaan_id' => $sesi['perusahaan_id'],
            'dari'          => $f['dari'] ?? null,
            'sampai'        => $f['sampai'] ?? null,
        ]);
        if (empty($r['ok'])) throw new HttpException(502, $r['pesan'] ?: 'SIMRS tidak menjawab.');
        return $r['data'];
    }

    /**
     * Buang jejak kerja internal klinik sebelum dikirim ke perusahaan.
     *
     * Perusahaan berhak tahu status pesertanya sendiri, bukan siapa petugas
     * yang memverifikasi, kandidat rekam medik mana yang sempat dipertimbangkan,
     * atau alasan teknis pencocokannya. Kandidat khususnya: isinya nama,
     * tanggal lahir, dan NIK pasien LAIN yang kebetulan mirip.
     */
    private static function saring(array $d): array
    {
        $buang = ['kandidat', 'alasan_cocok', 'no_mr_usulan', 'diverifikasi_oleh', 'diverifikasi_at'];

        $d['peserta'] = array_map(static function ($p) use ($buang) {
            $p = (array) $p;
            foreach ($buang as $k) unset($p[$k]);
            // "RAGU" adalah istilah kerja petugas; bagi perusahaan artinya
            // sederhana: datanya perlu dipastikan klinik.
            if (($p['cocok'] ?? '') === 'RAGU') $p['cocok'] = 'DIPERIKSA';
            return $p;
        }, (array) ($d['peserta'] ?? []));

        $d['batch'] = self::saringBatch((array) ($d['batch'] ?? []));

        return $d;
    }

    /** Nama petugas klinik bukan urusan perusahaan — dibuang dari batch. */
    private static function saringBatch(array $b): array
    {
        unset($b['diproses_oleh']);
        return $b;
    }
}
