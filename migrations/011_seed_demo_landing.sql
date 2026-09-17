-- =====================================================================
--  SEED DEMO — isi contoh agar landing page terlihat "hidup".
--
--  Aman & idempoten:
--   - settings: hanya mengisi yang MASIH kosong / placeholder (tak menimpa
--     nilai yang sudah Anda atur).
--   - konten (layanan, artikel, fasilitas, dokter demo): hanya menambah bila
--     slug-nya belum ada — jalankan berkali-kali tak menggandakan.
--   - TIDAK menyentuh paket (service_packages) — itu dari sinkron SIMRS.
--
--  Menghapus data demo nanti: hapus baris dengan slug berawalan 'demo-' atau
--  dokter dengan sync_status='manual' & simrs_id IS NULL.
--
--  Jalankan: psql -U postgres -d webcompro -f migrations/011_seed_demo_landing.sql
-- =====================================================================
SET search_path TO webcompro, public;

-- ------------------------------------------------------------ 1. SETTINGS
-- Hanya isi yang kosong/placeholder. WhatsApp demo agar tombol CTA tampil.
INSERT INTO webcompro.settings (key, value, grup) VALUES
  ('whatsapp_number',   '6281234567890', 'kontak'),
  ('whatsapp_greeting', 'Halo Klinik Pratama Andini, saya ingin bertanya mengenai', 'kontak'),
  ('telepon',           '(021) 8765 4321', 'kontak'),
  ('email_publik',      'info@klinikandini.id', 'kontak'),
  ('jam_operasional',   'Senin–Sabtu 08.00–20.00 · Minggu 08.00–14.00', 'umum'),
  ('hero_judul',        'Pelayanan kesehatan keluarga yang dekat & bisa diandalkan', 'beranda'),
  ('hero_subjudul',     'Klinik Pratama Andini melayani pemeriksaan umum, medical check-up, laboratorium, dan kunjungan homecare — dengan reservasi online tanpa antre lama.', 'beranda'),
  ('hero_image',        'https://picsum.photos/seed/andini-hero/1000/750', 'beranda'),
  ('sosial_instagram',  'https://instagram.com/klinikandini', 'sosial'),
  ('sosial_facebook',   'https://facebook.com/klinikandini', 'sosial')
ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value
  WHERE webcompro.settings.value IS NULL
     OR btrim(webcompro.settings.value) = ''
     OR webcompro.settings.value LIKE '%BELUM TERSEDIA%';

-- ------------------------------------------------------------ 2. KATEGORI
INSERT INTO webcompro.categories (tipe, nama, slug, urutan)
SELECT v.tipe, v.nama, v.slug, v.urutan
FROM (VALUES
  ('service', 'Rawat Jalan',        'rawat-jalan', 1),
  ('service', 'Penunjang Medis',    'penunjang-medis', 2),
  ('article', 'Tips Kesehatan',     'tips-kesehatan', 1),
  ('article', 'Info Klinik',        'info-klinik', 2)
) AS v(tipe, nama, slug, urutan)
WHERE NOT EXISTS (
  SELECT 1 FROM webcompro.categories c WHERE c.tipe = v.tipe AND lower(c.slug) = lower(v.slug));

-- ------------------------------------------------------------ 3. LAYANAN
INSERT INTO webcompro.services (nama, slug, ringkas, deskripsi, image, harga, jenis_harga, status, urutan, is_featured)
SELECT v.nama, v.slug, v.ringkas, v.deskripsi, v.image, v.harga::numeric,
       v.jenis_harga::webcompro.jenis_harga, v.status::webcompro.status_konten, v.urutan, v.is_featured
