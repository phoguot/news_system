<?php

declare(strict_types=1);

namespace Application\Session;

use Laminas\Session\Container as SessionContainer;
use Laminas\Session\Exception\RuntimeException;
use Laminas\Session\SessionManager;
use Laminas\Session\ValidatorChain;
use Psr\Container\ContainerInterface;

/**
 * Khởi tạo SessionManager dùng chung cho mọi dispatch (docs-dev/05-van-hanh/03).
 *
 * Vì EventManager của Application là service KHÔNG shared, cả listener của
 * Admin (AuthGuard) lẫn Frontend phải attach qua SharedEventManager rồi gọi
 * helper này — nếu không, SessionContainer sẽ tự bootstrap một manager trần
 * (cookie PHPSESSID, không validators).
 *
 * Nextra: laminas-session v2 ném RuntimeException cứng trong start() khi
 * validator (RemoteAddr/HttpUserAgent) fail — nghĩa là UA đổi giữa hai lần
 * tải trang (browser tự update) sẽ thành HTTP 500. Bọc lại ở đây: phiên cũ
 * không hợp lệ bị hủy và thay bằng phiên mới sạch, đúng hành vi "hết phiên"
 * mà docs mô tả (401/redirect, không phải 500).
 */
final class SessionBootstrap
{
    /**
     * @psalm-suppress DeprecatedMethod
     * @psalm-suppress DeprecatedClass
     */
    public static function ensureDefault(ContainerInterface $services): SessionManager
    {
        $manager = $services->get(SessionManager::class);
        SessionContainer::setDefaultManager($manager);

        // Chỉ reset khi client có mang cookie phiên — khách vãng lai không bị
        // start() sớm (giữ nguyên hành vi lazy-session của Laminas).
        if (isset($_COOKIE[$manager->getName()])) {
            try {
                $manager->start();
            } catch (RuntimeException) {
                // start() lần 1 đã attach validator "tham chiếu cũ" vào chain (listener
                // không tự gỡ) → phải thay chain sạch gắn với storage vừa destroy,
                // nếu không start() lần 2 vẫn fail vì listener cũ.
                $manager->destroy();
                unset($_SESSION);
                // psalm-suppress intentional: chain validator vẫn là API hợp lệ của
                // laminas-session ^2 (project pin); chỉ bị bỏ ở v3.
                $manager->setValidatorChain(new ValidatorChain($manager->getStorage()));
                $manager->start();
                session_regenerate_id(true);
            }
        }

        return $manager;
    }
}
