# UI & Data Contract: Imports Registry & Run Details

## 1. Routes Definition
- **Index**: `GET /admin/imports`
- **Show**: `GET /admin/imports/{id}`
- **Access Control**: `ROLE_ADMIN`
- **Allowed Methods**: `GET`, `HEAD` (все модифицирующие методы `POST`, `PUT`, `DELETE` возвращают `405 Method Not Allowed`)
- **Response Format**: `text/html; charset=UTF-8`

## 2. Injected Services & Data Providers
- `App\Repository\ImportRunRepository`
- `App\Monitoring\Sanitizer\MonitoringDataSanitizer`
- `Doctrine\DBAL\Connection` (выборка связанных записей outbox)
- Режим транзакции: `SET TRANSACTION READ ONLY;`, без вызова `flush()`.

## 3. Query Parameters (`/admin/imports`)
- `page`: integer (default: 1, min: 1)
- `limit`: integer (default: 25, max: 100)
- `status`: string (enum: `PENDING`, `PROCESSING`, `SUCCESS`, `FAILED`, null)
- `type`: string (enum: `catalog`, `offers`, null)

## 4. View Models

### 4.1 Index View Context
```php
[
    'runs' => [
        [
            'id' => int,
            'type' => 'catalog' | 'offers',
            'filename' => string,
            'status' => 'PENDING' | 'PROCESSING' | 'SUCCESS' | 'FAILED',
            'attempt' => int,
            'items_count' => int,
            'created_at' => DateTimeImmutable,
            'updated_at' => DateTimeImmutable,
            'has_error' => bool,
        ],
    ],
    'pagination' => [
        'current_page' => int,
        'total_pages' => int,
        'total_items' => int,
        'limit' => int,
    ],
    'filters' => [
        'status' => ?string,
        'type' => ?string,
    ],
]
```

### 4.2 Show View Context (`/admin/imports/{id}`)
```php
[
    'run' => [
        'id' => int,
        'type' => string,
        'filename' => string,
        'file_hash' => string,
        'status' => string,
        'attempt' => int,
        'fencing_token' => ?string,
        'items_count' => int,
        'created_at' => DateTimeImmutable,
        'updated_at' => DateTimeImmutable,
        'retry_scheduled_at' => ?DateTimeImmutable,
        'sanitized_error' => ?string, // Обработано через MonitoringDataSanitizer::sanitizeErrorMessage($run->getErrorMessage())
    ],
    'outbox_events' => [
        [
            'id' => int,
            'kind' => 'immediate' | 'retry' | 'defer',
            'queue_name' => string,
            'published_at' => ?DateTimeImmutable,
            'available_at' => DateTimeImmutable,
            'created_at' => DateTimeImmutable,
        ],
    ],
]
```

## 5. Security & Sanitization Rules
- Поле `errorMessage` сущности `App\Entity\ImportRun` **всегда** пропускается через `MonitoringDataSanitizer::sanitizeErrorMessage($run->getErrorMessage())` перед передачей в шаблон.
- Все строки (включая имя файла, хеши и санитизированный текст ошибки) выводятся с автоматическим Twig экранированием (`escape`), предотвращающим XSS атаки.
