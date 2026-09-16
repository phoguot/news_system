<?php

declare(strict_types=1);

namespace Frontend\Model\Setting;

/**
 * Hằng entity `settings` — key các thiết lập luồng công khai dùng (docs §4.5)
 * + mã `valueType` (docs §4.4.7) + PRG flag cho trang cài đặt admin (§3.12).
 */
class SettingConst
{
    /** Key danh sách email nhận thông báo liên hệ (CSV) */
    public const KEY_NOTIFY_EMAILS = 'notify_emails';

    public const KEY_ADDRESS       = 'address';
    public const KEY_MAP_EMBED_URL = 'map_embed_url';
    public const KEY_MAP_ADDRESS   = 'map_address';

    /** Key meta description mặc định (SEO) — render input 1 dòng, không textarea */
    public const KEY_DEFAULT_META_DESCRIPTION = 'default_meta_description';

    /** Mã `valueType` (docs §4.4.7): 1=string 2=text 3=html 4=number 5=boolean 6=json 7=media */
    public const VALUE_TYPE_STRING  = 1;
    public const VALUE_TYPE_TEXT    = 2;
    public const VALUE_TYPE_HTML    = 3;
    public const VALUE_TYPE_NUMBER  = 4;
    public const VALUE_TYPE_BOOLEAN = 5;
    public const VALUE_TYPE_JSON    = 6;
    public const VALUE_TYPE_MEDIA   = 7;

    /** 4 nhóm cài đặt (docs §3.12) */
    public const GROUP_LABELS = [
        'general' => 'Chung',
        'contact' => 'Liên hệ',
        'social'  => 'Mạng xã hội',
        'seo'     => 'SEO',
    ];

    /** PRG flag trang /admin/settings */
    public const FLAG_UPDATED = 'updated';

    /** Thông báo validate phía admin */
    public const ERROR_NOT_FOUND   = 'Không tìm thấy cài đặt.';
    public const ERROR_NOT_NUMBER  = 'Giá trị phải là số.';
    public const ERROR_NOT_BOOLEAN = 'Giá trị chỉ nhận Có/Không.';
    public const ERROR_NOT_MEDIA   = 'Hãy chọn ảnh hợp lệ trong thư viện Media, hoặc để trống.';
    public const ERROR_NOT_JSON    = 'Giá trị phải là JSON hợp lệ hoặc để trống.';
    public const ERROR_NOTIFY_EMAILS = 'Danh sách email không hợp lệ — mỗi địa chỉ phân tách bằng dấu phẩy '
        . 'phải đúng định dạng email.';
}
