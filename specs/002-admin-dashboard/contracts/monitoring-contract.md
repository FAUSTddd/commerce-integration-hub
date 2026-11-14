# UI & Data Contract: Monitoring Health API (`/admin/api/health`)

## 1. Route Definition
- **Path**: `/admin/api/health`
- **HTTP Method**: `GET` (строго `GET`/`HEAD`, методы `POST`, `PUT`, `DELETE` возвращают `405 Method Not Allowed`)
- **Access Control**: `ROLE_ADMIN`
- **Response Format**: `application/json; charset=UTF-8`

## 2. Injected Services
- `App\Monitoring\Service\SystemHealthMonitorService`
- Режим транзакции: `SET TRANSACTION READ ONLY;`, без вызова `flush()`.

## 3. JSON Response Schema

```json
{
  "status": "HEALTHY",
  "generated_at": "2026-11-13T20:00:00+00:00",
  "anomalies": [],
  "queues": {
    "async_catalog": {
      "name": "async_catalog",
      "size": 0,
      "oldest_message_age_seconds": 0
    },
    "async_offers": {
      "name": "async_offers",
      "size": 0,
      "oldest_message_age_seconds": 0
    },
    "failed": {
      "name": "failed",
      "size": 0,
      "oldest_message_age_seconds": 0
    }
  },
  "outbox": {
    "unpublished_count": 0,
    "max_lag_seconds": 0
  },
  "active_runs": [
    {
      "id": 101,
      "type": "catalog",
      "filename": "import.xml",
      "status": "PROCESSING",
      "duration_seconds": 45,
      "attempt": 1,
      "is_stale": false
    }
  ]
}
```

## 4. Performance & Rate Limiting SLA
- **Execution Time**: `<= 50ms` (ограничение запроса `statement_timeout = 250ms`).
- **HTTP Cache Headers**: `Cache-Control: no-cache, no-store, must-revalidate`.
- **Concurrency**: Вызовы API изолированы и не выполняют блокирующих чтений строк (`SELECT ... FOR UPDATE` запрещены).
- **Rate Limiting**: Ограничение частоты опроса клиентами (не чаще 1 запроса в 5 секунд на IP/сессию).
