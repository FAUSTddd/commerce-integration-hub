# Commerce Integration Hub — Core v1

Enterprise-сервис синхронизации товарных каталогов и предложений CommerceML 2 (1C) на стеке Symfony 7.4 + PHP 8.4 + PostgreSQL 16.

## Требования к окружению

- Docker Engine 24+ и Docker Compose v2 (или Podman с docker-compose-plugin).
- Локально установленный PHP/PostgreSQL на хосте не требуется: всё исполняется внутри контейнеров.

## Архитектура стека

- **App**: PHP 8.4-FPM (Alpine) с расширениями `pdo_pgsql`, `opcache`, `intl`, `zip`.
- **Web**: Nginx (Alpine), проксирующий FastCGI запросы на `app:9000` (порт `8080` на хосте).
- **PostgreSQL**: PostgreSQL 16 (Alpine) с томом `postgres_data` (порт `5432` на хосте).

## Быстрый запуск (Development)

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

## Production-развёртывание и эксплуатация

Для изоляции инфраструктуры и безопасности в продакшене используется оверлей `compose.prod.yaml`, сбрасывающий внешнюю публикацию порта PostgreSQL (`5432: !reset []`):

### 1. Запуск стека в Production
```bash
docker compose -f compose.yaml -f compose.prod.yaml up -d --build
```

### 2. Секреты CML (CML_USER и CML_PASSWORD)
Учетные данные CML для production шифруются через Symfony Secrets Vault:
```bash
# Генерация ключей шифрования (ключ расшифровки prod.decrypt.private.php не коммитится в Git!):
docker compose -f compose.yaml -f compose.prod.yaml exec -T app php bin/console secrets:generate-keys --env=prod

# Сохранение учетных данных в зашифрованное хранилище:
docker compose -f compose.yaml -f compose.prod.yaml exec -T app php bin/console secrets:set CML_USER --env=prod
docker compose -f compose.yaml -f compose.prod.yaml exec -T app php bin/console secrets:set CML_PASSWORD --env=prod
```
В production-окружении ключ расшифровки передается через защищенную переменную окружения хоста/оркестратора `SYMFONY_DECRYPTION_SECRET` либо монтируется как секретный файл.

### 3. Бесшовный деплой (Zero-Downtime Drain Pipeline)
Для деплоя без потери in-flight транзакций и активных задач очередей:
```bash
./deploy_drain.sh
```
Скрипт автоматически использует `-f compose.yaml -f compose.prod.yaml` для всех операций управления жизненным циклом контейнеров.

### 4. Регламент первого production-запуска (Production Deployment Checklist)

Пошаговый регламент и критерии готовности первого запуска:

1. **Экспорт и валидация Production-секретов**:
   ```bash
   export SYMFONY_DECRYPTION_SECRET="<base64_secret_key>"
   docker compose -f compose.yaml -f compose.prod.yaml config --quiet
   ```
   - *Критерий PASS*: код возврата `0`. При отсутствии ключа Compose аварийно завершается (`Production decryption secret is required`).

2. **Резервная копия PostgreSQL и валидация архива**:
   ```bash
   # Снятие дампа через бинарный поток (STDOUT хоста):
   docker compose exec -T postgres pg_dump -U commerce_user -Fc commerce_db > /tmp/backup_commerce_db_pre_launch.dump

   # Полная проверка целостности архива и оглавления дампа внутри контейнера СУБД:
   docker compose exec -T postgres pg_restore -l < /tmp/backup_commerce_db_pre_launch.dump > /dev/null
   ```
   - *Критерий PASS*: файл дампа создан и не пуст; `pg_restore -l` завершается с кодом `0` без обрыва конвейера.

3. **Dry-Run миграций на изолированной копии данных**:
   ```bash
   # Подготовка тестовой базы с явным подключением к сервисной БД postgres:
   docker compose exec -T postgres psql -U commerce_user -d postgres -c "DROP DATABASE IF EXISTS commerce_migration_dry_run;"
   docker compose exec -T postgres psql -U commerce_user -d postgres -c "CREATE DATABASE commerce_migration_dry_run WITH OWNER commerce_user;"

   # Восстановление дампа в копию с прямым перенаправлением ввода (защита от маскирования ошибок при pipefail) и флагом --exit-on-error:
   docker compose exec -T postgres pg_restore -U commerce_user -d commerce_migration_dry_run --exit-on-error < /tmp/backup_commerce_db_pre_launch.dump

   # Прогон миграций на копии данных через контейнер приложения:
   docker compose -f compose.yaml -f compose.prod.yaml run --rm --no-deps \
     -e DATABASE_URL="postgresql://commerce_user:secret_password@postgres:5432/commerce_migration_dry_run?serverVersion=16&charset=utf8" \
     app php bin/console doctrine:migrations:migrate --env=prod --no-interaction

   # Очистка изолированной копии:
   docker compose exec -T postgres psql -U commerce_user -d postgres -c "DROP DATABASE commerce_migration_dry_run;"
   ```
   - *Критерий PASS*: восстановление дампа и миграции завершены с кодом `0`.

