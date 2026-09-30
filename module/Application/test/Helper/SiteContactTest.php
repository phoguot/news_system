<?php

declare(strict_types=1);

namespace ApplicationTest\Helper;

use Application\View\Helper\SiteContact;
use Frontend\Model\Setting\SettingConst;
use Frontend\Service\SettingService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SiteContactTest extends TestCase
{
    public function testReturnsSettingsAndBuildsHotlineLink(): void
    {
        $settings = $this->createMock(SettingService::class);
        $settings->method('all')->willReturn([
            SettingConst::KEY_COMPANY_NAME => 'Văn Lang Care',
            SettingConst::KEY_ADDRESS => 'Hà Nội',
            SettingConst::KEY_HOTLINE => '096 630 4115',
            SettingConst::KEY_FACEBOOK_URL => 'facebook.com/vanlangcare',
            SettingConst::KEY_ZALO_URL => 'https://zalo.me/0966304115',
        ]);

        $values = (new SiteContact($settings))();

        self::assertSame('Văn Lang Care', $values['companyName']);
        self::assertSame('Hà Nội', $values['address']);
        self::assertSame('096 630 4115', $values['hotline']);
        self::assertSame('tel:0966304115', $values['hotlineHref']);
        self::assertSame('https://facebook.com/vanlangcare', $values['facebookUrl']);
        self::assertSame('https://zalo.me/0966304115', $values['zaloUrl']);
    }

    public function testRejectsUnsafeSocialUrls(): void
    {
        $settings = $this->createMock(SettingService::class);
        $settings->method('all')->willReturn([
            SettingConst::KEY_FACEBOOK_URL => 'javascript:alert(1)',
            SettingConst::KEY_ZALO_URL => 'https://zalo.me/example',
        ]);

        $values = (new SiteContact($settings))();

        self::assertNull($values['facebookUrl']);
        self::assertSame('https://zalo.me/example', $values['zaloUrl']);
    }

    public function testFallsBackWhenSettingsCannotBeRead(): void
    {
        $settings = $this->createMock(SettingService::class);
        $settings->method('all')->willThrowException(new RuntimeException('DB unavailable'));

        $values = (new SiteContact($settings))();

        self::assertSame('1900 1234', $values['hotline']);
        self::assertSame('tel:19001234', $values['hotlineHref']);
        self::assertNull($values['facebookUrl']);
        self::assertNull($values['zaloUrl']);
    }
}
