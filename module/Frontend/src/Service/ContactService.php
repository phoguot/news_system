<?php

declare(strict_types=1);

namespace Frontend\Service;

use Application\Factory\AppServiceFactory;
use Application\Service\CaptchaService;
use Application\Service\DateService;
use Application\Service\MailService;
use Frontend\Filter\Contact\ContactSaveFilter;
use Frontend\Model\Contact\ContactConst;
use Frontend\Model\Contact\ContactMapper;
use Frontend\Model\Service\ServiceMapper;
use Frontend\Model\Setting\SettingConst;

/**
 * Nghiệp vụ form liên hệ (docs §3.9 + §5.10):
 * validate (ContactSaveFilter — chạy tại đây theo chuẩn 07 §2) →
 * honeypot im lặng → captcha → rate-limit theo IP → lưu DB → email notify đồng bộ.
 * Email gửi lỗi KHÔNG làm hỏng kết quả trả về (khách vẫn thấy "đã gửi").
 * DI nền 07 §4 (13/09/2026): dependency lấy từ container qua
 * getContainerEntry() — constructor không nhận gì.
 */
class ContactService extends AppServiceFactory
{
    /** Tên field reCAPTCHA trong POST — ngoài InputFilter, service tự đọc raw. */
    private const CAPTCHA_FIELD = 'g-recaptcha-response';

    /** Typed accessor cho các entry dùng lặp lại trong service. */
    private function contactMapper(): ContactMapper
    {
        /** @var ContactMapper */
        return $this->getContainerEntry(ContactMapper::class);
    }

    /** Đọc settings qua tầng cache FR-39 (SettingService) — không gọi mapper thẳng. */
    private function settings(): SettingService
    {
        /** @var SettingService */
        return $this->getContainerEntry(SettingService::class);
    }

    private function serviceMapper(): ServiceMapper
    {
        /** @var ServiceMapper */
        return $this->getContainerEntry(ServiceMapper::class);
    }

    private function captcha(): CaptchaService
    {
        /** @var CaptchaService */
        return $this->getContainerEntry(CaptchaService::class);
    }

    private function mail(): MailService
    {
        /** @var MailService */
        return $this->getContainerEntry(MailService::class);
    }

    /**
     * Entry point cho POST /lien-he: tự chạy ContactSaveFilter (kèm CSRF +
     * honeypot) trên toàn bộ $_POST thô, rồi mới qua pipeline chống spam.
     *
     * @param array<array-key, mixed> $raw
     * @param array{ip?: string|null, userAgent?: string|null, sourceUrl?: string|null} $meta
     *
     * @return array{status: string, id: int|null, errors: array<string, string>}
     *               status một trong ContactConst::SUBMIT_*
     */
    public function submit(array $raw, array $meta, bool $withCsrf = true): array
    {
        $filter = new ContactSaveFilter();
        if (! $withCsrf) {
            // đường kiểm thử/CLI: không đụng session CSRF
            $filter->remove('csrf');
        }
        $filter->setData($raw);
        if (! $filter->isValid()) {
            return [
                'status' => ContactConst::SUBMIT_INVALID,
                'id'     => null,
                'errors' => $filter->fieldErrors(),
            ];
        }

        /** @var mixed $token */
        $token  = $raw[self::CAPTCHA_FIELD] ?? null;
        $result = $this->submitContact(
            $filter->getValues(),
            $meta,
            is_string($token) && $token !== '' ? $token : null
        );

        return ['status' => $result['status'], 'id' => $result['id'], 'errors' => []];
    }

    /** Giá trị cho <input type="hidden" name="csrf"> trong view. */
    public function csrfHash(): string
    {
        return (new ContactSaveFilter())->csrfHash();
    }

    /** Options select dịch vụ cho form (controller không đụng Mapper). */
    public function serviceOptions(): array
    {
        return $this->serviceMapper()->listActiveOptions();
    }

