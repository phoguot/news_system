<?php

declare(strict_types=1);

namespace Application\Filter;

use Laminas\Filter\Callback as CallbackFilter;
use Laminas\InputFilter\FileInput;
use Laminas\Validator\Callback;
use Laminas\Validator\File\Extension;
use Laminas\Validator\InArray;
use Laminas\Validator\NotEmpty;

/**
 * Bộ filter + validator dùng chung cho các InputFilter của hệ thống
 * ================================================================
 * - Chuẩn hóa việc định nghĩa field: text / int / float / enum / json / file…
 * - Giảm lặp code giữa các InputFilter
 * - Tất cả method đều ở dạng static → không cần khởi tạo class
 * - Thuộc Application (gộp từ Core 13/09/2026) — code dùng chung toàn dự án.
 * - Hằng LEN_*  và MAX_JSON_BYTES là cấu hình helper (không phải hằng cột entity).
 *
 * @psalm-suppress UnusedClass
 */
final class CommonFieldFilters
{
    public const string TYPE_TEXT = 'text';

    public const string TYPE_INT = 'int';

    public const string TYPE_FLOAT = 'float';

    public const string TYPE_ENUM = 'enum';

    /** Meta Title (SEO): tối đa ~70 ký tự */
    public const int LEN_META_TITLE = 70;

    /** Meta Description (SEO): tối đa ~160 ký tự */
    public const int LEN_META_DESCRIPTION = 160;

    /** Meta Keywords (SEO): tối đa ~320 ký tự */
    public const int LEN_META_KEYWORDS = 320;

    /** Title ngắn / tên chương trình: tối đa 120 ký tự */
    public const int LEN_TITLE = 120;

    /** Description ngắn / ghi chú: tối đa 255 ký tự */
    public const int LEN_DESCRIPTION = 255;

    /** Mã code / slug ngắn: tối đa 50 ký tự */
    public const int LEN_CODE = 50;

    /** Nội dung dài (content/body) tối đa */
    public const int LEN_CONTENT = 5000;

    /** Giới hạn mặc định cho chuỗi JSON raw: 2MB (tính theo byte) */
    public const int MAX_JSON_BYTES = 2097152;