FROM (VALUES
  ('Pemeriksaan Umum', 'demo-pemeriksaan-umum',
   'Konsultasi dan pemeriksaan oleh dokter umum untuk keluhan sehari-hari.',
   '<p>Layanan pemeriksaan umum menangani keluhan seperti demam, batuk-pilek, nyeri, hingga kontrol penyakit kronis. Dokter kami memberikan diagnosis, resep, dan rujukan bila diperlukan.</p>',
   'https://loremflickr.com/800/600/doctor?lock=31', NULL, 'contact_us', 'published', 1, true),
  ('Medical Check-Up', 'demo-medical-check-up',
   'Paket pemeriksaan kesehatan menyeluruh untuk deteksi dini.',
   '<p>Medical check-up membantu mengetahui kondisi kesehatan Anda secara menyeluruh: pemeriksaan fisik, laboratorium, hingga rontgen. Cocok untuk syarat kerja maupun pemantauan berkala.</p>',
   'https://loremflickr.com/800/600/medical?lock=32', NULL, 'contact_us', 'published', 2, true),
  ('Laboratorium', 'demo-laboratorium',
   'Pemeriksaan darah, urin, dan penunjang lain dengan hasil cepat.',
   '<p>Laboratorium klinik kami melayani pemeriksaan darah lengkap, gula darah, kolesterol, asam urat, dan lainnya dengan hasil yang cepat dan akurat.</p>',
   'https://loremflickr.com/800/600/laboratory?lock=33', NULL, 'contact_us', 'published', 3, false),
  ('Vaksinasi & Imunisasi', 'demo-vaksinasi',
   'Imunisasi anak dan vaksin dewasa sesuai jadwal.',
   '<p>Kami menyediakan imunisasi dasar anak dan vaksin dewasa (influenza, hepatitis, dan lainnya) dengan pendampingan tenaga medis.</p>',
   'https://loremflickr.com/800/600/vaccine?lock=34', NULL, 'contact_us', 'published', 4, false),
  ('Kesehatan Ibu & Anak', 'demo-kia',
   'Pemeriksaan kehamilan, KB, dan tumbuh kembang anak.',
   '<p>Layanan KIA meliputi pemeriksaan kehamilan (ANC), keluarga berencana, serta pemantauan tumbuh kembang anak oleh tenaga terlatih.</p>',
   'https://loremflickr.com/800/600/pregnancy?lock=35', NULL, 'contact_us', 'published', 5, false),
  ('Poli Gigi', 'demo-poli-gigi',
   'Perawatan gigi: tambal, cabut, dan pembersihan karang gigi.',
   '<p>Poli gigi kami melayani penambalan, pencabutan, pembersihan karang gigi (scaling), dan konsultasi kesehatan gigi dan mulut.</p>',
   'https://loremflickr.com/800/600/dentist?lock=36', NULL, 'contact_us', 'published', 6, false)
) AS v(nama, slug, ringkas, deskripsi, image, harga, jenis_harga, status, urutan, is_featured)
WHERE NOT EXISTS (SELECT 1 FROM webcompro.services s WHERE lower(s.slug) = lower(v.slug));

-- ------------------------------------------------------------ 4. ARTIKEL
INSERT INTO webcompro.articles (judul, slug, excerpt, konten, thumbnail, status, published_at, reading_minutes, is_featured)
SELECT v.judul, v.slug, v.excerpt, v.konten, v.thumbnail,
       v.status::webcompro.status_konten, now() - (v.hari || ' days')::interval, v.baca, v.unggulan
FROM (VALUES
  ('5 Tips Menjaga Kesehatan Jantung', 'demo-tips-jantung',
   'Langkah sederhana sehari-hari untuk menjaga jantung tetap sehat.',
   '<p>Jantung sehat dimulai dari kebiasaan harian: makan bergizi seimbang, aktif bergerak minimal 30 menit sehari, tidur cukup, mengelola stres, dan rutin memeriksakan tekanan darah. Hindari rokok dan batasi gula serta garam.</p>',
   'https://picsum.photos/seed/andini-art1/800/500', 'published', 2, 4, true),
  ('Pentingnya Medical Check-Up Rutin', 'demo-mcu-rutin',
   'Kenali manfaat MCU berkala untuk deteksi dini penyakit.',
   '<p>Medical check-up rutin membantu menemukan masalah kesehatan sebelum bergejala. Deteksi dini membuat penanganan lebih mudah, lebih murah, dan lebih efektif. Disarankan minimal setahun sekali, terutama di atas usia 35 tahun.</p>',
   'https://picsum.photos/seed/andini-art2/800/500', 'published', 6, 3, false),
  ('Cara Mencegah Demam Berdarah di Musim Hujan', 'demo-cegah-dbd',
   'Terapkan 3M Plus untuk memutus rantai penularan DBD.',
   '<p>Cegah demam berdarah dengan 3M Plus: menguras, menutup, dan mendaur ulang tempat penampungan air, ditambah memakai lotion anti-nyamuk dan menanam tanaman pengusir nyamuk. Segera periksakan diri bila demam tinggi lebih dari dua hari.</p>',
   'https://picsum.photos/seed/andini-art3/800/500', 'published', 10, 3, false)
) AS v(judul, slug, excerpt, konten, thumbnail, status, hari, baca, unggulan)
WHERE NOT EXISTS (SELECT 1 FROM webcompro.articles a WHERE lower(a.slug) = lower(v.slug));

