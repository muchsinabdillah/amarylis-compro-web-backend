<?php
declare(strict_types=1);

namespace Helpers;

use Core\HttpException;
use Core\Request;

/**
 * Validasi masukan.
 *
 * Seluruh galat dikumpulkan lebih dulu, baru dilempar sekaligus. Berhenti
 * pada galat pertama membuat pengisi formulir memperbaiki satu isian,
 * menyimpan, lalu menemukan galat berikutnya — berulang kali.
 */
final class Validator
{
    private array $galat = [];
    private array $bersih = [];

    public function __construct(private readonly Request $req) {}

    public static function untuk(Request $req): self
    {
        return new self($req);
    }

    public function teks(string $kunci, bool $wajib = true, int $maks = 255, ?int $min = null): self
    {
        $v = $this->req->str($kunci);
        if ($v === null) {
            if ($wajib) {
                $this->galat[$kunci] = 'Wajib diisi.';
            }
            $this->bersih[$kunci] = null;
            return $this;
        }
        if (mb_strlen($v) > $maks) {
            $this->galat[$kunci] = "Maksimal {$maks} karakter.";
        } elseif ($min !== null && mb_strlen($v) < $min) {
            $this->galat[$kunci] = "Minimal {$min} karakter.";
        }
        $this->bersih[$kunci] = $v;
        return $this;
    }

    public function email(string $kunci, bool $wajib = true): self
    {
        $v = $this->req->str($kunci);
        if ($v === null) {
            if ($wajib) {
                $this->galat[$kunci] = 'Wajib diisi.';
            }
            return $this;
        }
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->galat[$kunci] = 'Alamat email tidak sah.';
        }
        $this->bersih[$kunci] = strtolower($v);
        return $this;
    }

    public function angka(string $kunci, bool $wajib = false, ?float $min = null, ?float $maks = null): self
    {
        if (!$this->req->ada($kunci) || $this->req->str($kunci) === null) {
            if ($wajib) {
                $this->galat[$kunci] = 'Wajib diisi.';
            }
            $this->bersih[$kunci] = null;
            return $this;
        }
        $v = $this->req->str($kunci);
        if (!is_numeric($v)) {
            $this->galat[$kunci] = 'Harus berupa angka.';
            return $this;
        }
        $n = (float) $v;
        if ($min !== null && $n < $min) {
            $this->galat[$kunci] = "Minimal {$min}.";
        }
        if ($maks !== null && $n > $maks) {
            $this->galat[$kunci] = "Maksimal {$maks}.";
        }
        $this->bersih[$kunci] = $n;
        return $this;
    }

    /** @param string[] $pilihan */
    public function pilihan(string $kunci, array $pilihan, bool $wajib = true, ?string $bawaan = null): self
    {
        $v = $this->req->str($kunci) ?? $bawaan;
        if ($v === null) {
            if ($wajib) {
                $this->galat[$kunci] = 'Wajib dipilih.';
            }
            $this->bersih[$kunci] = null;
            return $this;
        }
        if (!in_array($v, $pilihan, true)) {
            $this->galat[$kunci] = 'Pilihan tidak sah: ' . implode(', ', $pilihan) . '.';
        }
        $this->bersih[$kunci] = $v;
        return $this;
    }

    public function url(string $kunci, bool $wajib = false): self
    {
        $v = $this->req->str($kunci);
        if ($v === null) {
            if ($wajib) {
                $this->galat[$kunci] = 'Wajib diisi.';
            }
            $this->bersih[$kunci] = null;
            return $this;
        }
        if (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $v)) {
            $this->galat[$kunci] = 'Harus berupa alamat http(s) yang sah.';
        }
        $this->bersih[$kunci] = $v;
        return $this;
    }

    public function tanggal(string $kunci, bool $wajib = false): self
    {
        $v = $this->req->str($kunci);
        if ($v === null) {
            if ($wajib) {
                $this->galat[$kunci] = 'Wajib diisi.';
            }
            $this->bersih[$kunci] = null;
            return $this;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            $this->galat[$kunci] = 'Format tanggal harus YYYY-MM-DD.';
        }
        $this->bersih[$kunci] = $v;
        return $this;
    }

    public function bool(string $kunci, bool $bawaan = false): self
    {
        $this->bersih[$kunci] = $this->req->bool($kunci, $bawaan);
        return $this;
    }

    /** Isi HTML editor — selalu melewati pembersih. */
    public function htmlKaya(string $kunci, bool $wajib = false): self
    {
        $mentah = $this->req->str($kunci);
        if ($mentah === null) {
            if ($wajib) {
                $this->galat[$kunci] = 'Wajib diisi.';
            }
            $this->bersih[$kunci] = null;
            return $this;
        }
        $this->bersih[$kunci] = Html::bersihkan($mentah);
        return $this;
    }

    /** @return array<string,mixed> */
    public function selesai(): array
    {
        if ($this->galat !== []) {
            throw HttpException::validasi($this->galat);
        }
        return $this->bersih;
    }
}
