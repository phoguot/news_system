<?php

declare(strict_types=1);

namespace Application\Filter;

use Laminas\Filter\StringTrim;
use Laminas\Filter\ToInt;
use Laminas\InputFilter\Input;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\Callback;
use Laminas\Validator\Csrf;
use Laminas\Validator\Digits;
use Laminas\Validator\Regex;
use Laminas\Validator\StringLength;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * InputFilter nền tảng chung cho MỌI filter của Admin + Frontend (chuẩn 07 §6).
 *
 * Trách nhiệm tập trung ở đây (filter riêng chỉ khai báo field bằng các hàm
 * add*()/getter dùng chung, không tự lặp lại cơ chế):
 * - CSRF: sở hữu validator `Csrf` (name 'csrf'), tự add input khi
 *   $withCsrf = true; lộ `csrfHash()` cho view render hidden input.
 * - `fieldErrors()`: lỗi đầu tiên từng trường cho view/JSON.
 * - Container: `setContainer()`/`getContainer()` (tuỳ chọn — DI thường do
 *   Service truyền mapper trực tiếp vì InputFilter stateful, `new` mỗi
 *   request, KHÔNG đăng ký service_manager).
 * - Helper khai báo field (trim/Digits, StringLength, slug, ToInt, giờ VN…)
 *   và getter giá trị an toàn kiểu (positiveIdValue, stringValue…).
 *
 * @extends InputFilter<array<array-key, mixed>>
 *
 * Validator Csrf deprecated từ laminas-validator 3 nhưng vẫn là cơ chế chuẩn
 * của repo (07 §6) — suppress gom về đúng một chỗ tại đây.
 *
 * @psalm-suppress DeprecatedClass
 */
abstract class AppInputFilter extends InputFilter
{
    /**
     * Định dạng giờ VN tường minh từ input datetime-local
     * ('Y-m-d', 'Y-m-d H:i'/'Y-m-d\TH:i', tuỳ chọn giây) — dùng chung cho
     * publishedAt/startAt/endAt; quy đổi UTC thuộc trách nhiệm Service.
     */
    public const string DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/';

    /** Pattern slug chuẩn của hệ thống: chữ thường, số, gạch nối đơn. */
    public const string SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    private ?ContainerInterface $container = null;

    /** @psalm-suppress DeprecatedClass */
    private readonly Csrf $csrfValidator;

    /**
     * $withCsrf = true (mặc định): thêm input `csrf` bắt buộc. API JSON nội bộ
     * dựa cookie SameSite=Lax → Service truyền false.
     */
    public function __construct(bool $withCsrf = true)
    {
        $this->csrfValidator = new Csrf();
        $this->csrfValidator->setName('csrf');

        if ($withCsrf) {
            $csrf = new Input('csrf');
            $csrf->setRequired(true);
            $csrf->getValidatorChain()->attach($this->csrfValidator);
            $this->add($csrf);
        }
    }

