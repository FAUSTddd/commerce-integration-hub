# Commerce Integration Hub — Core v1

Enterprise-сервис синхронизации товарных каталогов и предложений CommerceML 2 (1C) на стеке Symfony 7.4 + PHP 8.4 + PostgreSQL 16.

## Требования к окружению

- Docker Engine 24+ и Docker Compose v2 (или Podman с docker-compose-plugin).
- Локально установленный PHP/PostgreSQL на хосте не требуется: всё исполняется внутри контейнеров.

## Архитектура стека

- **App**: PHP 8.4-FPM (Alpine) с расширениями `pdo_pgsql`, `opcache`, `intl`, `zip`.
- **Web**: Nginx (Alpine), проксирующий FastCGI запросы на `app:9000` (порт `8080` на хосте).
- **PostgreSQL**: PostgreSQL 16 (Alpine) с томом `postgres_data` (порт `5432` на хосте).

## Быстрый запуск

### 1. Поднятие сервисов
```bash
docker compose up -d --build
```

### 2. Применение миграций схемы БД
```bash
docker compose exec -T app php bin/console doctrine:migrations:migrate --no-interaction
```

### 3. Валидация синхронизации схемы ORM с СУБД
```bash
docker compose exec -T app php bin/console doctrine:schema:validate
```
> **Важно (Намеренное исключение Schema Drift):**
> Команда `doctrine:schema:validate` сообщает о расхождении и предлагает удалить индекс `uniq_import_runs_active_type_filename` (`[ERROR] The database schema is not in sync with the current mapping file`).
> Это **ожидаемое поведение и намеренное архитектурное исключение**: данный частичный индекс (`WHERE status IN ('PENDING', 'PROCESSING')`) создан миграцией `Version20261102000001` и критически необходим для атомарной дедупликации очередей в PostgreSQL (`ON CONFLICT ... DO NOTHING`). Декларативный маппинг Doctrine ORM не поддерживает предикаты `WHERE` для индексов.
> **Индекс не должен удаляться. Выполнение команды `doctrine:schema:update --force` категорически запрещено.** Все изменения схемы производятся строго через миграции.

### 4. Запуск всех тестов (Unit + PostgreSQL Integration)
Создание тестовой БД при первом старте:
```bash
docker compose exec -T postgres psql -U commerce_user -d commerce_db -c "CREATE DATABASE commerce_db_test;" 2>/dev/null || true
```
Запуск тестового набора:
```bash
docker compose exec -T app php bin/phpunit
```

### 5. Ручной запуск импорта CML через CLI
```bash
# Импорт каталога товаров:
docker compose exec -T app php bin/console app:cml:import tests/Fixtures/import_catalog.xml

# Импорт торговых предложений, цен и остатков:
docker compose exec -T app php bin/console app:cml:import tests/Fixtures/import_offers.xml
```

## Проверка данных в БД
```bash
docker compose exec -T postgres psql -U commerce_user -d commerce_db -c "SELECT id, external_id, sku, name FROM products;"
docker compose exec -T postgres psql -U commerce_user -d commerce_db -c "SELECT id, external_id, price_amount, stock, currency FROM product_variants;"
```

## Результаты нагрузочного тестирования и профилирования памяти

Проведены нагрузочные испытания потокового импорта каталога товаров при фиксированном `memory_limit = 128M` на разных объемах данных и конфигурациях окружения Symfony.

### Сравнительные метрики прогонов

