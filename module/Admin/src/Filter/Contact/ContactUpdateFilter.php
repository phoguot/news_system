<?php

declare(strict_types=1);

namespace Admin\Filter\Contact;

use Application\Filter\AppInputFilter;
use Frontend\Model\Contact\ContactConst;
use Laminas\Filter\StringTrim;
use Laminas\Filter\ToInt;
use Laminas\InputFilter\Input;
use Laminas\Validator\Callback;
use Laminas\Validator\StringLength;

/**
 * Validate form xử lý một lượt liên hệ trong hộp thư admin (docs §3.9):
 * id + status (theo ContactConst::STATUS_LABELS) + adminNote + CSRF.
 * Service chạy filter này thay cho controller (chuẩn 07 §2).
 */
final class ContactUpdateFilter extends AppInputFilter
{
    public function __construct(bool $withCsrf = true)
    {
        $this->addIdField();

        $status = new Input('status');
        $status->setRequired(true)->getFilterChain()->attach(new ToInt());
        $status->getValidatorChain()->attach(new Callback([
            'callback' => static fn (mixed $v): bool => is_int($v)
                && isset(ContactConst::STATUS_LABELS[$v]),
            'message'  => ContactConst::ERROR_STATUS,
        ]));
        $this->add($status);

        $note = new Input('adminNote');
        $note->setRequired(false)->getFilterChain()->attach(new StringTrim());
        $note->getValidatorChain()->attach(new StringLength([
            'max'            => ContactConst::MAX_LENGTH_ADMIN_NOTE,
            'message'        => 'Ghi chú vượt quá ' . ContactConst::MAX_LENGTH_ADMIN_NOTE . ' ký tự.',
            'encoding'       => 'UTF-8',
        ]));
        $this->add($note);

        parent::__construct($withCsrf);
    }

    public function idValue(): ?int
    {
        return $this->positiveIdValue('id');
    }
}
