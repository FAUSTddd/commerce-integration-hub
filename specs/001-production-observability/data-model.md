# Data Model: Production Observability & Operational Monitoring

**Feature**: `001-production-observability`
**Target**: Commerce Integration Hub (CIH)
**Date**: 2026-11-10

Модель данных подсистемы мониторинга является строго виртуальной (DTO / Read-Only Snapshots). Никаких новых таблиц или колонок в БД не добавляется. Источником данных служат существующие таблицы `import_runs`, `messenger_messages` и `import_run_outbox`.

---

## Read-Only Snapshots & Value Objects

### 1. ImportRunSnapshot
Отражает состояние отдельного активного или сбойного процесса импорта.
- `id` (int): Идентификатор запуска.
- `type` (string): Тип импорта (`catalog` | `offers`).
- `status` (string): Статус запуска (`PENDING`, `PROCESSING`, `SUCCESS`, `FAILED`).
- `attempt` (int): Текущий номер попытки выполнения.
- `duration_seconds` (int): Длительность выполнения в секундах от `created_at` (или `started_at`).
- `is_stale` (bool): Флаг превышения порога `redeliver_timeout` (5400s для `catalog`, 900s для `offers`).
- `filename_clean` (string): Санитизированное базовое имя файла (без системных путей).
- `last_error_sanitized` (?string): Очищенный текст ошибки без секретов, путей и XML.

### 2. ImportRunsSummary
Агрегированная сводка жизненного цикла импортов за скользящий интервал (по умолчанию 24ч).
- `window_hours` (int): Глубина интервала анализа.
- `total_runs` (int): Общее количество запусков за интервал.
- `pending_count` (int): Количество запусков в статусе `PENDING`.
- `processing_count` (int): Количество запусков в статусе `PROCESSING`.
- `success_count` (int): Количество запусков в статусе `SUCCESS`.
- `failed_count` (int): Количество запусков в статусе `FAILED`.
- `active_runs` (array<ImportRunSnapshot>): Детализированный список активных запусков (`PENDING`, `PROCESSING`).
- `recent_failed_runs` (array<ImportRunSnapshot>): Список последних запусков в статусе `FAILED` с санитизированными ошибками.

### 3. QueueMetricSnapshot
Срез метрик отдельной очереди Symfony Messenger (для `async_catalog`, `async_offers`, `failed`).
- `queue_name` (string): Имя очереди (`async_catalog`, `async_offers`, `failed`).
- `pending_count` (int): Количество готовых к взятию сообщений (`delivered_at IS NULL AND available_at <= NOW()`).
- `in_flight_count` (int): Количество сообщений, взятых воркером (`delivered_at IS NOT NULL`). Трактуется как обработка или ожидание redelivery по истечении `redeliver_timeout`.
- `delayed_count` (int): Количество отложенных по backoff сообщений (`delivered_at IS NULL AND available_at > NOW()`).
- `oldest_message_age_seconds` (?int): Возраст старейшего необработанного сообщения в секундах.
- `failed_transport_count` (int): Количество сообщений в транспорте `failed` (`queue_name = 'failed'`).

### 4. OutboxMetricSnapshot
Срез состояния транзакционного буфера Outbox (`import_run_outbox`).
- `unpublished_count` (int): Количество неопубликованных сообщений (`published = FALSE`).
- `deferred_retry_count` (int): Количество сообщений в состоянии повторной отправки (`retry_count > 0 AND published = FALSE`).
- `oldest_unpublished_age_seconds` (?int): Задержка публикации старейшего неопубликованного события от `created_at`.
- `last_published_at` (?\DateTimeImmutable): Метка времени последней успешной публикации (`MAX(published_at)`).

### 5. QueueStarvationIndicator
Оценка обслуживания очереди и детекция признаков голодания.
- `queue_name` (string): Имя анализируемой очереди.
- `pending_backlog_detected` (bool): Признак наличия готовых к обработке сообщений (`pending_count > 0`).
- `starvation_suspected` (bool): Флаг подозрения на голодание (наличие бэклога при отсутствии активных `ImportRun` и изменений очередей).
- `evaluation_basis` (string): Описание эвристики ("No active ImportRun processing while queue has ready messages").
- *Примечание*: поле `last_activity_at` не является обязательным и не заполняется при отсутствии достоверного источника.

### 6. ErrorCategorizationSnapshot
Категориальное разделение ошибок без взаимного двойного учёта.
- `import_run_failures` (int): Ошибки бизнес-логики и транзакций импорта (`ImportRun::STATUS_FAILED`).
- `messenger_delivery_failures` (int): Исчерпанные попытки доставки Messenger (сообщения в `failed` транспорте).
- `messenger_transient_retries` (int): Сообщения на отложенном повторе (`available_at > NOW()`, с учетом бэкоффа 3s/5s/9s для offers).
- `outbox_publishing_failures` (int): Ошибки публикации Outbox (`retry_count > 0 AND published = FALSE`).
- `failures_by_type` (array<string, int>): Распределение сбоев импорта по типам (`catalog` vs `offers`).

### 7. SystemHealthOverview
Интегральный оперативный статус всей системы.
- `status` (string): `HEALTHY` | `DEGRADED` | `CRITICAL`.
- `timestamp` (\DateTimeImmutable): Время снятия диагностического среза.
- `import_runs` (ImportRunsSummary): Сводка импортов.
- `queues` (array<QueueMetricSnapshot>): Сводка очередей Messenger.
- `outbox` (OutboxMetricSnapshot): Сводка Outbox.
- `starvation` (array<QueueStarvationIndicator>): Индикаторы голодания очередей.
- `errors` (ErrorCategorizationSnapshot): Категоризированные ошибки.
- `detected_anomalies` (array<string>): Список текстовых описаний выявленных деградаций.

---

## State Transition Rules (System Health)

- **CRITICAL**:
  - PostgreSQL connection failure / timeout.
  - Подтвержденное критическое голодание воркеров (`starvation_suspected = true`) при растущем бэклоге дольше лимита времени.
- **DEGRADED**:
  - Наличие сообщений в транспорте `failed` (`failed_count > 0`).
  - Обнаружен зависший импорт (`is_stale = true`).
  - Наличие отложенных повторов Outbox (`deferred_retry_count > 0`).
  - Сообщения `in_flight` удерживаются дольше `redeliver_timeout` (5400s catalog / 900s offers).
- **HEALTHY**:
  - Отсутствие условий `CRITICAL` и `DEGRADED`.
