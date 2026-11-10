<!-- SYSTEM_MAP: Архитектурный индекс и карта инвариантов.
     НЕ является источником истины. Актуальные параметры (DSN, schema, DI)
     всегда валидируются через git diff, bin/console debug:* и файлы конфигурации. -->

# Commerce Integration Hub — System Architecture Map

## 1. Domain & Persistence Model
- `Product` (`products`): `id` (BIGINT), `external_id` (VARCHAR 64, UNIQUE), `name`, `sku` (INDEX), `created_at`, `updated_at`. Связь: 1-to-N к `ProductVariant`.
- `ProductVariant` (`product_variants`): `id` (BIGINT), `product_id` (FK RESTRICT, INDEX), `external_id` (VARCHAR 128, UNIQUE), `price_amount` (BIGINT, минорные единицы/копейки), `currency` (VARCHAR 3, def 'RUB'), `stock` (NUMERIC 14,3), `updated_at`.
- `ImportRun` (`import_runs`): `id` (BIGINT), `type` (catalog|offers), `filename`, `file_hash` (VARCHAR 64), `status` (PENDING|PROCESSING|SUCCESS|FAILED), `items_count`, `error_message`, `created_at`, `started_at`, `finished_at`. Индексы: `status`, `created_at`.
- **Частичный индекс дедупликации:** `uniq_import_runs_active_type_filename` на `(type, filename) WHERE status IN ('PENDING', 'PROCESSING')`.
  - *Происхождение и архитектурное назначение:* создан миграцией `Version20261102000001` в PostgreSQL. Критичен для атомарной дедупликации очередей: на него опирается нативный запрос `ImportRunRepository::tryCreateActiveRun()` (`INSERT ... ON CONFLICT (type, filename) WHERE status IN ('PENDING', 'PROCESSING') DO NOTHING RETURNING id`). Удаление индекса недопустимо (вызывает ошибку PostgreSQL `42P10` и ломает защиту от параллельных дублей).
  - *Намеренное исключение Schema Drift:* `doctrine:schema:validate` сообщает о расхождении и требует `DROP INDEX`, поскольку частичные индексы с предикатом `WHERE` не поддерживаются декларативным маппингом атрибутов Doctrine ORM. Расхождение зафиксировано как намеренное.
  - *Правило безопасности:* выполнение команды `doctrine:schema:update --force` категорически запрещено. Все изменения схемы БД выполняются исключительно через версионированные миграции `doctrine:migrations:migrate`.
  - *Синхронизация Doctrine Metadata Cache (prod):* выполнена регламентная инвалидация и прогрев метаданных `bin/console cache:clear --env=prod` (создан резервный архив `/var/www/html/var/cache/prod_cache_backup.tar.gz`). Метаданные `ImportRun` в `prod` содержат актуальные поля `attempt`, `fencingToken`, `retryScheduledAt`, `hasDeferredRetry`. Ошибочные команды `DROP` этих полей полностью устранены из `doctrine:schema:update --dump-sql`. Архитектурные инварианты PostgreSQL (частичные индексы `uniq_import_runs_active_type_filename`, `uniq_outbox_active_defer`, `idx_outbox_unpublished`, внешний ключ `import_run_outbox_run_id_fkey`, типы `JSONB` и `BIGSERIAL`) сохранены без изменений. Доступность веб-слоя подтверждена контрольным HTTP probe (401 Unauthorized на `/cml/1c_exchange?type=catalog&mode=checkauth`).

## 2. Ingress & Routing (Entry Points)
- **HTTP / CML Exchange:** `/cml/1c_exchange` (`GET|POST`, маршруты `faustddd_1c_exchange`, `import_1c`).
  - *Механизм сессии и авторизации:* Symfony Security Bundle не задействован (`security.yaml` отсутствует). Контроллер `Import1CController` делегирует обработку `Cml1cInteractor`. Авторизация stateful: фаза `mode=checkauth` сверяет HTTP Basic / query credentials через `hash_equals()`, стартует PHP-сессию (`session->migrate(true)`) и возвращает cookie session ID по спецификации 1C. Последующие фазы (`init`, `file`, `import`) валидируют сессию через `isAuthorized()`.
- **CLI Commands:**
  - `app:cml:import` (`RunImportCliCommand`): парсит файл, определяет тип (catalog/offers), диспатчит в Messenger bus.
  - `app:cml:cleanup-files` (`CleanupImportFilesCliCommand`): удаляет временные XML старше TTL (min 1h, def 24h) с защитой активных в `ImportRun`.
  - `app:cml:recover-stale-runs` (`RecoverStaleRunsCliCommand`): переводит зависшие PROCESSING в FAILED с защитой сообщений в окне `redeliver_timeout` (<1h).

