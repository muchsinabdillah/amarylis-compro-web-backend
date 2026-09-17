-- =====================================================================
-- Data awal webcompro — peran, hak akses, pengaturan, kategori
-- =====================================================================
--
-- Yang diisi di sini hanyalah KERANGKA: peran, hak akses, kunci pengaturan,
-- dan nama kategori. Tidak ada satu pun data klinis, harga, nama dokter,
-- nomor WhatsApp, atau kegiatan yang dikarang.
--
-- Pengaturan yang nilainya belum diketahui sengaja diisi penanda
-- "[DATA BELUM TERSEDIA]" — bukan dikosongkan. Kolom kosong terbaca sebagai
-- "belum sempat diisi" dan mudah terlewat; penanda ini muncul terang-terangan
-- di halaman sampai seseorang menggantinya.
--
-- Profil klinik TIDAK diisi ulang di sini. Nama, alamat, telepon, dan email
-- dibaca langsung dari MasterdataSQL.A_DATA_RS agar tidak pernah ada dua
-- versi alamat klinik yang berbeda.
--
-- Aman diulang.
-- =====================================================================

BEGIN;

-- ---------------------------------------------------------------------
-- Peran
-- ---------------------------------------------------------------------
INSERT INTO webcompro.roles (kode, nama, keterangan) VALUES
    ('SUPER_ADMIN',   'Super Admin',    'Akses penuh, termasuk pengguna dan pengaturan sistem.'),
    ('ADMIN_CONTENT', 'Admin Konten',   'Mengelola artikel, berita, video, layanan, MCU, homecare, dan galeri.'),
    ('ADMIN_KLINIK',  'Admin Klinik',   'Mengelola profil dokter, jadwal tampil, dan layanan.')
ON CONFLICT DO NOTHING;

-- ---------------------------------------------------------------------
-- Hak akses
-- ---------------------------------------------------------------------
INSERT INTO webcompro.permissions (kode, modul, nama)
SELECT v.kode, v.modul, v.nama FROM (VALUES
    ('setting.view',    'settings', 'Lihat pengaturan'),
    ('setting.update',  'settings', 'Ubah pengaturan'),
    ('page.manage',     'pages',    'Kelola halaman statis'),
    ('service.manage',  'services', 'Kelola layanan'),
    ('mcu.manage',      'mcu',      'Kelola paket MCU'),
    ('homecare.manage', 'homecare', 'Kelola layanan homecare'),
    ('doctor.manage',   'doctors',  'Kelola profil dokter yang ditampilkan'),
    ('facility.manage', 'facility', 'Kelola fasilitas & galeri'),
    ('article.manage',  'articles', 'Kelola artikel'),
    ('news.manage',     'news',     'Kelola berita & kegiatan'),
    ('video.manage',    'videos',   'Kelola video'),
    ('category.manage', 'taxonomy', 'Kelola kategori & tag'),
    ('media.manage',    'media',    'Kelola pustaka media'),
    ('user.manage',     'users',    'Kelola pengguna, peran, dan hak akses')
) AS v(kode, modul, nama)
WHERE NOT EXISTS (SELECT 1 FROM webcompro.permissions p WHERE lower(p.kode) = v.kode);

-- Super Admin: seluruhnya.
INSERT INTO webcompro.role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM webcompro.roles r CROSS JOIN webcompro.permissions p
 WHERE upper(r.kode) = 'SUPER_ADMIN'
ON CONFLICT DO NOTHING;

-- Admin Konten: konten & pemasaran, tanpa pengguna dan pengaturan sistem.
INSERT INTO webcompro.role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM webcompro.roles r JOIN webcompro.permissions p ON true
 WHERE upper(r.kode) = 'ADMIN_CONTENT'
   AND p.kode IN ('page.manage','service.manage','mcu.manage','homecare.manage',
                  'facility.manage','article.manage','news.manage','video.manage',
                  'category.manage','media.manage','setting.view')
ON CONFLICT DO NOTHING;

-- Admin Klinik: dokter & layanan.
INSERT INTO webcompro.role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM webcompro.roles r JOIN webcompro.permissions p ON true
 WHERE upper(r.kode) = 'ADMIN_KLINIK'
   AND p.kode IN ('doctor.manage','service.manage','media.manage','setting.view')
ON CONFLICT DO NOTHING;

