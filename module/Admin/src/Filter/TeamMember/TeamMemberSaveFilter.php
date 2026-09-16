<?php

declare(strict_types=1);

namespace Admin\Filter\TeamMember;

use Admin\Model\TeamMember\TeamMemberConst;
use Application\Filter\AppInputFilter;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\Validator\EmailAddress;
use Laminas\Validator\Regex;
use Laminas\Validator\StringLength;

/**
 * Validate input tạo/sửa thành viên đội ngũ (docs §3.6) — chuẩn 07 §6:
 * InputFilter thuần, Service `new` mỗi request (stateful), dùng chung create + update.
 * `userId` liên kết tài khoản CMS (nullable, unique) + `socialLinks` JSON (URL mạng xã hội).
 */
final class TeamMemberSaveFilter extends AppInputFilter
{
    public function __construct(bool $withCsrf = true)
    {
        $fullName = new Input('fullName');
        $fullName->setRequired(true)->getFilterChain()->attach(new StringTrim());
        $fullName->getValidatorChain()->attach(new StringLength([
            'min'            => 2,
            'max'            => TeamMemberConst::MAX_LENGTH_FULL_NAME,
            'messageTooLong' => 'Họ tên tối đa %max% ký tự.',
        ]));
        $this->add($fullName);

        $position = new Input('positionTitle');
        $position->setRequired(true)->getFilterChain()->attach(new StringTrim());
        $position->getValidatorChain()->attach(new StringLength([
            'min'            => 2,
            'max'            => TeamMemberConst::MAX_LENGTH_POSITION,
            'messageTooLong' => 'Chức danh tối đa %max% ký tự.',
        ]));
        $this->add($position);

        $this->addIdField('avatarMediaId', required: false);
        $this->addStringField('bio', required: false);

        $email = new Input('email');
        $email->setRequired(false)->getFilterChain()->attach(new StringTrim());
        $email->getValidatorChain()
            ->attach(new StringLength(['max' => TeamMemberConst::MAX_LENGTH_EMAIL]))
            ->attach(new EmailAddress([
                'message' => 'Email không hợp lệ.',
                'useMxCheck' => false,
            ]));
        $this->add($email);

        $phone = new Input('phone');
        $phone->setRequired(false)->getFilterChain()->attach(new StringTrim());
        $phone->getValidatorChain()
            ->attach(new StringLength(['max' => TeamMemberConst::MAX_LENGTH_PHONE]))
            ->attach(new Regex([
                'pattern' => '/^[0-9+.\-\s]*$/',
                'message' => 'Số điện thoại chỉ gồm số, dấu +, ., - và khoảng trắng.',
            ]));
        $this->add($phone);

        $this->addIdField('userId', required: false);

        $social = new Input('socialLinks');
        $social->setRequired(false)->getFilterChain()->attach(new StringTrim());
        // JSON hợp lệ khi không rỗng — sai format → lỗi, không lặng lẽ bỏ qua
        $social->getValidatorChain()->attach(new \Laminas\Validator\Callback([
            'callback' => static function (mixed $value): bool {
                if (! is_string($value) || trim($value) === '') {
                    return true;
                }
                $decoded = json_decode(trim($value), true);
                return is_array($decoded);
            },
            'message' => TeamMemberConst::ERROR_SOCIAL_LINKS,
        ]));
        $this->add($social);

        $this->addRawField('showContact');
        $this->addIntCastField('sortOrder');
        $this->addRawField('isFeatured');
        $this->addRawField('isActive');

        parent::__construct($withCsrf);
    }

    public function userIdValue(): ?int
    {
        return $this->positiveIdValue('userId');
    }

    /**
     * JSON `socialLinks` hợp lệ hoặc null — sai JSON → bỏ qua (không chặn lưu),
     * chỉ giữ chuỗi JSON compact khi parse được.
     */
    public function socialLinksValue(): ?string
    {
        $raw = trim($this->stringValue('socialLinks'));
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        // Chỉ giữ các URL http(s) hợp lệ — tránh lưu rác/xss qua JSON
        $clean = [];
        foreach ($decoded as $key => $url) {
            if (! is_string($key) || ! is_string($url)) {
                continue;
            }
            $k = trim($key);
            $u = trim($url);
            if ($k === '' || $u === '') {
                continue;
            }
            if (filter_var($u, FILTER_VALIDATE_URL) === false) {
                continue;
            }
            $scheme = strtolower((string) parse_url($u, PHP_URL_SCHEME));
            if ($scheme !== 'http' && $scheme !== 'https') {
                continue;
            }
            $clean[$k] = $u;
        }

        return $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
