-- ============================================================================
-- 2026-09-13 — SEED DEMO — Van Lang News CMS (MySQL 8.0.19+, utf8mb4_0900_ai_ci)
-- ----------------------------------------------------------------------------
-- Mục đích : kho dữ liệu demo cho MỌI bảng (16/16 theo schema.sql) để xem thử
--            trang chủ, danh sách tin, chi tiết, hộp thư, media, team, settings.
-- Chạy     : mysql -u root -p news_system < data/schema/2026-09-13-seed-demo.sql
--            (file TỰ CHỨ: không cần chạy seed.sql trước — settings +
--             home_sections được dựng lại đầy đủ trong đây)
-- CẢNH BÁO : môi trường DEMO/LOCAL. Phần dữ liệu nội dung (media → home_section_items)
--            sẽ bị XOÁ (DELETE) rồi chèn lại để file chạy idempotent và giữ quan hệ
--            ID cố định. Bảng `users` KHÔNG xoá (luật 1 dòng — AGENTS §3): dòng admin
--            chỉ được chèn nếu chưa tồn tại (INSERT IGNORE, cùng hash với
--            2026-09-13-seed-admin.sql — đăng nhập admin@vanlang.vn / Admin@1234).
-- Thời gian: mọi DATETIME lưu UTC (README §Quy tắc); bài "hẹn giờ" = status 1 +
--            publishedAt tương lai, khớp ContentConst.
-- Media    : các path trỏ file trong public/uploads/. File gốc + biến thể thumb đã
--            sinh kèm bằng script placeholder — ảnh màu gradient, không phải ảnh thật.
-- Mã hằng dùng trong file (đối chiếu code):
--   posts.status        : 0 nháp · 1 xuất bản · 2 lưu trữ          (ContentConst)
--   post_revisions.type : 0 manual · 1 autosave · 2 before-publish (PostRevisionMapper)
--   home_sections.type  : 1 hero · 2 featured · 3 latest · 4 category_posts
--                         5 services · 6 team · 7 contact_cta      (HomeSectionConst)
--   home_section_items.itemType : 1 post · 2 service · 3 team      (ContentConst)
--   settings.valueType  : 1 string · 2 text · 3 html · 4 number · 5 bool · 6 json · 7 media
--   contact.status      : 0 mới · 1 đang xử lý · 2 xong · 3 spam   (ContactConst)
--   banners.position    : home_hero · news_top                      (BannerConst)
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- 0) DỌN DỮ LIỆU CŨ theo chiều ngược quan hệ (không FK — dọn tay theo thứ tự)
-- ---------------------------------------------------------------------------
DELETE FROM home_section_items;
DELETE FROM home_sections;
DELETE FROM banners;
DELETE FROM services;
DELETE FROM team_members;
DELETE FROM post_tags;
DELETE FROM post_revisions;
DELETE FROM post_view_daily;
DELETE FROM posts;
DELETE FROM tags;
DELETE FROM categories;
DELETE FROM contact_submissions;
DELETE FROM password_reset_tokens;
DELETE FROM media;
DELETE FROM settings;

-- ---------------------------------------------------------------------------
-- 1) users — LUẬT 1 DÒNG: chỉ chèn nếu chưa có (không xoá, không reset mật khẩu
--    của admin hiện hữu). Trùng với 2026-09-13-seed-admin.sql (bcrypt Admin@1234).
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO users
  (id, fullName, email, username, passwordHash, phone, failedLoginCount, lockedUntil, lastLoginAt, createdAt, updatedAt)
VALUES
  (1, 'Quan tri Van Lang', 'admin@vanlang.vn', 'admin',
   '$2y$10$.syAimGTrgSugau9W7na/.bNoxO0TpqCxS459BiYl7MtoggwEsVPO',
   '0900000000', 0, NULL, '2026-09-13 03:20:00', '2026-09-12 08:00:00', '2026-09-13 03:20:00');

