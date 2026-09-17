-- =====================================================================
-- Isi pengaturan kontak yang sudah dipastikan klinik
-- =====================================================================
--
-- Peta disimpan sebagai URL src-nya saja, bukan seluruh tag <iframe>.
-- Menyimpan potongan HTML mentah dari luar lalu menempelkannya ke halaman
-- adalah jalan masuk XSS yang paling sering terlewat; menyimpan URL-nya
-- membuat halaman merakit sendiri iframe-nya dengan atribut yang sudah
-- ditentukan (sandbox, loading, referrerpolicy).
--
-- Koordinat dipisah karena dibutuhkan hal lain selain peta: penanda
-- LocalBusiness pada SEO, dan tautan "Buka di Google Maps" untuk ponsel
-- yang lebih baik membuka aplikasi petanya daripada memuat iframe.
--
-- NOMOR WHATSAPP SENGAJA BELUM DIISI.
--
-- Yang tersedia baru (021) 75919401 — nomor telepon kabel. WhatsApp pada
-- nomor kabel hanya berfungsi bila didaftarkan sebagai WhatsApp Business
-- dengan verifikasi suara. Bila dipasang tanpa kepastian itu, SETIAP tombol
-- WhatsApp di situs akan membuka percakapan ke nomor yang tidak terdaftar,
-- dan kegagalannya tidak terlihat oleh siapa pun di klinik — hanya oleh
-- calon pasien yang lalu pergi.
--
-- Penanda [DATA BELUM TERSEDIA] dibiarkan sampai nomornya dipastikan.
-- =====================================================================

BEGIN;

UPDATE webcompro.settings
   SET value = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3965.483722515551!2d106.79702757499128!3d-6.331318193658271!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x2e69ef68303fa339%3A0xf754a5959dee8729!2sKLINIK%20PRATAMA%20ANDINI!5e0!3m2!1sen!2sid!4v1788845691029!5m2!1sen!2sid',
       updated_at = now(), updated_by = 'migrasi-004'
 WHERE key = 'maps_embed_url';

INSERT INTO webcompro.settings (key, value, grup, label, keterangan, tipe, urutan)
SELECT v.* FROM (VALUES
    ('telepon', '(021) 75919401', 'kontak', 'Telepon klinik',
     'Nomor yang ditampilkan dan dijadikan tautan tel: di situs.', 'tel', 5),
    ('maps_lat', '-6.331318193658271', 'kontak', 'Lintang (latitude)',
     'Dipakai penanda LocalBusiness pada SEO dan tautan buka-di-Maps.', 'text', 41),
    ('maps_lng', '106.79702757499128', 'kontak', 'Bujur (longitude)', NULL, 'text', 42),
    ('maps_place_url', 'https://www.google.com/maps/search/?api=1&query=-6.331318193658271,106.79702757499128',
     'kontak', 'Tautan buka di Google Maps',
     'Dipakai tombol "Buka di Google Maps"; di ponsel membuka aplikasi petanya langsung.', 'url', 43)
) AS v(key, value, grup, label, keterangan, tipe, urutan)
WHERE NOT EXISTS (SELECT 1 FROM webcompro.settings s WHERE s.key = v.key);

-- Keterangan WhatsApp dipertegas agar yang mengisinya nanti tahu persis
-- bentuk yang dibutuhkan dan mengapa nomor kabel tidak dipakai begitu saja.
UPDATE webcompro.settings
   SET keterangan = 'Format internasional tanpa tanda plus, mis. 6281234567890. '
                    || 'Telepon klinik (021) 75919401 TIDAK dipakai di sini: WhatsApp pada nomor kabel '
                    || 'hanya berfungsi bila terdaftar sebagai WhatsApp Business. '
                    || 'Selama masih bertanda [DATA BELUM TERSEDIA], seluruh tombol WhatsApp di situs '
                    || 'dinonaktifkan dan digantikan tombol Telepon — bukan dibiarkan mengarah ke nomor yang salah.',
       updated_at = now(), updated_by = 'migrasi-004'
 WHERE key = 'whatsapp_number';

COMMIT;

\echo ===== Pengaturan kontak =====
SELECT key, label,
       CASE WHEN length(coalesce(value,'')) > 60 THEN left(value, 57) || '...' ELSE coalesce(value,'(kosong)') END AS nilai
  FROM webcompro.settings WHERE grup = 'kontak' ORDER BY urutan;
