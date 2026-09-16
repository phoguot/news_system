<?php

declare(strict_types=1);

namespace Admin\Filter\Account;

use Admin\Model\User\UserConst;
use Application\Filter\AppInputFilter;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\Validator\Callback;
use Laminas\Validator\Digits;
use Laminas\Validator\EmailAddress;
use Laminas\Validator\Regex;
use Laminas\Validator\StringLength;

/**
 * Validate form "Tài khoản của tôi" — hồ sơ (docs §3.11, FR-14):
 * họ tên + email (đăng nhập) + username (tuỳ chọn, unique) + SĐT optional +
 * avatarMediaId optional + CSRF.
 *
 * Callback kiểm unique username nhận từ Service (filter chỉ dùng trong
 * Admin\Service\AccountService::profileForm với id dòng hiện hữu — bảng 1 dòng).
 */
final class AccountProfileFilter extends AppInputFilter
{
    /**
     * @param callable(string): bool|null $usernameAvailable NULL = không kiểm unique
     *                                                       (lấy CSRF hash)
     */
    public function __construct(?callable $usernameAvailable = null)
    {
        $name = new Input('fullName');
        $name->setRequired(true)->getFilterChain()->attach(new StringTrim());
        $name->getValidatorChain()->attach(new StringLength([
            'max'        => UserConst::MAX_LENGTH_FULL_NAME,
            'messageMax' => 'Họ tên tối đa ' . UserConst::MAX_LENGTH_FULL_NAME . ' ký tự.',
        ]));
        $this->add($name);

        $email = new Input('email');
        $email->setRequired(true)->getFilterChain()->attach(new StringTrim());
        $email->getValidatorChain()
            ->attach(new StringLength([
                'max'        => UserConst::MAX_LENGTH_EMAIL,
                'messageMax' => 'Email tối đa ' . UserConst::MAX_LENGTH_EMAIL . ' ký tự.',
            ]))
            ->attach(new EmailAddress(['message' => 'Email không hợp lệ.']));
        $this->add($email);

        $username = new Input('username');
        $username->setRequired(false)->setAllowEmpty(true)
            ->getFilterChain()->attach(new StringTrim());
        $username->getValidatorChain()
            ->attach(new StringLength([
                'max'        => UserConst::MAX_LENGTH_USERNAME,
                'messageMax' => 'Tên đăng nhập tối đa ' . UserConst::MAX_LENGTH_USERNAME . ' ký tự.',
            ]))
            ->attach(new Regex([
                'pattern' => UserConst::USERNAME_PATTERN,
                'message' => UserConst::ERROR_USERNAME_FORMAT,
            ]));
        if ($usernameAvailable !== null) {
            $username->getValidatorChain()->attach(new Callback([
                'callback' => static fn (mixed $value): bool => is_string($value) && $value !== ''
                    && $usernameAvailable($value),
                'message'  => UserConst::ERROR_USERNAME_TAKEN,
            ]));
        }
        $this->add($username);

        $phone = new Input('phone');
        $phone->setRequired(false)->setAllowEmpty(true)
            ->getFilterChain()->attach(new StringTrim());
        $phone->getValidatorChain()
            ->attach(new StringLength([
                'max'        => UserConst::MAX_LENGTH_PHONE,
                'messageMax' => 'Số điện thoại tối đa ' . UserConst::MAX_LENGTH_PHONE . ' ký tự.',
            ]))
            ->attach(new Regex([
                'pattern' => UserConst::PHONE_PATTERN,
                'message' => 'Số điện thoại chỉ gồm chữ số và các ký tự + - . cách (5–20 ký tự).',
            ]));
        $this->add($phone);

        $avatar = new Input('avatarMediaId');
        $avatar->setRequired(false)->setAllowEmpty(true)
            ->getFilterChain()->attach(new StringTrim());
        $avatar->getValidatorChain()->attach(new Digits(['message' => 'Media avatar phải là số id.']));
        $this->add($avatar);

        parent::__construct();
    }

    public function fullNameValue(): string
    {
        return $this->stringValue('fullName');
    }

    public function emailValue(): string
    {
        return $this->stringValue('email');
    }

    public function usernameValue(): ?string
    {
        return $this->nullableStringValue('username');
    }

    public function phoneValue(): ?string
    {
        return $this->nullableStringValue('phone');
    }

    public function avatarMediaIdValue(): ?int
    {
        return $this->positiveIdValue('avatarMediaId');
    }
}
