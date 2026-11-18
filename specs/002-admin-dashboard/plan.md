# Implementation Plan: Operational Admin Dashboard & Management UI

**Branch**: `feature/admin-dashboard` | **Spec**: [specs/002-admin-dashboard/spec.md](spec.md)

## 1. Summary & Architecture Overview

План ввода административной веб-панели для Commerce Integration Hub. Панель строится по модульному принципу поверх существующих доменных сущностей и сервисов оперативного наблюдения (`SystemHealthMonitorService`, `MonitoringQueryRepository`, `MonitoringDataSanitizer`). Вся логика строго read-only.

Архитектура UI опирается на стандартный MVC-подход Symfony:
- **Routing & Security Isolation**:
  - Файрвол `cml`: паттерн `^/cml`, `security: false` (приоритетная изоляция обмена 1C, обрабатываемого `faustddd/symfony-1c-import`).
  - Файрвол `admin`: паттерн `^/admin`, `http_basic`, обязательный TLS за trusted proxy (`X-Forwarded-Proto`), rate limiting от brute-force.
  - Аутентификация: `ADMIN_USER` и хэшированный пароль `ADMIN_PASSWORD_HASH` (bcrypt/argon2id), открытый пароль в `.env` категорически исключен.
  - Доверенные прокси: явная конфигурация `framework.trusted_proxies` и `framework.trusted_headers` в `config/packages/framework.yaml` с ограниченным белым списком адресов (локальный Nginx / Docker network).
- **Service & Database Safety**:
  - Чтение выполняется в контексте безопасного жизненного цикла транзакции PostgreSQL:
    `$connection->beginTransaction()` -> `$connection->executeStatement('SET TRANSACTION READ ONLY')` -> выполнение DQL/SQL чтений -> `$connection->commit()` (или `rollback()`). Категорически запрещены любые запросы (включая предварительные `SELECT`) до вызова `SET TRANSACTION READ ONLY`.
  - Вызовы `flush()` категорически исключены из кода админки.
  - Оптимизированные запросы: двухфазная пагинация каталога во избежание N+1 и деградации Doctrine ORM 3.7.
- **View Layer**: Twig-шаблоны с компонентной структурой (`base.html.twig`, сайдбар, карточки метрик, бейджи статусов, автоэкранирование HTML/XSS).

## 2. Technical Stack & Dependencies

- **PHP**: `8.4` (composer lock `7.4.x` compatibility)
- **Symfony Framework**: `v7.4.*`
- **Новые зависимости к установке (Composer)**:
  - `symfony/twig-bundle` (`^7.4`)
  - `twig/twig` (`^3.19` или `^3.20`)
  - `symfony/security-bundle` (`^7.4`)
  - `symfony/asset` (`^7.4`)
  - `symfony/rate-limiter` (`^7.4`) (для защиты Basic Auth)
- **База данных**: PostgreSQL 16 (доступ по существующему `DATABASE_URL`).

## 3. Database Indexes & Query Safety Matrix

Сверка с существующими миграциями проекта:
1. `products`:
   - `UNIQ_B3BA5A5A9F75D7B0 (external_id)` -> точечный поиск O(1).
   - `idx_products_sku (sku)` -> поиск по артикулу O(log N).
   - *Подтвержденное предположение*: Сортировка по `id DESC` опирается на первичный ключ `products_pkey`.
2. `product_variants`:
   - `idx_product_variants_product_id (product_id)` -> быстрая двухфазная выборка вариантов по ID родительских товаров.
3. `import_runs`:
   - `idx_import_runs_status (status)` -> фильтрация активных/упавших импортов.
   - `idx_import_runs_created_at (created_at)` -> эффективная пагинация последних запусков по времени.
4. `import_run_outbox`:
   - Частичный индекс `idx_outbox_unpublished` -> мгновенная выборка лага и непубликованных сообщений.

## 4. Phased Implementation Roadmap

### Phase 1: Security & Dashboard Core (P0)
- Установка и регистрация `twig-bundle`, `security-bundle`, `asset`, `rate-limiter`.
- Конфигурация `config/packages/security.yaml` и `config/packages/framework.yaml`:
  - `trusted_proxies: '%env(TRUSTED_PROXIES)%'`, `trusted_headers: ['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-port']`;
  - `cml: { pattern: ^/cml, security: false }`;
  - `admin: { pattern: ^/admin, http_basic: ..., login_throttling: ... }`;
  - Провайдер `in_memory` с `ADMIN_USER` и хэшем `ADMIN_PASSWORD_HASH`.
- Реализация `ReadOnlyTransactionMiddleware` или контроллерного хелпера: `BEGIN` -> `SET TRANSACTION READ ONLY` -> `SELECT` -> `COMMIT`.
- Базовый layout Twig (`templates/admin/base.html.twig`) с автоэкранированием спецсимволов.
- `DashboardController`: агрегация среза через `SystemHealthMonitorService` и рендеринг виджетов состояния.
- Веб-тесты доступа и безопасности (`AdminSecurityTest`: 401 на неверные креды, 200 на валидные, 405 на POST-запросы, изоляция `/cml`).

### Phase 2: Monitoring API & Queues / Outbox View (P1)
- `MonitoringController`: эндпоинт `/admin/api/health` со строгим JSON ответом и проверкой прав.
- `QueueController`: страница `/admin/queues` со срезом очередей Messenger (`async_catalog`, `async_offers`, `failed`) и задержкой Outbox.
- Интеграция легковесного JS polling для автообновления виджетов дашборда.

### Phase 3: Import Runs Registry & Details (P1)
- `ImportRunController`: маршрут `/admin/imports` с пагинацией и фильтрами по статусу.
- Маршрут `/admin/imports/{id}`: карточка запуска, санитизированный стек-трейс (`MonitoringDataSanitizer::sanitizeErrorMessage(run.errorMessage)`), привязка к outbox записям.
- Web-тесты экранов импортов и санитизации ошибок на UI.

### Phase 4: Products & Inventory Catalog (P2)
- Метод `ProductRepository::findPaginatedWithVariants(int $page, int $limit)`:
  - Шаг 1: `SELECT p.id FROM Product p ORDER BY p.id DESC LIMIT :limit OFFSET :offset`
  - Шаг 2: `SELECT p, v FROM Product p LEFT JOIN p.variants v WHERE p.id IN (:ids)`
- `ProductController`: страница `/admin/products` с форматированием цен (копейки -> рубли) и строгим отображением остатков `NUMERIC(14, 3)`.
- Поиск по SKU и 1C ID.

## 5. Non-Functional & Verification Criteria
- Все маршруты `/admin/**` возвращают `401 Unauthorized` без валидных учетных данных.
- Модифицирующие HTTP-методы (`POST`, `PUT`, `DELETE`, `PATCH`) на `/admin/**` возвращают `405 Method Not Allowed`.
- Ни один DQL/SQL запрос в контроллерах админки не выполняет `UPDATE`, `DELETE` или `INSERT`, вызовы `flush()` отсутствуют.
- Запросы защищены лимитами времени (`statement_timeout = 250ms` для мониторинга, до `1000ms` для каталога).
- Полное тестовое покрытие (WebTestCase) для проверки HTTP статусов, защищенности, запрета методов и корректного экранирования HTML/XSS.
