#!/usr/bin/env bash
set -Ee

WEB_STOPPED=0
PUBLISHER_STOPPED=0

# Поддержка production compose файлов
COMPOSE_PROD_ARGS="-f compose.yaml -f compose.prod.yaml"

cleanup() {
    local rc=$?
    trap - ERR EXIT
    if [ "$rc" -ne 0 ]; then
        if [ "$PUBLISHER_STOPPED" -eq 1 ]; then
            echo "[DEPLOY CLEANUP] Pipeline terminated abnormally (exit code $rc). Restoring outbox publisher..." >&2
            if ! docker compose $COMPOSE_PROD_ARGS up -d --no-deps outbox_publisher 2>&1; then
                echo "[DEPLOY CLEANUP][CRITICAL] Failed to restore outbox_publisher container! Manual intervention required." >&2
            fi
        fi
        if [ "$WEB_STOPPED" -eq 1 ]; then
            echo "[DEPLOY CLEANUP] Pipeline terminated abnormally (exit code $rc). Restoring web ingress..." >&2
            if ! docker compose $COMPOSE_PROD_ARGS up -d --no-deps web 2>&1; then
                echo "[DEPLOY CLEANUP][CRITICAL] Failed to restore web ingress container! Manual intervention required." >&2
            fi
        fi
    fi
    exit "$rc"
}

trap cleanup EXIT
trap 'rc=$?; echo "[DEPLOY ERROR] Pipeline failed at line $LINENO! Halting deployment." >&2; exit "$rc"' ERR
trap 'exit 130' INT
trap 'exit 143' TERM

# 1. Graceful Ingress Stop (PID 1 Nginx штатно завершает активные HTTP-запросы)
WEB_STOPPED=1
docker compose $COMPOSE_PROD_ARGS stop -t 900 web

# 2. Остановка Outbox Publisher с ожиданием graceful shutdown (30s)
PUBLISHER_STOPPED=1
docker compose $COMPOSE_PROD_ARGS stop -t 30 outbox_publisher

# 3. Ожидание завершения открытых PostgreSQL-транзакций (30s таймаут с дампом при сбое)
docker compose $COMPOSE_PROD_ARGS exec -T app php -r '
require_once "/var/www/html/vendor/autoload.php";
$kernel = new App\Kernel("prod", false);
$kernel->boot();
$c = $kernel->getContainer()->get("doctrine.dbal.default_connection");
$s = time();
while (true) {
    $q = "SELECT pid, application_name, state, xact_start, query FROM pg_stat_activity WHERE pid <> pg_backend_pid() AND datname = current_database() AND xact_start IS NOT NULL";
    $rows = $c->fetchAllAssociative($q);
    if (empty($rows)) { break; }
    if (time() - $s > 30) {
        fwrite(STDERR, "[TX TIMEOUT] Active transactions detected:
" . json_encode($rows, JSON_PRETTY_PRINT) . "
");
        exit(1);
    }
    sleep(1);
}
'

# 4. Zero-Flight Watchdog (45 минут таймаут, строгий COUNT(*))
docker compose $COMPOSE_PROD_ARGS exec -T app php -r '
require_once "/var/www/html/vendor/autoload.php";
$kernel = new App\Kernel("prod", false);
$kernel->boot();
$c = $kernel->getContainer()->get("doctrine.dbal.default_connection");
$hasOutbox = $c->createSchemaManager()->tablesExist(["import_run_outbox"]);
$s = time();
while (true) {
    $l = (int) $c->fetchOne("SELECT COUNT(*) FROM import_runs r JOIN pg_locks l ON l.locktype = \x27advisory\x27 AND l.objsubid = 1 AND l.database = (SELECT oid FROM pg_database WHERE datname = current_database()) AND ((l.classid::bigint << 32) | (l.objid::bigint & 4294967295)) = r.id AND l.granted WHERE r.status = \x27PROCESSING\x27");
    $f = (int) $c->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE delivered_at IS NOT NULL AND ((queue_name = \x27catalog\x27 AND delivered_at > NOW() - INTERVAL \x275400s\x27) OR (queue_name = \x27offers\x27 AND delivered_at > NOW() - INTERVAL \x27900s\x27))");
    $r = (int) $c->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE delivered_at IS NULL AND available_at <= NOW() AND queue_name IN (\x27catalog\x27, \x27offers\x27)");
    $o = $hasOutbox ? (int) $c->fetchOne("SELECT COUNT(*) FROM import_run_outbox WHERE published_at IS NULL AND available_at <= NOW()") : 0;
    if ($l === 0 && $f === 0 && $r === 0 && $o === 0) { exit(0); }
    if (time() - $s > 2700) { exit(1); }
    sleep(3);
}
'

# 5. Graceful Workers Stop (отправка SIGTERM и ожидание stop_grace_period)
docker compose $COMPOSE_PROD_ARGS stop worker_catalog worker_offers

# 6. Миграции БД
docker compose $COMPOSE_PROD_ARGS exec -T app php bin/console doctrine:migrations:migrate --no-interaction

# 7. Запуск обновлённых контейнеров и восстановление Ingress (delayed/outbox сообщения сохраняются)
docker compose $COMPOSE_PROD_ARGS up -d --no-deps worker_catalog worker_offers outbox_publisher web
WEB_STOPPED=0
PUBLISHER_STOPPED=0
