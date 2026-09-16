<?php

declare(strict_types=1);

namespace Admin\Filter\Account;

use Admin\Model\User\UserConst;
use Application\Filter\AppInputFilter;
use Laminas\InputFilter\Input;
use Laminas\Validator\StringLength;

/**
 * Validate form đổi mật khẩu (docs §3.11, FR-14): mật khẩu hiện tại + mật khẩu
 * mới (≥ 8 ký tự) + xác nhận + CSRF. Mật khẩu GIỮ NGUYÊN khoảng trắng (không
 * trim — như LoginFilter). So currentPassword với hash và đối chiếu
 * newPassword/confirmPassword do AccountService đảm nhiệm (InputFilter thuần
 * không có validator cross-field — 07 §6).
 */
final class AccountPasswordFilter extends AppInputFilter
{
    public function __construct()
    {
        $current = new Input('currentPassword');
        $current->setRequired(true);
        $current->getValidatorChain()
            ->attach(new StringLength(['min' => 1, 'max' => UserConst::MAX_LENGTH_PASSWORD]));
        $this->add($current);

        $new = new Input('newPassword');
        $new->setRequired(true);
        $new->getValidatorChain()->attach(new StringLength([
            'min'           => UserConst::PASSWORD_MIN_LENGTH,
            'max'           => UserConst::MAX_LENGTH_PASSWORD,
            'messageMin'    => sprintf(UserConst::ERROR_PASSWORD_SHORT, UserConst::PASSWORD_MIN_LENGTH),
            'messageMax'    => 'Mật khẩu tối đa ' . UserConst::MAX_LENGTH_PASSWORD . ' ký tự.',
            'messageLength' => 'Độ dài mật khẩu phải trong khoảng 8–100 ký tự.',
        ]));
        $this->add($new);

        $confirm = new Input('confirmPassword');
        $confirm->setRequired(true);
        $confirm->getValidatorChain()
            ->attach(new StringLength(['min' => 1, 'max' => UserConst::MAX_LENGTH_PASSWORD]));
        $this->add($confirm);

        parent::__construct();
    }

    public function currentValue(): string
    {
        return $this->stringValue('currentPassword', trim: false);
    }

    public function newPasswordValue(): string
    {
        return $this->stringValue('newPassword', trim: false);
    }

    public function confirmPasswordValue(): string
    {
        return $this->stringValue('confirmPassword', trim: false);
    }
}
