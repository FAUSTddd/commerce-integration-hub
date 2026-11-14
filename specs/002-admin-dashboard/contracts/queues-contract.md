# UI & Data Contract: Queues & Outbox Overview (`/admin/queues`)

## 1. Route Definition
- **Path**: `/admin/queues`
- **HTTP Method**: `GET` (строго `GET`/`HEAD`, методы `POST`, `PUT`, `DELETE` возвращают `405 Method Not Allowed`)
- **Access Control**: `ROLE_ADMIN`
- **Response Format**: `text/html; charset=UTF-8`

## 2. Injected Services & Data Providers
- `App\Monitoring\Repository\MonitoringQueryRepository`
- `Doctrine\DBAL\Connection`
- Режим транзакции: `SET TRANSACTION READ ONLY;`, без вызова `flush()`.

## 3. View Model (Twig Context)

```php
[
    'queues' => [
        [
            'name' => 'async_catalog',
            'size' => int,
            'oldest_message_age_seconds' => int,
            'status' => 'OK' | 'WARN' | 'CRITICAL',
        ],
        [
            'name' => 'async_offers',
            'size' => int,
            'oldest_message_age_seconds' => int,
            'status' => 'OK' | 'WARN' | 'CRITICAL',
        ],
        [
            'name' => 'failed',
            'size' => int,
            'status' => 'OK' | 'CRITICAL',
        ],
    ],
    'outbox' => [
        'unpublished_count' => int,
        'max_lag_seconds' => int,
        'stale_defers_count' => int, // kind = 'defer' старше 15 минут
        'recent_unpublished' => [
            [
                'id' => int,
                'run_id' => int,
                'attempt' => int,
                'kind' => 'immediate' | 'retry' | 'defer',
                'queue_name' => string,
                'available_at' => DateTimeImmutable,
                'created_at' => DateTimeImmutable,
            ],
        ],
    ],
    'worker_starvation_detected' => bool,
]
```

## 4. Operational Thresholds & Indicators
1. **Queue Age Warning**:
   - `async_catalog`: age > 3600s (`WARN`), > 5400s (`CRITICAL`);
   - `async_offers`: age > 300s (`WARN`), > 900s (`CRITICAL`).
2. **Failed Queue Indicator**:
   - `size > 0` немедленно маркируется красным бейджем с предупреждением о ручной инспекции.
3. **Outbox Health**:
   - `max_lag_seconds > 60` сигнализирует о задержке демона `app:cml:publish-outbox`.
