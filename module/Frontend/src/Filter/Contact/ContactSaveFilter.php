<?php

declare(strict_types=1);

namespace Frontend\Filter\Contact;

use Application\Filter\AppInputFilter;
use Frontend\Model\Contact\ContactConst;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\Validator\Digits;
use Laminas\Validator\EmailAddress;
use Laminas\Validator\Identical;
use Laminas\Validator\StringLength;

/**
 * Validate form liên hệ công khai (docs §3.9): họ tên + email + SĐT + dịch vụ +
 * tiêu đề + nội dung + consent + honeypot ẩn + CSRF token.
 * Chuẩn 07 §6: InputFilter thuần kế thừa AppInputFilter (nền tảng lo CSRF +
 * fieldErrors), không dùng Laminas\Form làm lớp validate — view render element
 * HTML trực tiếp, token CSRF lấy qua csrfHash().
 */
final class ContactSaveFilter extends AppInputFilter
{
    public function __construct()
    {
        $fullName = new Input('fullName');
        $fullName->setRequired(true)
            ->getFilterChain()->attach(new StringTrim());
        $fullName->getValidatorChain()->attach(new StringLength([
            'min' => 2,
            'max' => ContactConst::MAX_LENGTH_FULLNAME,
        ]));
        $this->add($fullName);

        $email = new Input('email');
        $email->setRequired(true)
            ->getFilterChain()->attach(new StringTrim());
        $email->getValidatorChain()
            ->attach(new StringLength(['max' => ContactConst::MAX_LENGTH_EMAIL]))
            ->attach(new EmailAddress());
        $this->add($email);

        $this->addStringField('phone', required: false, maxLength: ContactConst::MAX_LENGTH_PHONE);

        $serviceId = new Input('serviceId');
        $serviceId->setRequired(false);
        $serviceId->getValidatorChain()->attach(new Digits());
        $this->add($serviceId);

        $this->addStringField('subject', required: false, maxLength: ContactConst::MAX_LENGTH_SUBJECT);

        $message = new Input('message');
        $message->setRequired(true)
            ->getFilterChain()->attach(new StringTrim());
        $message->getValidatorChain()->attach(new StringLength([
            'min' => 10,
            'max' => ContactConst::MAX_LENGTH_MESSAGE,
        ]));
        $this->add($message);

        $consent = new Input('consent');
        $consent->setRequired(true);
        $consent->getValidatorChain()->attach(new Identical(['token' => '1']));
        $this->add($consent);

        // Honeypot: người không nhìn thấy (CSS ẩn), bot điền vào → service bỏ qua im lặng
        $this->addStringField(ContactConst::HONEYPOT_FIELD, required: false);

        parent::__construct();
    }
}
