# 08 — Sai ↔ Đúng: quy định thực thi tầng (khuôn EnglishTrain)

> **Chốt chuẩn 12/09/2026** — 4 luật thực thi dưới đây viết theo mẫu ❌/ đúng, giữ **nguyên văn** từ chuẩn EnglishTrain (tên class mẫu: `Article*`, `ApiResultModel`, `DateModel`, `businessId`…). Áp dụng vào News thì thay bằng entity thật — xem §5 (ánh xạ + trạng thái repo). Xung đột với `03-quy-chuan-code.md`: file này **thế phần chi tiết** cho luồng được nêu (03 §2 đã dẫn chiếu sang đây).

## 1. Controller chỉ nhận request và gọi Service

Controller không validate form, không query DB, không xử lý business logic.

### Code sai

```php
public function deleteAction(): JsonResponse
{
    $id = (int)$this->params()->fromPost('id');
    if (!$id) {
        return (new ApiResultModel())->errorInvalidFormResponse(['id' => 'Invalid id']);
    }

    $mapper = $this->getContainerEntry(ArticleMapper::class);
    $model = new ArticleModel();
    $model->setId($id);
    $mapper->deleteArticle($model);

    return (new ApiResultModel())->successResponse();
}
```

### Code đúng

```php
public function deleteAction(): JsonResponse
{
    $service = $this->getContainerEntry(ArticleService::class);
    return $service->articleDelete($this->getPostParamsApi());
}
```

## 2. Service điều phối flow nghiệp vụ

Service chịu trách nhiệm validate, check `businessId`, check tồn tại/ownership, gọi mapper và **trả response**.

### Code sai

```php
public function articleDelete(array $payload = []): JsonResponse
{
    $mapper = $this->getContainerEntry(ArticleMapper::class);

    $model = new ArticleModel();
    $model->setId((int)$payload['id']);
    $mapper->deleteArticle($model);

    return (new ApiResultModel())->successResponse();
}
```

### Code đúng

```php
public function articleDelete(array $payload = []): JsonResponse
{
    $apiResult = new ApiResultModel();

    $filter = new ArticleDeleteFilter($this->getContainer());
    $filter->setData($payload);
    if (!$filter->isValid()) {
        return $apiResult->errorInvalidFormResponse($filter->getMessagesArr());
    }

    $businessId = $this->tryRequireBusinessId();
    if (!$businessId) {
        return $apiResult->errorData403Response([AppMessage::CANT_MANAGE_STORE]);
    }

    $formData = $filter->getData();
    $model = new ArticleModel();
    $model->setId((int)$formData['id']);
    $model->setBusinessId($businessId);

    $mapper = $this->getContainerEntry(ArticleMapper::class);
    if (!$mapper->getArticle($model)) {
        return $apiResult->errorData404Response([AppMessage::NO_DATA]);
    }

    $mapper->deleteArticle($model);

    return $apiResult->successResponse([], [AppMessage::DELETE_SUCCESSFULLY]);
}
```

- Trình tự bắt buộc: **Filter validate → check quyền/sở hữu → check tồn tại → gọi Mapper → trả response**. Không bỏ bước nào, không đẩy các bước này lên Controller.
- `InputFilter` stateful (`setData`) → `new` trực tiếp trong method **Service** mỗi request, **không** đăng ký container; Controller không đụng Filter (07 §6 — News đã align 12/09/2026).

## 3. Update trạng thái/thuộc tính — dùng hàm riêng, không dùng `save`

Khi chỉ cần cập nhật 1–2 trường cụ thể (`status`, `type`, `isDefault`, …), phải tạo hàm `updateAttributeXxx` riêng cho từng trường hợp. **Không** gộp chung vào hàm `save`.

### Code sai

```php
public function save(ArticleModel $item): bool
{
    $update = $dbSql->update(ArticleMapper::TABLE_NAME);
    $update->set([
        'title'     => $item->getTitle(),
        'status'    => $item->getStatus(),
        'updatedAt' => DateModel::getTimeStampsCurrent(),
        // ... toàn bộ các field khác
    ]);
    $update->where(['id = ?' => $item->getId()]);
    // ...
}

// Gọi để chỉ update status:
$model->setStatus(ArticleConst::STATUS_INACTIVE);
$mapper->save($model); // ← ghi đè toàn bộ field, field nào không set sẽ thành null
```

### Code đúng

```php
public function updateAttrsArticleCategory(ArticleCategoryModel $item, $data)
{
    if (!$data || !$item->getId()) {
        return null;
    }
    $businessId = $item->getBusinessId();
    if (!$businessId) {
        return null;
    }
    $dbAdapter = $this->getBusinessMasterAdapter($businessId);
    $dbSql = $this->getDbSql();
    $update = $dbSql->update(ArticleCategoryMapper::TABLE_NAME);
    $update->set($data);
    $update->where([
        'id = ?' => (int)$item->getId(),
    ]);
    $rows = $dbAdapter->query($dbSql->buildSqlString($update), $dbAdapter::QUERY_MODE_EXECUTE);
    if (!$rows->count()) {
        return false;
    }
    return true;
}
```

**Lý do:** hàm `save` ghi đè toàn bộ fields. Nếu model không được set đầy đủ trước khi gọi `save`, các field không truyền vào sẽ bị ghi đè thành `null` — gây mất dữ liệu, khó debug.

