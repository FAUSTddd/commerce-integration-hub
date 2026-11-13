# Interface Contract: CLI Monitoring Command

**Command**: `bin/console monitor:status` (алиас: `app:monitoring:status`)
**Target**: Commerce Integration Hub (CIH)
**Date**: 2026-11-10

## Input Options
- `--format=txt|json` (default: `txt`): Формат вывода оперативного среза.
- `--window-hours=24` (default: 24): Интервал анализа для счетчиков `ImportRun`.
- `--no-interaction` (`-n`): Неинтерактивный режим выполнения.

## Exit Codes
- `0`: Успешное выполнение, статус системы `HEALTHY` или `DEGRADED`.
- `1`: Ошибка выполнения команды (например, ошибка аргументов или внутренняя ошибка).
- `2`: Статус системы `CRITICAL` (позволяет использовать команду для healthcheck-скриптов и алертинга).

---

## Output Contract (JSON Schema & Example)

### JSON Output Specification (`--format=json`)

```json
{
  "status": "HEALTHY|DEGRADED|CRITICAL",
  "timestamp": "2026-11-10T14:30:00+01:00",
  "import_runs": {
    "window_hours": 24,
    "summary": {
      "total": 12,
      "pending": 1,
      "processing": 1,
      "success": 9,
      "failed": 1
    },
    "active_runs": [
      {
        "id": 1042,
        "type": "catalog",
        "status": "PROCESSING",
        "attempt": 1,
        "duration_seconds": 320,
        "is_stale": false,
        "filename": "import.xml"
      }
    ],
    "recent_failures": [
      {
        "id": 1039,
        "type": "offers",
        "status": "FAILED",
        "attempt": 3,
        "duration_seconds": 15,
        "filename": "offers.xml",
        "last_error": "CommerceML XML schema validation failed: [REDACTED]"
      }
    ]
  },
  "queues": [
    {
      "queue_name": "async_catalog",
      "pending": 0,
      "in_flight": 1,
      "delayed": 0,
      "oldest_message_age_seconds": 320
    },
    {
      "queue_name": "async_offers",
      "pending": 2,
      "in_flight": 0,
      "delayed": 1,
      "oldest_message_age_seconds": 45
    }
  ],
  "failed_transport": {
    "count": 0
  },
  "outbox": {
    "unpublished_count": 0,
    "deferred_retries": 0,
    "oldest_unpublished_age_seconds": null,
    "last_published_at": "2026-11-10T14:28:15+01:00"
  },
  "starvation_indicators": [
    {
      "queue_name": "async_catalog",
      "pending_backlog": false,
      "starvation_suspected": false,
      "evaluation_basis": "Worker active or queue empty"
    },
    {
      "queue_name": "async_offers",
      "pending_backlog": true,
      "starvation_suspected": false,
      "evaluation_basis": "Backlog within acceptable time window"
    }
  ],
  "errors_summary": {
    "import_run_failures": 1,
    "messenger_delivery_failures": 0,
    "messenger_transient_retries": 1,
    "outbox_publishing_failures": 0,
    "by_type": {
      "catalog": 0,
      "offers": 1
    }
  },
  "anomalies": []
}
```

---

## Output Contract (Human-Readable CLI)

```text
[OK] Commerce Integration Hub: HEALTHY (2026-11-10 14:30:00 CET)

Import Runs (last 24h):
  Total: 12 | PENDING: 1 | PROCESSING: 1 | SUCCESS: 9 | FAILED: 1
  Active:
    - #1042 [catalog] PROCESSING (320s, attempt 1) file: import.xml [OK]

Queues (Doctrine Messenger):
  - async_catalog: pending=0, in_flight=1, delayed=0, oldest=320s
  - async_offers:  pending=2, in_flight=0, delayed=1, oldest=45s
  - failed_transport: 0 messages

Outbox:
  - Unpublished: 0 (deferred retry: 0)
  - Last published: 2026-11-10 14:28:15 CET

Errors & Retries:
  - ImportRun FAILED: 1 (catalog: 0, offers: 1)
  - Messenger Failed Transport: 0
  - Messenger Delayed Retries: 1
  - Outbox Retries: 0
```
