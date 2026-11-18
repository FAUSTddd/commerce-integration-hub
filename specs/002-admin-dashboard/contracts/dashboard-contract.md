# UI & Data Contract: Dashboard (`/admin`)

## 1. Route Definition
- **Path**: `/admin`
- **HTTP Method**: `GET` (строго `GET`/`HEAD`, любые `POST`, `PUT`, `DELETE` возвращают `405 Method Not Allowed`)
- **Access Control**: `ROLE_ADMIN` (HTTP Basic Auth через файрвол `admin`)
- **Rate Limiting**: Ограничение попыток входа (10 попыток/мин на IP)
- **Response Format**: `text/html; charset=UTF-8`

## 2. Injected Services & Data Providers
- `App\Monitoring\Service\SystemHealthMonitorService::getOverview(): SystemHealthOverview`
- `App\Repository\ImportRunRepository::findRecentRuns(int $limit = 5): ImportRun[]`
- Транзакция: `READ ONLY` на уровне PostgreSQL (`SET TRANSACTION READ ONLY;`), без вызова `flush()`.

## 3. View Model (Twig Context)

```php
[
    'overview' => [
        'status' => 'HEALTHY' | 'DEGRADED' | 'CRITICAL',
        'generated_at' => '2026-11-13T20:00:00+00:00',
        'anomalies' => string[],
        'queues' => [
            'async_catalog' => [
                'name' => 'async_catalog',
                'size' => int,
                'oldest_message_age_seconds' => int,
            ],
            'async_offers' => [
                'name' => 'async_offers',
                'size' => int,
                'oldest_message_age_seconds' => int,
            ],
            'failed' => [
                'name' => 'failed',
                'size' => int,
            ],
        ],
        'outbox' => [
            'unpublished_count' => int,
            'max_lag_seconds' => int,
        ],
        'active_runs' => [
            [
                'id' => int,
                'type' => 'catalog' | 'offers',
                'filename' => string,
                'status' => 'PENDING' | 'PROCESSING',
                'duration_seconds' => int,
                'attempt' => int,
                'is_stale' => bool,
            ],
        ],
    ],
    'recent_runs' => ImportRun[],
]
```

## 4. UI Elements & Layout Requirements
1. **Header**: Логотип, текущее окружение (`APP_ENV`), статус авторизованного пользователя, кнопка ручного обновления.
2. **Hero Status Banner**:
   - `HEALTHY`: Зеленый фон, "Все подсистемы работают штатно".
   - `DEGRADED`: Желтый фон, список предупреждений (лаг Outbox, зависшие импорты).
   - `CRITICAL`: Красный фон, критические аномалии (сообщения в `failed`, критический лаг очередей).
3. **Metric Cards Grid**:
   - `Catalog Queue`: бэклог сообщений + возраст старейшего;
   - `Offers Queue`: бэклог сообщений + возраст старейшего;
   - `Failed Messages`: счетчик ошибок с прямой ссылкой на раздел очередей;
   - `Outbox Lag`: непубликованные сообщения + лаг в секундах.
4. **Active Runs Table**: Таблица выполняющихся в данный момент импортов с бейджем длительности и индикацией зависания.
5. **Recent Activity Stream**: 5 последних завершенных импортов со статусами (`SUCCESS` / `FAILED`).
6. **XSS Protection**: Twig auto-escaping включен для всех текстовых полей, включая имена файлов и аномалии.
