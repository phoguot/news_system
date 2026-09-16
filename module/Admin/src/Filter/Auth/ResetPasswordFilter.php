<?php

declare(strict_types=1);

namespace Admin\Filter\Auth;

use Admin\Model\User\UserConst;
use Application\Filter\AppInputFilter;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\Validator\StringLength;

/**
 * Validate form đặt lại mật khẩu (FR-13): token + mật khẩu mới + xác nhận + CSRF.
 */
final class ResetPasswordFilter extends AppInputFilter
{
    public function __construct(bool $withCsrf = true)
    {
        $token = new Input('token');
        $token->setRequired(true);
        $token->getFilterChain()->attach(new StringTrim());
        $token->getValidatorChain()->attach(new StringLength(['min' => 32, 'max' => 255]));
        $this->add($token);

        $password = new Input('password');
        $password->setRequired(true);
        $password->getValidatorChain()->attach(new StringLength([
            'min' => UserConst::PASSWORD_MIN_LENGTH,
            'max' => UserConst::MAX_LENGTH_PASSWORD,
            'messageMin' => sprintf('Mật khẩu mới tối thiểu %d ký tự.', UserConst::PASSWORD_MIN_LENGTH),
        ]));
        $this->add($password);

        $confirm = new Input('confirmPassword');
        $confirm->setRequired(true);
        $confirm->getValidatorChain()->attach(new StringLength(['min' => 1, 'max' => UserConst::MAX_LENGTH_PASSWORD]));
        $this->add($confirm);

        parent::__construct($withCsrf);
    }

    public function tokenValue(): string
    {
        return $this->stringValue('token');
    }

    public function passwordValue(): string
    {
        return $this->stringValue('password', trim: false);
    }

    public function confirmPasswordValue(): string
    {
        return $this->stringValue('confirmPassword', trim: false);
    }
}