## 3. Streaming XML & Import Flow
- **Реальный механизм парсинга:** `FaustDDD\Symfony1cImport\Application\Service\XmlStreamHelper`.
  - Базируется на потоковом `\XMLReader` (`open(..., LIBXML_NONET)`), который итерирует курсор по документу без загрузки всего дерева в RAM.
  - При совпадении тега/пути узел конвертируется в изолированный `\SimpleXMLElement` через `readOuterXML()` и передается в callback хэндлера.
- **Батчинг и очистка памяти:**
  - `CatalogImportHandler` и `OffersImportHandler` аккумулируют сущности и выполняют `$entityManager->flush()` и `$entityManager->clear()` каждые 100 элементов (`BATCH_SIZE = 100`), предотвращая переполнение `UnitOfWork`.
  - **Важно:** промежуточные вызовы `flush()` и `clear()` сбрасывают SQL DML в рамках открытой транзакции, но НЕ фиксируют её (транзакция фиксируется строго единым `commit()` в конце обработки всего файла).

## 4. Asynchronous Topology (Messenger) & Transactions
- **Bus & Middleware:** `messenger.bus.default` -> `App\Messenger\DeduplicateImportMiddleware`.
  - При диспатче `ImportCatalogCommand` / `ImportOffersCommand` middleware атомарно резервирует активный `ImportRun` через `tryCreateActiveRun()` (DBAL).
  - При дубле вешает `DuplicateImportStamp` и прерывает пайплайн. В случае ошибки постановки выполняет компенсацию (`compensateFailedEnqueue`).
- **Transports / Queues:**
  - `async_catalog` (`doctrine://default?queue_name=catalog`): `max_retries: 0`, DLQ = `failed`.
  - `async_offers` (`doctrine://default?queue_name=offers`): `max_retries: 3`, `delay: 2000` (2s), `multiplier: 2`, `max_delay: 10000` (10s), дефолтный Symfony jitter = 0.1 (±10% к задержке), DLQ = `failed`.
- **Управление транзакциями:** 1 XML-файл = 1 транзакция БД. Хэндлер открывает `$entityManager->beginTransaction()` перед чтением стрима, фиксирует `$entityManager->commit()` по завершении или откатывает `$entityManager->rollback()` при `Throwable`.
- **Синхронизация Identity Map после CAS2 (Claim Run):**
  - При захвате запуска обработчик (`CatalogImportHandler`, `OffersImportHandler`) вызывает прямой атомарный SQL `claimRunCas2`, переводящий запись в `status = PROCESSING`, инкрементирующий `attempt` и генерирующий `fencing_token`.
  - Для устранения рассинхронизации между базой данных и UnitOfWork Doctrine вместо устаревшего `$runRepo->save($run)` выполняется `$entityManager->refresh($run)`. Это гарантирует загрузку актуальных `attempt`, `fencing_token` и `status` в память сущности без выполнения избыточных DML-запросов и без риска перезаписи состояния при последующих `flush()`.
- **Связывание офферов:** `OffersImportHandler` парсит `external_id` (разделитель `#`). Если родительский `Product` отсутствует, но каталог еще импортируется (`hasActiveCatalogImport()`), бросает `RecoverableMessageHandlingException` для повторной обработки через ретраи Messenger.

## 5. Outbox Pattern, CAS Retry Sync & Outbox ACK
- **Архитектурное решение Outbox:** Таблица `import_run_outbox` используется для надежной доставки отложенных и повторных сообщений (`defer` / `retry`) с защитой от дублей через частичный индекс `uniq_outbox_active_defer` на `(run_id, kind) WHERE published_at IS NULL AND acked_at IS NULL`.
- **Синхронизация retry timestamp через CAS4A (`TransportMessageIdStamp`):**
  - При повторной отправке сообщения подписчик `MessengerDispatchSubscriber::onWorkerMessageRetried` перехватывает событие ретрая, извлекает идентификатор созданного транспортного сообщения через `TransportMessageIdStamp` и передает его в `ImportRunRepository::markRunForRetryCas4A`.
  - Метка `retry_scheduled_at` в `import_runs` выставляется атомарным подзапросом `(SELECT available_at AT TIME ZONE 'UTC' FROM messenger_messages WHERE id = :msgId)`. Это гарантирует строгую синхронизацию времени готовности задачи в Messenger с таймстемпом в таблице запусков, исключая race conditions и ложное сжигание попыток при преждевременном потреблении воркером.
- **Штамп трассировки Outbox (`OutboxMessageStamp`):**
  - При публикации доступных записей `OutboxPublisher::publishAvailableMessages()` сериализованный Envelope декодируется и обогащается штампом `App\Messenger\OutboxMessageStamp($outboxId)` (первичный ключ записи `import_run_outbox`).
  - При редиспатче в шину Messenger штамп сохраняется через транспортный сериализатор (`SigningSerializer`).
