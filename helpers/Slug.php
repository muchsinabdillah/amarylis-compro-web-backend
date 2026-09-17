<?php
declare(strict_types=1);

namespace Helpers;

use Core\Database;

/**
 * Pembuat slug.
 *
 * NAMA TABEL YANG DIKIRIM KE SINI TIDAK BOLEH BERASAL DARI MASUKAN PENGGUNA;
 * ia disisipkan langsung ke kueri karena nama tabel tidak dapat diikat
 * sebagai parameter. Seluruh pemanggil memakai tetapan di dalam kelasnya.
 */
final class Slug
{
    public static function dasar(string $teks): string
    {
        $s = strtolower(trim($teks));
        $s = str_replace(['&'], ' dan ', $s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim(preg_replace('/-+/', '-', $s) ?? '', '-');

        return $s === '' ? 'item' : mb_substr($s, 0, 120);
    }

    /**
     * Slug yang belum dipakai pada tabel tersebut.
     *
     * `$syarat` mempersempit lingkup keunikan — kategori misalnya unik per
     * tipe, sehingga "umum" boleh ada baik untuk artikel maupun layanan.
     */
    public static function unik(
        string $tabel, string $dasar, ?int $kecualiId = null, array $syarat = []
    ): string {
        $slug   = self::dasar($dasar);
        $asal   = $slug;
        $n      = 1;

        $where  = ['lower(slug) = :slug', '(:id::bigint IS NULL OR id <> :id)'];
        $params = [':id' => $kecualiId];
        foreach ($syarat as $kol => $nilai) {
            $where[]           = $kol . ' = :s_' . $kol;
            $params[':s_' . $kol] = $nilai;
        }
        $sql = "SELECT 1 FROM {$tabel} WHERE " . implode(' AND ', $where) . ' LIMIT 1';

        while (Database::nilai($sql, $params + [':slug' => $slug]) !== null) {
            $slug = $asal . '-' . (++$n);
        }
        return $slug;
    }
}
