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