- **Жизненный цикл точного Outbox ACK и сброс контекста:**
  - Прием сообщения (`WorkerMessageReceivedEvent`): из Envelope считываются `ImportRunStamp` и `OutboxMessageStamp`, инициализируя scoped-состояние в `ImportRunContext`.
  - Успешная обработка (`WorkerMessageHandledEvent`): вызывается строго после завершения хэндлера и перед `$receiver->ack($envelope)`.
    * Первичный путь: при наличии `OutboxMessageStamp` вызывается атомарный `markOutboxAckedById($outboxId)`.
    * Изолированный legacy fallback: при отсутствии штампа вызывается `markOutboxAcked($runId, $attempt)`.
    * По завершении Outbox ACK выставляется флаг `$importRunContext->setHandledSuccessfully(true)`.
  - Fail-closed защита при ошибке ACK: если при вызове `markOutboxAckedById` возникает исключение (например, сбой соединения с БД), оно беспрепятственно всплывает наружу, `handledSuccessfully` не выставляется, предотвращая отправку ложного ACK в транспорт брокера.
  - Очистка контекста и снятие локов (`WorkerRunningEvent`): выполняется в цикле воркера строго после ACK/reject сообщения брокером. В блоке `finally` освобождается сессионный advisory lock (если удерживался) и вызывается `ImportRunContext::reset()`, гарантируя изоляцию между итерациями.
- **Сквозная E2E-верификация (`OffersRetryLifecycleIntegrationTest`):**
  - Подтвержден полный жизненный цикл ретраев Messenger и перемещения в DLQ (`failed`): задержки экспоненциального бэкоффа (3s, 5s, 9s), сохранение штампа `ImportRunStamp` при трансфере в `failed`, успешный последующий импорт офферов и изоляция сущностей `Product`/`ProductVariant`.
  - Результат изолированного прогона: **PASS, 1 test, 38 assertions, exit code 0** (время 20.14 с, память 24.00 МБ).
  - Двухфазный механизм `tearDown()` выполняет адресную очистку по точным базовым именам файлов (`expectedFilenames` на базе `testRunSuffix`), валидирует `createdRunIds` и очищает связанные записи без побочных эффектов для базовых (id=8,9) и диагностических (id=43,44,45,46, message 34,40) артефактов.
- **Сквозная E2E-верификация (`testConcurrentWorkersDeferUnderActiveLockWithoutLoss`):**
  - Подтвержден полный жизненный цикл: CAS-блокировка Worker A -> отказ в блокировке Worker B/C -> регистрация Defer в Outbox с дедупликацией -> публикация через `OutboxPublisher` в физическую очередь `messenger_messages` с простановкой `published_at` -> декодирование через контейнерный `SigningSerializer` -> round-trip штампа `OutboxMessageStamp` -> точечный ACK через `markOutboxAckedById` с фиксацией `acked_at IS NOT NULL`.
  - Результат изолированного прогона: **PASS, 1 test, 14 assertions, exit code 0** (время 84 мс, память 20.00 МБ).
- **Изоляция окружения и тестовых данных:**
  - Тестовая фикстура формирует полноценный Envelope с командой и `ImportRunStamp`, сериализуя его через контейнерный `SigningSerializer` в структуру `['body' => $encoded['body']]` с соответствующими `headers`.
  - В тесте `OutboxLifecycleIntegrationTest` внедрен точный трекинг созданных сущностей: `$createdRunIds` и `$createdMessengerMessageIds`. Метод `tearDown()` выполняет изолированную очистку строго по этим идентификаторам без глобальных `DELETE FROM` и без ошибочных `JOIN` по несуществующим колонкам (`message_id`).
  - Сохранность данных: в `commerce_db_test` гарантированно сохранены исходные записи (`import_runs` id=8, 9; `import_run_outbox` id=7, 8; `messenger_messages` = 0).
+  - Рабочая база `commerce_db` не затрагивалась (14 import_runs, 1 messenger_messages DLQ).
+  - Все сессионные advisory locks теста сняты (активен только штатный воркер рабочей БД).
+  - **Статус адаптации `OutboxLifecycleIntegrationTest`:** все 5 сценариев класса (`testConcurrentWorkersDeferUnderActiveLockWithoutLoss`, `testCas4AAtomicWithOutboxInsert`, `testOutboxPublisherTransfersToMessengerMessages`, `testParallelPublishersSkipLockedWithoutDuplication`, `testBusyAdvisoryLockSkipsRecordWithoutPublishing`) адаптированы под точечную изоляцию: каждый метод регистрирует сгенерированные runId и messageId в `$createdRunIds` / `$createdMessengerMessageIds`, а `tearDown()` выполняет адресную очистку строго созданных записей, сохраняя базовые строки БД.
+
+## 6. Runtime Environments & Isolation
- **Основной стек Compose (`workspace/commerce-integration-hub/compose.yaml`):**
  - Проект Compose: `commerce-integration-hub`.
  - Контейнеры: `commerce_hub_app` (PHP 8.4-FPM), `commerce_hub_web` (Nginx, порт 8080:80), `commerce_hub_postgres` (PostgreSQL 16, порт 5432:5432).
  - Декларированные воркеры: `commerce_hub_worker_catalog`, `commerce_hub_worker_offers` (с флагами `--memory-limit=512M`, `stop_grace_period: 300s`).
