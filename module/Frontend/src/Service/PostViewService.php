<?php

declare(strict_types=1);

namespace Frontend\Service;

use Admin\Model\Post\PostMapper;
use Admin\Model\PostViewDaily\PostViewDailyMapper;
use Application\Factory\AppServiceFactory;
use Application\Service\DbService;

/**
 * Ghi lượt xem bài viết (FR-41, docs §3.3.4(9)/§5.8/§4.6):
 * - dedup: cookie + session 30' (key pv_{postId})
 * - bot UA: bỏ qua
 * - chỉ ghi khi bài đã xuất bản công khai (caller đảm bảo), previewToken không tính
 * - transaction: increment post_view_daily (upsert UTC_DATE) + posts.viewCount
 */
final class PostViewService extends AppServiceFactory
{
    private const COOKIE_PREFIX = 'pv_';
    private const DEDUP_SECONDS = 1800;

    /** UA bot đơn giản — đủ cho MVP, không cần thư viện. */
    private const BOT_PATTERN =
        '/bot|crawler|spider|slurp|mediapartners|baidu|yandex|sogou|exabot|facebot|ia_archiver/i';

    /**
     * Thử ghi lượt xem; trả true nếu đã ghi, false nếu bị dedup/bot/preview.
     *
     * @param array<string, string> $cookies  $_COOKIE snapshot (đọc)
     * @param array<string, mixed>  $session  &$_SESSION snapshot (đọc/ghi dedup)
     */
    public function tryRecord(int $postId, ?string $userAgent, bool $isPreview, array $cookies, array &$session): bool
    {
        if ($isPreview || $postId <= 0) {
            return false;
        }

        if ($this->isBot($userAgent)) {
            return false;
        }

        $cookieKey = self::COOKIE_PREFIX . $postId;
        if (isset($cookies[$cookieKey])) {
            return false;
        }

        $now = time();
        /** @var mixed $last */
        $last = $session[$cookieKey] ?? null;
        if (is_int($last) && ($now - $last) < self::DEDUP_SECONDS) {
            return false;
        }

        $this->db()->transactional(function () use ($postId): void {
            $this->postViewDaily()->increment($postId);
            $this->posts()->incrementViewCount($postId);
        });

        $session[$cookieKey] = $now;

        return true;
    }

    /**
     * Header Set-Cookie cho dedup 30' — caller gắn vào Response.
     */
    public function cookieHeader(int $postId): string
    {
        $expires = gmdate('D, d M Y H:i:s', time() + self::DEDUP_SECONDS) . ' GMT';

        return self::COOKIE_PREFIX . $postId . '=1; Expires=' . $expires . '; Path=/; SameSite=Lax';
    }

    private function isBot(?string $ua): bool
    {
        if ($ua === null || $ua === '') {
            return false;
        }

        return (bool) preg_match(self::BOT_PATTERN, $ua);
    }

    private function db(): DbService
    {
        /** @var DbService */
        return $this->getContainerEntry(DbService::class);
    }

    private function posts(): PostMapper
    {
        /** @var PostMapper */
        return $this->getContainerEntry(PostMapper::class);
    }

    private function postViewDaily(): PostViewDailyMapper
    {
        /** @var PostViewDailyMapper */
        return $this->getContainerEntry(PostViewDailyMapper::class);
    }
}
