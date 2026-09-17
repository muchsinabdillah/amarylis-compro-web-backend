<?php
declare(strict_types=1);

namespace Core;

/**
 * Permintaan HTTP yang masuk.
 *
 * Nilai selalu diambil lewat pengambil bertipe (str/int/bool), bukan langsung
 * dari larik mentah. Masukan dari luar tidak pernah bertipe seperti yang
 * diharapkan, dan memeriksanya satu per satu di setiap controller adalah
 * cara tercepat melewatkan satu.
 */
final class Request
{
    private array $query;
    private array $body;
    private array $server;
    private array $files;
    private ?array $auth = null;      // diisi middleware autentikasi

    public function __construct()
    {
        $this->query  = $_GET;
        $this->server = $_SERVER;
        $this->files  = $_FILES;
        $this->body   = $this->uraiBody();
    }

    private function uraiBody(): array
    {
        $tipe = strtolower($this->server['CONTENT_TYPE'] ?? '');

        if (str_contains($tipe, 'application/json')) {
            $mentah = file_get_contents('php://input') ?: '';
            if ($mentah === '') {
                return [];
            }
            $data = json_decode($mentah, true);
            // JSON rusak dijawab sebagai galat permintaan, bukan diperlakukan
            // sebagai badan kosong yang lalu gagal validasi dengan pesan keliru.
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new HttpException(400, 'Badan permintaan bukan JSON yang sah.');
            }
            return is_array($data) ? $data : [];
        }

        return $_POST;
    }

    public function metode(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function jalur(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $uri = parse_url($uri, PHP_URL_PATH) ?: '/';
        return '/' . trim($uri, '/');
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return (string) ($this->server['HTTP_USER_AGENT'] ?? '');
    }

    public function header(string $nama): ?string
    {
        $kunci = 'HTTP_' . strtoupper(str_replace('-', '_', $nama));
        return isset($this->server[$kunci]) ? (string) $this->server[$kunci] : null;
    }

    public function origin(): ?string
    {
        return $this->header('Origin');
    }

    /** Gabungan query + body; body menang. */
    public function semua(): array
    {
        return $this->body + $this->query;
    }

    public function ada(string $kunci): bool
    {
        return array_key_exists($kunci, $this->body) || array_key_exists($kunci, $this->query);
    }

    public function str(string $kunci, ?string $bawaan = null): ?string
    {
        $v = $this->body[$kunci] ?? $this->query[$kunci] ?? null;
        if ($v === null || is_array($v)) {
            return $bawaan;
        }
        $v = trim((string) $v);
        return $v === '' ? $bawaan : $v;
    }

    public function int(string $kunci, ?int $bawaan = null): ?int
    {
        $v = $this->body[$kunci] ?? $this->query[$kunci] ?? null;
        return ($v === null || $v === '' || is_array($v)) ? $bawaan : (int) $v;
    }

    public function bool(string $kunci, bool $bawaan = false): bool
    {
        $v = $this->body[$kunci] ?? $this->query[$kunci] ?? null;
        if ($v === null || $v === '') {
            return $bawaan;
        }
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public function arr(string $kunci): array
    {
        $v = $this->body[$kunci] ?? $this->query[$kunci] ?? null;
        return is_array($v) ? $v : [];
    }

    public function file(string $kunci): ?array
    {
        return $this->files[$kunci] ?? null;
    }

    /**
     * Halaman & jumlah per halaman, sudah dipagari.
     *
     * Batas atas bukan kenyamanan melainkan pertahanan: tanpa itu satu
     * permintaan ?per_page=100000 cukup untuk membuat server menyusun
     * seluruh isi tabel ke dalam memori.
     */
    public function paginasi(int $bawaanPerHalaman = 12, int $maks = 60): array
    {
        $halaman    = max(1, (int) ($this->query['page'] ?? 1));
        $perHalaman = (int) ($this->query['per_page'] ?? $bawaanPerHalaman);
        $perHalaman = max(1, min($perHalaman, $maks));
        return [$halaman, $perHalaman, ($halaman - 1) * $perHalaman];
    }

    public function setAuth(?array $auth): void
    {
        $this->auth = $auth;
    }

    public function auth(): ?array
    {
        return $this->auth;
    }

    public function userId(): ?int
    {
        return isset($this->auth['sub']) ? (int) $this->auth['sub'] : null;
    }

    public function namaPengguna(): string
    {
        return (string) ($this->auth['nama'] ?? 'sistem');
    }

    /** @return string[] */
    public function izin(): array
    {
        $p = $this->auth['izin'] ?? [];
        return is_array($p) ? $p : [];
    }

    public function punyaIzin(string $kode): bool
    {
        $izin = $this->izin();
        return in_array('*', $izin, true) || in_array($kode, $izin, true);
    }
}
