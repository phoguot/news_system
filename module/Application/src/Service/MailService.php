<?php

declare(strict_types=1);

namespace Application\Service;

use Application\Factory\AppServiceFactory;
use Laminas\Mail\Message;
use Laminas\Mail\Transport\Smtp;
use Laminas\Mail\Transport\SmtpOptions;
use Throwable;

/**
 * Gửi mail đồng bộ qua SMTP (docs §3.9: không hàng đợi; lỗi mail KHÔNG làm hỏng luồng).
 * Timeout ngắn cấu hình qua connection_config.timeout (mặc định 5 giây).
 *
 * DI nền 07 §4 (batch 7 — 13/09/2026): không constructor — key `mail` đọc lazy
 * qua `getContainerEntry('Config')` mỗi lần gửi, đăng ký bằng `AppInvokableFactory`.
 */
class MailService extends AppServiceFactory
{
    private const DEFAULT_TIMEOUT = 5;

    /** @psalm-suppress PossiblyUnusedMethod — API tiện ích cho 1 người nhận; pipeline liên hệ dùng sendMany. */
    public function send(string $toEmail, string $subject, string $bodyText): bool
    {
        return $this->sendMany([$toEmail], $subject, $bodyText);
    }

    /**
     * @param list<string> $toEmails
     */
    public function sendMany(array $toEmails, string $subject, string $bodyText): bool
    {
        if ($toEmails === []) {
            return false;
        }

        try {
            $mail            = $this->mailConfig();
            $transportConfig = (array) ($mail['transport'] ?? []);
            $options         = (array) ($transportConfig['options'] ?? []);
            $host            = (string) ($options['host'] ?? '127.0.0.1');

            $connectionConfig = array_merge(
                ['timeout' => self::DEFAULT_TIMEOUT],
                (array) ($options['connection_config'] ?? [])
            );

            $smtpOptions = new SmtpOptions();
            $smtpOptions->setName($host);
            $smtpOptions->setHost($host);
            $smtpOptions->setPort((int) ($options['port'] ?? 25));
            $smtpOptions->setConnectionClass((string) ($options['connection_class'] ?? 'plain'));
            $smtpOptions->setConnectionConfig($connectionConfig);

            $transport = new Smtp($smtpOptions);

            $message = new Message();
            $message->setFrom((string) ($mail['from'] ?? 'no-reply@localhost'));
            foreach ($toEmails as $email) {
                $message->addTo($email);
            }
            $message->setSubject($subject);
            $message->setBody($bodyText);

            $transport->send($message);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Key `mail` trong config/autoload/global.php (ép về array để mọi access
     * không phải đi qua mixed).
     *
     * @return array<array-key, mixed>
     */
    private function mailConfig(): array
    {
        /** @var array<array-key, mixed>|\Laminas\Config\Config $config */
        $config = $this->getContainer()->get('Config');
        $all = is_array($config) ? $config : $config->toArray();
        /** @var array<array-key, mixed> $mail */
        $mail = is_array($all['mail'] ?? null) ? $all['mail'] : [];

        return $mail;
    }
}
