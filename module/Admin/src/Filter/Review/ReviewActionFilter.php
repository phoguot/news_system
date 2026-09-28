<?php

declare(strict_types=1);

namespace Admin\Filter\Review;

use Application\Filter\AppInputFilter;

final class ReviewActionFilter extends AppInputFilter
{
    public function __construct(bool $withCsrf = true)
    {
        $this->addIdField();
        parent::__construct($withCsrf);
    }

    public function idValue(): int
    {
        return (int) $this->positiveIdValue('id');
    }
}
