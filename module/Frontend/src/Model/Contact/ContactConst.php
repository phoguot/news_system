<?php

declare(strict_types=1);

namespace Frontend\Model\Contact;

/**
 * Hằng entity `contact_submissions` + luồng liên hệ (docs §3.9, §5.10;
 * quy ước: docs-dev/01-quy-chuan/06-quy-uoc-const.md).
 */
class ContactConst
{
    /** Trạng thái — khớp docs §3.9 + schema (0 new / 1 processing / 2 done / 3 spam) */
    public const STATUS_NEW        = 0;
    public const STATUS_PROCESSING = 1;
    public const STATUS_DONE       = 2;
    public const STATUS_SPAM       = 3;

    /** Map hiển thị cho admin list (docs §3.9) */
    public const STATUS_LABELS = [
        self::STATUS_NEW        => 'Mới',
        self::STATUS_PROCESSING => 'Đang xử lý',
        self::STATUS_DONE       => 'Đã xong',
        self::STATUS_SPAM       => 'Spam',
    ];

    /** Giới hạn gửi form (docs §3.9 + §5.10): >= 3 lần trong 10 phút theo IP */
    public const RATE_LIMIT_MAX            = 3;
    public const RATE_LIMIT_WINDOW_MINUTES = 10;

    /** Tên trường honeypot ẩn — bot điền vào là bị coi là spam im lặng (docs §3.9) */
    public const HONEYPOT_FIELD = 'website';

    /** Kết quả ContactService::submit() (docs 07 §2 — filter chạy trong service) */
    public const SUBMIT_OK           = 'ok';
    public const SUBMIT_RATE_LIMITED = 'rate_limited';
    public const SUBMIT_CAPTCHA_FAIL = 'captcha_fail';
    public const SUBMIT_INVALID      = 'invalid';

    /** Độ dài tối đa lưu DB (khớp VARCHAR trong schema.sql) */
    public const MAX_LENGTH_FULLNAME  = 150;
    public const MAX_LENGTH_EMAIL     = 255;
    public const MAX_LENGTH_PHONE     = 20;
    public const MAX_LENGTH_SUBJECT   = 255;
    public const MAX_LENGTH_MESSAGE   = 5000;
    public const MAX_LENGTH_USERAGENT = 255;
    public const MAX_LENGTH_SOURCEURL = 500;

    /** Thông báo lỗi hiển thị cho khách */
    public const ERROR_RATE_LIMITED = 'Bạn đã gửi quá nhiều yêu cầu trong ít phút. Vui lòng thử lại sau.';
    public const ERROR_CAPTCHA      = 'Xác minh captcha thất bại. Vui lòng thử lại.';
    public const ERROR_FORM_INVALID = 'Dữ liệu gửi lên không hợp lệ. Vui lòng kiểm tra lại các trường.';

    /** PRG flag cho hộp thư admin (docs §3.9 — luồng Admin\Service\ContactService) */
    public const FLAG_UPDATED = 'updated';
    public const FLAG_DELETED = 'deleted';

    /** Giới hạn ghi chú admin trên form xử lý (cột là TEXT — chặn từ tầng form) */
    public const MAX_LENGTH_ADMIN_NOTE = 5000;

    /** Thông báo phía admin */
    public const ERROR_NOT_FOUND = 'Không tìm thấy lượt liên hệ.';
    public const ERROR_STATUS    = 'Trạng thái không hợp lệ.';
}
