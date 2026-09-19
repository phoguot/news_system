<?php

declare(strict_types=1);

namespace FrontendTest\Service;

use ApplicationTest\Helper\TestContainer;
use Frontend\Model\Menu\MenuConst;
use Frontend\Model\Menu\MenuMapper;
use Frontend\Model\Menu\MenuModel;
use Frontend\Service\MenuService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Menu công khai đọc các dòng active theo thứ tự mapper trả về.
 */
final class MenuServiceTest extends TestCase
{
    private MenuMapper&MockObject $menus;
    private MenuService $service;

    protected function setUp(): void
    {
        $this->menus = $this->createMock(MenuMapper::class);
        $this->service = (new MenuService())->setContainer(new TestContainer([
            MenuMapper::class => $this->menus,
        ]));
    }

    public function testPublicItemsMapModelsToLayoutPayload(): void
    {
        $this->menus->expects(self::once())->method('listActive')->willReturn([
            MenuModel::fromRow([
                'id' => 1,
                'label' => 'Trang chủ',
                'url' => '/',
                'target' => MenuConst::TARGET_SELF,
                'sortOrder' => 0,
                'isActive' => MenuConst::ACTIVE,
            ]),
            MenuModel::fromRow([
                'id' => 2,
                'label' => 'Facebook',
                'url' => 'https://facebook.com/vanlang',
                'target' => MenuConst::TARGET_BLANK,
                'sortOrder' => 1,
                'isActive' => MenuConst::ACTIVE,
            ]),
        ]);

        self::assertSame(
            [
                ['label' => 'Trang chủ', 'url' => '/', 'target' => '_self'],
                ['label' => 'Facebook', 'url' => 'https://facebook.com/vanlang', 'target' => '_blank'],
            ],
            $this->service->publicItems()
        );
    }
}
