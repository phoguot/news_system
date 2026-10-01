<?php

declare(strict_types=1);

namespace AdminTest\Filter\Active;

use Admin\Filter\Active\ActiveStatusFilter;
use PHPUnit\Framework\TestCase;

final class ActiveStatusFilterTest extends TestCase
{
    public function testAcceptsInactiveZeroValue(): void
    {
        $filter = new ActiveStatusFilter(false);
        $filter->setData(['id' => '12', 'isActive' => '0']);

        self::assertTrue($filter->isValid());
        self::assertSame(0, $filter->activeValue());
    }

    public function testAcceptsActiveOneValue(): void
    {
        $filter = new ActiveStatusFilter(false);
        $filter->setData(['id' => '12', 'isActive' => '1']);

        self::assertTrue($filter->isValid());
        self::assertSame(1, $filter->activeValue());
    }
}