4. **Строгий порядок запуска сервисов (Сначала схема, затем воркеры)**:
   ```bash
   # Шаг 4.1: Старт СУБД и контейнера ядра:
   docker compose -f compose.yaml -f compose.prod.yaml up -d --no-deps postgres app

   # Шаг 4.2: Применение проверенных миграций к рабочей БД:
   docker compose -f compose.yaml -f compose.prod.yaml exec -T app php bin/console doctrine:migrations:migrate --env=prod --no-interaction

   # Шаг 4.3: Старт фоновых обработчиков строго ПОСЛЕ обновления схемы:
   docker compose -f compose.yaml -f compose.prod.yaml up -d --no-deps outbox_publisher worker_catalog worker_offers

   # Шаг 4.4: Валидация сетевой изоляции СУБД:
   ss -tulpn | grep 5432 || true
   ```
   - *Критерий PASS*: порт 5432 не слушает хостовые интерфейсы, сервисы запущены.

5. **Проверка состояния по строгому JSON (Monitor Status)**:
   ```bash
   STATUS_JSON=$(docker compose -f compose.yaml -f compose.prod.yaml exec -T app php bin/console monitor:status --env=prod --format=json)
   echo "$STATUS_JSON" | php -r '
     $data = json_decode(stream_get_contents(STDIN), true);
     if (!isset($data["status"]) || $data["status"] !== "HEALTHY") {
       fwrite(STDERR, "FAIL: status is not HEALTHY
");
       exit(1);
     }
     if (!empty($data["anomalies"])) {
       fwrite(STDERR, "FAIL: anomalies detected
");
       exit(1);
     }
     if (($data["queues"]["failed"]["size"] ?? 0) > 0) {
       fwrite(STDERR, "FAIL: failed queue has messages
");
       exit(1);
     }
     echo "STATUS: PASS
";
   '
   ```
   - *Критерий PASS*: вывод `STATUS: PASS`.

6. **Запуск Ingress трафика**:
   ```bash
   docker compose -f compose.yaml -f compose.prod.yaml up -d --no-deps web
   curl -s -o /dev/null -w "%{http_code}
" "http://localhost:8080/cml/1c_exchange?type=catalog&mode=checkauth"
   ```
   - *Критерий PASS*: HTTP статус 401 или 200.

7. **Регламент безопасного Rollback**:
   ```bash
   # Шаг 7.1: Остановка веб-трафика со штатным grace period 900s:
   docker compose -f compose.yaml -f compose.prod.yaml stop -t 900 web

   # Шаг 7.2: Остановка фоновых воркеров со штатными grace period:
   docker compose -f compose.yaml -f compose.prod.yaml stop -t 900 worker_catalog
   docker compose -f compose.yaml -f compose.prod.yaml stop -t 300 worker_offers
   docker compose -f compose.yaml -f compose.prod.yaml stop -t 30 outbox_publisher

   # Шаг 7.3: Отключение активных соединений с явным подключением к сервисной БД postgres:
   docker compose exec -T postgres psql -U commerce_user -d postgres -c "
     SELECT pg_terminate_backend(pid) FROM pg_stat_activity 
     WHERE datname IN ('commerce_db', 'commerce_db_failed_pre_rollback') AND pid <> pg_backend_pid();
   "

   # Шаг 7.4: Проверка существования архивной БД без автоматического DROP (защита от потери расследовательских данных):
   docker compose exec -T postgres psql -U commerce_user -d postgres -v ON_ERROR_STOP=1 -c "
     DO \$\$
     BEGIN
       IF EXISTS (SELECT 1 FROM pg_database WHERE datname = 'commerce_db_failed_pre_rollback') THEN
         RAISE EXCEPTION 'Target archive database commerce_db_failed_pre_rollback already exists. Manual intervention required to prevent data loss.';
       END IF;
     END
     \$\$;
   "

   # Шаг 7.5: Ротация БД и восстановление через явное подключение к postgres и прямой ввод:
   docker compose exec -T postgres psql -U commerce_user -d postgres -c "ALTER DATABASE commerce_db RENAME TO commerce_db_failed_pre_rollback;"
   docker compose exec -T postgres psql -U commerce_user -d postgres -c "CREATE DATABASE commerce_db WITH OWNER commerce_user;"
   docker compose exec -T postgres pg_restore -U commerce_user -d commerce_db --exit-on-error < /tmp/backup_commerce_db_pre_launch.dump
   ```
   - *Критерий PASS*: код возврата `0`; сбойная БД сохранена как `commerce_db_failed_pre_rollback` для постмортема.

### 5. Запуск всех тестов (Unit + PostgreSQL Integration)
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
