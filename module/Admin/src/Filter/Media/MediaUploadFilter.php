<?php

declare(strict_types=1);

namespace Admin\Filter\Media;

use Admin\Model\Media\MediaConst;
use Application\Filter\AppInputFilter;
use Laminas\Filter\StringTrim;
use Laminas\Filter\ToInt;
use Laminas\InputFilter\Input;
use Laminas\Validator\Callback;

/**
 * Validate form tải media lên (docs §3.10): CSRF + số file nhận được.
 * Bộ lọc chỉ gác cửa "có file & đúng token"; kiểm tra dung lượng/Kiểu file
 * thật (finfo) thuộc MediaService vì cần mảng `$_FILES`.
 * Controller ghép `fileCount` = số mục upload còn hiệu lực vào raw trước khi
 * gọi Service (chuẩn 07 §2 — Service chạy Filter).
 */
final class MediaUploadFilter extends AppInputFilter
{
    public function __construct()
    {
        $count = new Input('fileCount');
        $count->setRequired(true)
            ->getFilterChain()->attach(new StringTrim())->attach(new ToInt());
        $count->getValidatorChain()->attach(new Callback([
            'callback' => static fn (mixed $value): bool => is_numeric($value) && (int) $value >= 1,
            'message'  => MediaConst::ERROR_NO_FILE,
        ]));
        $this->add($count);

        parent::__construct();
    }
}