    /**
     * Pipeline sau validate (nhánh honeypot/captcha/rate/insert/mail).
     *
     * @param array<array-key, mixed> $data      dữ liệu form ĐÃ validate (InputFilter)
     * @param array{ip?: string|null, userAgent?: string|null, sourceUrl?: string|null} $meta
     *
     * @return array{status: string, id: int|null} một trong ContactConst::SUBMIT_*
     */
    public function submitContact(array $data, array $meta, ?string $captchaToken): array
    {
        // 1) Honeypot: bot điền vào trường ẩn → trả OK giả, không lưu gì cả
        if (trim((string) ($data[ContactConst::HONEYPOT_FIELD] ?? '')) !== '') {
            return ['status' => ContactConst::SUBMIT_OK, 'id' => null];
        }

        $ip = isset($meta['ip']) ? trim($meta['ip']) : '';

        // 2) Captcha (docs §3.9). secret rỗng (dev) → CaptchaService tự cho qua
        if (! $this->captcha()->verify($captchaToken, $ip)) {
            return ['status' => ContactConst::SUBMIT_CAPTCHA_FAIL, 'id' => null];
        }

        // 3) Rate-limit: >= 3 lần / IP / 10 phút → 429 (docs §5.10)
        if (
            $ip !== ''
            && $this->contactMapper()->countRecentSubmissionsFromIp(
                $ip,
                ContactConst::RATE_LIMIT_WINDOW_MINUTES
            ) >= ContactConst::RATE_LIMIT_MAX
        ) {
            return ['status' => ContactConst::SUBMIT_RATE_LIMITED, 'id' => null];
        }

        // 4) Lưu DB — status new, consentAt UTC (docs §3.9 luồng xử lý)
        $id = $this->contactMapper()->insertSubmission([
            'fullName'   => (string) $data['fullName'],
            'email'      => (string) $data['email'],
            'phone'      => $this->nullableTrim(isset($data['phone']) ? (string) $data['phone'] : null),
            'serviceId'  => $this->nullableInt($data['serviceId'] ?? null),
            'subject'    => $this->nullableTrim((string) ($data['subject'] ?? '')),
            'message'    => (string) $data['message'],
            'consentAtUtc' => DateService::nowUtc(),
            'ipAddress'  => $ip !== '' ? $ip : null,
            'userAgent'  => $this->cap($meta['userAgent'] ?? null, ContactConst::MAX_LENGTH_USERAGENT),
            'sourceUrl'  => $this->cap($meta['sourceUrl'] ?? null, ContactConst::MAX_LENGTH_SOURCEURL),
        ]);

        // 5) Email notify đồng bộ; lỗi mail chỉ log, không đổi kết quả (docs §3.9)
        $this->notifyAdmins($data);

        return ['status' => ContactConst::SUBMIT_OK, 'id' => $id];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function notifyAdmins(array $data): void
    {
        $recipients = $this->parseNotifyEmails($this->settings()->stringOrNull(SettingConst::KEY_NOTIFY_EMAILS));
        if ($recipients === []) {
            return;
        }

        $fullName = trim((string) ($data['fullName'] ?? ''));
        $email    = trim((string) ($data['email'] ?? ''));
        $phone    = trim((string) ($data['phone'] ?? ''));
        $subject  = trim((string) ($data['subject'] ?? ''));
        $message  = trim((string) ($data['message'] ?? ''));
        $serviceId = $this->nullableInt($data['serviceId'] ?? null);
        $serviceName = null;
        if ($serviceId !== null) {
            try {
                $svc = $this->serviceMapper()->findById($serviceId);
                $serviceName = $svc !== null ? $svc->name : null;
            } catch (\Throwable) {
                $serviceName = null;
            }
        }

        $nowUtc = DateService::nowUtc();
        $lines = [];
        $lines[] = 'Bạn có liên hệ mới trên website Vạn Lang.';
        $lines[] = 'Thời gian: ' . $nowUtc . ' UTC';
        $lines[] = '';
        $lines[] = 'Người gửi: ' . $fullName . ' <' . $email . '>';
        if ($phone !== '') {
            $lines[] = 'Điện thoại: ' . $phone;
        }
        if ($serviceName !== null) {
            $lines[] = 'Dịch vụ quan tâm: ' . $serviceName;
        }
        if ($subject !== '') {
            $lines[] = 'Tiêu đề: ' . $subject;
        }
        $lines[] = '';
        $lines[] = 'Nội dung:';
        $lines[] = $message !== '' ? $message : '(không có nội dung)';
        $lines[] = '';
        $lines[] = '--';
        $lines[] = 'Gửi tự động từ form /lien-he — trả lời trực tiếp tới ' . $email . ' để phản hồi khách.';
        $body = implode("
", $lines);

        $mailSubject = '[Liên hệ mới] ' . ($subject !== '' ? $subject : ($fullName !== '' ? $fullName : 'Không tiêu đề'));
        if (mb_strlen($mailSubject, 'UTF-8') > 120) {
            $mailSubject = mb_substr($mailSubject, 0, 120, 'UTF-8');
        }

        if (! $this->mail()->sendMany($recipients, $mailSubject, $body)) {
            error_log('contact notify email failed for ' . implode(',', $recipients));
        }
    }

    /**
     * @return list<string>
     */
    private function parseNotifyEmails(?string $csv): array
    {
        if ($csv === null || trim($csv) === '') {
            return [];
        }

        $emails = [];
        foreach (explode(',', $csv) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[] = $candidate;
            }
        }

        return $emails;
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function cap(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : substr($value, 0, $maxLength);
    }
}
