<?php

declare(strict_types=1);

namespace Admin\Filter\Post;

use Admin\Model\Category\CategoryMapper;
use Admin\Model\Post\PostConst;
use Admin\Model\Post\PostMapper;
use Application\Filter\AppInputFilter;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\Validator\Callback;
use Laminas\Validator\Digits;
use Laminas\Validator\InArray;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;

/**
 * Validate input tạo/sửa bài viết (docs §3.3) — chuẩn 07 §6.
 * `intent` (draft|publish|schedule) + `tags` (CSV) được validate tại đây;
 * thời gian hẹn giờ chỉ kiểm tra định dạng tường minh (giờ VN) — quy đổi UTC
 * nằm ở PostService. $withCsrf: form trang có CSRF (mặc định); API JSON
 * dựa cookie SameSite=Lax nên truyền false.
 *
 * $draftMode (nháp trên bài NHÁP/mới tạo): `content` được phép trống —
 * soạn dở phải lưu được. Vẫn bắt buộc `title` + `categoryId` vì cột DB
 * NOT NULL; trên bài đã xuất bản/lưu trữ không bật chế độ này
 * (nháp không được xoá trắng nội dung đang hiển thị công khai).
 */
final class PostSaveFilter extends AppInputFilter
{
    public function __construct(
        PostMapper $posts,
        CategoryMapper $categories,
        ?int $excludeId = null,
        bool $draftMode = false,
        bool $withCsrf = true,
    ) {
        $title = new Input('title');
        $title->setRequired(true)->getFilterChain()->attach(new StringTrim());
        $title->getValidatorChain()->attach(new NotEmpty([
            'message' => 'Nhập tiêu đề (tạm được) rồi hãy lưu nháp.',
        ]));
        $title->getValidatorChain()->attach(new StringLength($draftMode
            ? [
                'max'            => PostConst::MAX_LENGTH_TITLE,
                'messageTooLong' => 'Tiêu đề tối đa %max% ký tự.',
            ]
            : [
                'min'            => 3,
                'max'            => PostConst::MAX_LENGTH_TITLE,
                'messageTooLong' => 'Tiêu đề tối đa %max% ký tự.',
            ]));
        $this->add($title);

        // Slug optional — trống thì Service tự sinh từ tiêu đề; nhập tay: format + unique
        $this->addSlugField(
            PostConst::MAX_LENGTH_SLUG,
            static fn (string $value): bool => $posts->existsSlug($value, $excludeId),
        );

        $categoryId = new Input('categoryId');
        $categoryId->setRequired(true)->getFilterChain()->attach(new StringTrim());
        $categoryId->getValidatorChain()
            ->attach(new NotEmpty(['message' => 'Chọn chuyên mục cho bài viết.']))
            ->attach(new Digits(['message' => 'Chọn chuyên mục cho bài viết.']))
            ->attach(new Callback([
                'callback' => static fn (mixed $value): bool => is_numeric($value)
                    && $categories->findById((int) $value) !== null,
                'message' => 'Chuyên mục không tồn tại.',
            ]));
        $this->add($categoryId);

        $content = new Input('content');
        $content->setRequired(! $draftMode);
        if (! $draftMode) {
            $content->getValidatorChain()->attach(new NotEmpty([
                'message' => 'Nội dung bài viết không được để trống.',
            ]));
        }
        $this->add($content);

        $this->addStringField('excerpt', required: false, maxLength: PostConst::MAX_LENGTH_EXCERPT);
        $this->addIdField('bannerMediaId', required: false);
        $this->addIdField('thumbnailMediaId', required: false);

        // Giờ VN tường minh từ datetime-local: 'YYYY-MM-DD HH:MM' hoặc 'YYYY-MM-DDTHH:MM'
        $this->addDateTimeField('publishedAt', PostConst::ERROR_INVALID_TIME);

        $this->addRawField('isFeatured');
        $this->addStringField('tags', required: false);
        $this->addStringField('metaTitle', required: false, maxLength: PostConst::MAX_LENGTH_META_TITLE);
        $this->addStringField(
            'metaDescription',
            required: false,
            maxLength: PostConst::MAX_LENGTH_META_DESCRIPTION,
        );

        // Nút bấm trên form/API: draft | publish | schedule — validate tập giá trị
        $intent = new Input('intent');
        $intent->setRequired(true)->getFilterChain()->attach(new StringTrim());
        $intent->getValidatorChain()->attach(new InArray([
            'haystack' => [
                PostConst::INTENT_DRAFT,
                PostConst::INTENT_PUBLISH,
                PostConst::INTENT_SCHEDULE,
            ],
            'message' => 'Hành động lưu không hợp lệ.',
        ]));
        $this->add($intent);

        parent::__construct($withCsrf);
    }
}
