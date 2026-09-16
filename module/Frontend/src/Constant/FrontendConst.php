<?php

declare(strict_types=1);

namespace Frontend\Constant;

/**
 * Hằng kỹ thuật của module Frontend (06-quy-uoc-const §2 — <Module>/Constant/).
 * Frontend không có form/const entity; đây là chỗ cho tham số hiển thị công khai.
 */
final class FrontendConst
{
    /** Số bài mỗi trang trên `/tin-tuc` (docs §5.3: LIMIT 12 OFFSET). */
    public const NEWS_PAGE_SIZE = 12;

    /** Số dịch vụ mỗi trang trên `/dich-vu` (FR-08 — lưới phẳng 3 cột, phân trang `?page=`). */
    public const SERVICE_PAGE_SIZE = 12;

    /** Số dòng bảng giá mỗi trang trên `/bang-gia` (kiểu bảng tra cứu, Medlatec-style). */
    public const PRICING_PAGE_SIZE = 20;

    /** Cửa sổ số trang hiển thị quanh trang hiện tại trong pager (FR-02). */
    public const PAGER_WINDOW = 3;
}
