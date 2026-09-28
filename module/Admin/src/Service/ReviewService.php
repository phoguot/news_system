<?php

declare(strict_types=1);

namespace Admin\Service;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Filter\Review\ReviewActionFilter;
use Admin\Filter\Review\ReviewSaveFilter;
use Admin\Model\Media\MediaMapper;
use Admin\Model\Review\ReviewConst;
use Admin\Model\Review\ReviewMapper;
use Admin\Model\Review\ReviewModel;
use Application\Constant\CacheConst;
use Application\Factory\AppServiceFactory;
use Application\Service\PageCacheService;

class ReviewService extends AppServiceFactory
{
    private function reviews(): ReviewMapper
    {
        /** @var ReviewMapper */
        return $this->getContainerEntry(ReviewMapper::class);
    }

    private function media(): MediaMapper
    {
        /** @var MediaMapper */
        return $this->getContainerEntry(MediaMapper::class);
    }

    /** @return list<ReviewModel> */
    public function listAll(): array
    {
        return $this->reviews()->listAll();
    }

    /** @return list<array{id: int, label: string, path: string}> */
    public function mediaOptions(): array
    {
        return $this->media()->listOptions();
    }

    public function findOrFail(int $id): ReviewModel
    {
        $model = $this->reviews()->findById($id);
        if ($model === null) {
            throw NotFoundException::forEntity('ảnh đánh giá', $id);
        }
        return $model;
    }

    /** @param array<array-key, mixed> $raw */
    public function saveForm(?int $id, array $raw): void
    {
        $filter = new ReviewSaveFilter($this->media());
        $filter->setData($raw);
        if (! $filter->isValid()) {
            throw new ValidationException($filter->fieldErrors());
        }
        $values = $filter->getValues();
        $data = [
            'imageMediaId' => (int) $values['imageMediaId'],
            'altText' => $this->nullableTrim($values['altText'] ?? null),
            'sortOrder' => (int) ($values['sortOrder'] ?? 0),
            'isActive' => $this->flag($values['isActive'] ?? null),
        ];
        if ($id === null) {
            $this->reviews()->insert($data);
        } else {
            $this->findOrFail($id);
            $this->reviews()->update($id, $data);
        }
        $this->invalidateHome();
    }

    /** @param array<array-key, mixed> $raw */
    public function deleteForm(array $raw): string
    {
        $filter = new ReviewActionFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            return isset($filter->fieldErrors()['csrf']) ? 'csrf' : 'notfound';
        }
        try {
            $id = $filter->idValue();
            $this->findOrFail($id);
            $this->reviews()->delete($id);
            $this->invalidateHome();
            return ReviewConst::FLAG_DELETED;
        } catch (NotFoundException) {
            return 'notfound';
        }
    }

    public function saveFormCsrfHash(): string
    {
        return (new ReviewSaveFilter($this->media()))->csrfHash();
    }

    public function deleteFormCsrfHash(): string
    {
        return (new ReviewActionFilter())->csrfHash();
    }

    private function invalidateHome(): void
    {
        $cache = $this->getContainerEntry(PageCacheService::class);
        if ($cache instanceof PageCacheService) {
            $cache->forget(CacheConst::KEY_HOME);
        }
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function flag(mixed $value): int
    {
        return $value === null || $value === '' || $value === '0' || $value === false
            ? ReviewConst::INACTIVE
            : ReviewConst::ACTIVE;
    }
}
