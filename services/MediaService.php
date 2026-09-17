<?php
declare(strict_types=1);

namespace Services;

use Core\Database;
use Core\Env;
use Core\HttpException;

/**
 * Pustaka media.
 *
 * Gambar tidak disimpan apa adanya. Berkas yang diunggah dibuka ulang oleh
 * GD lalu ditulis kembali dari data pikselnya, sehingga apa pun yang
 * menumpang di dalamnya — potongan PHP di komentar EXIF, muatan polyglot
 * yang sah sebagai gambar sekaligus sebagai skrip — tidak ikut tersimpan.
 * Memeriksa ekstensi dan tipe MIME saja tidak cukup: keduanya berasal dari
 * pengunggah, dan keduanya mudah dipalsukan.
 */
final class MediaService
{
    private const TIPE = [
        'image/jpeg' => ['jpg',  IMAGETYPE_JPEG],
        'image/png'  => ['png',  IMAGETYPE_PNG],
        'image/webp' => ['webp', IMAGETYPE_WEBP],
    ];

    /** Sisi terpanjang gambar yang disimpan. Foto kamera 4000px hanya memperlambat halaman. */
    private const SISI_MAKS = 1600;

    public static function unggah(array $berkas, ?string $alt, int $userId): array
    {
        self::periksaGalatUnggah($berkas);

        $batas = Env::int('UPLOAD_MAX_BYTES', 3145728);
        if ($berkas['size'] > $batas) {
            throw HttpException::validasi(['file' => sprintf(
                'Ukuran berkas %s melebihi batas %s.',
                self::ukuran((int) $berkas['size']), self::ukuran($batas))]);
        }

        // Tipe ditentukan dari isi berkas, bukan dari nama atau dari header
        // yang dikirim peramban.
        $info = @getimagesize($berkas['tmp_name']);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';

        $diizinkan = array_map('trim', explode(',',
            (string) Env::get('UPLOAD_MIME', 'image/jpeg,image/png,image/webp')));

        if (!isset(self::TIPE[$mime]) || !in_array($mime, $diizinkan, true)) {
            throw HttpException::validasi(
                ['file' => 'Hanya gambar JPG, PNG, atau WEBP yang dapat diunggah.']);
        }

        [$ext] = self::TIPE[$mime];

        $dir = self::direktori();
        $sub = date('Y/m');
        if (!is_dir($dir . '/' . $sub) && !@mkdir($dir . '/' . $sub, 0755, true) && !is_dir($dir . '/' . $sub)) {
            throw new HttpException(500, 'Folder unggahan tidak dapat dibuat.');
        }

        // Nama berkas dibuat sendiri. Nama asli tidak pernah dipakai sebagai
        // nama simpan — di situlah "../" dan ".php.jpg" biasanya menyelinap.
        $nama    = bin2hex(random_bytes(16)) . '.' . $ext;
        $relatif = $sub . '/' . $nama;
        $tujuan  = $dir . '/' . $relatif;

        [$lebar, $tinggi] = self::tulisUlang($berkas['tmp_name'], $tujuan, $mime);

        Database::jalankan(
            'INSERT INTO media (nama_file, path, mime, ukuran, lebar, tinggi, alt, uploaded_by)
             VALUES (:n, :p, :m, :u, :l, :t, :a, :b)',
            [
                ':n' => mb_substr(basename((string) $berkas['name']), 0, 255),
                ':p' => $relatif,
                ':m' => $mime,
                ':u' => filesize($tujuan) ?: 0,
                ':l' => $lebar,
                ':t' => $tinggi,
                ':a' => $alt !== null ? mb_substr($alt, 0, 255) : null,
                ':b' => $userId,
            ]);

        $id = (int) Database::nilai("SELECT currval(pg_get_serial_sequence('webcompro.media','id'))");

        return self::bentuk(Database::satu(
            'SELECT m.*, u.nama AS pengunggah
               FROM media m LEFT JOIN users u ON u.id = m.uploaded_by
              WHERE m.id = :i', [':i' => $id]) ?? []);
    }

    /** @return array{0:int,1:int} lebar & tinggi hasil simpan */
    private static function tulisUlang(string $sumber, string $tujuan, string $mime): array
    {
        $gambar = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($sumber),
            'image/png'  => @imagecreatefrompng($sumber),
            'image/webp' => @imagecreatefromwebp($sumber),
        };
        if ($gambar === false || $gambar === null) {
            throw HttpException::validasi(['file' => 'Berkas gambar tidak dapat dibaca atau rusak.']);
        }

        $w = imagesx($gambar);
        $h = imagesy($gambar);

        $skala = min(1.0, self::SISI_MAKS / max($w, $h));
        if ($skala < 1.0) {
            $wBaru = (int) round($w * $skala);
            $hBaru = (int) round($h * $skala);
            $kecil = imagecreatetruecolor($wBaru, $hBaru);
            imagealphablending($kecil, false);
            imagesavealpha($kecil, true);
            imagecopyresampled($kecil, $gambar, 0, 0, 0, 0, $wBaru, $hBaru, $w, $h);
            imagedestroy($gambar);
            $gambar = $kecil;
            $w = $wBaru;
            $h = $hBaru;
        } elseif ($mime !== 'image/jpeg') {
            imagealphablending($gambar, false);
            imagesavealpha($gambar, true);
        }

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($gambar, $tujuan, 82),
            'image/png'  => imagepng($gambar, $tujuan, 6),
            'image/webp' => imagewebp($gambar, $tujuan, 82),
        };
        imagedestroy($gambar);

        if (!$ok) {
            throw new HttpException(500, 'Gambar gagal disimpan.');
        }
        return [$w, $h];
    }

