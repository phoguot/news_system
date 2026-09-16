<?php

declare(strict_types=1);

namespace Admin\Filter\Auth;

use Application\Filter\AppInputFilter;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\Validator\EmailAddress;
use Laminas\Validator\StringLength;

/**
 * Validate form quên mật khẩu (FR-13): chỉ email + CSRF.
 */
final class ForgotPasswordFilter extends AppInputFilter
{
    public function __construct(bool $withCsrf = true)
    {
        $email = new Input('email');
        $email->setRequired(true);
        $email->getFilterChain()->attach(new StringTrim());
        $email->getValidatorChain()
            ->attach(new StringLength(['max' => 255]))
            ->attach(new EmailAddress(['message' => 'Email không hợp lệ.']));
        $this->add($email);

        parent::__construct($withCsrf);
    }

    public function emailValue(): string
    {
        return $this->stringValue('email');
    }
}
