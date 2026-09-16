<?php

declare(strict_types=1);

namespace Admin\Controller;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Model\HomeSection\HomeSectionConst;
use Admin\Model\HomeSection\HomeSectionModel;
use Admin\Service\HomeSectionService;
use Application\Constant\ContentConst;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Stdlib\ParametersInterface;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;

/**
 * Trang quản trị /admin/home-sections (docs §3.7, FR-32 + FR-33). PRG cho
 * form xoá và các form mục manual (op add|remove|up|down → redirect về trang
 * sửa kèm query flag), render-form cho lưu lỗi (chuẩn 07 §2). Controller
 * mỏng: chỉ ghép id route vào raw rồi gọi HomeSectionService.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động (Params, Redirect…).
 */
class HomeSectionController extends AbstractActionController
{
    public function __construct(
        private readonly HomeSectionService $sections,
    ) {
    }

    /**
     * @return ViewModel
     */
    public function indexAction()
    {
        return new ViewModel([
            'items'           => $this->sections->listAll(),
            'typeLabels'      => HomeSectionConst::TYPE_LABELS,
            'flag'            => $this->queryFlag(),
            'csrfHash'        => $this->sections->deleteFormCsrfHash(),
            'reorderCsrfHash' => $this->sections->reorderCsrfHash(),
        ]);
    }

    /**
     * @return ViewModel
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function createAction()
    {
        return $this->renderForm(null, [], [], 'Thêm section');
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function editAction()
    {
        $id = $this->intParam('id');
        if ($id === null) {
            return $this->redirect()->toRoute('admin/home-sections');
        }

        try {
            $section = $this->sections->findOrFail($id);
        } catch (NotFoundException) {
            return $this->redirect()->toRoute(
                'admin/home-sections',
                [],
                ['query' => ['flag' => 'notfound']]
            );
        }

        return $this->renderForm($id, $section->toFormValues(), [], 'Sửa section', $this->queryFlag() ?? '');
    }

    /**
     * POST một thao tác mục manual (FR-33): /home-sections/items/:id — id là
     * SECTION, `op/id/itemType/itemId` nằm trong raw. Service trả flag PRG,
     * redirect về trang sửa của đúng section.
     *
     * @return Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function itemsAction()
    {
        $request = $this->getRequest();
        $id      = $this->intParam('id');
        if (! $request instanceof HttpRequest || ! $request->isPost() || $id === null) {
            return $this->redirect()->toRoute('admin/home-sections');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();

        try {
            $flag = $this->sections->itemsForm($id, $post->toArray());
        } catch (NotFoundException) {
            return $this->redirect()->toRoute(
                'admin/home-sections',
                [],
                ['query' => ['flag' => 'notfound']]
            );
        }

        return $this->redirect()->toRoute(
            'admin/home-sections',
            ['action' => 'edit', 'id' => $id],
            ['query' => ['flag' => $flag]]
        );
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function saveAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/home-sections');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id    = $this->intParam('id');
        $title = $id === null ? 'Thêm section' : 'Sửa section';

        try {
            $this->sections->saveForm($id, $raw);

            return $this->redirect()->toRoute(
                'admin/home-sections',
                [],
                ['query' => ['flag' => $id === null ? HomeSectionConst::FLAG_CREATED : HomeSectionConst::FLAG_UPDATED]]
            );
        } catch (ValidationException $e) {
            return $this->renderForm($id, $raw, $e->getErrors(), $title);
        } catch (NotFoundException) {
            return $this->renderForm($id, $raw, ['type' => HomeSectionConst::ERROR_NOT_FOUND], $title);
        }
    }

    /**
     * @return Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function deleteAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/home-sections');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id = $this->intParam('id');
        if ($id !== null) {
            $raw['id'] = (string) $id;
        }

        $flag = $this->sections->deleteForm($raw);

        return $this->redirect()->toRoute('admin/home-sections', [], ['query' => ['flag' => $flag]]);
    }

    /**
     * POST /admin/home-sections/reorder — kéo-thả đổi thứ tự section (FR-32).
     * JS gửi XHR nhận JSON {flag, applied}; POST trực tiếp rơi về PRG.
     *
     * @return JsonModel|Response
     * @psalm-suppress DeprecatedClass JsonModel deprecated trong laminas-view 2.x — khuôn ApiResponseModel.
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function reorderAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/home-sections');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();

        $result = $this->sections->formReorder($post->toArray());

        if ($request->isXmlHttpRequest()) {
            return new JsonModel($result);
        }

        return $this->redirect()->toRoute(
            'admin/home-sections',
            [],
            ['query' => ['flag' => $result['flag'], 'applied' => $result['applied']]]
        );
    }

    /**
     * @param array<array-key, mixed> $values
     * @param array<string, string>   $errors
     *
     * @return ViewModel
     */
    private function renderForm(?int $id, array $values, array $errors, string $title, string $flag = ''): ViewModel
    {
        $items       = [];
        $allowedType = null;
        $manualMode  = false;
        if ($id !== null) {
            $section = $this->sectionOrNull($id);
            if ($section !== null) {
                $allowedType = HomeSectionConst::MANUAL_ITEM_TYPES[$section->type] ?? null;
                $manualMode  = $allowedType !== null && $this->isManual($section);
                if ($manualMode) {
                    $items = $this->sections->itemsList($id);
                }
            }
        }

        $model = new ViewModel([
            'id'              => $id,
            'values'          => $values,
            'errors'          => $errors,
            'title'           => $title,
            'flag'            => $flag,
            'csrfHash'        => $this->sections->saveFormCsrfHash(),
            'typeLabels'      => HomeSectionConst::TYPE_LABELS,
            'items'           => $items,
            'allowedItemType' => $allowedType,
            'manualMode'      => $manualMode,
            'itemLabels'      => ContentConst::SECTION_ITEM_LABELS,
            'itemOptions'     => $this->sections->itemOptions($allowedType),
            'itemsCsrfHash'   => $this->sections->itemsFormCsrfHash(),
        ]);
        $model->setTemplate('admin/home-section/form');

        return $model;
    }

    /** Section của trang sửa — null khi id không còn (saveAction lỗi trên dòng đã xoá). */
    private function sectionOrNull(int $id): ?HomeSectionModel
    {
        try {
            return $this->sections->findOrFail($id);
        } catch (NotFoundException) {
            return null;
        }
    }

    /** Block quản mục chỉ hiện khi section manual (config.mode = 'manual'). */
    private function isManual(HomeSectionModel $section): bool
    {
        $config = $section->configArray();

        return is_array($config) && ($config['mode'] ?? null) === HomeSectionConst::MODE_MANUAL;
    }

    private function queryFlag(): ?string
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest) {
            return null;
        }

        $flagRaw = $request->getQuery('flag');

        return is_string($flagRaw) ? $flagRaw : null;
    }

    private function intParam(string $name): ?int
    {
        /** @var mixed $raw */
        $raw = $this->params()->fromRoute($name);

        return is_numeric($raw) ? (int) $raw : null;
    }
}
