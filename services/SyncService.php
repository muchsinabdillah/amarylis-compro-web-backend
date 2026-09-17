<?php
declare(strict_types=1);

namespace Services;

use Core\Database;
use Services\SimrsClient;

/**
 * Sinkronisasi satu arah SIMRS -> website.
 *
 * ARAHNYA HANYA SATU, dan itu bukan sekadar kesepakatan: website tidak punya
 * sambungan apa pun ke basis data SIMRS. Data ditarik lewat lima titik akhir
 * GET di API SIMRS (lihat SimrsClient), dan pintunya menolak setiap metode
 * selain GET. Tidak ada jalan menulis ke SIMRS dari sini, bahkan bila kelas
 * ini kelak salah ditulis.
 *
 * TIGA ATURAN YANG DIPEGANG
 *
 * 1. Baris baru masuk BELUM TAYANG.
 *    Master dokter memuat baris uji dan akun teknis — pada data klinik ini,
 *    dua baris "dr. Radiologi" bergelar "j" berspesialis "i". Menayangkannya
 *    otomatis berarti keduanya muncul di halaman publik, dan tidak ada cara
 *    andal membedakannya dari nama saja.
 *
 * 2. Kolom yang sudah disunting redaksi DILEWATI.
 *    Website kerap memakai nama tampil yang lebih ramah daripada nama di
 *    sistem. Bila sinkronisasi menimpanya, suntingan itu "hilang sendiri"
 *    tanpa sebab yang bisa ditunjuk — dan orang berhenti mempercayai CMS.
 *
 * 3. Yang menghilang di SIMRS DITANDAI, bukan dihapus.
 *    Menghapus barisnya berarti membuang foto dan bio yang sudah dikerjakan,
 *    dan mematikan URL yang mungkin sudah terlanjur dibagikan orang.
 */
final class SyncService
{
    /** Kolom yang boleh ditimpa sinkronisasi bila tidak dikunci redaksi. */
    private const KOLOM_DOKTER = ['nama', 'gelar', 'spesialis', 'no_sip'];
    private const KOLOM_PAKET  = ['kode', 'nama', 'harga_simrs', 'jml_kunjungan', 'jenis'];

    private const HARI = [
        'Senin' => 'Senin', 'Selasa' => 'Selasa', 'Rabu' => 'Rabu', 'Kamis' => 'Kamis',
        'Jumat' => 'Jumat', 'Sabtu' => 'Sabtu', 'Minggu' => 'Minggu',
    ];

    /** Jalankan seluruh modul. @return array<string,array> */
    public function semua(string $dipicuOleh): array
    {
        // Profil klinik ikut disegarkan; orang yang menekan "Sinkronkan"
        // mengharapkan SELURUH data SIMRS diperbarui, bukan sebagiannya
        // sementara nama dan alamat masih dari singgahan sejam lalu.
        SimrsClient::segarkanCache();

        return [
            'doctors'   => $this->dokter($dipicuOleh),
            'schedules' => $this->jadwal($dipicuOleh),
            'packages'  => $this->paket($dipicuOleh),
        ];
    }

    // =================================================================
    //  DOKTER
    // =================================================================
    public function dokter(string $dipicuOleh): array
    {
        $runId = $this->mulaiCatatan('doctors', $dipicuOleh);
        $baru = $diperbarui = $dilewati = 0;

        try {
            $sumber = SimrsClient::ambil('website/dokter');

            Database::transaksi(function () use ($sumber, &$baru, &$diperbarui, &$dilewati) {
                foreach ($sumber as $s) {
                    $ada = Database::satu(
                        'SELECT id, field_locks FROM doctors WHERE simrs_id = :id', [':id' => $s['simrs_id']]);

                    if ($ada === null) {
                        Database::jalankan(
                            'INSERT INTO doctors (simrs_id, nama, gelar, spesialis, no_sip, slug,
                                                  aktif_simrs, is_published, sync_status, synced_at)
                             VALUES (:simrs_id, :nama, :gelar, :spesialis, :no_sip, :slug,
                                     :aktif, false, \'tersinkron\', now())',
                            [
                                ':simrs_id'  => $s['simrs_id'],
                                ':nama'      => $s['nama'] !== '' ? $s['nama'] : 'Tanpa Nama',
                                ':gelar'     => $s['gelar'],
                                ':spesialis' => $s['spesialis'],
                                ':no_sip'    => $s['no_sip'],
                                ':slug'      => $this->slugUnik('doctors', $s['nama'], (string) $s['simrs_id']),
                                ':aktif'     => $s['aktif_simrs'] ? 'true' : 'false',
                            ]);
                        $baru++;
                        continue;
                    }

                    $kunci  = $this->kunciKolom($ada['field_locks']);
                    $ubah   = [];
                    $params = [':id' => $ada['id']];

                    foreach (self::KOLOM_DOKTER as $kol) {
                        if (in_array($kol, $kunci, true)) {
                            $dilewati++;
                            continue;
                        }
                        $ubah[] = "{$kol} = :{$kol}";
                        $params[":{$kol}"] = $s[$kol];
                    }

                    // aktif_simrs & penanda sinkron selalu ikut: keduanya
                    // menggambarkan keadaan di SIMRS, bukan keputusan redaksi.
                    $ubah[] = 'aktif_simrs = :aktif';
                    $ubah[] = "sync_status = 'tersinkron'";
                    $ubah[] = 'synced_at = now()';
                    $params[':aktif'] = $s['aktif_simrs'] ? 'true' : 'false';

                    Database::jalankan(
                        'UPDATE doctors SET ' . implode(', ', $ubah) . ' WHERE id = :id', $params);
                    $diperbarui++;
                }
            });

            $hilang = $this->tandaiHilang('doctors', array_column($sumber, 'simrs_id'));
            $this->selesaikanCatatan($runId, 'selesai', compact('baru', 'diperbarui', 'dilewati', 'hilang'));

            return ['baru' => $baru, 'diperbarui' => $diperbarui,
                    'dilewati' => $dilewati, 'hilang' => $hilang];
        } catch (\Throwable $e) {
            $this->selesaikanCatatan($runId, 'gagal', [], $e->getMessage());
            throw $e;
        }
    }