-- ------------------------------------------------------------ 5. FASILITAS
INSERT INTO webcompro.facilities (nama, slug, deskripsi, image, urutan, is_active)
SELECT v.nama, v.slug, v.deskripsi, v.image, v.urutan, true
FROM (VALUES
  ('Ruang Tunggu Nyaman', 'demo-ruang-tunggu', 'Ruang tunggu ber-AC yang bersih dan luas.', 'https://loremflickr.com/700/500/clinic?lock=41', 1),
  ('Laboratorium', 'demo-fasilitas-lab', 'Laboratorium dengan alat modern dan hasil cepat.', 'https://loremflickr.com/700/500/laboratory?lock=42', 2),
  ('Farmasi / Apotek', 'demo-farmasi', 'Apotek dengan obat lengkap di dalam klinik.', 'https://loremflickr.com/700/500/pharmacy?lock=43', 3),
  ('Ambulans Siaga', 'demo-ambulans', 'Layanan ambulans siaga untuk kondisi darurat.', 'https://loremflickr.com/700/500/ambulance?lock=44', 4)
) AS v(nama, slug, deskripsi, image, urutan)
WHERE NOT EXISTS (SELECT 1 FROM webcompro.facilities f WHERE lower(f.slug) = lower(v.slug));

-- Catatan: DOKTER, JADWAL DOKTER, dan TARIF/PAKET sengaja TIDAK di-seed —
-- semuanya berasal dari integrasi/sinkron SIMRS. Untuk menampilkan dokter di
-- landing, tayangkan dokter hasil sinkron lewat CMS → Dokter.

-- ------------------------------------------------------------ 6. HALAMAN "Tentang Kami"
INSERT INTO webcompro.pages (slug, judul, konten, hero_image, status)
SELECT 'tentang-kami', 'Tentang Kami',
  '<p>Klinik Pratama Andini hadir untuk memberikan pelayanan kesehatan primer yang ramah, cepat, dan terjangkau bagi keluarga Indonesia. Didukung dokter berpengalaman dan fasilitas yang memadai, kami berkomitmen menjadi mitra kesehatan Anda.</p><p><strong>Visi:</strong> Menjadi klinik keluarga pilihan utama di wilayah kami.</p><p><strong>Misi:</strong> Memberikan layanan bermutu, mengedepankan pencegahan, dan melayani dengan hati.</p>',
  'https://picsum.photos/seed/andini-about/1000/500', 'published'
WHERE NOT EXISTS (SELECT 1 FROM webcompro.pages p WHERE lower(p.slug) = 'tentang-kami');

-- Bila halaman "tentang-kami" sudah ada tapi masih draf/kosong: tayangkan & isi.
UPDATE webcompro.pages
   SET status = 'published',
       hero_image = COALESCE(NULLIF(btrim(hero_image), ''), 'https://picsum.photos/seed/andini-about/1000/500'),
       konten = CASE
         WHEN coalesce(btrim(konten), '') = '' OR konten LIKE '%BELUM TERSEDIA%'
         THEN '<p>Klinik Pratama Andini hadir untuk memberikan pelayanan kesehatan primer yang ramah, cepat, dan terjangkau bagi keluarga Indonesia. Didukung dokter berpengalaman dan fasilitas yang memadai, kami berkomitmen menjadi mitra kesehatan Anda.</p><p><strong>Visi:</strong> Menjadi klinik keluarga pilihan utama di wilayah kami.</p><p><strong>Misi:</strong> Memberikan layanan bermutu, mengedepankan pencegahan, dan melayani dengan hati.</p>'
         ELSE konten END
 WHERE lower(slug) = 'tentang-kami' AND status <> 'published';
