<?php

declare(strict_types=1);

namespace Admin\Controller;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Service\ContactService;
use Frontend\Model\Contact\ContactConst;
use Frontend\Model\Contact\ContactModel;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Stdlib\ParametersInterface;
use Laminas\View\Model\ViewModel;

/**
 * Hộp thư liên hệ /admin/contacts (docs §3.9 FR-35). PRG cho form xoá (chuẩn 07 §2).
 * Controller mỏng: chỉ ghép id route vào raw rồi gọi ContactService; lỗi validate
 * render lại trang chi tiết kèm field errors (không redirect mất dữ liệu nhập).
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động (Params, Redirect…).
 */
class ContactController extends AbstractActionController
{
    public function __construct(
        private readonly ContactService $contacts,
    ) {
    }

    /**
     * @return ViewModel
     */
    public function indexAction()
    {
        $request = $this->getRequest();
        /** @var array<array-key, mixed> $query */
        $query = $request instanceof HttpRequest ? $request->getQuery()->toArray() : [];

        return new ViewModel([
            'items'        => $this->contacts->listFiltered($query),
            'filters'      => $query,
            'statusLabels' => $this->contacts->statusLabels(),
            'serviceNames' => $this->contacts->serviceOptions(),
            'flag'         => $this->queryFlag(),
            'csrfHash'     => $this->contacts->deleteFormCsrfHash(),
        ]);
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function viewAction()
    {
        $id = $this->intParam('id');
        if ($id === null) {
            return $this->redirect()->toRoute('admin/contacts');
        }

        try {
            $contact = $this->contacts->findOrFail($id);
        } catch (NotFoundException) {
            return $this->redirect()->toRoute(
                'admin/contacts',
                [],
                ['query' => ['flag' => 'notfound']]
            );
        }

        return $this->renderDetail($contact, $contact->toFormValues(), [], $this->queryFlag());
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function updateAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/contacts');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id = $this->intParam('id');
        if ($id !== null) {
            $raw['id'] = (string) $id;
        }

        try {
            $this->contacts->updateForm($raw);

            return $this->redirect()->toRoute(
                'admin/contacts/view',
                ['id' => $id],
                ['query' => ['flag' => ContactConst::FLAG_UPDATED]]
            );
        } catch (ValidationException $e) {
            if ($id === null) {
                return $this->redirect()->toRoute(
                    'admin/contacts',
                    [],
                    ['query' => ['flag' => 'notfound']]
                );
            }

            try {
                $contact = $this->contacts->findOrFail($id);
            } catch (NotFoundException) {
                return $this->redirect()->toRoute(
                    'admin/contacts',
                    [],
                    ['query' => ['flag' => 'notfound']]
                );
            }

            unset($raw['csrf']);

            return $this->renderDetail($contact, $raw, $e->getErrors(), null);
        } catch (NotFoundException) {
            return $this->redirect()->toRoute(
                'admin/contacts',
                [],
                ['query' => ['flag' => 'notfound']]
            );
        }
    }

    /**
     * @return Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function exportAction()
    {
        $request = $this->getRequest();
        /** @var array<array-key, mixed> $query */
        $query = $request instanceof HttpRequest ? $request->getQuery()->toArray() : [];
        $csv     = $this->contacts->exportCsv($query);
        $response = $this->getResponse();
        if (! $response instanceof Response) {
            $response = new Response();
        }

        $response->setContent($csv);
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'text/csv; charset=UTF-8');
        $headers->addHeaderLine(
            'Content-Disposition',
            'attachment; filename="contacts-' . gmdate('Ymd-His') . '.csv"'
        );
        $headers->addHeaderLine('Content-Length', (string) strlen($csv));

        return $response;
    }

    /**
     * @return Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function deleteAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/contacts');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id = $this->intParam('id');
        if ($id !== null) {
            $raw['id'] = (string) $id;
        }

        $flag = $this->contacts->deleteForm($raw);

        return $this->redirect()->toRoute('admin/contacts', [], ['query' => ['flag' => $flag]]);
    }

    /**
     * @param array<array-key, mixed> $values
     * @param array<string, string>   $errors
     *
     * @return ViewModel
     */
    private function renderDetail(ContactModel $contact, array $values, array $errors, ?string $flag): ViewModel
    {
        $model = new ViewModel([
            'contact'        => $contact,
            'serviceName'    => $contact->serviceId === null
                ? null
                : ($this->contacts->serviceOptions()[$contact->serviceId] ?? null),
            'statusLabels'   => $this->contacts->statusLabels(),
            'values'         => $values,
            'errors'         => $errors,
            'flag'           => $flag,
            'updateCsrfHash' => $this->contacts->updateFormCsrfHash(),
            'deleteCsrfHash' => $this->contacts->deleteFormCsrfHash(),
        ]);
        $model->setTemplate('admin/contact/view');

        return $model;
    }

    private function queryFlag(): ?string
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest) {
            return null;
        }

        /** @var mixed $flagRaw */
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