    public static function daftar(int $limit, int $offset): array
    {
        $total = (int) Database::nilai('SELECT count(*) FROM media');
        $baris = array_map([self::class, 'bentuk'], Database::semua(
            'SELECT m.*, u.nama AS pengunggah
               FROM media m LEFT JOIN users u ON u.id = m.uploaded_by
              ORDER BY m.id DESC LIMIT :l OFFSET :o',
            [':l' => $limit, ':o' => $offset]));

        return ['baris' => $baris, 'total' => $total];
    }

    public static function ubahAlt(int $id, ?string $alt): void
    {
        $n = Database::jalankan('UPDATE media SET alt = :a WHERE id = :i',
            [':a' => $alt !== null ? mb_substr($alt, 0, 255) : null, ':i' => $id]);
        if ($n === 0) {
            throw HttpException::takDitemukan('Media tidak ditemukan.');
        }
    }

    /**
     * Hapus dari basis data lebih dulu, berkas menyusul.
     *
     * Bila penghapusan berkas gagal, yang tertinggal hanya berkas yatim di
     * disk. Urutan sebaliknya akan meninggalkan baris yang menunjuk gambar
     * yang sudah tiada, dan itu tampak sebagai halaman rusak bagi pengunjung.
     */
    public static function hapus(int $id): void
    {
        $m = Database::satu('SELECT path FROM media WHERE id = :i', [':i' => $id]);
        if ($m === null) {
            throw HttpException::takDitemukan('Media tidak ditemukan.');
        }
        Database::jalankan('DELETE FROM media WHERE id = :i', [':i' => $id]);

        $berkas = self::direktori() . '/' . $m['path'];
        if (is_file($berkas)) {
            @unlink($berkas);
        }
    }

    public static function direktori(): string
    {
        $dir = (string) Env::get('UPLOAD_DIR', 'storage/uploads');
        if (!preg_match('#^([A-Za-z]:[\\\\/]|/)#', $dir)) {
            $dir = BASE_PATH . '/' . ltrim($dir, '/');
        }
        return rtrim(str_replace('\\', '/', $dir), '/');
    }

    private static function bentuk(array $m): array
    {
        if ($m === []) {
            return [];
        }
        return [
            'id'     => (int) $m['id'],
            'nama'   => $m['nama_file'],
            'url'    => '/media/' . $m['path'],
            'mime'   => $m['mime'],
            'ukuran' => (int) $m['ukuran'],
            'lebar'  => $m['lebar'] !== null ? (int) $m['lebar'] : null,
            'tinggi' => $m['tinggi'] !== null ? (int) $m['tinggi'] : null,
            'alt'    => $m['alt'],
            'pengunggah' => $m['pengunggah'] ?? null,
            'created_at' => $m['created_at'],
        ];
    }

    private static function periksaGalatUnggah(array $b): void
    {
        $kode = (int) ($b['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($kode === UPLOAD_ERR_OK) {
            if (!is_uploaded_file((string) $b['tmp_name'])) {
                throw HttpException::validasi(['file' => 'Berkas tidak sah.']);
            }
            return;
        }

        // Pesan yang menyebut penyebabnya; "gagal mengunggah" saja membuat
        // orang mencoba berkas yang sama berulang kali.
        throw HttpException::validasi(['file' => match ($kode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'Berkas melebihi batas ukuran yang diizinkan server.',
            UPLOAD_ERR_PARTIAL   => 'Unggahan terputus. Coba lagi.',
            UPLOAD_ERR_NO_FILE   => 'Tidak ada berkas yang dipilih.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
                'Server tidak dapat menulis berkas sementara.',
            UPLOAD_ERR_EXTENSION => 'Unggahan ditolak oleh ekstensi PHP.',
            default              => 'Berkas gagal diunggah.',
        }]);
    }

    private static function ukuran(int $bita): string
    {
        return $bita >= 1048576
            ? round($bita / 1048576, 1) . ' MB'
            : round($bita / 1024) . ' KB';
    }
}