-- ---------------------------------------------------------------------------
-- 2) media — 26 dòng: logo/OG (1-2), hero (3-5), cover danh mục (6-8),
--    banner bài viết (9-18), dịch vụ (19-22), avatar đội ngũ (23-26).
--    variants: thumb/medium/large theo MediaService (GD hạ kích thước).
-- ---------------------------------------------------------------------------
INSERT INTO media (id, disk, path, originalName, mimeType, sizeBytes, width, height, altText, variants, uploadedBy, createdAt, updatedAt) VALUES
  (1,  'local', '2026/09/logo-van-lang.png',              'logo-van-lang.png',       'image/png',  18432, 320, 96,  'Logo Trung tâm Vạn Lang',            JSON_OBJECT('thumb','2026/09/thumb/logo-van-lang.png','medium','2026/09/medium/logo-van-lang.png','large','2026/09/large/logo-van-lang.png'), 1, '2026-09-12 08:10:00', '2026-09-12 08:10:00'),
  (2,  'local', '2026/09/og-mac-dinh.png',                'og-default.png',              'image/png',  45056, 1200, 630,'Ảnh chia sẻ mặc định Vạn Lang',          JSON_OBJECT('thumb','2026/09/thumb/og-mac-dinh.png','medium','2026/09/medium/og-mac-dinh.png','large','2026/09/large/og-mac-dinh.png'), 1, '2026-09-12 08:11:00', '2026-09-12 08:11:00'),
  (3,  'local', '2026/09/hero-cham-soc-tam-tam.jpg',      'hero-cham-soc-tam-tam.jpg','image/jpeg', 240640, 1600, 600,'Bác sĩ ân cần trò chuyện cùng người cao tuổi', JSON_OBJECT('thumb','2026/09/thumb/hero-cham-soc-tam-tam.jpg','medium','2026/09/medium/hero-cham-soc-tam-tam.jpg','large','2026/09/large/hero-cham-soc-tam-tam.jpg'), 1, '2026-09-12 08:12:00', '2026-09-12 08:12:00'),
  (4,  'local', '2026/09/hero-phuc-hoi-chuc-nang.jpg',    'hero-phuc-hoi-chuc-nang.jpg','image/jpeg',225280, 1600, 600,'Khu tập phục hồi chức năng sáng sửa',   JSON_OBJECT('thumb','2026/09/thumb/hero-phuc-hoi-chuc-nang.jpg','medium','2026/09/medium/hero-phuc-hoi-chuc-nang.jpg','large','2026/09/large/hero-phuc-hoi-chuc-nang.jpg'), 1, '2026-09-12 08:13:00', '2026-09-12 08:13:00'),
  (5,  'local', '2026/09/hero-dich-vu-tai-nha.jpg',       'hero-dich-vu-tai-nha.jpg', 'image/jpeg', 231424, 1600, 600,'Điều dưỡng chăm sóc người bệnh tại nhà', JSON_OBJECT('thumb','2026/09/thumb/hero-dich-vu-tai-nha.jpg','medium','2026/09/medium/hero-dich-vu-tai-nha.jpg','large','2026/09/large/hero-dich-vu-tai-nha.jpg'), 1, '2026-09-12 08:14:00', '2026-09-12 08:14:00'),
  (6,  'local', '2026/09/cover-suc-khoe-doi-song.jpg',    'cover-suc-khoe.jpg',       'image/jpeg', 154624, 1200, 400,'Ảnh bìa chuyên mục Sức khỏe đời sống',  JSON_OBJECT('thumb','2026/09/thumb/cover-suc-khoe-doi-song.jpg','medium','2026/09/medium/cover-suc-khoe-doi-song.jpg'), 1, '2026-09-12 08:15:00', '2026-09-12 08:15:00'),
  (7,  'local', '2026/09/cover-cham-soc-ncu.jpg',         'cover-cham-soc.jpg',       'image/jpeg', 149504, 1200, 400,'Ảnh bìa chuyên mục Chăm sóc người cao tuổi', JSON_OBJECT('thumb','2026/09/thumb/cover-cham-soc-ncu.jpg','medium','2026/09/medium/cover-cham-soc-ncu.jpg'), 1, '2026-09-12 08:16:00', '2026-09-12 08:16:00'),
  (8,  'local', '2026/09/cover-dinh-duong.jpg',           'cover-dinh-duong.jpg',     'image/jpeg', 160768, 1200, 400,'Bữa ăn đủ chất cho người cao tuổi',    JSON_OBJECT('thumb','2026/09/thumb/cover-dinh-duong.jpg','medium','2026/09/medium/cover-dinh-duong.jpg'), 1, '2026-09-12 08:17:00', '2026-09-12 08:17:00'),
  (9,  'local', 'post/2026/09/bai-doi-uc-o-ncu.jpg',      'doi-uc-o-ncu.jpg',         'image/jpeg', 189440, 1200, 675,'Người cao tuổi tập thở dưỡng sinh',    JSON_OBJECT('thumb','post/2026/09/thumb/bai-doi-uc-o-ncu.jpg','medium','post/2026/09/medium/bai-doi-uc-o-ncu.jpg'), 1, '2026-09-01 02:00:00', '2026-09-01 02:00:00'),
  (10, 'local', 'post/2026/09/bai-an-uong-mua-lanh.jpg',  'an-uong-mua-lanh.jpg',     'image/jpeg', 176128, 1200, 675,'Cháo dinh dưỡng ấm nóng ngày lạnh',    JSON_OBJECT('thumb','post/2026/09/thumb/bai-an-uong-mua-lanh.jpg','medium','post/2026/09/medium/bai-an-uong-mua-lanh.jpg'), 1, '2026-09-02 02:00:00', '2026-09-02 02:00:00'),
  (11, 'local', 'post/2026/09/bai-tap-vat-ly-tri-lieu.jpg','tap-vltl.jpg',            'image/jpeg', 182272, 1200, 675,'Kỹ thuật viên hướng dẫn tập trị liệu', JSON_OBJECT('thumb','post/2026/09/thumb/bai-tap-vat-ly-tri-lieu.jpg','medium','post/2026/09/medium/bai-tap-vat-ly-tri-lieu.jpg'), 1, '2026-09-03 02:00:00', '2026-09-03 02:00:00'),
  (12, 'local', 'post/2026/09/bai-nhat-ky-ngay.jpg',      'nhat-ky-ngay.jpg',         'image/jpeg', 171008, 1200, 675,'Bảng theo dõi sinh hiệu hằng ngày',    JSON_OBJECT('thumb','post/2026/09/thumb/bai-nhat-ky-ngay.jpg','medium','post/2026/09/medium/bai-nhat-ky-ngay.jpg'), 1, '2026-09-04 02:00:00', '2026-09-04 02:00:00'),
  (13, 'local', 'post/2026/09/bai-rao-chan-ngua-te-ngua.jpg','rao-chan.jpg',          'image/jpeg', 165888, 1200, 675,'Lắp thanh vịn chống trượt ngã',       JSON_OBJECT('thumb','post/2026/09/thumb/bai-rao-chan-ngua-te-ngua.jpg','medium','post/2026/09/medium/bai-rao-chan-ngua-te-ngua.jpg'), 1, '2026-09-05 02:00:00', '2026-09-05 02:00:00'),
  (14, 'local', 'post/2026/09/bai-tiem-chung-cum.jpg',    'tiem-chung.jpg',           'image/jpeg', 158720, 1200, 675,'Tiêm vắc xin cúm mùa cho người cao tuổi', JSON_OBJECT('thumb','post/2026/09/thumb/bai-tiem-chung-cum.jpg','medium','post/2026/09/medium/bai-tiem-chung-cum.jpg'), 1, '2026-09-06 02:00:00', '2026-09-06 02:00:00'),
  (15, 'local', 'post/2026/09/bai-xet-nghiem-tan-nha.jpg','xet-nghiem.jpg',           'image/jpeg', 150528, 1200, 675,'Lấy mẫu xét nghiệm tại nhà',           JSON_OBJECT('thumb','post/2026/09/thumb/bai-xet-nghiem-tan-nha.jpg','medium','post/2026/09/medium/bai-xet-nghiem-tan-nha.jpg'), 1, '2026-09-07 02:00:00', '2026-09-07 02:00:00'),
  (16, 'local', 'post/2026/09/bai-da-cao-tuoi.jpg',       'da-cao-tui.jpg',           'image/jpeg', 146432, 1200, 675,'Hội thảo chăm sóc da người cao tuổi',  JSON_OBJECT('thumb','post/2026/09/thumb/bai-da-cao-tuoi.jpg','medium','post/2026/09/medium/bai-da-cao-tuoi.jpg'), 1, '2026-09-08 02:00:00', '2026-09-08 02:00:00'),
  (17, 'local', 'post/2026/09/bai-phong-ngua-tai-bien.jpg','tai-bien.jpg',            'image/jpeg', 152576, 1200, 675,'Biểu đồ huyết áp và chế độ ăn nhạt',  JSON_OBJECT('thumb','post/2026/09/thumb/bai-phong-ngua-tai-bien.jpg','medium','post/2026/09/medium/bai-phong-ngua-tai-bien.jpg'), 1, '2026-09-09 02:00:00', '2026-09-09 02:00:00'),
  (18, 'local', 'post/2026/09/bai-gia-dinh-khoe.jpg',     'gia-dinh-khoe.jpg',        'image/jpeg', 148480, 1200, 675,'Gia đình ba thế hệ cùng tập thể dục',  JSON_OBJECT('thumb','post/2026/09/thumb/bai-gia-dinh-khoe.jpg','medium','post/2026/09/medium/bai-gia-dinh-khoe.jpg'), 1, '2026-09-10 02:00:00', '2026-09-10 02:00:00'),
  (19, 'local', '2026/09/dv-cham-soc-tai-nha.jpg',        'dv-cham-soc.jpg',          'image/jpeg', 122880, 800,  600,'Điều dưỡng đo huyết áp cho cụ ông',    JSON_OBJECT('thumb','2026/09/thumb/dv-cham-soc-tai-nha.jpg'), 1, '2026-09-11 02:00:00', '2026-09-11 02:00:00'),
  (20, 'local', '2026/09/dv-vat-ly-tri-lieu.jpg',         'dv-vltl.jpg',              'image/jpeg', 118784, 800,  600,'Phòng tập phục hồi chức năng',         JSON_OBJECT('thumb','2026/09/thumb/dv-vat-ly-tri-lieu.jpg'), 1, '2026-09-11 02:01:00', '2026-09-11 02:01:00'),
  (21, 'local', '2026/09/dv-truyen-dich.jpg',             'dv-truyen-dich.jpg',       'image/jpeg', 115712, 800,  600,'Truyền dịch tại nhà đúng quy trình',   JSON_OBJECT('thumb','2026/09/thumb/dv-truyen-dich.jpg'), 1, '2026-09-11 02:02:00', '2026-09-11 02:02:00'),
  (22, 'local', '2026/09/dv-xet-nghiem.jpg',              'dv-xet-nghiem.jpg',        'image/jpeg', 111616, 800,  600,'Bộ lấy mẫu xét nghiệm lưu động',       JSON_OBJECT('thumb','2026/09/thumb/dv-xet-nghiem.jpg'), 1, '2026-09-11 02:03:00', '2026-09-11 02:03:00'),
  (23, 'local', '2026/09/avatar-nguyen-thi-lan.jpg',      'avatar-lan.jpg',           'image/jpeg', 61440,  400,  400,'Ảnh BS. Nguyễn Thị Lan',               JSON_OBJECT('thumb','2026/09/thumb/avatar-nguyen-thi-lan.jpg'), 1, '2026-09-11 03:00:00', '2026-09-11 03:00:00'),
  (24, 'local', '2026/09/avatar-tran-van-minh.jpg',       'avatar-minh.jpg',          'image/jpeg', 59392,  400,  400,'Ảnh ThS.Trần Văn Minh',                JSON_OBJECT('thumb','2026/09/thumb/avatar-tran-van-minh.jpg'), 1, '2026-09-11 03:01:00', '2026-09-11 03:01:00'),
  (25, 'local', '2026/09/avatar-le-thi-huong.jpg',        'avatar-huong.jpg',         'image/jpeg', 58368,  400,  400,'Ảnh điều dưỡng trưởng Lê Thị Hương',   JSON_OBJECT('thumb','2026/09/thumb/avatar-le-thi-huong.jpg'), 1, '2026-09-11 03:02:00', '2026-09-11 03:02:00'),
  (26, 'local', '2026/09/avatar-pham-duc-thanh.jpg',      'avatar-thanh.jpg',         'image/jpeg', 57344,  400,  400,'Ảnh KTV Phạm Đức Thành',               JSON_OBJECT('thumb','2026/09/thumb/avatar-pham-duc-thanh.jpg'), 1, '2026-09-11 03:03:00', '2026-09-11 03:03:00');

