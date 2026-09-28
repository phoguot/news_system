<?php

declare(strict_types=1);

namespace AdminTest\Service;

use Admin\Exception\ValidationException;
use Admin\Model\Media\MediaMapper;
use Admin\Model\Media\MediaModel;
use Admin\Model\Review\ReviewConst;
use Admin\Model\Review\ReviewMapper;
use Admin\Model\Review\ReviewModel;
use Admin\Service\ReviewService;
use ApplicationTest\Helper\TestContainer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ReviewServiceTest extends TestCase
{
    private ReviewMapper&MockObject $reviews;
    private MediaMapper&MockObject $media;
    private ReviewService $service;

    protected function setUp(): void
    {
        $this->reviews = $this->createMock(ReviewMapper::class);
        $this->media = $this->createMock(MediaMapper::class);
        $this->service = (new ReviewService())->setContainer(new TestContainer([
            ReviewMapper::class => $this->reviews,
            MediaMapper::class => $this->media,
        ]));
    }

    public function testSaveFormCreatesImageReview(): void
    {
        $image = MediaModel::fromRow(['id' => 9, 'path' => 'review.webp']);
        $this->media->method('findById')->with(9)->willReturn($image);
        $captured = null;
        $this->reviews->expects(self::once())->method('insert')
            ->willReturnCallback(function (array $values) use (&$captured): int {
                $captured = $values;
                return 1;
            });

        $this->service->saveForm(null, [
            'imageMediaId' => '9',
            'altText' => ' Khách hàng đánh giá ',
            'sortOrder' => '2',
            'isActive' => '1',
            'csrf' => $this->service->saveFormCsrfHash(),
        ]);

        self::assertIsArray($captured);
        self::assertSame(9, $captured['imageMediaId']);
        self::assertSame('Khách hàng đánh giá', $captured['altText']);
        self::assertSame(ReviewConst::ACTIVE, $captured['isActive']);
    }

    public function testSaveFormRejectsMissingMedia(): void
    {
        $this->media->method('findById')->willReturn(null);
        $this->reviews->expects(self::never())->method('insert');
        $this->expectException(ValidationException::class);
        $this->service->saveForm(null, [
            'imageMediaId' => '99',
            'csrf' => $this->service->saveFormCsrfHash(),
        ]);
    }

    public function testDeleteFormDeletesExistingReview(): void
    {
        $model = ReviewModel::fromRow(['id' => 4, 'imageMediaId' => 9]);
        $this->reviews->method('findById')->with(4)->willReturn($model);
        $this->reviews->expects(self::once())->method('delete')->with(4);
        $flag = $this->service->deleteForm([
            'id' => '4',
            'csrf' => $this->service->deleteFormCsrfHash(),
        ]);
        self::assertSame(ReviewConst::FLAG_DELETED, $flag);
    }
}