> ⚠️ **Điều chỉnh khi áp vào News (luật bảo mật AGENTS.md không nới):** mẫu trên thực thi bằng `buildSqlString` + `query()` (adapter multi-tenant EnglishTrain). News **bắt buộc** giữ prepared statement — thay dòng cuối bằng `$update->prepareStatement(...)` qua `$sql->prepareStatementForSqlObject($update)->execute()`. Cấu trúc hàm (guard → set → where → trả số dòng bị ảnh hưởng) giữ nguyên.

## 4. Dùng `DateModel::getTimeStampsCurrent()` thay vì `time()`

Khi set `updatedAt`, `createdAt` trong mapper, phải dùng `DateModel::getTimeStampsCurrent()`. Không dùng `time()` trực tiếp.

### Code sai

```php
$update->set([
    'status'    => $status,
    'updatedAt' => time(),
]);
```

### Code đúng

```php
$update->set([
    'status'    => $status,
    'updatedAt' => DateModel::getTimeStampsCurrent(),
]);
```

**Lý do:** `DateModel::getTimeStampsCurrent()` là điểm tập trung duy nhất cho timestamp hiện tại — dễ mock trong test, dễ thay đổi precision sau này nếu cần. Dùng `time()` rải rác làm mất tính nhất quán và khó kiểm soát.

## 5. Ánh xạ mẫu → repo News + trạng thái (kiểm chứng 12/09/2026 — cập nhật 13/09/2026 sau đợt áp dụng pattern webapp-be)

| Khuôn mẫu (08) | Tương đương trong News | Trạng thái |
|---|---|---|
| `Article{Mapper,Model,Const}` | `{Entity}{Mapper,Model,Const}` ở `src/Model/{Entity}/` (07 §1) — method đọc của Mapper trả Model hydrate `fromRow()` (07 §3) | ✅ đang dùng |
| `ArticleDeleteFilter` | `{Entity}{Action}Filter` (InputFilter) ở `src/Filter/{Entity}/` (07 §6) — **chạy trong Service**, Controller không `new` Filter | ✅ đang dùng |
| `getContainerEntry(X::class)` + `AppInvokableFactory` | **Chuẩn 13/09/2026 (user duyệt):** service `extends AppServiceFactory` + accessor riêng gọi `getContainerEntry(...)`, đăng ký `Service\X::class => AppInvokableFactory::class`; Controller/Mapper vẫn dùng closure `$container->get(X::class)` (03 §3, 07 §4) | ✅ toàn bộ 19 service đã migrate (batch 7: cả `Db`/`Mail`/`Captcha`/`HtmlPurifier`) |
| Test service DI | `(new XService())->setContainer(new TestContainer([XMapper::class => $mock]))` — `Application/test/Helper/TestContainer` | ✅ pattern chuẩn trong 14 test service |
| `ApiResultModel` (`successResponse`/`errorInvalidFormResponse`/403/404) | `Admin\Service\Api\ApiResultModel` (static: `ok`/`error`/`fromThrowable` map 422·409·404 + `serializeModel`/`serializeRow` ISO `…Z`) trả `Admin\Service\Api\ApiResponseModel` (JsonModel + statusCode) — **API flow: Service trả trọn response, ApiController chỉ router + gắn status** (override dòng "Service không đụng HTTP" của 03 §2, đúng ghi chú cũ) | ✅ đã code 13/09/2026 |
| `businessId` / `getBusinessMasterAdapter` / `tryRequireBusinessId` | News là **single-tenant** — không có `businessId`; ownership thay bằng guard phiên admin (`AdminAuthService`) + validate ràng buộc entity | ⛔ không áp dụng nguyên văn |
| `AppMessage::...` | Thông báo lỗi/nghiệp vụ là hằng `ERROR_*`/label trong `{Entity}Const.php` (06) | ✅ đang dùng |
| `DateModel::getTimeStampsCurrent()` | `Application\Service\DateService` (trước 13/09 là `Core\Service\DateService`): `nowUtc()` (thay `gmdate` inline ở `PostService`/`AdminAuthService`/`ContactService`), `timestampUtc()`, `plusMinutesUtc()` (khoá tài khoản), `isoToUtc()` (lõi lenient — `PostService::isoToUtc` wrapper ném ValidationException đúng field). `updatedAt` vẫn do schema `ON UPDATE CURRENT_TIMESTAMP` | ✅ đã code 13/09/2026 |
| `updateAttributeXxx` riêng từng tác vụ | `PostMapper::updatePublished/updateArchived/updateDrafted/updatePreviewToken`, `UserMapper::updatePasswordHash`, `MediaMapper::updateAltText` (hàm `update` chung của MediaMapper bị xoá vì hết caller). `update()` toàn values vẫn dùng cho luồng save đầy đủ | ✅ đã code 13/09/2026 |

## 6. Luật suy ra bắt buộc khi review

- [ ] Controller ≤ vài dòng mỗi action: lấy params → gọi Service → trả kết quả Service (08 §1).
- [ ] Service làm trọn flow validate → quyền → tồn tại → mapper → response, đúng thứ tự (08 §2).
- [ ] Update 1–2 trường: hàm `updateAttributeXxx` riêng, **không** gọi `save/set` toàn field (08 §3).
- [ ] Timestamp: một điểm tập trung duy nhất, không `time()` rải rác (08 §4).
- [ ] Mọi thực thi SQL phía News vẫn qua `prepareStatementForSqlObject` (bất kể mẫu 08 viết sao).