    // =================================================================
    //  JADWAL PRAKTIK
    // =================================================================
    /**
     * Jadwal di SIMRS tersimpan mendatar: satu baris per dokter-poli dengan
     * sepasang kolom untuk tiap hari (Senin, Senin_Awal, Senin_Akhir).
     * Di sini dibalik menjadi satu baris per hari agar halaman dokter cukup
     * menampilkannya berurutan tanpa mengurai apa pun.
     *
     * Jadwal ditulis ulang seluruhnya setiap sinkron — tidak ada suntingan
     * redaksi di sini, dan menyamakan selisihnya baris demi baris hanya
     * menambah rumit tanpa manfaat.
     */
    public function jadwal(string $dipicuOleh): array
    {
        $runId = $this->mulaiCatatan('schedules', $dipicuOleh);
        $baris = 0;

        try {
            $sumber = SimrsClient::ambil('website/jadwal');

            $urutHari = array_flip(array_values(self::HARI));

            Database::transaksi(function () use ($sumber, $urutHari, &$baris) {
                Database::jalankan('DELETE FROM doctor_schedules');
                foreach ($sumber as $s) {
                    $dokterId = Database::nilai(
                        'SELECT id FROM doctors WHERE simrs_id = :id', [':id' => $s['simrs_id']]);
                    if ($dokterId === null) {
                        continue;                 // dokternya belum tersinkron
                    }
                    Database::jalankan(
                        'INSERT INTO doctor_schedules
                             (doctor_id, hari, jam_mulai, jam_selesai, unit, catatan, urutan, synced_at)
                         VALUES (:d, :h, :m, :s, :u, :c, :o, now())',
                        [
                            ':d' => $dokterId, ':h' => $s['hari'],
                            ':m' => $s['jam_mulai'], ':s' => $s['jam_selesai'],
                            ':u' => $s['unit'], ':c' => $s['catatan'],
                            ':o' => $urutHari[$s['hari']] ?? 99,
                        ]);
                    $baris++;
                }
            });

            $this->selesaikanCatatan($runId, 'selesai', ['diperbarui' => $baris]);
            return ['baris' => $baris];
        } catch (\Throwable $e) {
            $this->selesaikanCatatan($runId, 'gagal', [], $e->getMessage());
            throw $e;
        }
    }