    // ------------------------------------------------------------------
    // Container (tuỳ chọn — không bắt buộc; Service vẫn new filter mỗi request)
    // ------------------------------------------------------------------

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function setContainer(?ContainerInterface $container): static
    {
        $this->container = $container;

        return $this;
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getContainer(): ContainerInterface
    {
        if ($this->container === null) {
            throw new RuntimeException(
                static::class . ' chưa được setContainer() — filter chuẩn hoạt động'
                . ' không cần container (Service truyền dependency trực tiếp khi new).',
            );
        }

        return $this->container;
    }

    // ------------------------------------------------------------------
    // CSRF + lỗi validate (tập trung — mọi filter thừa hưởng)
    // ------------------------------------------------------------------

    /** Giá trị cho <input type="hidden" name="csrf"> trong view. */
    public function csrfHash(): string
    {
        return $this->csrfValidator->getHash();
    }

    /**
     * Lỗi đầu tiên từng trường: [fieldName => message].
     *
     * @return array<string, string>
     */
    public function fieldErrors(): array
    {
        $errors = [];
        foreach ($this->getMessages() as $field => $messages) {
            $first = $messages === [] ? null : reset($messages);
            if (is_string($first) && $first !== '') {
                $errors[(string) $field] = $first;
            }
        }

        return $errors;
    }

    // ------------------------------------------------------------------
    // Getter giá trị dùng chung (an toàn kiểu trên dữ liệu đã lọc)
    // ------------------------------------------------------------------

    /** Chuỗi sau validate; $trim = false cho mật khẩu (giữ nguyên khoảng trắng). */
    public function stringValue(string $field, bool $trim = true): string
    {
        /** @var mixed $raw */
        $raw = $this->getValue($field);
        $value = is_string($raw) ? $raw : '';

        return $trim ? trim($value) : $value;
    }

    /** Chuỗi trim; rỗng → null (optional text). */
    public function nullableStringValue(string $field): ?string
    {
        $value = $this->stringValue($field);

        return $value === '' ? null : $value;
    }

    /** Số id dương hợp lệ; sai/thiếu → null. */
    public function positiveIdValue(string $field): ?int
    {
        /** @var mixed $raw */
        $raw = $this->getValue($field);

        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    /** Checkbox/flag: 1 | '1' | true → true. */
    public function flagValue(string $field): bool
    {
        /** @var mixed $raw */
        $raw = $this->getValue($field);

        return $raw === 1 || $raw === '1' || $raw === true;
    }

    // ------------------------------------------------------------------
    // Builder field dùng chung — filter riêng CHỈ gọi các hàm này
    // ------------------------------------------------------------------

    /**
     * Field id/số: trim + Digits. Bỏ trống chuỗi '' an toàn vì không required
     * (required = true → thiếu là lỗi bắt buộc theo semantics InputFilter).
     */
    protected function addIdField(
        string $name = 'id',
        bool $required = true,
        ?string $digitsMessage = null,
    ): void {
        $input = new Input($name);
        $input->setRequired($required)->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(
            $digitsMessage === null ? new Digits() : new Digits(['message' => $digitsMessage]),
        );
        $this->add($input);
    }

    /**
     * Field text: trim (+ StringLength max khi truyền $maxLength).
     */
    protected function addStringField(
        string $name,
        bool $required,
        ?int $maxLength = null,
    ): void {
        $input = new Input($name);
        $input->setRequired($required)->getFilterChain()->attach(new StringTrim());
        if ($maxLength !== null) {
            $input->getValidatorChain()->attach(new StringLength(['max' => $maxLength]));
        }
        $this->add($input);
    }

    /**
     * Field slug optional dùng chung 4 entity: trống → Service tự sinh;
     * nhập tay phải đúng format và không đụng `$slugExists` (callback nhận
     * slug đã trim, trả true khi ĐÃ tồn tại).
     */
    protected function addSlugField(
        int $maxLength,
        callable $slugExists,
        string $name = 'slug',
    ): void {
        $input = new Input($name);
        $input->setRequired(false)->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()
            ->attach(new StringLength(['max' => $maxLength]))
            ->attach(new Regex([
                'pattern' => self::SLUG_PATTERN,
                'message' => 'Slug chỉ gồm chữ thường, số và dấu gạch nối.',
            ]))
            ->attach(new Callback([
                'callback' => static fn (mixed $value): bool => ! is_string($value)
                    || $value === ''
                    || ! $slugExists($value),
                'message' => 'Slug đã được sử dụng.',
            ]));
        $this->add($input);
    }

    /** Field số nguyên nội bộ (sortOrder…): ép ToInt, không chặn validate. */
    protected function addIntCastField(string $name, bool $required = false): void
    {
        $input = new Input($name);
        $input->setRequired($required)->getFilterChain()->attach(new ToInt());
        $this->add($input);
    }

    /** Field cờ/boolean trả về nguyên văn từ request (isActive, isFeatured…). */
    protected function addRawField(string $name, bool $required = false): void
    {
        $input = new Input($name);
        $input->setRequired($required);
        $this->add($input);
    }

    /** Field giờ VN tường minh (publishedAt, startAt, endAt…): trim + Regex. */
    protected function addDateTimeField(
        string $name,
        string $message,
        bool $required = false,
    ): void {
        $input = new Input($name);
        $input->setRequired($required)->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new Regex([
            'pattern' => self::DATETIME_PATTERN,
            'message' => $message,
        ]));
        $this->add($input);
    }
}
