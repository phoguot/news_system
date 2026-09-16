<?php

declare(strict_types=1);

namespace Admin\Model\Post;

/**
 * Hằng entity `posts` phía quản trị (docs §3.3; quy ước: docs-dev/01-quy-chuan/06).
 * Trạng thái dùng chung `posts.status` nằm ở `Application\Constant\ContentConst`
 * (06-quy-uoc-const §2) — còn ở đây chỉ thứ thuộc quyền Admin.
 */
final class PostConst
{
    /** Nhánh intent của nút bấm trên form soạn bài (docs §3.3.2, §3.3.3). */
    public const INTENT_DRAFT   = 'draft';
    public const INTENT_PUBLISH = 'publish';
    public const INTENT_SCHEDULE = 'schedule';

    /** Tab danh sách admin (docs §3.3.1) — "scheduled" là published + publishedAt tương lai. */
    public const TAB_ALL        = 'all';
    public const TAB_DRAFT      = 'draft';
    public const TAB_SCHEDULED  = 'scheduled';
    public const TAB_PUBLISHED  = 'published';
    public const TAB_ARCHIVED   = 'archived';

    public const TAB_LABELS = [
        self::TAB_ALL       => 'Tất cả',
        self::TAB_DRAFT     => 'Nháp',
        self::TAB_SCHEDULED => 'Hẹn giờ',
        self::TAB_PUBLISHED => 'Đã xuất bản',
        self::TAB_ARCHIVED  => 'Lưu trữ',
    ];

    /** Số từ / phút để tính readingMinutes (docs §3.3.4(7), FR-24). */
    public const WORDS_PER_MINUTE = 200;

    /** Độ dài previewToken = CHAR(32) (docs §3.3.2). */
    public const PREVIEW_TOKEN_BYTES = 16;

    /** Giữ 20 revision thủ công/trước-xuất-bản mỗi bài (docs §5.13). */
    public const REVISION_KEEP = 20;

    /** Số tag tối đa nhận từ một lần lưu form. */
    public const MAX_TAGS_PER_POST = 20;

    /** Độ dài VARCHAR theo schema.sql. */
    public const MAX_LENGTH_TITLE             = 255;
    public const MAX_LENGTH_SLUG              = 255;
    public const MAX_LENGTH_EXCERPT           = 500;
    public const MAX_LENGTH_META_TITLE        = 255;
    public const MAX_LENGTH_META_DESCRIPTION  = 500;

    /** Cờ flash qua query sau PRG. */
    public const FLAG_CREATED  = 'created';
    public const FLAG_UPDATED  = 'updated';
    public const FLAG_DELETED  = 'deleted';
    public const FLAG_PUBLISHED = 'published';
    public const FLAG_ARCHIVED = 'archived';
    public const FLAG_DRAFTED  = 'drafted';

    /** Hành động hàng loạt (FR-22 — docs §3.3.1; API `POST /posts/bulk`). */
    public const BULK_PUBLISH  = 'publish';
    public const BULK_ARCHIVE  = 'archive';
    public const BULK_CATEGORY = 'category';
    public const BULK_DELETE   = 'delete';

    public const BULK_ACTIONS = [
        self::BULK_PUBLISH,
        self::BULK_ARCHIVE,
        self::BULK_CATEGORY,
        self::BULK_DELETE,
    ];

    /** Cờ PRG riêng cho bulk (thông báo kèm số áp dụng/bỏ qua ở view). */
    public const FLAG_BULK_DONE    = 'bulk-done';
    public const FLAG_BULK_INVALID = 'bulk-invalid';

    /** Thông báo lỗi nghiệp vụ. */
    public const ERROR_BANNER_REQUIRED = 'Bài viết cần có ảnh banner trước khi xuất bản (docs §3.3.4(3)).';
    public const ERROR_NOT_FOUND       = 'Không tìm thấy bài viết.';
    public const ERROR_INVALID_TIME    = 'Định dạng thời gian xuất bản không hợp lệ.';
    public const ERROR_CATEGORY_REQUIRED = 'Chọn danh mục cho bài viết.';
    public const ERROR_BULK_ACTION  = 'Hành động hàng loạt không hợp lệ.';
    public const ERROR_BULK_EMPTY   = 'Chưa chọn bài viết nào để thao tác.';
    public const ERROR_BULK_CONFIRM = 'Xoá hàng loạt: phải gõ đúng số bài đã chọn để xác nhận.';
    public const ERROR_BULK_CATEGORY = 'Danh mục đích không hợp lệ.';

    /** Nhãn trạng thái + hẹn giờ cho danh sách (kế hợp ContentConst::STATUS_LABELS). */
    public const LABEL_SCHEDULED_SUFFIX = ' (hẹn giờ)';
}
