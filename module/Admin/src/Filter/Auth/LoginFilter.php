<?php

declare(strict_types=1);

namespace Admin\Filter\Auth;

use Application\Filter\AppInputFilter;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\Validator\Regex;
use Laminas\Validator\StringLength;

/**
 * Validate đăng nhập admin (docs §7.3): định danh + password + CSRF token.
 * Từ 13/09/2026 ô định danh chấp nhận CẢ email lẫn username — không dùng
 * validator EmailAddress (chặn username); Regex chỉ chặn tập ký tự lạc hướng,
 * giá trị không tồn tại vẫn trả thông báo credential chung như mọi ca sai khác.
 * Chuẩn 07 §6: InputFilter thuần kế thừa AppInputFilter (CSRF + csrfHash()
 * do nền tảng đảm nhiệm), Service `new` mỗi request.
 */
final class LoginFilter extends AppInputFilter
{
    /** Tập ký tự an toàn cho cả email lẫn username (username regex chặt ở tầng mapper lookup). */
    public const IDENTITY_PATTERN = '#^[A-Za-z0-9@._%+\-]{3,255}$#';

    public function __construct(bool $withCsrf = true)
    {
        $identity = new Input('identity');
        $identity->setRequired(true)
            ->getFilterChain()->attach(new StringTrim());
        $identity->getValidatorChain()
            ->attach(new StringLength(['min' => 3, 'max' => 255]))
            ->attach(new Regex(['pattern' => self::IDENTITY_PATTERN]));
        $this->add($identity);

        $password = new Input('password');
        $password->setRequired(true);
        $password->getValidatorChain()
            ->attach(new StringLength(['min' => 1, 'max' => 100]));
        $this->add($password);

        parent::__construct($withCsrf);
    }
}
