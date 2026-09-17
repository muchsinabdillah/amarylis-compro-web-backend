<?php
declare(strict_types=1);

namespace Controllers;

use Core\Database;
use Core\HttpException;
use Core\Request;
use Core\Response;
use Helpers\Html;
use Helpers\Validator;
use Repositories\ContentRepository;
use Services\SettingsService;
use Services\SimrsClient;
use Services\SyncService;

/**
 * Titik akhir CMS.
 *
 * Seluruh rute di sini sudah melewati middleware auth dan izin; controller
 * tidak memeriksa ulang wewenang. Pemeriksaan di dua tempat terdengar aman
 * tetapi justru berbahaya: keduanya bisa berbeda, dan yang longgar yang
 * akan menang.
 */
final class AdminController
{
    /** Kolom yang boleh ditulis per modul — daftar putih, bukan apa pun yang dikirim. */
    private const KOLOM = [
        'articles'  => ['category_id','judul','excerpt','konten','thumbnail','status',
                        'published_at','is_featured','seo_title','seo_description','og_image'],
        'news'      => ['category_id','judul','excerpt','konten','thumbnail','event_date','lokasi',
                        'status','published_at','is_featured','seo_title','seo_description','og_image'],
        'videos'    => ['category_id','judul','deskripsi','video_type','video_url','embed_url',
                        'thumbnail','durasi','status','published_at','is_featured','urutan',
                        'seo_title','seo_description','og_image'],
        'services'  => ['category_id','kategori_tarif','nama','ringkas','deskripsi','image','icon',
                        'harga','jenis_harga','whatsapp_message','is_featured','status','urutan',
                        'seo_title','seo_description','og_image'],
        'mcu'       => ['paket_id','category_id','nama','ringkas','deskripsi','thumbnail','harga',
                        'jenis_harga','durasi','persiapan','whatsapp_message','is_featured','status',
                        'urutan','seo_title','seo_description','og_image'],
        'homecare'  => ['paket_id','category_id','nama','ringkas','deskripsi','image','harga',
                        'jenis_harga','area_layanan','durasi','whatsapp_message','is_featured',
                        'status','urutan','seo_title','seo_description','og_image'],
    ];

    private const HTML_KAYA = ['konten', 'deskripsi', 'persiapan'];

