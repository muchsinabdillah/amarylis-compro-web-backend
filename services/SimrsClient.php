<?php
declare(strict_types=1);

namespace Services;

use Core\Env;
use Core\HttpException;

/**
 * Satu-satunya jalan website menuju SIMRS.
 *
 * Website TIDAK menyambung ke basis data SIMRS. Ia memanggil beberapa titik
 * akhir baca-saja di API SIMRS, dan itu satu-satunya tautan di antara
 * keduanya. Akibatnya:
 *
 *   - Website dapat dipindah ke server mana pun. Yang perlu diikutkan hanya
 *     satu alamat dan satu kunci, bukan kredensial basis data.
 *   - Pencadangan dan pemulihan keduanya berdiri sendiri. Memulihkan SIMRS
 *     tidak menimpa isi website, dan sebaliknya.
 *   - Bila SIMRS mati, halaman publik tetap tayang — situs membaca salinannya
 *     sendiri, dan hanya tombol "Sinkronkan" di CMS yang gagal.
 *
 * Kuncinya statis dan tidak berumur, jadi tempatnya hanya di .env — tidak
 * pernah di dalam kode dan tidak pernah masuk git.
 */
final class SimrsClient
{
    /** Batas waktu. Sinkronisasi boleh lambat; halaman publik tidak boleh menunggu. */
    private const TIMEOUT_DETIK = 20;

    public static function terpasang(): bool
    {
        $url = (string) Env::get('SIMRS_API_URL', '');
        $key = (string) Env::get('SIMRS_API_KEY', '');
        return $url !== '' && strlen($key) >= 32;
    }