-- ---------------------------------------------------------------------------
-- 3) categories — 5 danh mục cha + 3 danh mục con (cây tối đa 2 cấp, §3.2)
-- ---------------------------------------------------------------------------
INSERT INTO categories (id, parentId, name, slug, description, coverMediaId, sortOrder, isActive, metaTitle, metaDescription) VALUES
  (1, NULL, 'Sức khỏe đời sống',          'suc-khoe-doi-song',        'Kiến thức chăm sóc sức khỏe thường thức cho mọi gia đình.', 6, 1, 1, 'Sức khỏe đời sống — Vạn Lang', 'Tin tức sức khỏe đời sống, mẹo chăm sóc gia đình từ Trung tâm Vạn Lang.'),
  (2, NULL, 'Chăm sóc người cao tuổi',    'cham-soc-nguoi-cao-tuoi',  'Giải pháp chăm sóc toàn diện cho ông bà, cha mẹ.',           7, 2, 1, 'Chăm sóc người cao tuổi — Vạn Lang', 'Hướng dẫn chăm sóc người cao tuổi tại nhà và tại trung tâm.'),
  (3, NULL, 'Dinh dưỡng',                 'dinh-duong',               'Thực đơn, chế độ ăn cho người cao tuổi và người bệnh.',     8, 3, 1, NULL, NULL),
  (4, NULL, 'Hoạt động công ty',          'hoat-dong-cong-ty',        'Thông tin tuyển dụng, sự kiện và cộng đồng Vạn Lang.',      NULL, 4, 1, NULL, NULL),
  (5, NULL, 'Kiến thức y khoa',           'kien-thuc-y-khoa',         'Bài viết tham vấn y khoa từ đội ngũ bác sĩ.',               NULL, 5, 1, NULL, NULL),
  (6, 2,    'Chăm sóc tại nhà',           'cham-soc-tai-nha',         'Dịch vụ và hướng dẫn chăm sóc người bệnh tại nhà.',         NULL, 1, 1, NULL, NULL),
  (7, 2,    'Vật lý trị liệu',            'vat-ly-tri-lieu',          'Bài tập, kỹ thuật phục hồi chức năng phổ biến.',            NULL, 2, 1, NULL, NULL),
  (8, 3,    'Thực đơn hàng tuần',         'thuc-don-hang-tuan',       'Gợi ý thực đơn đủ chất theo mùa cho gia đình.',             NULL, 1, 1, NULL, NULL),
  (9, NULL, 'Tin thử nghiệm (ẩn)',        'an-thu-nghiem',            'Danh mục ẩn — dùng để test luồng lọc không hiện ra.',       NULL, 99, 0, NULL, NULL);

-- ---------------------------------------------------------------------------
-- 4) tags — 10 thẻ phủ các chủ đề bài viết
-- ---------------------------------------------------------------------------
INSERT INTO tags (id, name, slug) VALUES
  (1,  'Người cao tuổi',      'nguoi-cao-tuoi'),
  (2,  'Sức khỏe',            'suc-khoe'),
  (3,  'Chăm sóc tại nhà',    'cham-soc-tai-nha'),
  (4,  'Dinh dưỡng',          'dinh-duong'),
  (5,  'Vật lý trị liệu',     'vat-ly-tri-lieu'),
  (6,  'Hô hấp',              'ho-hap'),
  (7,  'An toàn tại nhà',     'an-toan-tai-nha'),
  (8,  'Tai biến',            'tai-bien'),
  (9,  'Tiêm chủng',          'tiem-chung'),
  (10, 'Giấc ngủ',            'giac-ngu');

