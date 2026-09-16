<?php

declare(strict_types=1);

namespace Admin\Filter\Setting;

use Application\Filter\AppInputFilter;
use Frontend\Model\Setting\SettingConst;
use Frontend\Model\Setting\SettingModel;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\Validator\Callback;

/**
 * Validate form tổng hợp trang /admin/settings (docs §3.12): mỗi cài đặt là
 * input `setting_<id>` (view render input phẳng, không mảng lồng) + CSRF.
 * Rule từng input theo `valueType` của đúng dòng SettingModel được seed.
 * Service `new` filter này mỗi request (chuẩn 07 §2 — InputFilter stateful).
 */
final class SettingSaveFilter extends AppInputFilter
{
    /** @param list<SettingModel> $settings */
    public function __construct(array $settings, bool $withCsrf = true)
    {
        foreach ($settings as $setting) {
            $input = new Input('setting_' . $setting->id);
            $input->setRequired(false)->getFilterChain()->attach(new StringTrim());
            if ($setting->settingKey === SettingConst::KEY_NOTIFY_EMAILS) {
                $input->getValidatorChain()->attach(new Callback([
                    'callback' => static fn (mixed $v): bool => self::isValidNotifyEmails(trim((string) $v)),
                    'message'  => SettingConst::ERROR_NOTIFY_EMAILS,
                ]));
            } else {
                $input->getValidatorChain()->attach(new Callback([
                    'callback' => self::valueValidator($setting->valueType),
                    'message'  => self::valueMessage($setting->valueType),
                ]));
            }
            $this->add($input);
        }

        parent::__construct($withCsrf);
    }

    private static function valueValidator(int $type): callable
    {
        return static function (mixed $value) use ($type): bool {
            if ($value === null || trim((string) $value) === '') {
                return true; // rỗng = để NULL (trừ boolean được chọn 0/1 từ select)
            }

            $v = trim((string) $value);

            return match ($type) {
                SettingConst::VALUE_TYPE_NUMBER  => is_numeric($v),
                SettingConst::VALUE_TYPE_BOOLEAN => $v === '0' || $v === '1',
                SettingConst::VALUE_TYPE_MEDIA   => is_numeric($v) && (int) $v > 0,
                SettingConst::VALUE_TYPE_JSON    => self::isJson($v),
                default                          => mb_strlen($v, 'UTF-8') <= 60000,
            };
        };
    }

    private static function valueMessage(int $type): string
    {
        return match ($type) {
            SettingConst::VALUE_TYPE_NUMBER  => SettingConst::ERROR_NOT_NUMBER,
            SettingConst::VALUE_TYPE_BOOLEAN => SettingConst::ERROR_NOT_BOOLEAN,
            SettingConst::VALUE_TYPE_MEDIA   => SettingConst::ERROR_NOT_MEDIA,
            SettingConst::VALUE_TYPE_JSON    => SettingConst::ERROR_NOT_JSON,
            default                          => 'Giá trị quá dài.',
        };
    }

    /**
     * Validator riêng cho notify_emails (CSV email) — tái dùng chung logic
     * với parseNotifyEmails của Frontend\ContactService nhưng trả lỗi rõ ràng
     * ngay trên form Cài đặt để bạn biết mail cá nhân nhập sai định dạng.
     */
    public static function isValidNotifyEmails(string $csv): bool
    {
        $csv = trim($csv);
        if ($csv === '') {
            return true;
        }
        foreach (explode(',', $csv) as $part) {
            $email = trim($part);
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return false;
            }
        }

        return true;
    }

    private static function isJson(string $v): bool
    {
        return json_validate($v);
    }

    /**
     * Giá trị đã trim từng cài đặt: ['setting_<id>' => string].
     *
     * @return array<string, string>
     */
    public function valuesByInputName(): array
    {
        $out = [];
        /** @psalm-suppress MixedAssignment — getValues() luôn trả mixed */
        foreach ($this->getValues() as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'setting_') && is_string($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
