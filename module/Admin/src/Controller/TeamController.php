<?php

declare(strict_types=1);

namespace Admin\Controller;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Model\TeamMember\TeamMemberConst;
use Admin\Service\TeamMemberService;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Stdlib\ParametersInterface;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;

/**
 * Trang quản trị /admin/team (docs §3.6). PRG cho form xoá (chuẩn 07 §2).
 * Controller mỏng: chỉ ghép id route vào raw rồi gọi TeamMemberService.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động (Params, Redirect…).
 */
class TeamController extends AbstractActionController
{
    public function __construct(
        private readonly TeamMemberService $team,
    ) {
    }

    /**
     * @return ViewModel
     */
    public function indexAction()
    {
        $items = $this->team->listAll();

        return new ViewModel([
            'items'           => $items,
            'avatars'         => $this->team->avatarCardsFor($items),
            'flag'            => $this->queryFlag(),
            'csrfHash'        => $this->team->deleteFormCsrfHash(),
            'reorderCsrfHash' => $this->team->reorderCsrfHash(),
        ]);
    }

    /**
     * @return ViewModel
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function createAction()
    {
        return $this->renderForm(null, [], [], 'Thêm thành viên');
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function editAction()
    {
        $id = $this->intParam('id');
        if ($id === null) {
            return $this->redirect()->toRoute('admin/team');
        }

        try {
            $member = $this->team->findOrFail($id);
        } catch (NotFoundException) {
            return $this->redirect()->toRoute(
                'admin/team',
                [],
                ['query' => ['flag' => 'notfound']]
            );
        }

        return $this->renderForm($id, $member->toFormValues(), [], 'Sửa thành viên');
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function saveAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/team');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id    = $this->intParam('id');
        $title = $id === null ? 'Thêm thành viên' : 'Sửa thành viên';

        try {
            $this->team->saveForm($id, $raw);

            return $this->redirect()->toRoute(
                'admin/team',
                [],
                ['query' => ['flag' => $id === null ? TeamMemberConst::FLAG_CREATED : TeamMemberConst::FLAG_UPDATED]]
            );
        } catch (ValidationException $e) {
            return $this->renderForm($id, $raw, $e->getErrors(), $title);
        } catch (NotFoundException) {
            return $this->renderForm($id, $raw, ['fullName' => TeamMemberConst::ERROR_NOT_FOUND], $title);
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
            return $this->redirect()->toRoute('admin/team');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id = $this->intParam('id');
        if ($id !== null) {
            $raw['id'] = (string) $id;
        }

        $flag = $this->team->deleteForm($raw);

        return $this->redirect()->toRoute('admin/team', [], ['query' => ['flag' => $flag]]);
    }

    /**
     * POST /admin/team/reorder — kéo-thả đổi thứ tự nhân sự (FR-34 reorder).
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
            return $this->redirect()->toRoute('admin/team');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();

        $result = $this->team->formReorder($post->toArray());

        if ($request->isXmlHttpRequest()) {
            return new JsonModel($result);
        }

        return $this->redirect()->toRoute(
            'admin/team',
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
    private function renderForm(?int $id, array $values, array $errors, string $title): ViewModel
    {
        $model = new ViewModel([
            'id'       => $id,
            'values'   => $values,
            'errors'   => $errors,
            'title'    => $title,
            'csrfHash' => $this->team->saveFormCsrfHash(),
            'mediaOptions' => $this->team->mediaOptions(),
        ]);
        $model->setTemplate('admin/team/form');

        return $model;
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