    /**
     * Ambil satu sumber daya dari SIMRS.
     *
     * @return array<int|string,mixed>
     */
    public static function ambil(string $jalur): array
    {
        if (!self::terpasang()) {
            throw new HttpException(503,
                'Integrasi SIMRS belum dikonfigurasi. Isi SIMRS_API_URL dan SIMRS_API_KEY pada .env.');
        }

        $url = rtrim((string) Env::get('SIMRS_API_URL'), '/') . '/' . ltrim($jalur, '/');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_DETIK,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'X-Api-Key: ' . (string) Env::get('SIMRS_API_KEY'),
            ],
            // Pengalihan tidak diikuti: kunci API tidak boleh ikut terkirim ke
            // alamat lain yang ditunjuk jawaban server.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => Env::bool('SIMRS_API_VERIFY_SSL', true),
            CURLOPT_SSL_VERIFYHOST => Env::bool('SIMRS_API_VERIFY_SSL', true) ? 2 : 0,
        ]);

        $isi    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $galat  = curl_error($ch);
        curl_close($ch);

        if ($isi === false) {
            throw new HttpException(502,
                'Tidak dapat menghubungi SIMRS: ' . ($galat !== '' ? $galat : 'sambungan gagal.'));
        }
        if ($status === 401) {
            throw new HttpException(502,
                'Kunci API SIMRS ditolak. Periksa SIMRS_API_KEY dan WEBSITE_SYNC_KEY.');
        }
        if ($status >= 400) {
            throw new HttpException(502, 'SIMRS menjawab dengan status ' . $status . '.');
        }

        $json = json_decode((string) $isi, true);
        if (!is_array($json) || !array_key_exists('data', $json)) {
            throw new HttpException(502, 'Jawaban SIMRS tidak dikenali.');
        }
        if (($json['status'] ?? true) === false) {
            throw new HttpException(502, (string) ($json['message'] ?? 'SIMRS menolak permintaan.'));
        }

        return is_array($json['data']) ? $json['data'] : [];
    }

    /**
     * TULIS ke SIMRS (mis. buat booking). Best-effort: TIDAK melempar untuk
     * penolakan/kegagalan bisnis — mengembalikan {ok, pesan, data} agar pemanggil
     * bisa menangani dengan tenang (booking tetap tercatat di website walau SIMRS
     * menolak). Hanya X-Api-Key; endpoint SIMRS-nya bermiddleware `website.kunci:tulis`.
     *
     * @param array<string,mixed> $data
     * @return array{ok:bool,pesan:string,data:array<int|string,mixed>}
     */
    public static function kirim(string $jalur, array $data): array
    {
        if (!self::terpasang()) {
            return ['ok' => false, 'pesan' => 'Integrasi SIMRS belum dikonfigurasi.', 'data' => []];
        }
        $url = rtrim((string) Env::get('SIMRS_API_URL'), '/') . '/' . ltrim($jalur, '/');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_DETIK,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Api-Key: ' . (string) Env::get('SIMRS_API_KEY'),
            ],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => Env::bool('SIMRS_API_VERIFY_SSL', true),
            CURLOPT_SSL_VERIFYHOST => Env::bool('SIMRS_API_VERIFY_SSL', true) ? 2 : 0,
        ]);
        $isi    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $galat  = curl_error($ch);
        curl_close($ch);

        if ($isi === false) {
            return ['ok' => false, 'pesan' => 'Tidak dapat menghubungi SIMRS: ' . ($galat !== '' ? $galat : 'sambungan gagal'), 'data' => []];
        }
        $json = json_decode((string) $isi, true);
        if (!is_array($json)) {
            return ['ok' => false, 'pesan' => 'Jawaban SIMRS tidak dikenali (HTTP ' . $status . ').', 'data' => []];
        }
        $sukses = (($json['status'] ?? $json['success'] ?? false) == true) && $status < 400;
        return [
            'ok'    => (bool) $sukses,
            'pesan' => (string) ($json['message'] ?? ($sukses ? 'OK' : 'SIMRS menolak permintaan.')),
            'data'  => is_array($json['data'] ?? null) ? $json['data'] : [],
        ];
    }

    /**
     * Profil klinik, dengan salinan lokal.
     *
     * Halaman publik memanggil ini pada setiap kunjungan, jadi ia tidak boleh
     * menempel pada ketersediaan SIMRS. Jawabannya disimpan satu jam; bila
     * SIMRS sedang tidak terjangkau, salinan lama tetap dipakai — nama dan
     * alamat klinik jarang berubah, dan menampilkan yang kemarin jauh lebih
     * baik daripada halaman kosong.
     */
    public static function profil(): array
    {
        $berkas = self::berkasCache('profil');
        $umur   = is_file($berkas) ? time() - (int) filemtime($berkas) : PHP_INT_MAX;

        if ($umur < 3600) {
            $isi = json_decode((string) @file_get_contents($berkas), true);
            if (is_array($isi)) {
                return $isi;
            }
        }

        /*
         * Bila panggilan terakhir gagal, jangan dicoba lagi selama lima menit.
         *
         * Tanpa penahan ini, SIMRS yang mati membuat SETIAP pengunjung
         * menunggu batas waktu sambungan sebelum halamannya tampil. Satu
         * layanan yang padam lalu menyeret situs ikut terasa padam — dan
         * beban percobaan sambung yang bertubi-tubi justru mempersulit SIMRS
         * pulih.
         */
        $penanda = self::berkasCache('profil.gagal');
        if (is_file($penanda) && time() - (int) filemtime($penanda) < 300) {
            $isi = is_file($berkas)
                ? json_decode((string) @file_get_contents($berkas), true) : null;
            return is_array($isi) ? $isi : self::profilKosong();
        }

        try {
            $profil = self::ambil('website/profil');
            @file_put_contents($berkas, json_encode($profil, JSON_UNESCAPED_UNICODE), LOCK_EX);
            if (is_file($penanda)) {
                @unlink($penanda);
            }
            return $profil;
        } catch (\Throwable $e) {
            @touch($penanda);
            // Salinan basi lebih baik daripada tidak ada sama sekali.
            $isi = is_file($berkas)
                ? json_decode((string) @file_get_contents($berkas), true) : null;

            return is_array($isi) ? $isi : self::profilKosong();
        }
    }

    /** @return array<string,string> */
    private static function profilKosong(): array
    {
        return [
            'nama' => '', 'nama_singkat' => '', 'alamat' => '', 'kota' => '',
            'kodepos' => '', 'telepon' => '', 'email' => '', 'website' => '',
        ];
    }

    /** Buang salinan lokal, dipakai setelah sinkronisasi manual. */
    public static function segarkanCache(): void
    {
        foreach (['profil', 'profil.gagal'] as $nama) {
            $berkas = self::berkasCache($nama);
            if (is_file($berkas)) {
                @unlink($berkas);
            }
        }
    }

    private static function berkasCache(string $nama): string
    {
        $dir = BASE_PATH . '/storage/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/simrs_' . $nama . '.json';
    }
}
