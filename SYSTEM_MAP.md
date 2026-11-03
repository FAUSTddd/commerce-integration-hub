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
- **Связывание офферов:** `OffersImportHandler` парсит `external_id` (разделитель `#`). Если родительский `Product` отсутствует, но каталог еще импортируется (`hasActiveCatalogImport()`), бросает `RecoverableMessageHandlingException` для повторной обработки через ретраи Messenger.

## 5. Runtime Environments & Isolation
- **Основной стек Compose (`workspace/commerce-integration-hub/compose.yaml`):**
  - Проект Compose: `commerce-integration-hub`.
  - Контейнеры: `commerce_hub_app` (PHP 8.4-FPM), `commerce_hub_web` (Nginx, порт 8080:80), `commerce_hub_postgres` (PostgreSQL 16, порт 5432:5432).
  - Декларированные воркеры: `commerce_hub_worker_catalog`, `commerce_hub_worker_offers` (с флагами `--memory-limit=512M`, `stop_grace_period: 300s`).
- **Тестовый изолированный runtime (`/tmp/commerce-hub-worker-test/`):**
  - Проект Compose: `hub_worker_test`.
  - Контейнеры: `hub_worker_test_app`, `hub_worker_test_web`, `hub_worker_test_postgres`, `hub_worker_test_worker_catalog`, `hub_worker_test_worker_offers`.
  - Запущен параллельно для изоляции интеграционных тестов и нагрузочных прогонов воркеров без интерференции с основным стеком.

## 6. Worker Graceful Shutdown (PCNTL Invariants)
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
