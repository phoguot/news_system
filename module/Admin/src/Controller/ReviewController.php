<?php

declare(strict_types=1);

namespace Admin\Controller;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Model\Review\ReviewConst;
use Admin\Service\ReviewService;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Stdlib\ParametersInterface;
use Laminas\View\Model\ViewModel;

/**
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi property qua initializer.
 * @psalm-suppress MixedMethodCall plugin controller được resolve động.
 * @psalm-suppress PossiblyUnusedMethod router gọi action động.
 */
class ReviewController extends AbstractActionController
{
    public function __construct(private readonly ReviewService $service)
    {
    }

    public function indexAction(): ViewModel
    {
        return new ViewModel([
            'items' => $this->service->listAll(),
            'mediaOptions' => $this->service->mediaOptions(),
            'deleteCsrfHash' => $this->service->deleteFormCsrfHash(),
            'flag' => $this->queryFlag(),
        ]);
    }

    /** @psalm-suppress PossiblyUnusedMethod router gọi action động. */
    public function createAction(): ViewModel
    {
        return $this->renderForm(null, [], []);
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router gọi action động.
     */
    public function editAction()
    {
        $id = $this->idParam();
        if ($id === null) {
            return $this->redirect()->toRoute('admin/reviews');
        }
        try {
            return $this->renderForm($id, $this->service->findOrFail($id)->toFormValues(), []);
        } catch (NotFoundException) {
            return $this->redirect()->toRoute('admin/reviews', [], ['query' => ['flag' => 'notfound']]);
        }
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router gọi action động.
     */
    public function saveAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/reviews');
        }
        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw = $post->toArray();
        $id = $this->idParam();
        try {
            $this->service->saveForm($id, $raw);
            return $this->redirect()->toRoute('admin/reviews', [], [
                'query' => ['flag' => $id === null ? ReviewConst::FLAG_CREATED : ReviewConst::FLAG_UPDATED],
            ]);
        } catch (ValidationException $e) {
            return $this->renderForm($id, $raw, $e->getErrors());
        } catch (NotFoundException) {
            return $this->renderForm($id, $raw, ['imageMediaId' => ReviewConst::ERROR_NOT_FOUND]);
        }
    }

    /**
     * @return Response
     * @psalm-suppress PossiblyUnusedMethod router gọi action động.
     */
    public function deleteAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/reviews');
        }
        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw = $post->toArray();
        $id = $this->idParam();
        if ($id !== null) {
            $raw['id'] = (string) $id;
        }
        return $this->redirect()->toRoute('admin/reviews', [], [
            'query' => ['flag' => $this->service->deleteForm($raw)],
        ]);
    }

    /** @param array<array-key, mixed> $values @param array<string, string> $errors */
    private function renderForm(?int $id, array $values, array $errors): ViewModel
    {
        $model = new ViewModel([
            'id' => $id,
            'values' => $values,
            'errors' => $errors,
            'mediaOptions' => $this->service->mediaOptions(),
            'csrfHash' => $this->service->saveFormCsrfHash(),
        ]);
        $model->setTemplate('admin/review/form');
        return $model;
    }

    private function idParam(): ?int
    {
        /** @var mixed $raw */
        $raw = $this->params()->fromRoute('id');
        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    private function queryFlag(): ?string
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest) {
            return null;
        }
        /** @var mixed $flag */
        $flag = $request->getQuery('flag');
        return is_string($flag) ? $flag : null;
    }
}
