<?php

declare(strict_types=1);

namespace Application\Factory;

use Application\Filter\HtmlPurifierFilter;
use Exception;
use Laminas\I18n\Translator\Translator;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Nền service theo chuẩn DI 13/09/2026 (07-crud-convention §2): service KHÔNG
 * nhận dependency qua constructor — kế thừa base này, đăng ký trong
 * service_manager bằng `AppInvokableFactory`, rồi lấy mapper/service khác từ
 * container đúng lúc dùng:
 *
 * ```
 * $mapper = $this->getContainerEntry(BannerMapper::class);
 * $filter = new BannerSaveFilter(); // InputFilter stateful — vẫn `new` mỗi request
 * ```
 *
 * Base cũng là FactoryInterface (đường self-factory `X::class => X::class`):
 * nếu đăng ký kiểu đó, `__invoke` mồi container rồi gọi initService().
 */
class AppServiceFactory implements FactoryInterface
{
    protected ?ContainerInterface $container = null;

    /** Reuse một instance cho mọi lời gọi htmlPurifierParam(). */
    protected ?HtmlPurifierFilter $purifier = null;

    /**
     * @throws Exception khi service chưa được mồi container (lỗi đăng ký DI).
     */
    public function getContainer(): ContainerInterface
    {
        if ($this->container === null) {
            throw new Exception(
                static::class . ' chưa được setContainer() — đăng ký trong service_manager bằng AppInvokableFactory.',
            );
        }

        return $this->container;
    }

    public function setContainer(?ContainerInterface $container): static
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Lấy đối tượng từ container theo tên hoặc class; entry thiếu → null.
     *
     * @template T
     *
     * @param class-string<T>|string $entryName
     *
     * @return T|null
     */
    public function getContainerEntry(string $entryName)
    {
        try {
            $entry = $this->getContainer()->get($entryName);
            /** @var T|null $entry — container trả mixed; service chốt kiểu qua typed accessor */
            return $entry;
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Đường self-factory: `XService::class => XService::class` trong service_manager.
     *
     * @psalm-suppress MoreSpecificImplementedParamType — interface không chốt type cho $requestedName
     *
     * @param class-string $requestedName
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null,
    ): static {
        $this->setContainer($container);

        return $this->initService();
    }

    /** Hook khởi tạo sau khi container được mồi (override khi cần). */
    protected function initService(): static
    {
        return $this;
    }

    /** getTranslator — helper nền i18n cho service con (dịch vụ News chưa dùng). */
    public function getTranslator(): Translator
    {
        /** @var Translator */
        return $this->getContainer()->get('translator');
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod — helper nền, service con dùng khi cần i18n
     */
    public function translate(string $message): string
    {
        return $this->getTranslator()->translate($message);
    }

    /**
     * Purify HTML một tham số bằng HtmlPurifierFilter (instance dùng chung).
     *
     * @psalm-suppress PossiblyUnusedMethod — helper nền, service con dùng khi cần sanitize
     */
    public function htmlPurifierParam(string $paramName): mixed
    {
        $this->purifier ??= new HtmlPurifierFilter();

        return $this->purifier->filter($paramName);
    }
}
