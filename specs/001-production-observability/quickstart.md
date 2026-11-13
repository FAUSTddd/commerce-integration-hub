# Quickstart & Verification Guide: Production Observability

**Feature**: `001-production-observability`
**Target**: Commerce Integration Hub (CIH)
**Date**: 2026-11-10

Данное руководство описывает сценарии верификации работы мониторинга без вмешательства в рабочие данные и без нарушения Project Constitution v1.1.0.

---

## Scenario 1: Штатный срез состояния (Human-Readable)

### Шаги
1. Выполнить команду мониторинга:
   ```bash
   bin/console monitor:status
   ```
2. **Ожидаемый результат**:
   - Команда завершается с exit code `0` (или `2` при наличии критических аномалий/сбоев БД).
   - Выводится компактный текстовый отчет со статусом (`HEALTHY`, `DEGRADED`, `CRITICAL`).
   - Отображаются активные запуски `ImportRun` (`PENDING`, `PROCESSING`).
   - Отображаются раздельные счетчики для `async_catalog` и `async_offers` (pending, in_flight, delayed).
   - Выводится состояние Outbox и транспорта `failed`.
   - Имена файлов обрезаны до базового имени (`basename`), в ошибках и аномалиях отсутствуют пароли, DSN и абсолютные пути.

---

## Scenario 2: Машиночитаемый JSON-вывод

### Шаги
1. Выполнить команду с форматом JSON:
   ```bash
   bin/console monitor:status --format=json
   ```
2. **Ожидаемый результат**:
   - Вывод валидируется стандартным JSON-парсером (`jq .`).
   - Структура полностью соответствует контракту `specs/001-production-observability/contracts/cli-contract.md`.
   - Отсутствуют переносы строк и управляющие символы в тексте санитизированных ошибок.

---

## Scenario 3: Граничный кейс: Пустая база данных

### Шаги
1. Запустить мониторинг в тестовом окружении с чистой БД.
2. **Ожидаемый результат**:
   - Exit code `0`.
   - Статус: `HEALTHY`.
   - Все счетчики равны `0`, поля возрастов старейших сообщений равны `null` или `0`.
   - Отсутствуют PHP Warnings и исключения деления на ноль.

---

## Scenario 4: Проверка санитизации данных (Data Sanitization Verification)

### Шаги
1. Смоделировать в изолированном unit/integration-тесте запись `ImportRun` со сбоем:
   - `filename`: `/var/www/cml_storage/customer_secrets/import.xml`
   - `last_error`: `PDOException: Connection failed: postgresql://admin:super_secret_pwd@127.0.0.1:5432/cml_db at /var/www/src/Repository/RunRepository.php:42. Payload: <CommerceML><Client Inn="7701234567"/></CommerceML>`
2. Запросить срез мониторинга.
3. **Ожидаемый результат**:
   - `filename`: `import.xml`.
   - `last_error`: `super_secret_pwd`, IP-адрес, абсолютный путь `/var/www/...` и XML `<CommerceML...` вырезаны или замаскированы.

---

## Scenario 5: Проверка неинвазивности (Read-Only & Bounds)

### Шаги
1. Запустить команду мониторинга параллельно с активным потоком импорта.
2. Проверить в `pg_stat_activity`, что запрос мониторинга:
   - Не удерживает эксклюзивных блокировок (`AccessExclusiveLock`, `RowExclusiveLock`).
   - Завершается быстрее 500 мс.
   - Не блокирует транзакцию воркера, выполняющую `claimRunCas2` или точечный Outbox ACK.
