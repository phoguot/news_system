<?php

declare(strict_types=1);

namespace ApplicationTest\Helper;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Container PSR-11 tối giản cho unit test (chuẩn DI 13/09/2026 — 07 §4):
 * service kế thừa AppServiceFactory không còn nhận dependency qua constructor,
 * test mồi mock bằng `$service->setContainer(new TestContainer([...]))`.
 *
 * ```
 * $service = (new TagService())->setContainer(new TestContainer([
 *     TagMapper::class => $this->tags,
 * ]));
 * ```
 */
final class TestContainer implements ContainerInterface
{
    /** @param array<string, mixed> $entries */
    public function __construct(private readonly array $entries = [])
    {
    }

    /**
     * {@inheritDoc}
     *
     * @param string $id
     *
     * @return mixed
     */
    public function get($id)
    {
        if (! $this->has($id)) {
            throw new class ("Entry '{$id}' không có trong TestContainer.") extends RuntimeException implements
                NotFoundExceptionInterface
            {
            };
        }

        return $this->entries[$id];
    }

    /**
     * {@inheritDoc}
     *
     * @param string $id
     */
    public function has($id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}
