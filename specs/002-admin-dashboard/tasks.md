# Tasks Breakdown: Operational Admin Dashboard & Management UI

**Branch**: `feature/admin-dashboard` | **Spec**: [specs/002-admin-dashboard/spec.md](spec.md) | **Plan**: [specs/002-admin-dashboard/plan.md](plan.md)

---

## Phase 1: Security & Dashboard Core (P0)

- [ ] **T1.1 [ENV & CONFIG]** Установить и сконфигурировать пакеты `symfony/twig-bundle`, `symfony/security-bundle`, `symfony/asset`, `symfony/rate-limiter`.
- [ ] **T1.2 [SECURITY & PROXY CONFIG]** Создать `config/packages/security.yaml` и обновить `config/packages/framework.yaml`:
  - `framework.trusted_proxies: '%env(TRUSTED_PROXIES)%'` и `framework.trusted_headers: ['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-port']` с ограниченным белым списком IP;
  - Файрвол `cml`: `{ pattern: ^/cml, security: false }` для изоляции контроллера 1C;
  - Файрвол `admin`: `{ pattern: ^/admin, http_basic: ..., login_throttling: { max_attempts: 10, interval: '1 minute' } }`, провайдер пользователей `in_memory` (параметры `ADMIN_USER`, `ADMIN_PASSWORD_HASH` с хэшем bcrypt/argon2id, открытый пароль в `.env` исключен);
  - Требование схемы HTTPS/TLS через trusted proxy.
- [x] **T1.3 [DB SAFETY PATTERN]** Разработать `App\Admin\Database\ReadOnlyTransactionRunner` (или контроллерный trait/listener):
  - Строгий жизненный цикл транзакции: `$conn->beginTransaction()` -> `$conn->executeStatement('SET TRANSACTION READ ONLY')` -> выполнение чтений -> `$conn->commit()` (или `rollback()`);
  - Запрет выполнения каких-либо запросов (включая `SELECT`) до вызова `SET TRANSACTION READ ONLY`.
- [ ] **T1.4 [TEMPLATES]** Разработать базовые Twig-шаблоны с принудительным HTML-автоэкранированием:
  - `templates/admin/base.html.twig` (лейаут, сайдбар, заголовок, изолированные CSS-стили);
  - `templates/admin/components/_metric_card.html.twig`;
  - `templates/admin/components/_status_badge.html.twig`.
- [ ] **T1.5 [CONTROLLER]** Создать `App\Controller\Admin\DashboardController`:
  - Разрешить строго метод `GET`;
  - Внедрить `SystemHealthMonitorService`, выполнить чтение через `ReadOnlyTransactionRunner`, сформировать контекст дашборда и отрендерить `templates/admin/dashboard.html.twig`.
- [ ] **T1.6 [TESTS]** Написать `tests/Functional/Admin/AdminSecurityTest.php`:
  - Проверка `401 Unauthorized` при неверных/отсутствующих кредах;
  - Проверка `200 OK` при валидной Basic Auth с `ADMIN_PASSWORD_HASH`;
  - Проверка `405 Method Not Allowed` при вызове `POST /admin`;
  - Проверка работы за доверенными прокси (`X-Forwarded-Proto: https`);
  - Проверка независимости и изоляции `/cml/1c_exchange`.

---

## Phase 2: Monitoring API & Queues / Outbox View (P1)

- [ ] **T2.1 [CONTROLLER & ROUTE]** Создать `App\Controller\Admin\MonitoringApiController`: маршрут `GET /admin/api/health`, возвращающий сериализованный `SystemHealthOverview` в строгом JSON (`methods: ['GET']`), исполняемый в read-only транзакции.
- [ ] **T2.2 [CONTROLLER]** Создать `App\Controller\Admin\QueueController`: маршрут `GET /admin/queues` (`methods: ['GET']`), рендеринг детального состояния очередей Messenger и задержек Outbox.
- [ ] **T2.3 [TEMPLATES]** Разработать `templates/admin/queues.html.twig` с визуализацией порогов lag / failed messages.
- [ ] **T2.4 [JS POLLING]** Добавить в `dashboard.html.twig` ненавязчивый ванильный JS-скрипт периодического обновления статусов через `/admin/api/health` с защитой от наслоения запросов.
- [ ] **T2.5 [TESTS]** Написать `tests/Functional/Admin/MonitoringApiTest.php` и `tests/Functional/Admin/QueueControllerTest.php` (включая проверку 405 на POST/DELETE).

---

## Phase 3: Import Runs Registry & Details (P1)

- [ ] **T3.1 [REPOSITORY]** Добавить в `App\Repository\ImportRunRepository` метод `findPaginatedRuns(int $page, int $limit, ?string $status, ?string $type): array` с постраничной выборкой по индексу `created_at`.
- [ ] **T3.2 [CONTROLLER]** Создать `App\Controller\Admin\ImportRunController`:
  - `GET /admin/imports` (список с фильтрами и пагинатором);
  - `GET /admin/imports/{id}` (детальная информация, санитизированный `errorMessage`, привязанные события outbox).
- [ ] **T3.3 [TEMPLATES]** Разработать `templates/admin/imports/index.html.twig` и `templates/admin/imports/show.html.twig` (вывод `run.errorMessage` через `MonitoringDataSanitizer` и Twig `escape`).
- [ ] **T3.4 [TESTS]** Написать `tests/Functional/Admin/ImportRunControllerTest.php`:
  - Проверка санитизации паролей, DSN и путей при отображении `errorMessage`;
  - Проверка XSS-безопасности при вредоносных строках в имени файла и ошибках;
  - Проверка `405 Method Not Allowed` при `POST /admin/imports`.

---

## Phase 4: Products & Inventory Catalog (P2)

- [ ] **T4.1 [REPOSITORY]** Добавить в `App\Repository\ProductRepository` двухфазный метод выборки:
  - Шаг 1: `findPaginatedProductIds(int $page, int $limit, ?string $search): int[]`
  - Шаг 2: `findProductsWithVariantsByIds(array $ids): Product[]`
  - Гарантия отсутствия N+1 запросов и искажения пагинации в Doctrine ORM 3.7.
- [ ] **T4.2 [CONTROLLER]** Создать `App\Controller\Admin\ProductController`: маршрут `GET /admin/products` с фильтрацией по SKU/external_id в режиме `READ ONLY`.
- [ ] **T4.3 [TEMPLATES]** Разработать `templates/admin/products/index.html.twig`:
  - Форматирование копеек в рубли без `float`;
  - Точный вывод остатка `NUMERIC(14, 3)`;
  - Защита от XSS в наименованиях и реквизитах товаров.
- [ ] **T4.4 [TESTS]** Написать `tests/Functional/Admin/ProductControllerTest.php` (проверка пагинации, поиска, отсутствия лишних SQL-запросов и автоэкранирования спецсимволов).

---

## Phase 5: Verification & Quality Gates (P0)

- [ ] **T5.1 [PHPSTAN]** Выполнить статический анализ `vendor/bin/phpstan analyse -l 8 src/Controller/Admin` (уровень строгости 8, zero errors).
- [ ] **T5.2 [PHPUNIT]** Запустить полный набор тестов PHPUnit, проверить 100% прохождения существующих тестов ядра и новых админ-тестов.
- [ ] **T5.3 [SECURITY AUDIT]** Финальная проверка изоляции `/cml/**` и `/admin/**`, аудит rate limiting и HTTP-заголовков безопасности.