-- ---------------------------------------------------------------------
-- Pengaturan situs
-- ---------------------------------------------------------------------
INSERT INTO webcompro.settings (key, value, grup, label, keterangan, tipe, urutan)
SELECT v.* FROM (VALUES
    -- Kontak. Nilai yang bisa dibaca dari A_DATA_RS dibiarkan kosong dengan
    -- sengaja: lapisan pembaca mengambilnya dari sana, dan mengisinya di sini
    -- justru menciptakan versi kedua yang bisa berbeda.
    ('whatsapp_number', '[DATA BELUM TERSEDIA]', 'kontak', 'Nomor WhatsApp',
     'Format internasional tanpa tanda plus, mis. 6281234567890. Dipakai SELURUH tombol WhatsApp di situs.', 'tel', 10),
    ('whatsapp_greeting', 'Hallo Klinik Pratama Andini, saya ingin mendapatkan informasi mengenai', 'kontak',
     'Awalan pesan WhatsApp', 'Nama layanan/paket ditambahkan otomatis di belakangnya.', 'text', 20),
    ('email_publik', '', 'kontak', 'Email publik',
     'Kosongkan untuk memakai email pada Data RS (SIMRS).', 'text', 30),
    ('maps_embed_url', '[DATA BELUM TERSEDIA]', 'kontak', 'URL Google Maps Embed',
     'Salin dari Google Maps > Bagikan > Sematkan peta. Hanya bagian src iframe-nya.', 'url', 40),
    ('jam_operasional', 'Setiap hari sampai pukul 21.00', 'kontak', 'Jam operasional', NULL, 'text', 50),

    -- Media sosial
    ('sosial_instagram', '', 'sosial', 'Instagram', 'URL lengkap. Kosongkan bila tidak ada.', 'url', 10),
    ('sosial_facebook',  '', 'sosial', 'Facebook',  'URL lengkap. Kosongkan bila tidak ada.', 'url', 20),
    ('sosial_youtube',   '', 'sosial', 'YouTube',   'URL lengkap. Kosongkan bila tidak ada.', 'url', 30),
    ('sosial_tiktok',    '', 'sosial', 'TikTok',    'URL lengkap. Kosongkan bila tidak ada.', 'url', 40),

    -- Merek
    ('logo',    '', 'merek', 'Logo situs',  'Kosongkan untuk memakai logo pada Data RS.', 'image', 10),
    ('favicon', '', 'merek', 'Favicon',     NULL, 'image', 20),

    -- Beranda
    ('hero_judul', 'Pelayanan Kesehatan Profesional untuk Anda dan Keluarga', 'beranda',
     'Judul hero', NULL, 'text', 10),
    ('hero_subjudul',
     'Memberikan pelayanan kesehatan yang nyaman, profesional, dan terpercaya untuk kebutuhan kesehatan Anda.',
     'beranda', 'Subjudul hero', NULL, 'textarea', 20),
    ('hero_image', '', 'beranda', 'Gambar hero', NULL, 'image', 30),

    -- SEO
    ('seo_title_default', 'Klinik Pratama Andini — Pelayanan Kesehatan di Cinere, Depok', 'seo',
     'Judul SEO bawaan', NULL, 'text', 10),
    ('seo_description_default',
     'Klinik Pratama Andini melayani pemeriksaan umum, Medical Check Up, laboratorium, dan homecare di Cinere, Depok.',
     'seo', 'Deskripsi SEO bawaan', NULL, 'textarea', 20),
    ('og_image_default', '', 'seo', 'Gambar Open Graph bawaan',
     'Dipakai saat tautan dibagikan ke WhatsApp/Facebook bila konten belum punya gambarnya sendiri.', 'image', 30)
) AS v(key, value, grup, label, keterangan, tipe, urutan)
WHERE NOT EXISTS (SELECT 1 FROM webcompro.settings s WHERE s.key = v.key);

-- ---------------------------------------------------------------------
-- Kategori awal
-- ---------------------------------------------------------------------
INSERT INTO webcompro.categories (tipe, nama, slug, urutan)
SELECT v.* FROM (VALUES
    ('article',  'Tips Kesehatan',        'tips-kesehatan',        10),
    ('article',  'Edukasi Kesehatan',     'edukasi-kesehatan',     20),
    ('article',  'Informasi Layanan',     'informasi-layanan',     30),
    ('news',     'Kegiatan Klinik',       'kegiatan-klinik',       10),
    ('news',     'Kerja Sama Perusahaan', 'kerja-sama-perusahaan', 20),
    ('news',     'Pengumuman',            'pengumuman',            30),
    ('video',    'Edukasi Kesehatan',     'video-edukasi',         10),
    ('video',    'Kegiatan Klinik',       'video-kegiatan',        20),
    ('service',  'Layanan Medis',         'layanan-medis',         10),
    ('service',  'Layanan Penunjang',     'layanan-penunjang',     20),
    ('mcu',      'Perorangan',            'mcu-perorangan',        10),
    ('mcu',      'Perusahaan',            'mcu-perusahaan',        20),
    ('homecare', 'Kunjungan Tenaga Medis','homecare-kunjungan',    10),
    ('homecare', 'Perawatan di Rumah',    'homecare-perawatan',    20)
) AS v(tipe, nama, slug, urutan)
WHERE NOT EXISTS (
    SELECT 1 FROM webcompro.categories c WHERE c.tipe = v.tipe AND lower(c.slug) = v.slug
);

-- ---------------------------------------------------------------------
-- Halaman statis
-- ---------------------------------------------------------------------
INSERT INTO webcompro.pages (slug, judul, konten, status)
SELECT v.slug, v.judul, v.konten, v.status::webcompro.status_konten FROM (VALUES
    ('tentang-kami', 'Tentang Kami', '[DATA BELUM TERSEDIA] — isi profil klinik lewat CMS.', 'draft'::text),
    ('beranda',      'Beranda',      NULL, 'published'::text)
) AS v(slug, judul, konten, status)
WHERE NOT EXISTS (SELECT 1 FROM webcompro.pages p WHERE lower(p.slug) = v.slug);

UPDATE webcompro.pages SET status = 'published' WHERE slug = 'beranda' AND status <> 'published';

COMMIT;

\echo ===== Ringkasan data awal =====
SELECT 'roles' AS tabel, count(*) FROM webcompro.roles
UNION ALL SELECT 'permissions', count(*) FROM webcompro.permissions
UNION ALL SELECT 'role_permissions', count(*) FROM webcompro.role_permissions
UNION ALL SELECT 'settings', count(*) FROM webcompro.settings
UNION ALL SELECT 'categories', count(*) FROM webcompro.categories
UNION ALL SELECT 'pages', count(*) FROM webcompro.pages;

\echo ===== Pengaturan yang MASIH menunggu diisi =====
SELECT key, label FROM webcompro.settings WHERE value = '[DATA BELUM TERSEDIA]' ORDER BY grup, urutan;
