<?php
declare(strict_types=1);

namespace Core;

/**
 * Galat yang memang layak sampai ke pemanggil, beserta kode statusnya.
 *
 * Dibedakan dari Throwable lain dengan sengaja: pesannya boleh ditampilkan
 * apa adanya. Galat selain jenis ini diperlakukan sebagai kegagalan tak
 * terduga dan pesannya TIDAK diteruskan — pesan pengecualian kerap memuat
 * kueri SQL beserta nilai terikatnya.
 */
class HttpException extends \RuntimeException
{
    public function __construct(
        private readonly int $status,
        string $pesan,
        private readonly array $rincian = []
    ) {
        parent::__construct($pesan);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function rincian(): array
    {
        return $this->rincian;
    }

    public static function takSah(string $pesan = 'Tidak terautentikasi.'): self
    {
        return new self(401, $pesan);
    }

    public static function terlarang(string $pesan = 'Anda tidak berwenang melakukan tindakan ini.'): self
    {
        return new self(403, $pesan);
    }

    public static function takDitemukan(string $pesan = 'Data tidak ditemukan.'): self
    {
        return new self(404, $pesan);
    }

    /** @param array<string,string> $galat */
    public static function validasi(array $galat, string $pesan = 'Isian belum lengkap atau tidak sah.'): self
    {
        return new self(422, $pesan, $galat);
    }

    public static function terlaluSering(string $pesan = 'Terlalu banyak permintaan. Coba lagi sebentar lagi.'): self
    {
        return new self(429, $pesan);
    }
}