- **Тестовый изолированный runtime (`/tmp/commerce-hub-worker-test/`):**
  - Проект Compose: `hub_worker_test`.
  - Контейнеры: `hub_worker_test_app`, `hub_worker_test_web`, `hub_worker_test_postgres`, `hub_worker_test_worker_catalog`, `hub_worker_test_worker_offers`.
  - Запущен параллельно для изоляции интеграционных тестов и нагрузочных прогонов воркеров без интерференции с основным стеком.

## 7. Worker Graceful Shutdown (PCNTL Invariants)
- **Системные требования:** расширение PHP `pcntl` скомпилировано в базовом образе (Alpine, PHP 8.4.27). Подтверждена доступность `pcntl_signal()`, `pcntl_async_signals()` и `SignalRegistry::isSupported() = true`. Контейнеры воркеров сконфигурированы со `stop_grace_period: 300s`.
- **Эмпирическая верификация (стенд `pcntl_stand`):**
  - Окружение: полностью изолированный стенд (`/tmp/commerce-pcntl-test`, проект `pcntl_stand`, БД `commerce_pcntl_db`, порт 5435).
  - Сценарий Catalog: импорт каталога на 4 000 товаров (`prod-pcntl-1` .. `prod-pcntl-4000`), инициированный через `app:cml:import`. Во время подтвержденного статуса `PROCESSING` контейнеру `pcntl_stand_worker_catalog` отправлен сигнал останова через `docker compose stop -t 300 worker_catalog`. Воркер штатно завершил обработку всего стрима, выполнил финальный flush/commit; `ImportRun` (ID=2) переведен в `SUCCESS` с `items_count = 4000`, сообщение подтверждено (ACK, очереди пусты), процесс завершился с `ExitCode = 0`. Контейнер `pcntl_stand_worker_offers` оставался `Up`.
  - Сценарий Offers: импорт предложений на 4 000 элементов (`prod-pcntl-1#var-1` .. `prod-pcntl-4000#var-1`, фикстура SHA-256 `c252cb79e7e7c002b30312a0c148f3c0b9850809e16d4e1ae6d2b98ba211a1de`). Во время подтвержденного статуса `PROCESSING` контейнеру `pcntl_stand_worker_offers` отправлен сигнал останова через `docker compose stop -t 300 worker_offers`. Воркер завершил обработку сообщения, зафиксировал транзакцию; `ImportRun` (ID=3) переведен в `SUCCESS` с `items_count = 4000`, в БД создано ровно 4 000 `ProductVariant`, сообщение подтверждено (ACK, активная и failed очереди пусты), процесс завершился с `ExitCode = 0`. Ранее остановленный `pcntl_stand_worker_catalog` не затрагивался.
  - Анализ и сопоставление сигналов (SIGQUIT vs SIGTERM):
    - Базовый образ `php:8.4-fpm-alpine` объявляет `STOPSIGNAL SIGQUIT`. При выполнении `docker compose stop` демон Docker отправляет сконфигурированный StopSignal — `SIGQUIT` (POSIX signal 3). В обоих тестах под активной нагрузкой (Catalog и Offers по 4 000 записей) Symfony Messenger зафиксировал `Received signal 3. Stopping worker.`, штатно завершил транзакции и вышел с `ExitCode = 0`.
    - Сигнал `SIGTERM` (POSIX signal 15) был явно протестирован через `docker compose kill -s SIGTERM worker_catalog` на простаивающем воркере. В логах зафиксировано `Received signal 15. Stopping worker.`, воркер корректно остановился с `ExitCode = 0`.
    - Поведение `SIGTERM` (15) непосредственно при активной потоковой обработке сообщений ещё не тестировалось (штатная остановка Docker Compose транслирует `SIGQUIT`).
  - Изоляция: основная БД `commerce_db` сохранила полный исходный baseline (110 504 products, 110 503 product_variants, 14 import_runs, 1 сообщение Messenger, 0 изменений).
- **Границы и ограничения проверки:** поведение воркеров при максимальной нагрузке (стресс-тесты под предельным объемом данных/памяти с риском OOM на BacktraceDebugDataHolder) в рамках данных прогонов не тестировалось.