    // =================================================================
    //  Dasbor
    // =================================================================
    public function dasbor(Request $req): void
    {
        $sinkron = Database::semua(
            'SELECT DISTINCT ON (modul) modul, mulai_at, selesai_at, status,
                    baru, diperbarui, dilewati, hilang
               FROM sync_runs ORDER BY modul, id DESC');

        Response::sukses([
            'konten' => ContentRepository::ringkasan(),
            'dokter' => Database::satu(
                'SELECT count(*) FILTER (WHERE is_published) AS tayang,
                        count(*) AS total,
                        count(*) FILTER (WHERE sync_status = :h) AS hilang
                   FROM doctors', [':h' => 'hilang_di_simrs']),
            'sinkron_terakhir' => $sinkron,
            'terbaru' => [
                'articles' => $this->terbaru('articles'),
                'news'     => $this->terbaru('news'),
                'videos'   => $this->terbaru('videos'),
            ],
            // Pengaturan yang masih menunggu diisi ditampilkan di dasbor,
            // bukan disembunyikan di halaman pengaturan yang jarang dibuka.
            'perlu_dilengkapi' => Database::semua(
                "SELECT key, label FROM settings
                  WHERE coalesce(value,'') = '' OR value LIKE '%BELUM TERSEDIA%'
                  ORDER BY grup, urutan"),
        ]);
    }

    private function terbaru(string $modul): array
    {
        $m     = ContentRepository::modul($modul);
        $judul = in_array($m['tabel'], ['articles','news','videos'], true) ? 'judul' : 'nama';
        return Database::semua(
            "SELECT id, slug, {$judul} AS judul, status, updated_at
               FROM {$m['tabel']} ORDER BY updated_at DESC LIMIT 5");
    }

    // =================================================================
    //  Konten
    // =================================================================
    public function daftar(Request $req, array $args): void
    {
        $m     = ContentRepository::modul($args['modul']);
        $tabel = $m['tabel'];
        $judul = in_array($tabel, ['articles','news','videos'], true) ? 'judul' : 'nama';
        [$halaman, $perHalaman, $offset] = $req->paginasi(20, 100);

        $where  = ['1=1'];
        $params = [];
        if ($s = $req->str('status')) {
            $where[] = 't.status = :status';
            $params[':status'] = $s;
        }
        if ($q = $req->str('cari')) {
            $where[] = "t.{$judul} ILIKE :cari";
            $params[':cari'] = '%' . $q . '%';
        }
        $w = implode(' AND ', $where);

        $total = (int) Database::nilai("SELECT count(*) FROM {$tabel} t WHERE {$w}", $params);
        $baris = Database::semua(
            "SELECT t.id, t.slug, t.{$judul} AS judul, t.status, t.is_featured,
                    t.view_count, t.updated_at, c.nama AS kategori
               FROM {$tabel} t LEFT JOIN categories c ON c.id = t.category_id
              WHERE {$w} ORDER BY t.updated_at DESC LIMIT :l OFFSET :o",
            $params + [':l' => $perHalaman, ':o' => $offset]);

        Response::halaman($baris, $total, $halaman, $perHalaman);
    }

    public function ambil(Request $req, array $args): void
    {
        $m = ContentRepository::modul($args['modul']);
        $b = Database::satu("SELECT * FROM {$m['tabel']} WHERE id = :i", [':i' => (int) $args['id']]);
        if ($b === null) {
            throw HttpException::takDitemukan();
        }
        if ($args['modul'] === 'mcu') {
            $b['manfaat'] = Database::semua(
                'SELECT id, teks, keterangan, urutan FROM mcu_benefits
                  WHERE package_id = :i ORDER BY urutan, id', [':i' => $b['id']]);
        }
        if ($args['modul'] === 'news') {
            $b['galeri'] = Database::semua(
                'SELECT id, image, caption, urutan FROM news_gallery
                  WHERE news_id = :i ORDER BY urutan, id', [':i' => $b['id']]);
        }
        Response::sukses($b);
    }

    public function simpan(Request $req, array $args): void
    {
        $modul = $args['modul'];
        $m     = ContentRepository::modul($modul);
        $tabel = $m['tabel'];
        $id    = isset($args['id']) ? (int) $args['id'] : null;

        $kolomJudul = in_array($tabel, ['articles','news','videos'], true) ? 'judul' : 'nama';

        $v = Validator::untuk($req)
            ->teks($kolomJudul, $id === null, 220)
            ->pilihan('status', ['draft','published','archived'], false, 'draft');
        $bersih = $v->selesai();

        $data = [];
        foreach (self::KOLOM[$modul] as $kol) {
            if (!$req->ada($kol)) {
                continue;
            }
            $data[$kol] = in_array($kol, self::HTML_KAYA, true)
                ? Html::bersihkan($req->str($kol))          // isi editor selalu dibersihkan
                : $req->str($kol);
        }
        /*
         * Status hanya ikut berubah bila memang dikirim.
         *
         * Validator memberi nilai bawaan 'draft' untuk isian yang tidak wajib,
         * dan menerapkannya di sini akan menurunkan artikel yang sudah tayang
         * menjadi draf setiap kali seseorang menyunting satu paragraf saja —
         * kegagalan yang tidak menampilkan galat apa pun, hanya membuat
         * halaman hilang dari situs.
         */
        if ($req->ada('status')) {
            $data['status'] = $bersih['status'];
        } elseif ($id === null) {
            $data['status'] = $bersih['status'] ?? 'draft';
        }
        foreach (['is_featured'] as $kol) {
            if ($req->ada($kol)) {
                $data[$kol] = $req->bool($kol) ? 'true' : 'false';
            }
        }
        foreach (['category_id','urutan','paket_id'] as $kol) {
            if ($req->ada($kol)) {
                $data[$kol] = $req->int($kol);
            }
        }

        $berwaktu = in_array($tabel, ['articles', 'news', 'videos'], true);

        /*
         * Tanggal terbit diisi sekali, saat pertama kali tayang.
         *
         * Mengisinya ulang setiap penyimpanan akan melempar artikel lama ke
         * puncak daftar hanya karena satu salah ketik diperbaiki — dan urutan
         * itulah yang dilihat pengunjung sebagai "terbaru".
         */
        if ($berwaktu && ($data['status'] ?? '') === 'published' && !$req->ada('published_at')
            && ($id === null || Database::nilai(
                "SELECT published_at FROM {$tabel} WHERE id = :i", [':i' => $id]) === null)) {
            $data['published_at'] = date('c');
        }

        if (isset($data['konten'])) {
            if ($tabel === 'articles') {
                $data['reading_minutes'] = Html::menitBaca($data['konten']);
            }
            // Ringkasan otomatis hanya mengisi yang masih kosong; ringkasan
            // tulisan redaksi tidak ditimpa saat isinya disunting.
            $adaRingkas = $id !== null && trim((string) Database::nilai(
                "SELECT excerpt FROM {$tabel} WHERE id = :i", [':i' => $id])) !== '';

            if (!$req->ada('excerpt') && !$adaRingkas) {
                $data['excerpt'] = Html::keTeks($data['konten'], 200);
            }
        }

        if ($id === null) {
            $data['slug'] = ContentRepository::slugUnik(
                $modul, $req->str('slug') ?? $bersih[$kolomJudul] ?? 'item');
            $data[$kolomJudul] = $bersih[$kolomJudul];
            if (in_array($tabel, ['articles','news','videos'], true)) {
                $data['author_id'] = $req->userId();
            }

            $kolom = array_keys($data);
            $ph    = array_map(static fn($k) => ':' . $k, $kolom);
            Database::jalankan(
                "INSERT INTO {$tabel} (" . implode(',', $kolom) . ') VALUES (' . implode(',', $ph) . ')',
                array_combine($ph, array_values($data)));
            $id = (int) Database::nilai(
                "SELECT currval(pg_get_serial_sequence('webcompro.{$tabel}','id'))");
            Response::sukses(['id' => $id], [], 201);
            return;
        }

        if ($req->ada('slug')) {
            $data['slug'] = ContentRepository::slugUnik($modul, (string) $req->str('slug'), $id);
        }
        if ($data === []) {
            Response::sukses(['id' => $id]);
            return;
        }

        $set = [];
        $params = [':id' => $id];
        foreach ($data as $k => $val) {
            $set[] = "{$k} = :{$k}";
            $params[":{$k}"] = $val;
        }
        Database::jalankan(
            "UPDATE {$tabel} SET " . implode(', ', $set) . ' WHERE id = :id', $params);

        Response::sukses(['id' => $id]);
    }

    public function hapus(Request $req, array $args): void
    {
        $m = ContentRepository::modul($args['modul']);
        $n = Database::jalankan("DELETE FROM {$m['tabel']} WHERE id = :i", [':i' => (int) $args['id']]);
        if ($n === 0) {
            throw HttpException::takDitemukan();
        }
        Response::takAdaIsi();
    }

    // =================================================================
    //  Pengaturan
    // =================================================================
    public function pengaturan(Request $req): void
    {
        Response::sukses([
            'settings' => SettingsService::untukAdmin(),
            'klinik'   => SettingsService::profilKlinik(),   // hanya baca, dari SIMRS
        ]);
    }

    /**
     * Daftar paket SINKRON dari SIMRS (service_packages) untuk dipilih di form
     * konten — supaya paket MCU/homecare tak diketik ulang. ?jenis= opsional.
     */
    public function paketSimrs(Request $req): void
    {
        $jenis = strtolower(trim((string) $req->str('jenis')));
        $p = [];
        $where = "WHERE aktif_simrs = true AND sync_status <> 'hilang_di_simrs'";
        if (in_array($jenis, ['mcu', 'homecare', 'lainnya'], true)) {
            $where .= ' AND lower(coalesce(jenis, \'lainnya\')) = :j';
            $p[':j'] = $jenis;
        }
        Response::sukses(Database::semua(
            "SELECT id, simrs_id, kode, nama, harga_simrs, jml_kunjungan,
                    lower(coalesce(jenis,'lainnya')) AS jenis
               FROM service_packages {$where} ORDER BY nama", $p));
    }

    /** Detail satu paket + isinya dari SIMRS (untuk isi-otomatis form). */
    public function paketSimrsDetail(Request $req, array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw HttpException::validasi(['id' => 'ID paket tidak sah.']);
        }
        Response::sukses(SimrsClient::ambil('website/paket/' . $id));
    }

    public function simpanPengaturan(Request $req): void
    {
        $isi = $req->arr('settings');
        if ($isi === []) {
            throw HttpException::validasi(['settings' => 'Tidak ada yang dikirim.']);
        }

        $sah = array_column(Database::semua('SELECT key FROM settings'), 'key');
        foreach ($isi as $kunci => $nilai) {
            if (!in_array($kunci, $sah, true)) {
                continue;                    // kunci asing diabaikan, bukan dibuat
            }
            SettingsService::simpan($kunci, is_scalar($nilai) ? (string) $nilai : null,
                $req->namaPengguna());
        }
        Response::sukses(['tersimpan' => true]);
    }

    // =================================================================
    //  Dokter (hasil sinkronisasi + suntingan redaksi)
    // =================================================================
    public function daftarDokter(Request $req): void
    {
        Response::sukses(array_map(
            static function (array $d): array {
                // field_locks disimpan sebagai jsonb; PDO menyerahkannya
                // sebagai teks, dan CMS membutuhkannya sebagai daftar.
                $d['field_locks'] = json_decode((string) $d['field_locks'], true) ?: [];
                $d['is_published'] = (bool) $d['is_published'];
                $d['aktif_simrs']  = (bool) $d['aktif_simrs'];
                return $d;
            },
            Database::semua(
                'SELECT id, simrs_id, nama, gelar, spesialis, foto, bio, pendidikan,
                        pengalaman, is_published, urutan, aktif_simrs, sync_status,
                        synced_at, field_locks
                   FROM doctors ORDER BY urutan, nama')));
    }

    public function simpanDokter(Request $req, array $args): void
    {
        $id = (int) $args['id'];
        $ada = Database::satu('SELECT id FROM doctors WHERE id = :i', [':i' => $id]);
        if ($ada === null) {
            throw HttpException::takDitemukan();
        }

        $data = [];
        foreach (['nama','gelar','spesialis','foto','bio','pendidikan','pengalaman'] as $k) {
            if ($req->ada($k)) {
                $data[$k] = in_array($k, ['bio','pendidikan','pengalaman'], true)
                    ? Html::bersihkan($req->str($k)) : $req->str($k);
            }
        }
        if ($req->ada('is_published')) {
            $data['is_published'] = $req->bool('is_published') ? 'true' : 'false';
        }
        if ($req->ada('urutan')) {
            $data['urutan'] = $req->int('urutan', 0);
        }

        /*
         * Kolom yang berasal dari SIMRS dan diubah di sini otomatis dikunci.
         * Menyunting nama lalu melihatnya kembali seperti semula setelah
         * sinkronisasi berikutnya adalah cara tercepat membuat orang berhenti
         * memakai CMS — dan mereka tidak akan tahu penyebabnya.
         */
        if ($req->ada('field_locks')) {
            $data['field_locks'] = json_encode(
                array_values(array_filter($req->arr('field_locks'), 'is_string')));
        } else {
            $kunciBaru = array_values(array_intersect(
                array_keys($data), ['nama','gelar','spesialis','no_sip']));
            if ($kunciBaru !== []) {
                $lama = json_decode((string) Database::nilai(
                    'SELECT field_locks FROM doctors WHERE id = :i', [':i' => $id]), true) ?: [];
                $data['field_locks'] = json_encode(
                    array_values(array_unique(array_merge($lama, $kunciBaru))));
            }
        }

        if ($data === []) {
            Response::sukses(['id' => $id]);
            return;
        }

        $set = [];
        $params = [':id' => $id];
        foreach ($data as $k => $v) {
            $set[] = $k === 'field_locks' ? "{$k} = :{$k}::jsonb" : "{$k} = :{$k}";
            $params[":{$k}"] = $v;
        }
        Database::jalankan('UPDATE doctors SET ' . implode(', ', $set) . ' WHERE id = :id', $params);

        Response::sukses(['id' => $id]);
    }

    // =================================================================
    //  Sinkronisasi
    // =================================================================
    public function sinkron(Request $req): void
    {
        $modul = $req->str('modul', 'semua');
        $svc   = new SyncService();

        $hasil = match ($modul) {
            'semua'     => $svc->semua($req->namaPengguna()),
            'doctors'   => ['doctors'   => $svc->dokter($req->namaPengguna())],
            'schedules' => ['schedules' => $svc->jadwal($req->namaPengguna())],
            'packages'  => ['packages'  => $svc->paket($req->namaPengguna())],
            default     => throw HttpException::validasi(
                ['modul' => 'Pilih: semua, doctors, schedules, atau packages.']),
        };

        Response::sukses($hasil);
    }

    public function riwayatSinkron(Request $req): void
    {
        Response::sukses(Database::semua(
            'SELECT id, modul, dipicu_oleh, mulai_at, selesai_at, status,
                    baru, diperbarui, dilewati, hilang, pesan
               FROM sync_runs ORDER BY id DESC LIMIT 50'));
    }
}
