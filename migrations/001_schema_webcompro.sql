-- =====================================================================
-- Skema webcompro — Website Company Profile Klinik Pratama Andini
-- =====================================================================
--
-- BASIS DATA SENDIRI, SKEMA SENDIRI, DATA SENDIRI.
--
-- Website memakai basis data terpisah dari SIMRS (dibuat oleh
-- 000_buat_database.sql), dan di dalamnya berdiri pada skema `webcompro`.
-- Lalu lintas pengunjung karena itu tidak pernah menyentuh basis data
-- operasional: bila SIMRS sedang sibuk, sedang dipulihkan, atau sedang
-- dipindah server, halaman publik tetap tayang.
--
-- Data yang memang berasal dari SIMRS — dokter, jadwal praktik, paket —
-- masuk lewat SINKRONISASI SATU ARAH melalui API SIMRS, bukan lewat
-- sambungan basis data dan bukan diketik ulang. Website tidak memegang
-- kredensial basis data SIMRS sama sekali.
--
-- ATURAN SINKRONISASI YANG DIPEGANG RANCANGAN INI
--
--   1. Baris baru dari SIMRS masuk sebagai BELUM TAYANG. Master dokter
--      memuat baris uji dan akun teknis; menayangkannya otomatis berarti
--      keduanya muncul di halaman publik.
--   2. Kolom yang sudah disunting redaksi DIKUNCI (field_locks) dan
--      dilewati sinkronisasi berikutnya, sehingga suntingan tidak "hilang
--      sendiri" tanpa sebab yang bisa ditunjuk.
--   3. Baris yang menghilang di SIMRS TIDAK dihapus, hanya ditandai.
--      Menghapusnya berarti kehilangan foto, bio, dan mematikan URL yang
--      sudah terlanjur dibagikan orang.
--
-- PROFIL KLINIK TIDAK DISALIN
--
--   Nama, alamat, kota, kodepos, telepon, dan email klinik diambil dari
--   Data RS SIMRS lewat API setiap kali dibutuhkan, dan disimpan sesaat
--   sebagai berkas singgahan satu jam. Menyalinnya ke tabel sendiri akan
--   menciptakan versi kedua yang cepat atau lambat berbeda — dan alamat
--   klinik yang berbeda antara nota dan website baru ketahuan dari pasien
--   yang tersesat.
--
-- Tabel selebihnya milik ranah KONTEN & PEMASARAN — hal yang memang tidak
-- ada di sistem operasional dan tidak seharusnya ada di sana.
-- =====================================================================

BEGIN;

CREATE SCHEMA IF NOT EXISTS webcompro;

COMMENT ON SCHEMA webcompro IS
  'Konten & pemasaran website company profile. Data operasional (dokter, jadwal, tarif, paket) tetap milik skema SIMRS dan hanya dibaca.';

-- ---------------------------------------------------------------------
-- Nilai bersama
-- ---------------------------------------------------------------------
-- Status konten dipakai berulang; dibuat sebagai tipe agar nilai di luar
-- daftar ini ditolak basis data, bukan hanya oleh aplikasi.
DO $$ BEGIN
    CREATE TYPE webcompro.status_konten AS ENUM ('draft', 'published', 'archived');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
    CREATE TYPE webcompro.jenis_harga AS ENUM ('fixed', 'starting_from', 'contact_us');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
    CREATE TYPE webcompro.jenis_video AS ENUM ('youtube', 'vimeo', 'external', 'uploaded');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- Kolom jejak yang sama di hampir semua tabel.
CREATE OR REPLACE FUNCTION webcompro.sentuh_updated_at() RETURNS trigger AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END $$ LANGUAGE plpgsql;

