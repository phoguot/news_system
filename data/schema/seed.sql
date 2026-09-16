INSERT INTO settings (groupCode, settingKey, settingValue, valueType, label, sortOrder) VALUES
  ('general', 'site_name',                'Vạn Lang', 1, 'Tên website', 1),
  ('general', 'site_logo',                NULL, 7,  'Logo', 2),
  ('general', 'site_favicon',             NULL, 7,  'Favicon', 3),
  ('general', 'footer_text',              NULL, 3,   'Nội dung chân trang', 4),
  ('contact', 'company_name',             'Trung tâm Chăm sóc Sức khỏe Vạn Lang', 1, 'Tên pháp nhân', 1),
  ('contact', 'address',                  '123 Hoàng Văn Ca, Long Biên, Hà Nội', 2,   'Địa chỉ', 2),
  ('contact', 'hotline',                  '1900 1234', 1, 'Hotline', 3),
  ('contact', 'email',                    'hello@vanlang.vn', 1, 'Email công khai', 4),
  ('contact', 'working_hours',            '08:00 - 17:30 (T2 - T7)', 1, 'Giờ làm việc', 5),
  ('contact', 'map_embed_url',            NULL, 1, 'URL nhúng Google Maps (chỉ dùng khi không nhập địa chỉ)', 6),
  ('contact', 'map_address',              NULL, 2, 'Địa chỉ riêng cho Google Maps (để trống sẽ dùng ô Địa chỉ)', 7),
  ('contact', 'notify_emails',            NULL, 1, 'Mail cá nhân nhận thông báo liên hệ (phân tách bằng dấu phẩy)', 8),
  ('social',  'facebook_url',             NULL, 1, 'Facebook', 1),
  ('social',  'youtube_url',              NULL, 1, 'YouTube', 2),
  ('social',  'zalo_url',                 NULL, 1, 'Zalo OA', 3),
  ('social',  'linkedin_url',             NULL, 1, 'LinkedIn', 4),
  ('seo',     'default_meta_title',       'Vạn Lang - Tận tâm chăm sóc, vững tâm gia đình', 1, 'Meta title mặc định', 1),
  ('seo',     'default_meta_description', 'Trung tâm Chăm sóc Sức khỏe Vạn Lang - dịch vụ chăm sóc người lớn tuổi tận tâm, chuyên nghiệp.', 2,   'Meta description mặc định', 2),
  ('seo',     'default_og_image',         NULL, 7,  'Ảnh chia sẻ mặc định', 3),
  ('seo',     'ga_measurement_id',        NULL, 1, 'Google Analytics Measurement ID', 4);

INSERT INTO home_sections (type, title, config, sortOrder) VALUES
  (1,               NULL,                    JSON_OBJECT('autoplay', true, 'interval_ms', 5000), 1),
  (2,               'Tin nổi bật',           JSON_OBJECT('mode', 'auto', 'limit', 5), 2),
  (5,               'Dịch vụ của chúng tôi', JSON_OBJECT('mode', 'auto', 'limit', 6), 3),
  (3,               'Tin mới nhất',          JSON_OBJECT('limit', 6), 4),
  (6,               'Đội ngũ',               JSON_OBJECT('mode', 'auto', 'limit', 4), 5),
  (7,               'Bạn cần tư vấn?',       JSON_OBJECT('button_text', 'Liên hệ ngay', 'button_url', '/lien-he'), 6);