| Параметр / Метрика | Тест 1 (500 товаров, `dev`) | Тест 2 (10 000 товаров, `dev`) | Тест 3 (10 000 товаров, `prod`) |
| :--- | :--- | :--- | :--- |
| **Окружение** | `APP_ENV=dev`, `debug=true` | `APP_ENV=dev`, `debug=true` | `APP_ENV=prod`, `debug=false` |
| **Файл фикстуры** | `var/import/loadtest_500_catalog.xml` (106 КБ) | `var/import/loadtest_10k_catalog.xml` (2.2 МБ) | `var/import/loadtest_10k_prod_catalog.xml` (2.3 МБ) |
| **Команда постановки** | `bin/console app:cml:import ... --type=catalog` | `bin/console app:cml:import ... --type=catalog` | `bin/console app:cml:import ... --type=catalog -e prod --no-debug` |
| **Команда воркера** | `/usr/bin/time -v bin/console messenger:consume async_catalog --limit=1` | `/usr/bin/time -v bin/console messenger:consume async_catalog --limit=1` | `/usr/bin/time -v bin/console messenger:consume async_catalog --limit=1 -e prod --no-debug` |
| **Время выполнения (Elapsed)** | **0.24 с** | **1.55 с** (авария OOM) | **1.45 с** |
| **Peak RSS процесса** | **58.1 МБ** (59 500 KB) | **152.2 МБ** (155 844 KB) | **40.7 МБ** (41 708 KB) |
| **CPU %** | 71% | Не завершён (фатальная ошибка PHP OOM, Exit 255) | 44% (0.51s user, 0.13s sys) |
| **Throughput** | ~2 083 тов/с (приблизительная скорость всего прогона по общему времени команды) | 0 тов/с (откат транзакции) | **~6 896 тов/с** |
| **Статус `ImportRun`** | `SUCCESS` (500 items) | `FAILED` (0 items, транзакция откатана) | `SUCCESS` (10 000 items) |

### Анализ инцидента OOM в окружении `dev` и поведение в `prod`

1. **Причина исчерпания памяти в `dev` (Тест 2):**
   - При обработке пакетов большого объема в окружении `dev` (`kernel.debug=true`) сервис `Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder::addQuery()` регистрирует полный PHP-стек вызовов (`debug_backtrace`) для каждого SQL-запроса (`SELECT` проверки внешнего ID и `INSERT`).
   - На объеме 10 000 товаров совокупный размер накопленных бэктрейсов превысил лимит PHP в 128 МБ (`134217728 bytes`), вызвав фатальную ошибку памяти (`Allowed memory size exhausted in BacktraceDebugDataHolder.php:43`).
   - **Целостность данных:** благодаря единой транзакции на импорт всего файла СУБД PostgreSQL выполнила автоматический откат (`ROLLBACK`). В таблице `products` осталось 0 записей от упавшего прогона.
2. **Результат оптимизации в `prod` (Тест 3):**
   - При запуске с флагами `-e prod --no-debug` сервис `doctrine.dbal.debug_middleware` исключается из стека middleware DBAL.
   - Потоковый парсинг `XMLReader` в сочетании с батчингом (`flush` и `clear` каждые 100 сущностей) в production-тесте на 10 000 товаров показал Peak RSS воркера на уровне **40.7 МБ**, однако этого единичного измерения недостаточно для строгого доказательства константного масштабирования памяти на произвольных объемах; скорость обработки составила **~6 896 товаров/сек**.

### Регламент разрешения инцидента (ImportRun id=2 и очередь Messenger)

При фатальном падении процесса воркера из-за OOM (Exit 255) штатные перехватчики Messenger не успевают перенаправить сообщение в `failed`, в результате чего:
- Запись `import_runs id=2` осталась в статусе `PROCESSING`.
- Сообщение `messenger_messages id=2` зависло с установленным `delivered_at`.

Для исключения повторной обработки устаревшего сообщения воркером по истечении `redeliver_timeout` (3600 с) выполнена атомарная процедура закрытия с блокировками строк:
1. `import_runs id=2` переведен в статус `FAILED` с фиксацией времени завершения и диагностического сообщения об ошибке OOM.
2. Сообщение `id=2` удалено из таблицы `messenger_messages`.
3. Подтверждена целостность таблицы товаров (10 503 записи) и сохранность успешного запуска `import_runs id=3` (10 000 товаров, `SUCCESS`).