-- ---------------------------------------------------------------------
-- 1. Pengaturan situs
-- ---------------------------------------------------------------------
-- Disimpan sebagai pasangan kunci-nilai, bukan satu baris berkolom banyak:
-- pengaturan baru tidak menuntut perubahan struktur, dan nomor WhatsApp
-- cukup ada di SATU tempat — persyaratan yang mustahil dijaga bila ia
-- tersebar sebagai konstanta di banyak berkas.
CREATE TABLE IF NOT EXISTS webcompro.settings (
    key         varchar(80) PRIMARY KEY,
    value       text,
    grup        varchar(40)  NOT NULL DEFAULT 'umum',
    label       varchar(150),
    keterangan  varchar(400),
    tipe        varchar(20)  NOT NULL DEFAULT 'text',   -- text|textarea|url|tel|image|bool
    urutan      integer      NOT NULL DEFAULT 0,
    updated_at  timestamptz  NOT NULL DEFAULT now(),
    updated_by  varchar(80)
);

-- ---------------------------------------------------------------------
-- 2. Pengguna, peran, hak akses (RBAC)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webcompro.users (
    id          bigserial PRIMARY KEY,
    nama        varchar(120) NOT NULL,
    email       varchar(160) NOT NULL,
    password    varchar(255) NOT NULL,          -- password_hash(), tidak pernah plaintext
    avatar      varchar(255),
    is_active   boolean      NOT NULL DEFAULT true,
    last_login  timestamptz,
    created_at  timestamptz  NOT NULL DEFAULT now(),
    updated_at  timestamptz  NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_users_email ON webcompro.users (lower(email));

CREATE TABLE IF NOT EXISTS webcompro.roles (
    id          serial PRIMARY KEY,
    kode        varchar(40)  NOT NULL,
    nama        varchar(80)  NOT NULL,
    keterangan  varchar(255)
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_roles_kode ON webcompro.roles (upper(kode));

CREATE TABLE IF NOT EXISTS webcompro.permissions (
    id      serial PRIMARY KEY,
    kode    varchar(60) NOT NULL,               -- mis. article.create
    modul   varchar(40) NOT NULL,
    nama    varchar(120) NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_permissions_kode ON webcompro.permissions (lower(kode));

CREATE TABLE IF NOT EXISTS webcompro.role_permissions (
    role_id       integer NOT NULL REFERENCES webcompro.roles(id) ON DELETE CASCADE,
    permission_id integer NOT NULL REFERENCES webcompro.permissions(id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE IF NOT EXISTS webcompro.user_roles (
    user_id bigint  NOT NULL REFERENCES webcompro.users(id) ON DELETE CASCADE,
    role_id integer NOT NULL REFERENCES webcompro.roles(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, role_id)
);

-- ---------------------------------------------------------------------
-- 3. Media
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webcompro.media (
    id          bigserial PRIMARY KEY,
    nama_file   varchar(255) NOT NULL,
    path        varchar(400) NOT NULL,
    mime        varchar(80)  NOT NULL,
    ukuran      bigint       NOT NULL,
    lebar       integer,
    tinggi      integer,
    alt         varchar(255),
    uploaded_by bigint REFERENCES webcompro.users(id) ON DELETE SET NULL,
    created_at  timestamptz  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ix_media_created ON webcompro.media (created_at DESC);

-- ---------------------------------------------------------------------
-- 4. Taksonomi bersama
-- ---------------------------------------------------------------------
-- Satu tabel kategori untuk seluruh jenis konten, dibedakan kolom `tipe`.
-- Empat tabel kategori yang isinya seragam hanya melipatgandakan layar CMS
-- dan kode yang mengurusnya.
CREATE TABLE IF NOT EXISTS webcompro.categories (
    id         serial PRIMARY KEY,
    tipe       varchar(20)  NOT NULL,           -- article|news|video|service|mcu|homecare
    nama       varchar(120) NOT NULL,
    slug       varchar(150) NOT NULL,
    keterangan varchar(300),
    urutan     integer      NOT NULL DEFAULT 0,
    is_active  boolean      NOT NULL DEFAULT true,
    created_at timestamptz  NOT NULL DEFAULT now(),
    updated_at timestamptz  NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_categories_tipe_slug ON webcompro.categories (tipe, lower(slug));

CREATE TABLE IF NOT EXISTS webcompro.tags (
    id   serial PRIMARY KEY,
    nama varchar(80)  NOT NULL,
    slug varchar(100) NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_tags_slug ON webcompro.tags (lower(slug));

-- Relasi tag bersifat polimorfik agar satu tag dapat menempel pada artikel,
-- berita, maupun video tanpa tiga tabel pivot yang isinya sama.
CREATE TABLE IF NOT EXISTS webcompro.taggables (
    tag_id       integer     NOT NULL REFERENCES webcompro.tags(id) ON DELETE CASCADE,
    konten_tipe  varchar(20) NOT NULL,          -- article|news|video
    konten_id    bigint      NOT NULL,
    PRIMARY KEY (tag_id, konten_tipe, konten_id)
);
CREATE INDEX IF NOT EXISTS ix_taggables_konten ON webcompro.taggables (konten_tipe, konten_id);

-- ---------------------------------------------------------------------
-- 5. Halaman statis (Tentang Kami, Beranda, dll.)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webcompro.pages (
    id              serial PRIMARY KEY,
    slug            varchar(120) NOT NULL,
    judul           varchar(200) NOT NULL,
    konten          text,
    seksi           jsonb,                      -- blok bebas: visi, misi, nilai, keunggulan
    hero_image      varchar(400),
    seo_title       varchar(200),
    seo_description varchar(400),
    og_image        varchar(400),
    status          webcompro.status_konten NOT NULL DEFAULT 'draft',
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_pages_slug ON webcompro.pages (lower(slug));

-- ---------------------------------------------------------------------
-- 6. Layanan
-- ---------------------------------------------------------------------
-- kategori_tarif menautkan layanan ke CategoryProduct pada master tarif
-- SIMRS bila memang ada padanannya. Tautannya berupa teks, bukan kunci
-- asing, supaya master tarif tidak pernah terkunci oleh website.
CREATE TABLE IF NOT EXISTS webcompro.services (
    id                bigserial PRIMARY KEY,
    category_id       integer REFERENCES webcompro.categories(id) ON DELETE SET NULL,
    kategori_tarif    varchar(60),
    nama              varchar(160) NOT NULL,
    slug              varchar(180) NOT NULL,
    ringkas           varchar(400),
    deskripsi         text,
    image             varchar(400),
    icon              varchar(60),
    harga             numeric(18,2),
    jenis_harga       webcompro.jenis_harga NOT NULL DEFAULT 'contact_us',
    whatsapp_message  varchar(400),
    is_featured       boolean NOT NULL DEFAULT false,
    status            webcompro.status_konten NOT NULL DEFAULT 'draft',
    urutan            integer NOT NULL DEFAULT 0,
    view_count        integer NOT NULL DEFAULT 0,
    seo_title         varchar(200),
    seo_description   varchar(400),
    og_image          varchar(400),
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_services_slug ON webcompro.services (lower(slug));
CREATE INDEX IF NOT EXISTS ix_services_tampil ON webcompro.services (status, urutan) WHERE status = 'published';

-- ---------------------------------------------------------------------
-- 7. Paket MCU
-- ---------------------------------------------------------------------
-- paket_id menunjuk MasterdataSQL.A_PAKET bila paketnya benar-benar dijual
-- di klinik. Bila terisi, harga dan isi paket dibaca dari sana; kolom harga
-- di sini hanya dipakai untuk paket yang murni penawaran pemasaran.
CREATE TABLE IF NOT EXISTS webcompro.mcu_packages (
    id                bigserial PRIMARY KEY,
    paket_id          integer,
    category_id       integer REFERENCES webcompro.categories(id) ON DELETE SET NULL,
    nama              varchar(160) NOT NULL,
    slug              varchar(180) NOT NULL,
    ringkas           varchar(400),
    deskripsi         text,
    thumbnail         varchar(400),
    harga             numeric(18,2),
    jenis_harga       webcompro.jenis_harga NOT NULL DEFAULT 'contact_us',
    durasi            varchar(80),
    persiapan         text,
    whatsapp_message  varchar(400),
    is_featured       boolean NOT NULL DEFAULT false,
    status            webcompro.status_konten NOT NULL DEFAULT 'draft',
    urutan            integer NOT NULL DEFAULT 0,
    view_count        integer NOT NULL DEFAULT 0,
    seo_title         varchar(200),
    seo_description   varchar(400),
    og_image          varchar(400),
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_mcu_slug ON webcompro.mcu_packages (lower(slug));
CREATE INDEX IF NOT EXISTS ix_mcu_tampil ON webcompro.mcu_packages (status, urutan) WHERE status = 'published';

-- Isi paket sebagai baris tersendiri, bukan satu kolom teks panjang:
-- urutannya dapat diatur dan tiap butir dapat ditandai sebagai tambahan.
CREATE TABLE IF NOT EXISTS webcompro.mcu_benefits (
    id         bigserial PRIMARY KEY,
    package_id bigint NOT NULL REFERENCES webcompro.mcu_packages(id) ON DELETE CASCADE,
    teks       varchar(255) NOT NULL,
    keterangan varchar(255),
    urutan     integer NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS ix_mcu_benefits_pkg ON webcompro.mcu_benefits (package_id, urutan);

-- ---------------------------------------------------------------------
-- 8. Homecare
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webcompro.homecare_services (
    id                bigserial PRIMARY KEY,
    paket_id          integer,
    category_id       integer REFERENCES webcompro.categories(id) ON DELETE SET NULL,
    nama              varchar(160) NOT NULL,
    slug              varchar(180) NOT NULL,
    ringkas           varchar(400),
    deskripsi         text,
    image             varchar(400),
    harga             numeric(18,2),
    jenis_harga       webcompro.jenis_harga NOT NULL DEFAULT 'contact_us',
    area_layanan      varchar(255),
    durasi            varchar(80),
    whatsapp_message  varchar(400),
    is_featured       boolean NOT NULL DEFAULT false,
    status            webcompro.status_konten NOT NULL DEFAULT 'draft',
    urutan            integer NOT NULL DEFAULT 0,
    view_count        integer NOT NULL DEFAULT 0,
    seo_title         varchar(200),
    seo_description   varchar(400),
    og_image          varchar(400),
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_homecare_slug ON webcompro.homecare_services (lower(slug));
CREATE INDEX IF NOT EXISTS ix_homecare_tampil ON webcompro.homecare_services (status, urutan) WHERE status = 'published';

-- ---------------------------------------------------------------------
-- 9. Dokter — master website, disinkronkan dari SIMRS
-- ---------------------------------------------------------------------
-- Website berdiri di atas datanya sendiri supaya lalu lintas publik tidak
-- pernah menyentuh basis data operasional. Isinya tetap BERASAL dari SIMRS
-- lewat sinkronisasi satu arah, sehingga tidak ada pengetikan ganda dan
-- tidak ada dua versi nama dokter yang berbeda.
--
-- simrs_id menautkan baris ini ke MasterdataSQL.Doctors.ID. Boleh kosong:
-- website dapat menampilkan tenaga kesehatan yang memang tidak terdaftar
-- sebagai dokter di SIMRS.
--
-- field_locks mencatat kolom mana yang sudah disunting redaksi. Sinkronisasi
-- berikutnya melewatinya. Tanpa ini, nama tampil yang sengaja dibuat lebih
-- ramah akan tertimpa setiap kali sinkron berjalan, dan tidak ada yang tahu
-- mengapa suntingannya "hilang sendiri".
CREATE TABLE IF NOT EXISTS webcompro.doctors (
    id            bigserial PRIMARY KEY,
    simrs_id      integer,
    nama          varchar(160) NOT NULL,
    gelar         varchar(120),
    spesialis     varchar(120),
    no_sip        varchar(80),
    slug          varchar(180),

    -- milik website sepenuhnya; tidak pernah disentuh sinkronisasi
    foto          varchar(400),
    bio           text,
    pendidikan    text,
    pengalaman    text,
    urutan        integer NOT NULL DEFAULT 0,

    -- Penayangan adalah keputusan redaksi, bukan cerminan status di SIMRS.
    -- Baris baru selalu masuk belum tayang: master dokter memuat baris uji
    -- dan akun teknis yang tidak layak tampil ke publik.
    is_published  boolean NOT NULL DEFAULT false,

    aktif_simrs   boolean,
    field_locks   jsonb   NOT NULL DEFAULT '[]'::jsonb,
    sync_status   varchar(20) NOT NULL DEFAULT 'manual',  -- manual|tersinkron|hilang_di_simrs
    synced_at     timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_doctors_simrs ON webcompro.doctors (simrs_id) WHERE simrs_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS ux_doctors_slug  ON webcompro.doctors (lower(slug)) WHERE slug IS NOT NULL;
CREATE INDEX IF NOT EXISTS ix_doctors_tayang ON webcompro.doctors (urutan) WHERE is_published;

-- Jadwal praktik ikut disinkronkan agar halaman dokter tetap dapat
-- menampilkannya tanpa memanggil SIMRS saat halaman dibuka.
CREATE TABLE IF NOT EXISTS webcompro.doctor_schedules (
    id          bigserial PRIMARY KEY,
    doctor_id   bigint NOT NULL REFERENCES webcompro.doctors(id) ON DELETE CASCADE,
    hari        varchar(12),
    jam_mulai   varchar(8),
    jam_selesai varchar(8),
    unit        varchar(120),
    catatan     varchar(200),
    urutan      integer NOT NULL DEFAULT 0,
    synced_at   timestamptz
);
CREATE INDEX IF NOT EXISTS ix_doctor_schedules_dokter ON webcompro.doctor_schedules (doctor_id, urutan);

-- ---------------------------------------------------------------------
-- 9b. Paket layanan — master website, disinkronkan dari SIMRS
-- ---------------------------------------------------------------------
-- Pola yang sama dengan dokter, untuk MasterdataSQL.A_PAKET. Paket yang
-- murni penawaran pemasaran boleh berdiri tanpa simrs_id.
--
-- Harga disimpan terpisah antara yang datang dari SIMRS dan yang ditampilkan
-- website: klinik kerap menampilkan harga promosi yang berbeda dari tarif
-- sistem, dan menyatukan keduanya berarti sinkronisasi menghapus harga
-- promosi tanpa peringatan.
CREATE TABLE IF NOT EXISTS webcompro.service_packages (
    id            bigserial PRIMARY KEY,
    simrs_id      integer,
    kode          varchar(40),
    nama          varchar(160) NOT NULL,
    harga_simrs   numeric(18,2),
    jml_kunjungan integer,
    aktif_simrs   boolean,
    jenis         varchar(20),                  -- mcu|homecare|lainnya
    field_locks   jsonb   NOT NULL DEFAULT '[]'::jsonb,
    sync_status   varchar(20) NOT NULL DEFAULT 'manual',
    synced_at     timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_packages_simrs ON webcompro.service_packages (simrs_id) WHERE simrs_id IS NOT NULL;

-- ---------------------------------------------------------------------
-- 9c. Riwayat sinkronisasi
-- ---------------------------------------------------------------------
-- Sinkronisasi yang berjalan diam-diam mustahil dipercaya. Setiap jalannya
-- dicatat: apa yang bertambah, berubah, dan menghilang — sehingga bila suatu
-- hari halaman dokter berubah sendiri, ada yang bisa ditunjuk.
CREATE TABLE IF NOT EXISTS webcompro.sync_runs (
    id          bigserial PRIMARY KEY,
    modul       varchar(30) NOT NULL,           -- doctors|packages|schedules
    dipicu_oleh varchar(80),
    mulai_at    timestamptz NOT NULL DEFAULT now(),
    selesai_at  timestamptz,
    baru        integer NOT NULL DEFAULT 0,
    diperbarui  integer NOT NULL DEFAULT 0,
    dilewati    integer NOT NULL DEFAULT 0,     -- terkunci redaksi
    hilang      integer NOT NULL DEFAULT 0,
    status      varchar(20) NOT NULL DEFAULT 'berjalan',
    pesan       text,
    rincian     jsonb
);
CREATE INDEX IF NOT EXISTS ix_sync_runs_waktu ON webcompro.sync_runs (modul, mulai_at DESC);

-- ---------------------------------------------------------------------
-- 10. Fasilitas & galeri
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webcompro.facilities (
    id         bigserial PRIMARY KEY,
    nama       varchar(160) NOT NULL,
    slug       varchar(180),
    deskripsi  text,
    image      varchar(400),
    is_active  boolean NOT NULL DEFAULT true,
    urutan     integer NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- 11. Artikel
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webcompro.articles (
    id              bigserial PRIMARY KEY,
    category_id     integer REFERENCES webcompro.categories(id) ON DELETE SET NULL,
    author_id       bigint  REFERENCES webcompro.users(id) ON DELETE SET NULL,
    judul           varchar(220) NOT NULL,
    slug            varchar(240) NOT NULL,
    excerpt         varchar(500),
    konten          text,
    thumbnail       varchar(400),
    status          webcompro.status_konten NOT NULL DEFAULT 'draft',
    published_at    timestamptz,
    reading_minutes integer,
    view_count      integer NOT NULL DEFAULT 0,
    is_featured     boolean NOT NULL DEFAULT false,
    seo_title       varchar(200),
    seo_description varchar(400),
    og_image        varchar(400),
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_articles_slug ON webcompro.articles (lower(slug));
-- Indeks penayangan: daftar publik selalu menyaring status lalu mengurutkan
-- tanggal terbit, dan itulah kueri yang paling sering dijalankan situs.
CREATE INDEX IF NOT EXISTS ix_articles_tayang ON webcompro.articles (published_at DESC)
    WHERE status = 'published';
CREATE INDEX IF NOT EXISTS ix_articles_kategori ON webcompro.articles (category_id, published_at DESC);

-- ---------------------------------------------------------------------
-- 12. Berita & kegiatan
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webcompro.news (
    id              bigserial PRIMARY KEY,
    category_id     integer REFERENCES webcompro.categories(id) ON DELETE SET NULL,
    author_id       bigint  REFERENCES webcompro.users(id) ON DELETE SET NULL,
    judul           varchar(220) NOT NULL,
    slug            varchar(240) NOT NULL,
    excerpt         varchar(500),
    konten          text,
    thumbnail       varchar(400),
    event_date      date,
    lokasi          varchar(200),
    status          webcompro.status_konten NOT NULL DEFAULT 'draft',
    published_at    timestamptz,
    view_count      integer NOT NULL DEFAULT 0,
    is_featured     boolean NOT NULL DEFAULT false,
    seo_title       varchar(200),
    seo_description varchar(400),
    og_image        varchar(400),
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_news_slug ON webcompro.news (lower(slug));
CREATE INDEX IF NOT EXISTS ix_news_tayang ON webcompro.news (published_at DESC) WHERE status = 'published';

CREATE TABLE IF NOT EXISTS webcompro.news_gallery (
    id         bigserial PRIMARY KEY,
    news_id    bigint NOT NULL REFERENCES webcompro.news(id) ON DELETE CASCADE,
    image      varchar(400) NOT NULL,
    caption    varchar(255),
    urutan     integer NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ix_news_gallery_news ON webcompro.news_gallery (news_id, urutan);

-- ---------------------------------------------------------------------
-- 13. Video
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webcompro.videos (
    id              bigserial PRIMARY KEY,
    category_id     integer REFERENCES webcompro.categories(id) ON DELETE SET NULL,
    author_id       bigint  REFERENCES webcompro.users(id) ON DELETE SET NULL,
    judul           varchar(220) NOT NULL,
    slug            varchar(240) NOT NULL,
    deskripsi       text,
    video_type      webcompro.jenis_video NOT NULL DEFAULT 'youtube',
    video_url       varchar(500) NOT NULL,
    embed_url       varchar(500),
    thumbnail       varchar(400),
    durasi          varchar(20),
    status          webcompro.status_konten NOT NULL DEFAULT 'draft',
    published_at    timestamptz,
    view_count      integer NOT NULL DEFAULT 0,
    is_featured     boolean NOT NULL DEFAULT false,
    urutan          integer NOT NULL DEFAULT 0,
    seo_title       varchar(200),
    seo_description varchar(400),
    og_image        varchar(400),
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_videos_slug ON webcompro.videos (lower(slug));
CREATE INDEX IF NOT EXISTS ix_videos_tayang ON webcompro.videos (published_at DESC) WHERE status = 'published';

-- ---------------------------------------------------------------------
-- 14. Penghitung tampilan yang tidak mudah digelembungkan
-- ---------------------------------------------------------------------
-- Menaikkan view_count setiap kali halaman dibuka membuat angka itu bisa
-- dinaikkan hanya dengan menekan F5. Sidik pengunjung disimpan sehingga
-- kunjungan berulang dalam rentang waktu pendek tidak dihitung ulang.
CREATE TABLE IF NOT EXISTS webcompro.view_logs (
    konten_tipe varchar(20) NOT NULL,
    konten_id   bigint      NOT NULL,
    sidik       varchar(64) NOT NULL,           -- hash(IP + user agent + garam harian)
    dilihat_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (konten_tipe, konten_id, sidik)
);
CREATE INDEX IF NOT EXISTS ix_view_logs_waktu ON webcompro.view_logs (dilihat_at);

-- ---------------------------------------------------------------------
-- 15. Prospek dari tombol WhatsApp
-- ---------------------------------------------------------------------
-- Dicatat hanya sebagai jejak minat: konten apa yang paling sering
-- ditanyakan. Tidak menyimpan data pribadi apa pun.
CREATE TABLE IF NOT EXISTS webcompro.whatsapp_leads (
    id          bigserial PRIMARY KEY,
    konten_tipe varchar(20),
    konten_id   bigint,
    judul       varchar(220),
    halaman     varchar(400),
    referrer    varchar(400),
    created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ix_leads_waktu ON webcompro.whatsapp_leads (created_at DESC);

-- ---------------------------------------------------------------------
-- Pemicu updated_at
-- ---------------------------------------------------------------------
DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['users','categories','pages','services','mcu_packages',
                             'homecare_services','doctors','service_packages','facilities',
                             'articles','news','videos'] LOOP
        EXECUTE format(
            'DROP TRIGGER IF EXISTS trg_%1$s_updated ON webcompro.%1$s;
             CREATE TRIGGER trg_%1$s_updated BEFORE UPDATE ON webcompro.%1$s
             FOR EACH ROW EXECUTE FUNCTION webcompro.sentuh_updated_at();', t);
    END LOOP;
END $$;

COMMIT;

\echo ===== Tabel yang terbentuk =====
SELECT table_name FROM information_schema.tables
 WHERE table_schema = 'webcompro' ORDER BY table_name;
