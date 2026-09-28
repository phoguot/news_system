<?php

declare(strict_types=1);

namespace Frontend\Service;

use Application\Factory\AppServiceFactory;
use Frontend\Model\SiteVisitDaily\SiteVisitDailyMapper;

/** Ghi một khách/trình duyệt mỗi ngày UTC cho toàn bộ website công khai. */
final class SiteVisitService extends AppServiceFactory
{
    private const COOKIE_NAME = 'site_visitor_day';
    private const BOT_PATTERN =
        '/bot|crawler|spider|slurp|mediapartners|baidu|yandex|sogou|exabot|facebot|ia_archiver/i';

    /**
     * Trả header Set-Cookie khi vừa ghi khách mới; null nếu đã tính hoặc là bot.
     *
     * @param array<string, string> $cookies
     */
    public function tryRecord(?string $userAgent, array $cookies): ?string
    {
        if ($this->isBot($userAgent)) {
            return null;
        }

        $today = gmdate('Y-m-d');
        if (($cookies[self::COOKIE_NAME] ?? null) === $today) {
            return null;
        }

        $this->visits()->incrementToday();

        $expires = strtotime('tomorrow UTC');
        $expiresAt = gmdate('D, d M Y H:i:s', $expires === false ? time() + 86400 : $expires) . ' GMT';

        return self::COOKIE_NAME . '=' . $today . '; Expires=' . $expiresAt
            . '; Path=/; SameSite=Lax; HttpOnly';
    }

    private function isBot(?string $userAgent): bool
    {
        if ($userAgent === null || $userAgent === '') {
            return true;
        }

        return (bool) preg_match(self::BOT_PATTERN, $userAgent);
    }

    private function visits(): SiteVisitDailyMapper
    {
        /** @var SiteVisitDailyMapper */
        return $this->getContainerEntry(SiteVisitDailyMapper::class);
    }
}
