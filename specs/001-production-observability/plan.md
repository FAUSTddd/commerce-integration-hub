# Implementation Plan: Production Observability & Operational Monitoring

**Branch**: `001-production-observability` | **Date**: 2026-11-10 | **Spec**: [specs/001-production-observability/spec.md](spec.md)

**Input**: Feature specification from `/specs/001-production-observability/spec.md` and Project Constitution v1.1.0 (`.specify/memory/constitution.md`).

## Summary

Реализация неинвазивной, строго read-only подсистемы оперативного мониторинга и оценки состояния Commerce Integration Hub. Решение предоставляет единый инструмент (сервис агрегации среза + консольную команду `monitor:status` с поддержкой text и JSON) для сквозного контроля жизненного цикла `ImportRun`, состояния очередей Symfony Messenger (`catalog`, `offers`, `failed`), буфера Outbox (`import_run_outbox`) и выявления признаков голодания очередей воркерами (starvation). Мониторинг строго соблюдает инварианты транзакционной целостности, CAS/fencing защиты и безопасной санитизации конфиденциальных данных.

## Technical Context

**Language/Version**: PHP 8.4 (composer requires `>=8.3`, активная среда PHP 8.4)

**Primary Dependencies**: Symfony Framework Bundle 7.1+, Symfony Console 7.1+, Symfony Messenger & Doctrine Messenger 7.1+, Doctrine ORM 3.3+ / DBAL 4.0+

**Storage**: PostgreSQL 16 (таблицы `import_runs`, `messenger_messages`, `import_run_outbox`)

**Testing**: PHPUnit 10.5+ / 11.0, Symfony BrowserKit / WebTestCase / KernelTestCase

**Target Platform**: Linux Server (CLI-окружение и фоновые процессы Symfony)

**Project Type**: Enterprise backend service / Monolith CLI

**Performance Goals**:
- SC-001: Время формирования среза (при очереди 50 000 сообщений) — цель < 500мс (замеряется как target indicator).
- SC-002: Потребление CPU/IO при опросе (интервал 10с) — цель < 2% (замеряется как target indicator при базовой нагрузке).

**Constraints**:
- Strict Read-Only (`SELECT` only, no DML/DDL).
- Никаких блокировок строк/таблиц (`FOR UPDATE`/`FOR SHARE` запрещены).
- Выполнение запросов с лимитом времени `statement_timeout` (2000 мс).
- Изоляция `READ COMMITTED`: каждый отдельный SQL-запрос согласован, межзапросный моментальный снимок не требуется.
- Гарантированная санитизация: исключение секретов, credentials, connection params, абсолютных путей и XML из `filename` и `last_error`.
- Никаких новых сущностей БД, колонок, таблиц или внешних бинарных агентов.

**Scale/Scope**: Мониторинг до 50 000 сообщений в очередях Messenger, до сотен тысяч записей в `import_runs` за скользящие окна.

---

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

1. **Persistence & DB Integrity**:
   - Изменения схемы БД: **НЕТ** (новые таблицы/колонки отсутствуют, schema drift не затрагивается).
   - Частичные индексы (`uniq_import_runs_active_type_filename`, `uniq_outbox_active_defer`): соблюдаются как инварианты, запросы мониторинга согласованы с предикатами индексов.
   - CAS2 `claimRunCas2`: мониторинг не выполняет мутаций и не конкурирует за строки `ImportRun`.
   - **PASS**.

2. **Messenger & Transactional Integrity**:
   - Транзакции импорта 1 XML-файла и батчинг по 100 сущностей с `flush()`/`clear()` не затрагиваются.
   - Таймауты `redeliver_timeout` (5400s для `catalog`, 900s для `offers`) используются для расчета флага `is_stale` и категоризации in-flight сообщений.
   - Команда `recover-stale-runs` не вызывается автоматически и остается независимой регламентной процедурой.
   - **PASS**.

3. **Outbox Pattern & ACK Flow**:
   - Точечный ACK `markOutboxAckedById` и порядок подтверждений `$receiver->ack()` не затрагиваются.
   - Запросы к `import_run_outbox` выполняются в режиме строгого чтения.
   - **PASS**.

4. **Streaming & Resource Management**:
   - Потоковая обработка через `XMLReader` не затрагивается; мониторинг не читает XML с диска и не потребляет файловые дескрипторы очередей.
   - **PASS**.

5. **Concurrency, Fencing & Recovery**:
   - Advisory locks, `fencing_token` и `attempt` не модифицируются и не блокируются.
   - **PASS**.

6. **Testing & Isolation**:
   - Тесты подсистемы мониторинга не изменяют постоянные данные БД и изолируют тестовые фикстуры.
   - **PASS**.

7. **Governance**:
   - Изменения Constitution не требуются.
   - **PASS**.

---

## Project Structure

### Documentation (this feature)

```text
specs/001-production-observability/
├── spec.md                     # Feature Specification (Source of Truth)
├── plan.md                     # Implementation Plan (this file)
├── research.md                 # Technical Decisions & Rationale
├── data-model.md               # Virtual DTOs & Snapshots Model
├── quickstart.md               # Validation & Verification Scenarios
└── contracts/
    └── cli-contract.md         # CLI and JSON Output Contract
```

