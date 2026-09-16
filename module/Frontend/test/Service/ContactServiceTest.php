<?php

declare(strict_types=1);

namespace FrontendTest\Service;

use Application\Service\CaptchaService;
use Application\Service\MailService;
use ApplicationTest\Helper\TestContainer;
use Frontend\Model\Contact\ContactConst;
use Frontend\Model\Contact\ContactMapper;
use Frontend\Model\Service\ServiceMapper;
use Frontend\Model\Setting\SettingConst;
use Frontend\Model\Setting\SettingMapper;
use Frontend\Model\Setting\SettingModel;
use Frontend\Service\ContactService;
use Frontend\Service\SettingService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ContactServiceTest extends TestCase
{
    private const IP = '203.0.113.9';

    private ContactMapper&MockObject $contactMapper;
    private SettingMapper&MockObject $settingMapper;
    private ServiceMapper&MockObject $serviceMapper;
    private CaptchaService&MockObject $captcha;
    private MailService&MockObject $mail;
    private ContactService $service;

    protected function setUp(): void
    {
        $this->contactMapper = $this->createMock(ContactMapper::class);
        $this->settingMapper = $this->createMock(SettingMapper::class);
        $this->serviceMapper = $this->createMock(ServiceMapper::class);
        $this->captcha       = $this->createMock(CaptchaService::class);
        $this->mail          = $this->createMock(MailService::class);

        // KHÔNG stub sẵn verify() ở đây: matcher đăng ký TRƯỚC thắng, sẽ che mất
        // willReturn(false)/never() của từng test. listAll() tự trả [] mặc định.
        // Settings đọc qua SettingService thật nhưng KHÔNG gắn PageCacheService
        // (FR-39) → mỗi lần gọi tính thẳng từ mapper mock.
        $this->service = (new ContactService())->setContainer(new TestContainer([
            ContactMapper::class => $this->contactMapper,
            SettingService::class => (new SettingService())->setContainer(
                new TestContainer([SettingMapper::class => $this->settingMapper])
            ),
            ServiceMapper::class => $this->serviceMapper,
            CaptchaService::class => $this->captcha,
            MailService::class    => $this->mail,
        ]));
    }

    /**
     * Row notify_emails để SettingService dựng map (luồng FR-39 đọc listAll,
     * không còn getValue từng key).
     *
     * @return list<SettingModel>
     */
    private function notifyRows(string $csv): array
    {
        return [
            SettingModel::fromRow([
                'id'           => 9,
                'groupCode'    => 'contact',
                'settingKey'   => SettingConst::KEY_NOTIFY_EMAILS,
                'settingValue' => $csv,
                'valueType'    => SettingConst::VALUE_TYPE_STRING,
                'label'        => 'Email nhận thông báo',
                'sortOrder'    => 0,
            ]),
        ];
    }

    /**
     * @param array<array-key, mixed> $overrides
     *
     * @return array<array-key, mixed>
     */
    private function formData(array $overrides = []): array
    {
        return $overrides + [
            'fullName'  => 'Nguyễn Văn A',
            'email'     => 'a@example.com',
            'phone'     => '0901234567',
            'serviceId' => '3',
            'subject'   => 'Tư vấn tour',
            'message'   => 'Nội dung liên hệ đủ dài để hợp lệ.',
            'consent'   => '1',
            ContactConst::HONEYPOT_FIELD => '',
        ];
    }

    /**
     * @param array{ip?: string|null, userAgent?: string|null, sourceUrl?: string|null} $overrides
     *
     * @return array{ip: string|null, userAgent?: string|null, sourceUrl?: string|null}
     */
    private function meta(array $overrides = []): array
    {
        return $overrides + ['ip' => self::IP];
    }

    public function testHoneypotFilledReturnsOkSilently(): void
    {
        $this->contactMapper->expects(self::never())->method('insertSubmission');
        $this->captcha->expects(self::never())->method('verify');
        $this->mail->expects(self::never())->method('sendMany');

        $result = $this->service->submitContact(
            $this->formData([ContactConst::HONEYPOT_FIELD => 'http://spam.example']),
            $this->meta(),
            'token'
        );

        self::assertSame(ContactConst::SUBMIT_OK, $result['status']);
        self::assertNull($result['id']);
    }

    public function testCaptchaFailureSkipsInsert(): void
    {
        $this->captcha->method('verify')->willReturn(false);
        $this->contactMapper->expects(self::never())->method('insertSubmission');

        $result = $this->service->submitContact($this->formData(), $this->meta(), 'bad-token');

        self::assertSame(ContactConst::SUBMIT_CAPTCHA_FAIL, $result['status']);
    }

    public function testRateLimitedAtThreeRecentSubmissions(): void
    {
        $this->captcha->method('verify')->willReturn(true);
        $this->contactMapper
            ->method('countRecentSubmissionsFromIp')
            ->with(self::IP, ContactConst::RATE_LIMIT_WINDOW_MINUTES)
            ->willReturn(ContactConst::RATE_LIMIT_MAX);
        $this->contactMapper->expects(self::never())->method('insertSubmission');

        $result = $this->service->submitContact($this->formData(), $this->meta(), 'token');

        self::assertSame(ContactConst::SUBMIT_RATE_LIMITED, $result['status']);
    }

    public function testHappyPathInsertsNormalizedRowAndNotifies(): void
    {
        $this->captcha->method('verify')->willReturn(true);
        $this->contactMapper
            ->method('countRecentSubmissionsFromIp')
            ->willReturn(ContactConst::RATE_LIMIT_MAX - 1);

        $captured = null;
        $this->contactMapper
            ->method('insertSubmission')
            ->willReturnCallback(function (array $data) use (&$captured): int {
                $captured = $data;

                return 42;
            });

        $this->settingMapper
            ->method('listAll')
            ->willReturn($this->notifyRows('admin@vanlang.vn, không-phải-email , boss@vanlang.vn'));

        $this->mail
            ->expects(self::once())
            ->method('sendMany')
            ->with(
                ['admin@vanlang.vn', 'boss@vanlang.vn'],
                '[Liên hệ mới] Tư vấn tour',
                self::stringContains('Nguyễn Văn A')
            )
            ->willReturn(true);

        $result = $this->service->submitContact(
            $this->formData(),
            $this->meta(['userAgent' => 'Mozilla/5.0', 'sourceUrl' => 'https://site.test/lien-he']),
            'token'
        );

        self::assertSame(ContactConst::SUBMIT_OK, $result['status']);
        self::assertSame(42, $result['id']);

        self::assertIsArray($captured);
        self::assertSame('Nguyễn Văn A', $captured['fullName']);
        self::assertSame(3, $captured['serviceId']);
        self::assertSame(self::IP, $captured['ipAddress']);
        self::assertSame('Mozilla/5.0', $captured['userAgent']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string) $captured['consentAtUtc']
        );
    }

    public function testEmptyPhoneAndSubjectStoredAsNull(): void
    {
        $this->captcha->method('verify')->willReturn(true);
        $captured = null;
        $this->contactMapper
            ->method('insertSubmission')
            ->willReturnCallback(function (array $data) use (&$captured): int {
                $captured = $data;

                return 7;
            });

        $this->service->submitContact(
            $this->formData(['phone' => '  ', 'subject' => '', 'serviceId' => '']),
            $this->meta(),
            'token'
        );

        self::assertIsArray($captured);
        self::assertNull($captured['phone']);
        self::assertNull($captured['subject']);
        self::assertNull($captured['serviceId']);
    }

    public function testMissingIpSkipsRateLimitAndStoresNullIp(): void
    {
        $this->captcha->method('verify')->willReturn(true);
        $this->contactMapper->expects(self::never())->method('countRecentSubmissionsFromIp');

        $captured = null;
        $this->contactMapper
            ->method('insertSubmission')
            ->willReturnCallback(function (array $data) use (&$captured): int {
                $captured = $data;

                return 1;
            });

        $result = $this->service->submitContact($this->formData(), $this->meta(['ip' => '']), 'token');

        self::assertSame(ContactConst::SUBMIT_OK, $result['status']);
        self::assertIsArray($captured);
        self::assertNull($captured['ipAddress']);
    }

    public function testMailFailureStillReturnsOk(): void
    {
        $this->captcha->method('verify')->willReturn(true);
        $this->contactMapper->method('insertSubmission')->willReturn(5);
        $this->settingMapper
            ->method('listAll')
            ->willReturn($this->notifyRows('admin@vanlang.vn'));
        $this->mail->method('sendMany')->willReturn(false);

        $result = $this->service->submitContact($this->formData(), $this->meta(), 'token');

        self::assertSame(ContactConst::SUBMIT_OK, $result['status']);
        self::assertSame(5, $result['id']);
    }

    public function testNoNotifyEmailsSkipsMail(): void
    {
        $this->captcha->method('verify')->willReturn(true);
        $this->contactMapper->method('insertSubmission')->willReturn(6);
        $this->mail->expects(self::never())->method('sendMany');

        $result = $this->service->submitContact($this->formData(), $this->meta(), 'token');

        self::assertSame(ContactConst::SUBMIT_OK, $result['status']);
    }

    public function testSubmitValidRawRunsFilterThenPipeline(): void
    {
        $this->captcha->method('verify')->willReturn(true);
        $this->contactMapper->method('insertSubmission')->willReturn(9);
        $this->mail->expects(self::never())->method('sendMany');

        $result = $this->service->submit($this->formData(), $this->meta(), false);

        self::assertSame(ContactConst::SUBMIT_OK, $result['status']);
        self::assertSame(9, $result['id']);
        self::assertSame([], $result['errors']);
    }

    public function testSubmitInvalidRawReturnsFieldErrorsWithoutInsert(): void
    {
        $this->contactMapper->expects(self::never())->method('insertSubmission');

        $result = $this->service->submit($this->formData(['email' => 'not-an-email']), $this->meta(), false);

        self::assertSame(ContactConst::SUBMIT_INVALID, $result['status']);
        self::assertArrayHasKey('email', $result['errors']);
    }
}