-- ---------------------------------------------------------------------------
-- 5) posts — 16 bài: 10 đã xuất bản (5 bài nổi bật) + 1 hẹn giờ + 3 nháp
--    + 1 lưu trữ + 1 nháp đang có autosave. publishedAt tính UTC (VN = +7).
--    Draft không cần banner (filter chỉ chặn khi publish — 07 §6.2).
-- ---------------------------------------------------------------------------
INSERT INTO posts (id, categoryId, authorId, title, slug, excerpt, content, bannerMediaId, thumbnailMediaId, status, isFeatured, publishedAt, viewCount, readingMinutes, previewToken, metaTitle, metaDescription, createdAt, updatedAt) VALUES
  (1, 2, 1, '5 bài tập dưỡng sinh giúp người cao tuổi khỏe mỗi ngày', '5-bai-tap-duong-sinh-giup-nguoi-cao-tuoi-khoe-moi-ngay',
   'Dưỡng sinh đều đặn 15 phút mỗi sáng giúp cải thiện tuần hoàn, giấc ngủ và tâm trạng cho người từ 60 tuổi. Bác sĩ Vạn Lang hướng dẫn 5 động tác dễ thực hiện.',
   '<p>Dưỡng sinh là môn tập nhẹ nhàng, phù hợp nhất với người cao tuổi vì cường độ thấp nhưng tác động đến toàn bộ hệ cơ - xương - khớp và hô hấp. Theo bác sĩ Nguyễn Thị Lan, mỗi ngày chỉ cần 15 phút tập đều sẽ cải thiện rõ rệt chất lượng giấc ngủ và tinh thần.</p><p>Năm động tác cơ bản gồm: điều hòa hô hấp (thở bụng), vai tay khoanh trước, lưng gối cúi chạm, vận thế công bay và điều hòa kết thúc. Mỗi động tác lặp 8 lần, thở đều, không nín thở.</p><p>Lưu ý: nên khởi động kỹ cổ chân cổ tay, tránh tập sau khi ăn no, dừng lại ngay nếu chóng mặt hoặc đau ngực. Người có bệnh nền nên tham vấn bác sĩ trước khi thay đổi chế độ tập.</p>',
   9, 9, 1, 1, '2026-09-01 02:30:00', 1240, 2, NULL, NULL, NULL, '2026-09-01 02:00:00', '2026-09-05 03:10:00'),
  (2, 3, 1, 'Chế độ ăn uống mùa lạnh cho người cao tuổi: đủ chất, ấm bụng', 'che-do-an-uong-mua-lanh-cho-nguoi-cao-tuoi',
   'Mùa lạnh người cao tuổi dễ mất nhiệt, ăn gì để đủ năng lượng mà vẫn nhẹ bụng? Chuyên gia dinh dưỡng Vạn Lang gợi ý thực đơn ba bữa kèm cách chế biến.',
   '<p>Khi trời lạnh, nhu cầu năng lượng của cơ thể tăng lên nhưng vị giác và cảm giác khát lại giảm, khiến nhiều bác lớn tuổi ăn ít, uống ít nước. Điều này dễ gây mất nước, hạ đường huyết và táo bón.</p><p>Nguyên tắc chung: chia thành 4 - 5 bữa nhỏ, ưu tiên món nóng mềm như cháo, súp, canh hầm; bổ sung đạm dễ tiêu từ cá, trứng, đậu phụ; thêm gừng, nghệ để giữ ấm. Uống đủ 1,5 lít nước ấm mỗi ngày kể cả khi không khát.</p><p>Hạn chế đồ chiên nhiều dầu vào buổi tối và rượu bia - hai thủ phạm gây mất nhiệt về đêm. Nếu bác đang điều trị tiểu đường hoặc thận, hãy trao đổi với chuyên gia dinh dưỡng để điều chỉnh khẩu phần phù hợp.</p>',
   10, 10, 1, 1, '2026-09-03 03:00:00', 980, 2, NULL, NULL, NULL, '2026-09-02 09:00:00', '2026-09-03 03:00:00'),
  (3, 7, 1, 'Hướng dẫn bài tập phục hồi vận động sau tai biến tại nhà', 'huong-dan-bai-tap-phuc-hoi-van-dong-sau-tai-bien',
   'Phục hồi sau tai biến là hành trình cần kiên nhẫn. Kỹ thuật viên Vạn Lang chia sẻ giáo án tập 30 phút mỗi ngày cho giai đoạn ổn định.',
   '<p>Giai đoạn 3 - 6 tháng sau tai biến được xem là thời gian vàng để phục hồi vận động. Bên cạnh điều trị chuyên khoa, việc tập luyện đúng cách tại nhà giúp người bệnh lấy lại khả năng thăng bằng và phối hợp động tác.</p><p>Giáo án gợi ý gồm ba nhóm: tập tầm động khớp thụ động cho bên liệt, tập thăng bằng khi ngồi và đứng có người hỗ trợ, tập đi từng bước ngắn với khung trợ giúp. Mỗi buổi 30 phút, chia hai lần sáng chiều, nghỉ xen kẽ.</p><p>Tuyệt đối không kéo mạnh tay bên liệt vì dễ gây trật khớp vai. Dấu hiệu cần dừng và hỏi bác sĩ: đau tăng, tím tái bên liệt, huyết áp bất thường. Gia đình nên quay video buổi tập để kỹ thuật viên theo dõi từ xa.</p>',
   11, 11, 1, 1, '2026-09-04 04:00:00', 1530, 3, NULL, NULL, NULL, '2026-09-04 02:30:00', '2026-09-04 04:00:00'),
  (4, 6, 1, 'Nhật ký chăm sóc hằng ngày: công cụ nhỏ, lợi ích lớn', 'nhat-ky-cham-soc-hang-ngay',
   'Ghi lại thuốc ăn ngủ, sinh hiệu mỗi ngày giúp phát hiện sớm bất thường và làm việc hiệu quả hơn với bác sĩ. Mẫu nhật ký in sẵn trong bài.',
   '<p>Nhiều gia đình chủ quan cho rằng việc ghi chép là thừa, nhưng một cuốn nhật ký chăm sóc đơn giản lại là cầu nối quý giá giữa người bệnh và nhân viên y tế. Bác sĩ chỉ cần lướt qua sổ theo dõi huyết áp, đường huyết, giấc ngủ và bữa ăn trong hai tuần đã có thể điều chỉnh phác đồ chính xác hơn nhiều.</p><p>Cách ghi: mỗi ngày một dòng gồm ba cột - sinh hiệu (huyết áp, mạch, nhiệt độ nếu đo), ba bữa ăn và thuốc đã uống, tình trạng đặc biệt (mất ngủ, đau, khó tiêu). Nên đo huyết áp cùng khung giờ để số liệu so sánh được.</p><p>Trung tâm Vạn Lang cung cấp mẫu nhật ký in sẵn trong gói dịch vụ chăm sóc tại nhà. Khi cần tư vấn, gia đình có thể chụp ảnh trang nhật ký gửi kèm - tiết kiệm thời gian và tránh bỏ sót chi tiết.</p>',
   12, 12, 1, 0, '2026-09-05 02:00:00', 720, 3, NULL, NULL, NULL, '2026-09-05 01:40:00', '2026-09-05 02:00:00'),
  (5, 7, 1, 'Lắp đặt thanh vịn và chống trượt ngã trong nhà cho ông bà', 'lap-dat-thanh-vin-va-chong-truot-nga',
   'Ngã là nguyên nhân hàng đầu gây chấn thương ở người cao tuổi. Chỉ vài thay đổi nhỏ 200 - 500 nghìn đồng trong phòng tắm và cầu thang đã giảm rủi ro rõ rệt.',
   '<p>Phần lớn ca ngã ở người lớn tuổi xảy ra ngay trong chính ngôi nhà của họ, nhiều nhất là phòng tắm và cầu thang. Tin vui là đa số rủi ro này có thể giảm bằng những cải tạo rất đơn giản.</p><p>Checklist tối thiểu: thanh v inox ở bồn cầu và vòi sen; thảm chống trượt thật sự có đế hút (không phải thảm vải trơn); sơn phản quang mép bậc cầu thang; đèn cảm ứng hành lang ban đêm; bỏ hẳn dây điện chằng chịt và thảm cuộn ngang lối đi.</p><p>Với cầu thang dài, ngoài tay vịn hai bên nên bổ sung ghế nghỉ giữa tầng nếu người cao tuổi có bệnh tim mạch hoặc viêm khớp gối. Tổng chi phí cho một căn hộ phổ thông thường chỉ 2 - 5 triệu đồng nhưng giá trị phòng ngừa rất lớn.</p>',
   13, 13, 1, 1, '2026-09-06 03:30:00', 860, 2, NULL, NULL, NULL, '2026-09-06 02:00:00', '2026-09-06 03:30:00'),
  (6, 5, 1, 'Tiêm vắc xin cúm mùa cho người cao tuổi: hỏi - đáp cùng bác sĩ', 'tiem-vac-xin-cum-mua-cho-nguoi-cao-tuoi',
   'Cúm ở người trên 65 tuổi có thể biến chứng viêm phổi nặng. Bác sĩ Vạn Lang giải đáp 6 câu hỏi phổ biến nhất trước mùa tiêm chủng.',
   '<p>Vắc xin cúm là một trong những biện pháp hiệu quả và ít chi phí nhất để bảo vệ người cao tuổi trong mùa lạnh. Dưới đây là các thắc mắc mà đội ngũ y tế Vạn Lang nhận được nhiều nhất.</p><p>Ai nên tiêm? Mọi người từ 65 tuổi trở lên, ưu tiên người có bệnh nền tim mạch, hô hấp, tiểu đường. Tiêm lúc nào? Tốt nhất trước mùa cúm, nhưng bất kỳ thời điểm nào trong mùa cũng còn giá trị. Cúm nhẹ có nên tiêm sau? Nên tiêm sau khi khỏi khoảng hai tuần.</p><p>Hầu hết tác dụng phụ chỉ là sốt nhẹ và đau tại chỗ trong một ngày. Người từng phản vệ với thành phần vắc xin hoặc đang sốt cao cần hoãn và hỏi ý kiến bác sĩ trước khi tiêm.</p>',
   14, 14, 1, 0, '2026-09-07 02:00:00', 640, 2, NULL, NULL, NULL, '2026-09-07 01:00:00', '2026-09-07 02:00:00'),
  (7, 6, 1, 'Lấy mẫu xét nghiệm tại nhà: quy trình 5 bước của Vạn Lang', 'lay-mau-xet-nghiem-tai-nha',
   'Không cần đưa ông bà đến bệnh viện chờ đợi. Quy trình lấy mẫu tại nhà được chuẩn hóa đủ bước, trả kết quả điện tử trong 24 giờ.',
   '<p>Xét nghiệm định kỳ là hoạt động quan trọng với người cao tuổi nhưng mỗi lần đưa bác đi bệnh viện là một cực hình với cả nhà. Dịch vụ lấy mẫu tại nhà ra đời để giải quyết đúng phần khó đó.</p><p>Quy trình năm bước: đặt lịch qua hotline hoặc form liên hệ; điều dưỡng đến tận nơi đúng khung giờ đã hẹn, kiểm tra thông tin người bệnh; lấy mẫu và bảo quản trong hộp vận chuyển chuyên dụng; mẫu được chuyển về phòng lab liên kết trong ngày; kết quả trả qua email hoặc bản in tận nơi kèm tư vấn đọc kết quả miễn phí.</p><p>Gia đình nên cho bác nhịn ăn sáng nếu danh mục xét nghiệm có đường huyết và mỡ máu - điều dưỡng sẽ nhắc trước khi xác nhận lịch.</p>',
   15, 15, 1, 0, '2026-09-08 04:00:00', 450, 2, NULL, NULL, NULL, '2026-09-08 02:00:00', '2026-09-08 04:00:00'),
  (8, 1, 1, 'Hội thảo da liễu tuổi cao: da người lớn tuổi lão hóa thế nào?', 'hoi-thao-da-lieu-tuoi-cao',
   'Da ngứa, khô, lâu lành vết thương là dấu hiệu lão hóa da tự nhiên. Tổng hợp nội dung hội thảo do Vạn Lang phối hợp tổ chức tuần qua.',
   '<p>Tuần qua, Trung tâm Vạn Lang phối hợp cùng một phòng khám da liễu tổ chức buổi nói chuyện chuyên đề về chăm sóc da cho người cao tuổi với sự tham gia của hơn 80 gia đình.</p><p>Diễn giả nhấn mạnh ba thay đổi thường gặp: giảm tuyến dầu gây khô nứt, chậm tái tạo biểu bì nên vết thương lâu lành, giảm sắc tố bảo vệ khiến nám và tổn thương do nắng tích tụ. Ba việc nên làm hằng ngày: dưỡng ẩm ngay sau khi tắm, dùng kem chống nắng cho vùng da hở, kiểm tra da định kỳ tìm nốt bất thường.</p><p>Các gia đình quan tâm có thể xem lại tài liệu hội thảo tại mục liên hệ và đặt lịch tư vấn da liễu miễn phí trong tháng này.</p>',
   16, 16, 1, 0, '2026-09-09 03:00:00', 380, 2, NULL, NULL, NULL, '2026-09-09 02:00:00', '2026-09-09 03:00:00'),
  (9, 5, 1, 'Phòng ngừa tai biến: kiểm soát huyết áp đúng cách tại nhà', 'phong-ngua-tai-bien-kiem-soat-huyet-ap-dung-cach',
   'Tăng huyết áp là sát thủ thầm lặng. Hướng dẫn đo đúng, ghi đúng và dùng thuốc đúng để người cao tuổi an toàn suốt mùa lạnh.',
   '<p>Đa số ca tai biến khởi phát từ huyết áp cao kéo dài không được kiểm soát. Đo huyết áp tại nhà đúng cách là biện pháp rẻ tiền nhất để phát hiện sớm vấn đề.</p><p>Nguyên tắc đo: ngồi nghỉ năm phút trước khi đo, chân chạm sàn, tay ngang tim, không nói chuyện; đo hai lần cách nhau một phút và lấy số trung bình; ghi lại cùng khung giờ mỗi ngày. Máy đo cổ tay kém ổn định hơn máy đo cánh tay ở người cao tuổi.</p><p>Quan trọng hơn đo là dùng thuốc đều đặn theo đơn, không tự ý ngưng khi số đã đẹp. Mọi điều chỉnh liều cần bác sĩ quyết định. Người có chỉ số thường xuyên trên 160/95 nên đặt lịch tái khám sớm.</p>',
   17, 17, 1, 1, '2026-09-10 02:00:00', 520, 3, NULL, NULL, NULL, '2026-09-10 01:00:00', '2026-09-10 02:00:00'),
  (10, 1, 1, 'Ba thế hệ cùng vận động: gợi ý môn thể thao gia đình', 'ba-the-he-cung-van-dong',
   'Cả nhà cùng tập vừa rèn sức khỏe vừa gắn kết. Gợi ý các môn phù hợp cho ông bà, bố mẹ và trẻ nhỏ tập chung một sân.',
   '<p>Thay vì mỗi người một thiết bị tập riêng, nhiều gia đình chọn cách vận động chung để ông bà có động lực và con cháu hiểu hơn về người lớn tuổi.</p><p>Các môn phù hợp cả nhà: cầu lông đánh nhẹ, đi bộ kết hợp đếm bước, dưỡng sinh nhóm, bóng bàn bàn thấp và bơi nhẹ (hồ nông cho người lớn tuổi, phao cho trẻ). Nên tập cùng khung giờ cố định để thành thói quen, ví dụ 6 giờ sáng hoặc 19 giờ tối.</p><p>Với ông bà có bệnh khớp, ưu tiên môn giảm tải như bơi và đi bộ dưới nước; tránh nhảy xa, squat sâu. Sau buổi tập nếu bác đau kéo dài quá hai ngày thì cần giảm cường độ và hỏi ý kiến chuyên gia.</p>',
   18, 18, 1, 0, '2026-09-11 03:00:00', 300, 2, NULL, NULL, NULL, '2026-09-11 02:00:00', '2026-09-11 03:00:00'),
  -- hẹn giờ: status 1 nhưng publishedAt tương lai → nằm ở tab Hẹn giờ, chưa hiện public
  (11, 3, 1, 'Thực đơn tuần 3 tháng 9: thanh mát, dễ tiêu cho ông bà', 'thuc-don-tuan-3-thang-9',
   'Thực đơn mẫu 21 bữa chuẩn bị đăng — demo luồng hẹn giờ: bài đã xuất bản ở tương lai, chưa hiển thị ngoài site.',
   '<p>Nội dung demo cho luồng hẹn giờ (scheduled). Thứ tự món trong tuần cân bằng đạm - rau - tinh bột, ưu tiên món hầm mềm buổi tối.</p><p>Thứ hai: cháo yến mạch thịt bằm, cá kho nghệ, canh bí xanh. Thứ ba: súp khoai môn tôm, đậu hũ sốt cà, canh rau ngót. Thứ tư: miến gà, thịt ram hạt dẻ, canh mướp nấu lạc.</p><p>Các bản tiếp theo sẽ cập nhật danh sách đi chợ kèm khẩu phần cho người tiểu đường và tiền tiểu đường.</p>',
   10, 10, 1, 0, '2026-09-20 02:00:00', 0, 2, NULL, NULL, NULL, '2026-09-12 08:00:00', '2026-09-12 08:00:00'),
  -- nháp
  (12, 2, 1, 'Dự thảo: Cẩm nang chọn viện dưỡng lão phù hợp', 'du-thao-cam-nang-chon-vien-duong-lao',
   'Bài đang soạn — demo trạng thái nháp, chưa có banner, chưa hẹn ngày đăng.',
   '<p>Dự thảo dàn ý: tiêu chí y tế, tiêu chí tinh thần, chi phí, hợp đồng và những câu hỏi nên đặt khi đi tham quan thực tế.</p><p>Cần bổ sung so sánh ba mô hình: bán trú, nội trú dài hạn và chăm sóc theo giai đoạn phục hồi.</p>',
   NULL, NULL, 0, 0, NULL, 0, 2, NULL, NULL, NULL, '2026-09-12 09:00:00', '2026-09-12 10:30:00'),
  (13, 6, 1, 'Nháp nhanh: lưu ý khi dùng máy đo đường huyết tại nhà', 'nhab-luu-y-may-do-duong-huyet',
   '',
   '<p>Ghi chú nội bộ: thử que hạn dùng, rửa tay khô trước khi chích, bảo quản máy tránh ẩm. Chưa hoàn thiện nên để nháp.</p>',
   NULL, NULL, 0, 0, NULL, 0, 1, NULL, NULL, NULL, '2026-09-13 01:20:00', '2026-09-13 02:00:00'),
  (14, 4, 1, 'Tuyển dụng điều dưỡng làm việc theo ca (tuyển đủ)', 'tuyen-dung-dieu-duong-da-tuy-du',
   'Demo bài đã lưu trữ (archived) — tin tuyển dụng cũ đã đóng, không xuất hiện ở trang danh sách.',
   '<p>Bài demo trạng thái lưu trữ. Nội dung đã dừng hiệu lực và được chuyển sang trạng thái archive thay vì xoá cứng để giữ lịch sử.</p>',
   NULL, NULL, 2, 0, '2026-08-25 02:00:00', 210, 1, NULL, NULL, NULL, '2026-08-25 01:00:00', '2026-09-01 07:00:00'),
  (15, 8, 1, 'Gợi ý bữa sáng 10 phút: yến mạch và trứng hấp', 'goi-y-bua-sang-10-phut',
   'Bài có preview token để demo luồng xem trước bản nháp — link preview dùng token gắn sau slug.',
   '<p>Bữa sáng gọn nhẹ cho người cao tuổi: yến mạch nấu sữa ấm 5 phút, trứng hấp nhân thịt bằm 8 phút. Đủ đạm, mềm dễ nuốt, không cần dầu mỡ.</p><p>Mẹo: hấp trứng bằng nước sôi già rồi hạ liu riu sẽ mịn mặt, không tổ ong.</p>',
   10, 10, 0, 0, NULL, 0, 1, '7d2a9f0c4e1b8d3a6c5f2e9d8b7a4c1e', NULL, NULL, '2026-09-13 01:50:00', '2026-09-13 02:10:00');