### Source Code Layout (Planned Architecture)

```text
src/
├── Monitoring/
│   ├── Dto/
│   │   ├── ImportRunSnapshot.php
│   │   ├── ImportRunsSummary.php
│   │   ├── QueueMetricSnapshot.php
│   │   ├── OutboxMetricSnapshot.php
│   │   ├── QueueStarvationIndicator.php
│   │   ├── ErrorCategorizationSnapshot.php
│   │   └── SystemHealthOverview.php
│   ├── Sanitizer/
│   │   └── MonitoringDataSanitizer.php
│   ├── Repository/
│   │   └── MonitoringQueryRepository.php
│   └── Service/
│       └── SystemHealthMonitorService.php
└── Command/
    └── MonitorStatusCliCommand.php

tests/
├── Unit/
│   ├── Monitoring/
│   │   ├── MonitoringDataSanitizerTest.php
│   │   └── SystemHealthOverviewTest.php
└── Integration/
    ├── Monitoring/
    │   └── MonitoringQueryRepositoryTest.php
    └── Command/
        └── MonitorStatusCliCommandTest.php
```

**Structure Decision**: Реализация размещается в изолированном пространстве имен `App\Monitoring` (DTO, Sanitizer, Read-Only Query Repository, Service) с единой точкой входа через CLI-команду `App\Command\MonitorStatusCliCommand`. Это предотвращает загрязнение существующих сервисов бизнес-логики импорта и гарантирует строгое разграничение ответственности (SRP).

---

## Architectural Analysis & Trade-Offs

### 1. Выбор интерфейса (OD-1)
- **Решение**: CLI-команда Symfony (`bin/console monitor:status` / `app:monitoring:status`) с поддержкой `--format=json`.
- **Обоснование**: Минималистичное решение, полностью закрывающее сценарии операторов и интеграцию с внешними системами через stdout без открытия уязвимого HTTP-периметра без Security Bundle.

### 2. Формат экспорта (OD-2)
- **Решение**: Двухрежимный вывод: форматированный текст (human-readable) по умолчанию и структурированный JSON при `--format=json`. Экспорт в формате Prometheus (`/metrics`) выносится в открытые решения последующих этапов при подтвержденной потребности инфраструктуры.

### 3. Worker Liveness vs Starvation (OD-4 & FR-004)
- **Решение**: Использование чисто неинвазивной эвристики на основе данных БД (наличие необработанного бэклога `pending > 0` при отсутствии активных `ImportRun`). Физический статус процессов ОС (liveness) не эмулируется и не требует отсутствующих атрибутов (`last_activity_at`).

### 4. Пороги переходов здоровья (OD-5 & FR-006)
- **Решение**: Качественная логика:
  - `CRITICAL`: недоступность БД или подозрение на starvation воркеров при растущем бэклоге.
  - `DEGRADED`: сообщения в `failed` транспорте, зависший импорт (`is_stale`), задержки `in_flight` дольше `redeliver_timeout` или ошибки Outbox.
  - `HEALTHY`: штатное состояние.

---

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| *None* | Архитектурных нарушений Constitution v1.1.0 нет | Решение использует исключительно существующую схему БД и стандартные компоненты Symfony |

---

## Implementation Decisions & Known Limitations

- **Historical import run filtering (`--window-hours`)**:
  - The CLI option `--window-hours` (default: 24) is fully recognized, validated, and serialized into human-readable and JSON outputs (`import_runs.window_hours`).
  - Active runs are dynamically collected from current `PENDING` / `PROCESSING` states. Dynamic historical time-window filtering (`WHERE finished_at >= NOW() - INTERVAL '...'`) for historical terminal runs (`SUCCESS`/`FAILED`) is intentionally deferred to future analytical iterations to avoid complex indexing dependencies and keep the monitoring path strictly bounded.
- **Fail-safe isolation**:
  - `SystemHealthMonitorService` wraps individual data sources (`ImportRun`, `Messenger`, `Outbox`) in separate `try/catch` blocks.
  - Failures in individual data sources do not crash the command; they mark system status as `CRITICAL`, log sanitized error summaries into `anomalies`, and CLI exits with code `2`.
- **Read-Only / Non-invasive execution**:
  - All DB access uses non-locking queries with `statement_timeout = 2000ms`. No DDL, DML, `SELECT ... FOR UPDATE`, or worker-blocking transactions are introduced.
- **Performance targets (SC-001 & SC-002 empirical validation)**:
  - SC-001 confirmed: Full cold-start CLI execution with 50,000 pending messages completed in 110ms (< 500ms target). Pure PHP service latency averaged 11.59ms.
  - SC-002 confirmed: Database execution time for queue aggregation is ~6.58ms. Over a 10-second polling interval (0.1Hz), the query execution time accounts for ~0.0658% of the window duration. Note: 0.0658% represents the calculated ratio of query execution time to interval duration, not a direct OS-level PostgreSQL CPU utilization measurement.


