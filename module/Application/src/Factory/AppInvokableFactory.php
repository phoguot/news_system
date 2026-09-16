<?php

declare(strict_types=1);

namespace Application\Factory;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Factory dùng chung cho MỌI service kế thừa `AppServiceFactory` (và bất kỳ
 * class nào tự cung cấp `setContainer()`). Service constructor không nhận
 * dependency — mọi mapper/service khác được `getContainerEntry()` lấy từ
 * container đúng lúc dùng (07-crud-convention §2).
 *
 * Đăng ký: `Service\XService::class => AppInvokableFactory::class`.
 */
final class AppInvokableFactory implements FactoryInterface
{
    /**
     * @template T of object
     *
     * @psalm-suppress MoreSpecificImplementedParamType — interface không chốt type cho $requestedName
     *
     * @param class-string<T> $requestedName
     *
     * @return T
     *
     * @psalm-suppress MixedMethodCall — setContainer() được gọi qua guard method_exists
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): object
    {
        $object = new $requestedName();
        if (method_exists($object, 'setContainer')) {
            /** @psalm-suppress MixedMethodCall */
            $object->setContainer($container);
        }

        /** @var T */
        return $object;
    }
}