    // =================================================================
    //  PAKET
    // =================================================================
    /**
     * A_PAKET tidak memuat harga; harganya dijumlahkan view v_paket dari
     * rincian paketnya. View dipakai bila ada, dan bila tidak, paketnya tetap
     * masuk tanpa harga — lebih baik daripada tidak muncul sama sekali.
     */
    public function paket(string $dipicuOleh): array
    {
        $runId = $this->mulaiCatatan('packages', $dipicuOleh);
        $baru = $diperbarui = $dilewati = 0;

        try {
            $sumber = SimrsClient::ambil('website/paket');

            Database::transaksi(function () use ($sumber, &$baru, &$diperbarui, &$dilewati) {
                foreach ($sumber as $s) {
                    $ada = Database::satu(
                        'SELECT id, field_locks FROM service_packages WHERE simrs_id = :id',
                        [':id' => $s['simrs_id']]);

                    if ($ada === null) {
                        Database::jalankan(
                            'INSERT INTO service_packages
                                 (simrs_id, kode, nama, harga_simrs, jml_kunjungan, jenis,
                                  aktif_simrs, sync_status, synced_at)
                             VALUES (:simrs_id, :kode, :nama, :harga, :jml, :jenis,
                                     :aktif, \'tersinkron\', now())',
                            [
                                ':simrs_id' => $s['simrs_id'], ':kode' => $s['kode'],
                                ':nama'  => $s['nama'] ?? 'Tanpa Nama',
                                ':harga' => $s['harga_simrs'], ':jml' => $s['jml_kunjungan'],
                                ':jenis' => $s['jenis'],
                                ':aktif' => $s['aktif_simrs'] ? 'true' : 'false',
                            ]);
                        $baru++;
                        continue;
                    }

                    $kunci  = $this->kunciKolom($ada['field_locks']);
                    $ubah   = [];
                    $params = [':id' => $ada['id']];
                    foreach (self::KOLOM_PAKET as $kol) {
                        if (in_array($kol, $kunci, true)) {
                            $dilewati++;
                            continue;
                        }
                        $ubah[] = "{$kol} = :{$kol}";
                        $params[":{$kol}"] = $s[$kol];
                    }
                    $ubah[] = 'aktif_simrs = :aktif';
                    $ubah[] = "sync_status = 'tersinkron'";
                    $ubah[] = 'synced_at = now()';
                    $params[':aktif'] = $s['aktif_simrs'] ? 'true' : 'false';

                    Database::jalankan(
                        'UPDATE service_packages SET ' . implode(', ', $ubah) . ' WHERE id = :id', $params);
                    $diperbarui++;
                }
            });

            $hilang = $this->tandaiHilang('service_packages', array_column($sumber, 'simrs_id'));
            $this->selesaikanCatatan($runId, 'selesai', compact('baru', 'diperbarui', 'dilewati', 'hilang'));

            return ['baru' => $baru, 'diperbarui' => $diperbarui,
                    'dilewati' => $dilewati, 'hilang' => $hilang];
        } catch (\Throwable $e) {
            $this->selesaikanCatatan($runId, 'gagal', [], $e->getMessage());
            throw $e;
        }
    }

    // =================================================================
    //  Pembantu
    // =================================================================

    /** @return string[] */
    private function kunciKolom(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $d = json_decode($json, true);
        return is_array($d) ? array_values(array_filter($d, 'is_string')) : [];
    }

    /**
     * Baris yang tidak lagi ada di SIMRS ditandai, tidak dihapus.
     * Baris tanpa simrs_id (dibuat langsung di website) tidak tersentuh.
     */
    private function tandaiHilang(string $tabel, array $idAda): int
    {
        if ($tabel !== 'doctors' && $tabel !== 'service_packages') {
            throw new \InvalidArgumentException('Tabel tidak dikenal.');   // nama tabel tak bisa diikat
        }
        if ($idAda === []) {
            return 0;
        }
        // Diikat sebagai satu larik integer, bukan dirangkai jadi daftar IN.
        return Database::jalankan(
            "UPDATE {$tabel}
                SET sync_status = 'hilang_di_simrs', synced_at = now()
              WHERE simrs_id IS NOT NULL
                AND sync_status <> 'hilang_di_simrs'
                AND NOT (simrs_id = ANY(string_to_array(:ada, ',')::int[]))",
            [':ada' => implode(',', array_map('intval', $idAda))]);
    }

    private function slugUnik(string $tabel, string $nama, string $cadangan): string
    {
        $dasar = trim(preg_replace('/-+/', '-',
            preg_replace('/[^a-z0-9]+/', '-', strtolower($nama))) ?? '', '-');
        if ($dasar === '') {
            $dasar = 'dokter-' . $cadangan;
        }
        $slug = $dasar;
        $n = 1;
        while (Database::nilai("SELECT 1 FROM {$tabel} WHERE lower(slug) = :s", [':s' => $slug])) {
            $slug = $dasar . '-' . (++$n);
        }
        return $slug;
    }

    private function mulaiCatatan(string $modul, string $dipicuOleh): int
    {
        Database::jalankan(
            'INSERT INTO sync_runs (modul, dipicu_oleh) VALUES (:m, :o)',
            [':m' => $modul, ':o' => $dipicuOleh]);
        return (int) Database::nilai("SELECT currval(pg_get_serial_sequence('webcompro.sync_runs','id'))");
    }

    private function selesaikanCatatan(int $id, string $status, array $hitung, ?string $pesan = null): void
    {
        Database::jalankan(
            'UPDATE sync_runs
                SET selesai_at = now(), status = :st, pesan = :pesan,
                    baru = :baru, diperbarui = :upd, dilewati = :skip, hilang = :hil,
                    rincian = :rinci::jsonb
              WHERE id = :id',
            [
                ':id' => $id, ':st' => $status, ':pesan' => $pesan,
                ':baru' => $hitung['baru'] ?? 0, ':upd' => $hitung['diperbarui'] ?? 0,
                ':skip' => $hitung['dilewati'] ?? 0, ':hil' => $hitung['hilang'] ?? 0,
                ':rinci' => json_encode($hitung, JSON_UNESCAPED_UNICODE),
            ]);
    }
}