-- ---------------------------------------------------------------------------
-- 6) post_tags — gắn thẻ chéo cho các bài đã xuất bản
-- ---------------------------------------------------------------------------
INSERT INTO post_tags (postId, tagId) VALUES
  (1, 1), (1, 2), (1, 5),
  (2, 1), (2, 4),
  (3, 1), (3, 5), (3, 8),
  (4, 1), (4, 2), (4, 3), (4, 10),
  (5, 1), (5, 7),
  (6, 1), (6, 6), (6, 9),
  (7, 1), (7, 2), (7, 3),
  (8, 1), (8, 2),
  (9, 1), (9, 8),
  (10, 2), (10, 1);

-- ---------------------------------------------------------------------------
-- 7) post_revisions — vài bản per bài để màn revision có dữ liệu
--    (type: 0 manual · 1 autosave · 2 before-publish)
-- ---------------------------------------------------------------------------
INSERT INTO post_revisions (id, postId, userId, type, title, excerpt, content, createdAt) VALUES
  (1, 1, 1, 2, '5 bài tập dưỡng sinh giúp người cao tuổi khỏe mỗi ngày',
   'Dưỡng sinh đều đặn giúp cải thiện tuần hoàn và giấc ngủ.',
   '<p>Bản nháp đầu trước khi xuất bản: thiếu phần khởi động và cảnh báo bệnh nền.</p>', '2026-09-01 01:50:00'),
  (2, 1, 1, 0, '5 bài tập dưỡng sinh cho người cao tuổi',
   'Bản đổi tiêu đề ngắn trước khi tách sang nháp.',
   '<p>Dưỡng sinh là môn tập nhẹ nhàng phù hợp người cao tuổi.</p>', '2026-09-04 08:00:00'),
  (3, 3, 1, 2, 'Hướng dẫn bài tập phục hồi vận động sau tai biến tại nhà',
   'Bản trước publish - chưa có cảnh báo trật khớp vai.',
   '<p>Giáo án gợi ý gồm ba nhóm động tác chính.</p>', '2026-09-04 02:20:00'),
  (4, 4, 1, 0, 'Nhật ký chăm sóc hằng ngày',
   'Bản thêm mẫu in sẵn vào cuối bài.',
   '<p>Trung tâm cung cấp mẫu nhật ký in sẵn.</p>', '2026-09-05 01:30:00'),
  (5, 13, 1, 1, 'Nháp nhanh: lưu ý khi dùng máy đo đường huyết tại nhà',
   '',
   '<p>Ghi chú autosave lần 1: thử que hạn dùng.</p>', '2026-09-13 01:40:00'),
  (6, 13, 1, 1, 'Nháp nhanh: lưu ý khi dùng máy đo đường huyết',
   '',
   '<p>Ghi chú autosave lần 2: rửa tay khô, bảo quản máy tránh ẩm.</p>', '2026-09-13 01:55:00'),
  (7, 2, 1, 2, 'Ăn uống mùa lạnh cho người cao tuổi',
   'Bản trước publish của bài dinh dưỡng.',
   '<p>Khi trời lạnh nhu cầu năng lượng tăng lên.</p>', '2026-09-03 02:40:00');