    /**
     * Tạo cấu hình Filter + Validator cho field dạng text/meta/title/description.
     */
    public static function textField(
        string $name,
        bool $required = false,
        int $maxLength = self::LEN_DESCRIPTION,
    ): array {
        return [
            'name'       => $name,
            'required'   => $required,
            'filters'    => [
                ['name' => 'StringTrim'],
                ['name' => 'StripTags'],
                ['name' => HtmlPurifierFilter::class],
            ],
            'validators' => [
                [
                    'name'                   => 'StringLength',
                    'break_chain_on_failure' => true,
                    'options'                => [
                        'max'      => $maxLength,
                        'messages' => [
                            'stringLengthTooLong' =>
                                "Nội dung không hợp lệ (tối đa {$maxLength} ký tự)",
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Field chuỗi RAW — chỉ trim + (tùy chọn) NotEmpty.
     */
    public static function rawStringField(
        string $name,
        bool $required = false,
    ): array {
        return [
            'name'       => $name,
            'required'   => $required,
            'filters'    => [
                ['name' => 'StringTrim'],
            ],
            'validators' => $required
                ? [[
                    'name'                   => NotEmpty::class,
                    'break_chain_on_failure' => true,
                    'options'                => [
                        'messages' => [
                            NotEmpty::IS_EMPTY => "Trường '{$name}' không được để trống",
                        ],
                    ],
                ]]
                : [],
        ];
    }

    /**
     * Field nhận chuỗi JSON object từ FE, lưu nguyên văn (không decode).
     *
     * @param array{required?:bool,maxBytes?:int} $config
     */
    public static function jsonObjectStringField(
        string $name,
        array $config = [],
    ): array {
        $required = $config['required'] ?? false;
        $maxBytes = $config['maxBytes'] ?? self::MAX_JSON_BYTES;

        return [
            'name'       => $name,
            'required'   => $required,
            'filters'    => [
                ['name' => 'StringTrim'],
            ],
            'validators' => [
                [
                    'name'                   => NotEmpty::class,
                    'break_chain_on_failure' => true,
                    'options'                => [
                        'messages' => [
                            NotEmpty::IS_EMPTY => "Trường '{$name}' không được để trống",
                        ],
                    ],
                ],
                [
                    'name'                   => Callback::class,
                    'break_chain_on_failure' => true,
                    'options'                => [
                        'callback' => static function (mixed $value) use ($maxBytes): bool {
                            return is_string($value)
                                && strlen($value) <= $maxBytes
                                && is_object(json_decode($value));
                        },
                        'messages' => [
                            Callback::INVALID_VALUE =>
                                "Trường '{$name}' phải là chuỗi JSON object, tối đa {$maxBytes} byte",
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Tạo filter cho field dạng số nguyên (ID, counter, sort order...).
     */
    public static function intField(string $name, bool $required = false): array
    {
        return [
            'name'     => $name,
            'required' => $required,
            'filters'  => [
                ['name' => 'ToInt'],
            ],
        ];
    }

    /**
     * Tạo InputFilter cho upload file Excel.
     *
     * @param list<string> $allowedExtensions
     */
    public static function fileUploadField(
        string $name,
        bool $required = false,
        array $allowedExtensions = ['xls', 'xlt', 'xlsx', 'xlsm', 'xltx'],
    ): array {
        return [
            'name'       => $name,
            'type'       => FileInput::class,
            'required'   => $required,
            'validators' => [
                [
                    'name'                   => NotEmpty::class,
                    'break_chain_on_failure' => true,
                    'options'                => [
                        'messages' => [
                            NotEmpty::IS_EMPTY => 'Bạn chưa chọn file',
                        ],
                    ],
                ],
                [
                    'name'                   => Extension::class,
                    'break_chain_on_failure' => true,
                    'options'                => [
                        'extension' => $allowedExtensions,
                        'messages'  => [
                            Extension::FALSE_EXTENSION => 'File Excel không đúng định dạng',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Field nhận JSON từ FE (object hoặc JSON string).
     */
    public static function jsonPayloadField(
        string $name,
        bool $required = false,
    ): array {
        return [
            'name'       => $name,
            'required'   => $required,
            'filters'    => [
                ['name' => 'StringTrim'],
                ['name' => 'StripTags'],
                [
                    'name'    => CallbackFilter::class,
                    'options' => [
                        'callback' => static function (mixed $value): mixed {
                            if (is_array($value)) {
                                return $value;
                            }

                            if (is_string($value) && $value !== '') {
                                /** @psalm-suppress MixedAssignment */
                                $decoded = json_decode($value, true);

                                return json_last_error() === JSON_ERROR_NONE
                                    ? $decoded
                                    : null;
                            }

                            return null;
                        },
                    ],
                ],
            ],
            'validators' => [
                [
                    'name'                   => NotEmpty::class,
                    'break_chain_on_failure' => true,
                    'options'                => [
                        'messages' => [
                            NotEmpty::IS_EMPTY => 'Dữ liệu không được để trống',
                        ],
                    ],
                ],
                [
                    'name'                   => Callback::class,
                    'break_chain_on_failure' => true,
                    'options'                => [
                        'callback' => static function (mixed $value): bool {
                            return is_array($value);
                        },
                        'messages' => [
                            Callback::INVALID_VALUE => 'Payload không phải JSON hợp lệ',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Tạo filter động cho 1 field theo config.
     *
     * @param array{required?:bool,type?:string,enumValues?:list<mixed>,maxLength?:int} $config
     */
    public static function dynamicField(
        string $fieldName,
        array $config = [],
    ): array {
        $required   = $config['required'] ?? false;
        $type       = $config['type'] ?? 'int';
        $enumValues = $config['enumValues'] ?? [];
        $maxLength  = $config['maxLength'] ?? self::LEN_DESCRIPTION;

        $filters = [['name' => 'StringTrim']];

        switch ($type) {
            case 'text':
                $filters[] = ['name' => 'StripTags'];
                $filters[] = ['name' => HtmlPurifierFilter::class];
                break;

            case 'float':
                $filters[] = ['name' => 'ToFloat'];
                break;

            case 'enum':
            case 'int':
                $filters[] = ['name' => 'Digits'];
                break;
        }

        $validators = [];

        if ($required) {
            $validators[] = [
                'name'                   => NotEmpty::class,
                'break_chain_on_failure' => true,
                'options'                => [
                    'messages' => [
                        NotEmpty::IS_EMPTY => "Trường '{$fieldName}' không được để trống",
                    ],
                ],
            ];
        }

        if ($type === 'text') {
            $validators[] = [
                'name'                   => 'StringLength',
                'break_chain_on_failure' => true,
                'options'                => [
                    'max'      => $maxLength,
                    'messages' => [
                        'stringLengthTooLong' =>
                            "Trường '{$fieldName}' vượt quá {$maxLength} ký tự",
                    ],
                ],
            ];
        }

        if ($type === 'enum' && $enumValues) {
            $validators[] = [
                'name'                   => InArray::class,
                'break_chain_on_failure' => true,
                'options'                => [
                    'haystack' => $enumValues,
                    'messages' => [
                        InArray::NOT_IN_ARRAY => "Giá trị của '{$fieldName}' không hợp lệ",
                    ],
                ],
            ];
        }

        return [
            'name'       => $fieldName,
            'required'   => $required,
            'filters'    => $filters,
            'validators' => $validators,
        ];
    }

    private static function buildArrayField(
        string $name,
        bool $required,
        callable $itemCaster,
    ): array {
        return [
            'name'     => $name,
            'required' => $required,
            'filters' => [
                ['name' => 'StringTrim'],
                [
                    'name'    => CallbackFilter::class,
                    'options' => [
                        'callback' => static function (mixed $value) use ($itemCaster): mixed {
                            if (is_array($value)) {
                                return array_values(
                                    array_filter(array_map($itemCaster, $value)),
                                );
                            }

                            if (is_string($value) && $value !== '') {
                                /** @psalm-suppress MixedAssignment */
                                $decoded = json_decode($value, true);

                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    return array_values(
                                        array_filter(array_map($itemCaster, $decoded)),
                                    );
                                }
                            }

                            return [];
                        },
                    ],
                ],
            ],
            'validators' => $required
                ? [[
                    'name'                   => NotEmpty::class,
                    'break_chain_on_failure' => true,
                    'options'                => [
                        'messages' => [
                            NotEmpty::IS_EMPTY => "Trường '{$name}' không được để trống",
                        ],
                    ],
                ]]
                : [],
        ];
    }

    public static function intArrayField(
        string $name,
        bool $required = false,
    ): array {
        return self::buildArrayField($name, $required, static function (mixed $v): ?int {
            return is_numeric($v) ? (int) $v : null;
        });
    }

    public static function stringArrayField(
        string $name,
        bool $required = false,
    ): array {
        return self::buildArrayField($name, $required, static function (mixed $v): ?string {
            $str = trim((string) $v);

            return $str !== '' ? $str : null;
        });
    }

    public static function objectArrayField(
        string $name,
        bool $required = false,
    ): array {
        return self::buildArrayField($name, $required, static function (mixed $v): ?array {
            if (is_array($v)) {
                return $v;
            }

            if (is_object($v)) {
                return (array) $v;
            }

            return null;
        });
    }
}
