# Research: Production Observability & Operational Monitoring

**Feature**: `001-production-observability`
**Target**: Commerce Integration Hub (CIH)
**Date**: 2026-11-10

## Decision 1: Query Execution Model & Interface
- **Decision**: Реализовать внутренний сервис агрегации среза (`SystemHealthMonitorService` / `MonitoringRepository`) и консольную команду Symfony CLI (`bin/console monitor:status` / `app:monitoring:status`), поддерживающую форматы вывода human-readable text и `--format=json`.
- **Rationale**: CLI-команда полностью покрывает потребности операторов и дежурных инженеров (US1–US5) без необходимости открывать внешний сетевой периметр и без внедрения Symfony Security Bundle (Non-Goal 1). Флаг `--format=json` обеспечивает бесшовную интеграцию со скриптами автоматизации и внешними системами мониторинга (Zabbix/Telegraf/Cron).
- **Alternatives Considered**:
  - *HTTP API эндпоинт*: Отклонено для первой фазы, так как контроллер без Symfony Security Bundle создаёт риски несанкционированного доступа, либо требует отдельного механизма аутентификации, что противоречит минимизации сложности.

## Decision 2: Messenger Queue Depth & In-Flight Semantics
- **Decision**: Для очередей `async_catalog` и `async_offers` снимать показатели одним неблокирующим SQL-запросом к таблице `messenger_messages` с категоризацией:
  1. `pending`: `delivered_at IS NULL AND available_at <= NOW()`
  2. `in_flight`: `delivered_at IS NOT NULL`
  3. `delayed`: `delivered_at IS NULL AND available_at > NOW()`
  4. `failed`: `queue_name = 'failed'`
- **Rationale**: В Doctrine Messenger поле `delivered_at` выставляется при взятии сообщения воркером. При падении воркера сообщение не разблокируется мгновенно, а ожидает `redeliver_timeout` (5400s для catalog, 900s для offers). Разделение этих состояний в точности соответствует FR-002 и не создает ложных предположений о статусе воркеров.
- **Alternatives Considered**:
  - *Использование Symfony Messenger Receiver::getMessageCount()*: Отклонено, так как стандартный интерфейс не разделяет in-flight, delayed и pending сообщения, а также не предоставляет возраст старейшего сообщения.

## Decision 3: Worker Liveness vs Starvation Detection
- **Decision**: Использовать неинвазивную эвристику `QueueStarvationIndicator` без требования отсутствующих в схеме полей (`last_activity_at`) и без обращения к Docker/OS API.
- **Rationale**: Во-первых, воркеры CIH обрабатывают крупные XML-файлы потоково через `XMLReader` в рамках единой долгоживущей транзакции (до 5400 секунд), не производя промежуточных записей в очередей. Во-вторых, мониторинг должен быть строго decoupled (Const-7) и работать в среде без привилегий доступа к сокетам Docker или процессу ОС. Состояние starvation констатируется только при наличии готовых сообщений (`pending > 0`) и отсутствии активных `ImportRun` в статусе `PROCESSING` дольше контрольного порога.
- **Alternatives Considered**:
  - *Хранение heartbeat воркеров в БД*: Отклонено, так как требует модификации схемы БД, внедрения периодических фоновых транзакций воркера и рискует нарушить целостность соединений во время потокового разбора XML.
  - *Опрос процессов ОС (exec ps/docker)*: Отклонено, так как создает жесткую зависимость от инфраструктуры хоста и нарушает переносимость CLI-команды.

## Decision 4: Safe Data Sanitization
- **Decision**: Нормализация `filename` через `basename()` (исключение системных директорий вроде `var/import/` или абсолютных путей) и многоуровневая регулярная фильтрация `last_error` (маскирование DSN, паролей, токенов, абсолютных путей сервера `/home/...`, `/var/...` и XML-разметки `<...`).
- **Rationale**: Соответствует требованиям FR-001, AC-001, AC-009 и Const-6. Предотвращает утечку конфиденциальных данных и внутренней топологии сервера во внешние консоли и логи оператора.
- **Alternatives Considered**:
  - *Полное скрытие текста ошибки*: Отклонено, так как оператор лишается возможности первично классифицировать сбой (например, валидация схемы CommerceML против таймаута сети).

## Decision 5: Read-Only DB Bounds & Isolation Semantics
- **Decision**: Все запросы мониторинга выполняются в транзакции уровня `READ COMMITTED` с установкой локального `SET LOCAL statement_timeout = '2000'` (2 секунды) без хинтов блокировок.
- **Rationale**: Гарантирует абсолютную безопасность для параллельных транзакций импорта (пакеты по 100 сущностей с `flush()`/`clear()`). Уровень `READ COMMITTED` гарантирует согласованность внутри каждого отдельного `SELECT`, а разделение запросов исключает удержание snapshot и bloat в PostgreSQL MVCC.
- **Alternatives Considered**:
  - *`REPEATABLE READ` транзакция*: Отклонено, так как может приводить к ошибкам сериализации и удерживает старый снимок данных при медленных соединениях.