-- ---------------------------------------------------------------------------
-- 8) post_view_daily — lịch sử lượt xem 5 ngày gần nhất cho bài hot
--    (dashboard / thống kê — viewCount của bài khớp gần đúng tổng)
-- ---------------------------------------------------------------------------
INSERT INTO post_view_daily (postId, viewDate, views) VALUES
  (1, '2026-09-08', 210), (1, '2026-09-09', 265), (1, '2026-09-10', 240), (1, '2026-09-11', 280), (1, '2026-09-12', 245),
  (2, '2026-09-08', 150), (2, '2026-09-09', 175), (2, '2026-09-10', 160), (2, '2026-09-11', 190), (2, '2026-09-12', 165),
  (3, '2026-09-08', 290), (3, '2026-09-09', 310), (3, '2026-09-10', 305), (3, '2026-09-11', 320), (3, '2026-09-12', 300),
  (5, '2026-09-09', 120), (5, '2026-09-10', 135), (5, '2026-09-11', 150), (5, '2026-09-12', 140),
  (9, '2026-09-10', 180), (9, '2026-09-11', 170), (9, '2026-09-12', 170);

-- ---------------------------------------------------------------------------
-- 9) services — 6 dịch vụ (bảng do Frontend sở hữu mapper, Admin ghi hộ)
-- ---------------------------------------------------------------------------
INSERT INTO services (id, name, slug, shortDescription, content, iconMediaId, imageMediaId, sortOrder, isActive, metaTitle, metaDescription) VALUES
  (1, 'Chăm sóc người cao tuổi tại nhà', 'cham-soc-nguoi-cao-tuoi-tai-nha',
   'Điều dưỡng đến tận nhà theo ca, hỗ trợ vệ sinh, ăn uống, dùng thuốc và theo dõi sinh hiệu.',
   '<p>Gói chăm sóc tại nhà linh hoạt 4/8/12/24 giờ, điều dưỡng có chứng chỉ hành nghề, báo cáo hằng ca qua nhật ký chăm sóc.</p>', NULL, 19, 1, 1, NULL, NULL),
  (2, 'Vật lý trị liệu - Phục hồi chức năng', 'vat-ly-tri-lieu-phuc-hoi-chuc-nang',
   'Kỹ thuật viên tập 1-kèm-1 tại trung tâm hoặc tại nhà cho bệnh nhân sau tai biến, sau mổ.',
   '<p>Giáo án cá nhân hóa theo đánh giá ban đầu, thiết bị đạt chuẩn, tái đánh giá sau mỗi hai tuần.</p>', NULL, 20, 2, 1, NULL, NULL),
  (3, 'Truyền dịch tại nhà', 'truyen-dich-tai-nha',
   'Bác sĩ chỉ định, điều dưỡng thực hiện truyền dịch an toàn, có bộ cấp cứu tại chỗ.',
   '<p>Chỉ truyền khi có chỉ định của bác sĩ sau khi khám hoặc nhận hồ sơ bệnh án hợp lệ.</p>', NULL, 21, 3, 1, NULL, NULL),
  (4, 'Lấy mẫu xét nghiệm tận nơi', 'lay-mau-xet-nghiem-tan-noi',
   'Đặt lịch online, lấy mẫu tại nhà, trả kết quả điện tử trong 24 giờ kèm tư vấn đọc kết quả.',
   '<p>Liên kết phòng lab đạt chuẩn ISO 15189, hơn 300 danh mục xét nghiệm.</p>', NULL, 22, 4, 1, NULL, NULL),
  (5, 'Chăm sóc ban ngày (Day Care)', 'cham-soc-ban-ngay-day-care',
   'Đưa đón, ăn trưa, hoạt động nhận thức và vận động nhẹ ban ngày cho ông bà khỏe mạnh.',
   '<p>Mô hình nhà chung có tổ: sinh hoạt nhóm, tập dưỡng sinh, chơi cờ, ngủ trưa đúng giờ.</p>', NULL, 19, 5, 1, NULL, NULL),
  (6, 'Tư vấn dinh dưỡng và thực đơn', 'tu-van-dinh-duong-thuc-don',
   'Xây dựng thực đơn 4 tuần theo bệnh nền, kèm danh sách đi chợ và hướng dẫn chế biến.',
   '<p>Chuyên gia dinh dưỡng phụ trách, điều chỉnh sau mỗi tháng theo chỉ số sức khỏe.</p>', NULL, 20, 6, 1, NULL, NULL),
  (7, 'Dịch vụ tạm ngừng (demo)', 'dich-vu-tam-ngung',
   'Dòng demo dịch vụ đã ẩn — chỉ admin thấy ở danh sách.', NULL, NULL, NULL, 99, 0, NULL, NULL);

-- ---------------------------------------------------------------------------
-- 10) banners — 3 hero home (1 có cửa sổ ngày) + 1 news_top + 1 hết hạn ẩn
--     startAt/endAt lưu UTC (form nhập giờ VN → trừ 7, docs §4.1)
-- ---------------------------------------------------------------------------
INSERT INTO banners (id, position, title, subtitle, imageMediaId, mobileImageMediaId, linkUrl, openNewTab, buttonText, sortOrder, isActive, startAt, endAt) VALUES
  (1, 'home_hero', 'Tận tâm chăm sóc — Vững tâm gia đình', 'Điều dưỡng được đào tạo bài bản, đồng hành cùng ông bà mỗi ngày.', 3, NULL, '/lien-he', 0, 'Đặt lịch tư vấn', 1, 1, NULL, NULL),
  (2, 'home_hero', 'Phục hồi chức năng tại trung tâm hoặc tại nhà', 'Giáo án cá nhân hóa cho bệnh nhân sau tai biến, sau mổ.', 4, NULL, '/tin-tuc?danh-muc=vat-ly-tri-lieu', 0, 'Xem bài hướng dẫn', 2, 1, NULL, NULL),
  (3, 'home_hero', 'Chương trình tháng 9: Khám tư vấn miễn phí', 'Áp dụng từ 01/09 đến 30/09 cho gia đình đăng ký mới.', 5, NULL, '/lien-he', 0, 'Đăng ký ngay', 3, 1, '2026-08-31 17:00:00', '2026-09-30 16:59:59'),
  (4, 'news_top',  'Đọc ngay: Cẩm nang chăm sóc người cao tuổi mùa lạnh', NULL, 12, NULL, '/tin-tuc?danh-muc=cham-soc-nguoi-cao-tuoi', 0, 'Xem series', 1, 1, NULL, NULL),
  (5, 'home_hero', 'Banner đã tắt (demo)', 'Dòng này chứng minh bộ lọc isActive.', 3, NULL, '/lien-he', 0, NULL, 9, 0, NULL, NULL);

-- ---------------------------------------------------------------------------
-- 11) home_sections — đủ 7 loại (type 1..7), section nổi bật và team dùng
--     mode=manual để demo FR-33 (home_section_items bên dưới)
-- ---------------------------------------------------------------------------
INSERT INTO home_sections (id, type, title, subtitle, config, sortOrder, isActive) VALUES
  (1, 1, NULL, NULL, JSON_OBJECT('autoplay', TRUE, 'interval_ms', 5000), 1, 1),
  (2, 2, 'Tin nổi bật', 'Những bài được gia đình quan tâm nhất tuần qua', JSON_OBJECT('mode', 'manual', 'limit', 5), 2, 1),
  (3, 3, 'Tin mới nhất', NULL, JSON_OBJECT('mode', 'auto', 'limit', 6), 3, 1),
  (4, 4, NULL, NULL, JSON_OBJECT('category_id', 2, 'limit', 4), 4, 1),
  (5, 5, 'Dịch vụ của chúng tôi', 'Giải pháp chăm sóc toàn diện tại nhà và tại trung tâm', JSON_OBJECT('mode', 'auto', 'limit', 6), 5, 1),
  (6, 6, 'Đội ngũ của Vạn Lang', 'Bác sĩ, điều dưỡng và kỹ thuật viên đồng hành cùng gia đình', JSON_OBJECT('mode', 'manual', 'limit', 4), 6, 1),
  (7, 7, 'Bạn cần tư vấn?', 'Đội ngũ chúng tôi sẵn sàng lắng nghe gia đình bạn 7 ngày trong tuần.', JSON_OBJECT('button_text', 'Liên hệ ngay', 'button_url', '/lien-he'), 7, 1),
  (8, 2, 'Block tắt (demo)', NULL, JSON_OBJECT('mode', 'auto', 'limit', 3), 8, 0);

-- ---------------------------------------------------------------------------
-- 12) home_section_items — mục manual cho section 2 (post) và 6 (team_member)
--     itemType: 1 post · 2 service · 3 team_member; sortOrder 0..n
-- ---------------------------------------------------------------------------
INSERT INTO home_section_items (id, sectionId, itemType, itemId, sortOrder) VALUES
  (1, 2, 1, 1, 0),
  (2, 2, 1, 3, 1),
  (3, 2, 1, 5, 2),
  (4, 2, 1, 9, 3),
  (5, 2, 1, 2, 4),
  (6, 6, 3, 1, 0),
  (7, 6, 3, 2, 1),
  (8, 6, 3, 3, 2),
  (9, 6, 3, 4, 3);

-- ---------------------------------------------------------------------------
-- 13) team_members — 5 người: 4 active (3 featured) + 1 ẩn demo
-- ---------------------------------------------------------------------------
INSERT INTO team_members (id, userId, fullName, positionTitle, avatarMediaId, bio, email, phone, showContact, socialLinks, sortOrder, isFeatured, isActive) VALUES
  (1, NULL, 'BS. Nguyễn Thị Lan',   'Giám đốc chuyên môn', 23,
   'Hơn 20 năm kinh nghiệm nội tổng hợp, sáng lập chương trình chăm sóc người cao tuổi tại Vạn Lang.', 'lan.nguyen@vanlang.vn', '0912345601', 1,
   JSON_OBJECT('facebook', 'https://facebook.com/bacsi.nguyenlan'), 1, 1, 1),
  (2, NULL, 'ThS. Trần Văn Minh',   'Trưởng khoa Vật lý trị liệu', 24,
   'Thạc sĩ Y học cổ truyền - Phục hồi chức năng, phụ trách giáo án phục hồi sau tai biến.', 'minh.tran@vanlang.vn', NULL, 0,
   JSON_OBJECT('linkedin', 'https://linkedin.com/in/tranvanminh'), 2, 1, 1),
  (3, NULL, 'Điều dưỡng Lê Thị Hương', 'Điều dưỡng trưởng', 25,
   'Quản lý đội 18 điều dưỡng hiện trường, trực tiếp đào tạo quy trình chăm sóc tại nhà.', NULL, '0912345603', 1,
   NULL, 3, 1, 1),
  (4, NULL, 'KTV Phạm Đức Thành',   'Kỹ thuật viên trị liệu', 26,
   'Chuyên phục hồi vận động cho bệnh nhân sau mổ khớp háng và tai biến.', NULL, NULL, 0, NULL, 4, 0, 1),
  (5, NULL, 'CN Dinh dưỡng Ngô Mai Phương', 'Chuyên gia dinh dưỡng', NULL,
   'Xây dựng thực đơn 4 tuần theo bệnh nền cho khách hàng trung tâm.', NULL, NULL, 0, NULL, 5, 0, 0);

-- ---------------------------------------------------------------------------
-- 14) contact_submissions — 8 lượt phủ cả 4 trạng thái, có serviceId tham chiếu
--     ipAddress lưu binary (INET6_ATON) như ContactMapper ghi
-- ---------------------------------------------------------------------------
INSERT INTO contact_submissions (id, fullName, email, phone, serviceId, subject, message, consentAt, status, adminNote, handledAt, ipAddress, userAgent, sourceUrl, createdAt, updatedAt) VALUES
  (1, 'Nguyễn Gia Bảo',    'giabao.nguyen@gmail.com',  '0987654321', 1, 'Tư vấn gói chăm sóc 12 giờ',
   'Mẹ tôi 78 tuổi vừa ra viện, tôi muốn hỏi gói điều dưỡng 12 giờ ban ngày và chi phí trọn tháng.',
   '2026-09-12 08:05:00', 0, NULL, NULL, INET6_ATON('113.161.45.80'), 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '/lien-he', '2026-09-12 08:05:00', '2026-09-12 08:05:00'),
  (2, 'Trần Thị Hạnh',     'hanh.tran92@yahoo.com',    '0903221144', 2, 'Phục hồi sau tai biến cho bố',
   'Bố tôi 82 tuổi sau tai biến 2 tháng, liệt nửa người trái. Trung tâm có nhận tập tại nhà không và cần hồ sơ gì?',
   '2026-09-12 09:40:00', 1, 'Đã gọi điện hẹn khảo sát ngày 14/09, chờ gửi hồ sơ ra viện.', NULL, INET6_ATON('171.244.10.201'), 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', '/lien-he', '2026-09-12 09:40:00', '2026-09-12 10:15:00'),
  (3, 'Lê Hoàng Nam',      'hoangnam.le@outlook.com',  '0917555777', 3, 'Truyền dịch tại nhà buổi tối',
   'Bố tôi mệt, muốn truyền đạm buổi tối nay có kịp không ạ? Nhà ở Long Biên.',
   '2026-09-11 14:20:00', 2, 'Bác sĩ đã khám và chỉ định truyền, ca hoàn thành 21:30.', '2026-09-11 14:55:00', INET6_ATON('42.115.99.3'), 'Mozilla/5.0 (Windows NT 10.0)', '/lien-he', '2026-09-11 14:20:00', '2026-09-11 14:55:00'),
  (4, 'Phạm Thu Trang',    'thutrang.pham@gmail.com',  '0977889900', 4, 'Xét nghiệm định kỳ cho ông bà',
   'Tôi đặt combo xét nghiệm tổng quát cho hai bác trên 70 tuổi, cho tôi xin báo giá và khung giờ nhận mẫu.',
   '2026-09-11 02:10:00', 2, 'Đã gửi báo giá, lịch lấy mẫu 8:00 ngày 13/09.', '2026-09-11 03:00:00', INET6_ATON('14.240.20.9'), 'Mozilla/5.0 (Linux; Android 13)', '/tu-van', '2026-09-11 02:10:00', '2026-09-11 03:00:00'),
  (5, 'Vũ Đức Toàn',       'ductoan@gmail.com',        NULL, NULL, 'Gia nhập đội ngũ điều dưỡng',
   'Em mới tốt nghiệp điều dưỡng, muốn ứng tuyển vị trí cộng tác viên chăm sóc tại nhà, liên hệ em qua email ạ.',
   '2026-09-10 07:30:00', 1, 'Chuyển bộ phận nhân sự, đã hẹn phỏng vấn 16/09.', NULL, INET6_ATON('27.72.130.55'), 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '/tuyen-dung', '2026-09-10 07:30:00', '2026-09-10 08:00:00'),
  (6, 'Đặng Minh Anh',     'manhang.dang@gmail.com',   '0333444555', 5, 'Day care thứ 2-4-6',
   'Ông tôi 71 tuổi khỏe mạnh, muốn gửi day care 3 buổi/tuần để ông có bạn trò chuyện, trung tâm nhận không ạ?',
   '2026-09-09 04:00:00', 0, NULL, NULL, INET6_ATON('113.185.20.77'), 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15)', '/lien-he', '2026-09-09 04:00:00', '2026-09-09 04:00:00'),
  (7, 'Hoàng Quốc Việt',   'quocviet.hoang@gmail.com', '0905606060', 6, 'Thực đơn cho người tiểu đường',
   'Mẹ tôi tiểu đường type 2, tôi muốn đặt tư vấn dinh dưỡng xây thực đơn 4 tuần.',
   '2026-09-08 09:00:00', 3, NULL, NULL, INET6_ATON('1.55.200.44'), 'python-requests/2.31', '/lien-he', '2026-09-08 09:00:00', '2026-09-08 09:00:00'),
  (8, 'SeoBacklink Pro',   'spam@click-buy-traffic.biz', NULL, NULL, 'Tăng 5000 lượt truy cập web',
   'Chào bạn, bên mình chuyên chạy traffic giá rẻ, liên hệ zalo xxx để nhận ưu đãi 50 phần trăm.',
   '2026-09-07 22:00:00', 3, 'Đánh spam — email quảng cáo tự động.', NULL, INET6_ATON('103.199.2.8'), 'curl/8.0.1', '/lien-he', '2026-09-07 22:00:00', '2026-09-08 01:30:00');

-- ---------------------------------------------------------------------------
-- 15) settings — đủ 19 key 4 nhóm theo seed.sql, điền giá trị demo
--     (id đặt cố định; valueType theo SettingConst 1..7)
-- ---------------------------------------------------------------------------
INSERT INTO settings (id, groupCode, settingKey, settingValue, valueType, label, sortOrder, updatedBy) VALUES
  (1,  'general', 'site_name',                'Vạn Lang', 1, 'Tên website', 1, 1),
  (2,  'general', 'site_logo',                '1',   7, 'Logo', 2, 1),
  (3,  'general', 'site_favicon',             NULL,  7, 'Favicon', 3, NULL),
  (4,  'general', 'footer_text',              '<p>© 2026 Trung tâm Chăm sóc Sức khỏe Vạn Lang. Đơn vị chủ quản: Công ty TNHH Vạn Lang.</p>', 3, 'Nội dung chân trang', 4, 1),
  (5,  'contact', 'company_name',             'Trung tâm Chăm sóc Sức khỏe Vạn Lang', 1, 'Tên pháp nhân', 1, 1),
  (6,  'contact', 'address',                  '123 Hoàng Văn Ca, Long Biên, Hà Nội', 2, 'Địa chỉ', 2, 1),
  (7,  'contact', 'hotline',                  '1900 1234', 1, 'Hotline', 3, 1),
  (8,  'contact', 'email',                    'hello@vanlang.vn', 1, 'Email công khai', 4, 1),
  (9,  'contact', 'working_hours',            '08:00 - 17:30 (T2 - T7)', 1, 'Giờ làm việc', 5, 1),
  (10, 'contact', 'map_embed_url',            'https://www.google.com/maps/embed?pb=!4d-demo-van-lang', 1, 'URL nhúng Google Maps', 6, NULL),
  (11, 'contact', 'notify_emails',            'admin@vanlang.vn', 1, 'Email nhận thông báo liên hệ (phân tách bằng dấu phẩy)', 7, 1),
  (12, 'social',  'facebook_url',             'https://facebook.com/vanlang.care', 1, 'Facebook', 1, 1),
  (13, 'social',  'youtube_url',              'https://youtube.com/@vanlangcare', 1, 'YouTube', 2, NULL),
  (14, 'social',  'zalo_url',                 'https://zalo.me/1900123456', 1, 'Zalo OA', 3, NULL),
  (15, 'social',  'linkedin_url',             NULL, 1, 'LinkedIn', 4, NULL),
  (16, 'seo',     'default_meta_title',       'Vạn Lang - Tận tâm chăm sóc, vững tâm gia đình', 1, 'Meta title mặc định', 1, 1),
  (17, 'seo',     'default_meta_description', 'Trung tâm Chăm sóc Sức khỏe Vạn Lang - dịch vụ chăm sóc người cao tuổi tận tâm, chuyên nghiệp.', 2, 'Meta description mặc định', 2, 1),
  (18, 'seo',     'default_og_image',         '2', 7, 'Ảnh chia sẻ mặc định', 3, 1),
  (19, 'seo',     'ga_measurement_id',        NULL, 1, 'Google Analytics Measurement ID', 4, NULL);

-- ---------------------------------------------------------------------------
-- 16) password_reset_tokens — 2 dòng demo (FR-13 chưa có UI nên bảng trống
--     trong thực tế): 1 token đã dùng, 1 token đã hết hạn. Hash chỉ là giá trị
--     demo 64 hex — KHÔNG có token thật tương ứng.
-- ---------------------------------------------------------------------------
INSERT INTO password_reset_tokens (id, userId, tokenHash, expiresAt, usedAt, createdAt) VALUES
  (1, 1, 'a3f1c9e7b2d84f6a0e5c1b9d7f3a2c8e4b6d0f1a9c2e7b4d8f0a1c6e9b3d7f5a', '2026-09-10 02:00:00', '2026-09-09 03:10:00', '2026-09-09 02:00:00'),
  (2, 1, 'b7e2d4c8a9f03b5d6e1c7a2f9b4d8e0c3a6f1b9d5e2c7a4f8b0d3e6c9a1f4b7d', '2026-09-11 05:00:00', NULL, '2026-09-10 05:00:00');

-- ============================================================================
-- Hết file. Sau khi chạy: xóa cache trang chủ/settings nếu site đang bật cache
--   php bin/clear-config-cache.php   (chỉ config; data/cache/page tự hết TTL 60s)
-- Đăng nhập demo: admin@vanlang.vn hoặc username `admin` — mật khẩu Admin@1234
-- ============================================================================
