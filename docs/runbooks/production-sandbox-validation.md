#!/usr/bin/env bash
# ==============================================================================
# Safety Sandbox Runbook: Испытание Production Deployment Checklist в изолированной PostgreSQL-песочнице
# ==============================================================================
# Цель: безопасная валидация процедур развертывания и миграций схемы на изолированной
# временной базе данных PostgreSQL без воздействия на рабочую БД commerce_db и очереди.
# ==============================================================================
set -Eeuo pipefail

# Уникальный идентификатор запуска для устранения окон потери владения и гонок параллельных сессий
RUN_ID="$(date +%s)_$$"
SANDBOX_DB="commerce_checklist_sandbox_${RUN_ID}"
SANDBOX_FAILED_DB="commerce_checklist_sandbox_failed_${RUN_ID}"
DUMP_FILE="/tmp/backup_checklist_sandbox_${RUN_ID}.dump"

# Флаги владения ресурсами: удаляются строго те объекты, которые созданы текущим процессом
CREATED_SANDBOX_DB=0
CREATED_SANDBOX_FAILED_DB=0
CREATED_DUMP_FILE=0

# Штатная функция очистки: возвращает управление (return 0 или return 1) для последующей сверки baseline
cleanup_sandbox() {
  local cleanup_failed=0

  echo "[CLEANUP] Штатное освобождение ресурсов текущего запуска (${RUN_ID})..."

  if [ "${CREATED_SANDBOX_FAILED_DB}" -eq 1 ]; then
    echo "[CLEANUP] Удаление базы ${SANDBOX_FAILED_DB}..."
    if docker compose exec -T postgres psql -U commerce_user -d postgres -v ON_ERROR_STOP=1 -c "
      SELECT pg_terminate_backend(pid) FROM pg_stat_activity 
      WHERE datname = '${SANDBOX_FAILED_DB}' AND pid <> pg_backend_pid();
    " && docker compose exec -T postgres psql -U commerce_user -d postgres -v ON_ERROR_STOP=1 -c "DROP DATABASE IF EXISTS ${SANDBOX_FAILED_DB};"; then
      CREATED_SANDBOX_FAILED_DB=0
    else
      echo "[CLEANUP-ERROR] Ошибка удаления ${SANDBOX_FAILED_DB}" >&2
      cleanup_failed=1
    fi
  fi

  if [ "${CREATED_SANDBOX_DB}" -eq 1 ]; then
    echo "[CLEANUP] Удаление базы ${SANDBOX_DB}..."
    if docker compose exec -T postgres psql -U commerce_user -d postgres -v ON_ERROR_STOP=1 -c "
      SELECT pg_terminate_backend(pid) FROM pg_stat_activity 
      WHERE datname = '${SANDBOX_DB}' AND pid <> pg_backend_pid();
    " && docker compose exec -T postgres psql -U commerce_user -d postgres -v ON_ERROR_STOP=1 -c "DROP DATABASE IF EXISTS ${SANDBOX_DB};"; then
      CREATED_SANDBOX_DB=0
    else
      echo "[CLEANUP-ERROR] Ошибка удаления ${SANDBOX_DB}" >&2
      cleanup_failed=1
    fi
  fi

  if [ "${CREATED_DUMP_FILE}" -eq 1 ]; then
    echo "[CLEANUP] Удаление дампа ${DUMP_FILE}..."
    if rm -f "${DUMP_FILE}"; then
      CREATED_DUMP_FILE=0
    else
      echo "[CLEANUP-ERROR] Ошибка удаления ${DUMP_FILE}" >&2
      cleanup_failed=1
    fi
  fi

  if [ "${cleanup_failed}" -ne 0 ]; then
    echo "[CLEANUP-FATAL] Штатная очистка завершилась с ошибками!" >&2
    return 1
  fi

  return 0
}

# Аварийная очистка: вызывается из сигнальных ловушек, завершает процесс с целевым кодом
emergency_cleanup() {
  local target_exit_code="${1:-1}"
  trap - ERR INT TERM

  echo "[EMERGENCY-CLEANUP] Аварийная остановка (exit code: ${target_exit_code})..."

  cleanup_sandbox || {
    echo "[EMERGENCY-CLEANUP-FATAL] Ошибки при аварийной очистке!" >&2
    if [ "${target_exit_code}" -eq 0 ]; then
      target_exit_code=1
    fi
  }

  exit "${target_exit_code}"
}

# Регистрация сигнальных ловушек с сохранением семантики exit codes (130 для SIGINT, 143 для SIGTERM)
trap 'emergency_cleanup $?' ERR
trap 'emergency_cleanup 130' INT
trap 'emergency_cleanup 143' TERM

# 1. Фиксация baseline рабочей БД
# ВНИМАНИЕ: Сравнение OID и count(*) служит ограниченным контролем отсутствия
# пересоздания БД и сохранения общего количества строк в таблицах products, import_runs, messenger_messages.
# Данная проверка НЕ доказывает отсутствие точечных внутристрочных мутаций или смещения автоинкрементных sequence.
PROD_OID=$(docker compose exec -T postgres psql -U commerce_user -d postgres -tAc "SELECT oid FROM pg_database WHERE datname = 'commerce_db';")

PROD_COUNTS=$(docker compose exec -T postgres psql -U commerce_user -d commerce_db -tAc "
  SELECT (SELECT count(*) FROM products) || '|' ||
         (SELECT count(*) FROM import_runs) || '|' ||
         (SELECT count(*) FROM messenger_messages);
")
PROD_BASELINE="${PROD_OID}|${PROD_COUNTS}"
echo "Prod Baseline зафиксирован: ${PROD_BASELINE}"

# 2. Fail-Closed проверка отсутствия конфликтующих баз
docker compose exec -T postgres psql -U commerce_user -d postgres -v ON_ERROR_STOP=1 -c "
  DO \$\$
  BEGIN
    IF EXISTS (SELECT 1 FROM pg_database WHERE datname IN ('${SANDBOX_DB}', '${SANDBOX_FAILED_DB}')) THEN
      RAISE EXCEPTION 'Target sandbox database already exists! Aborting run.';
    END IF;
  END
  \$\$;
"

# 3. Создание изолированной БД с немедленной фиксацией владения
docker compose exec -T postgres psql -U commerce_user -d postgres -v ON_ERROR_STOP=1 -c "CREATE DATABASE ${SANDBOX_DB} WITH OWNER commerce_user;"
CREATED_SANDBOX_DB=1

# 4. Снятие и валидация дампа с защитой от частично созданного файла
CREATED_DUMP_FILE=1
docker compose exec -T postgres pg_dump -U commerce_user -Fc commerce_db_test > "${DUMP_FILE}"
docker compose exec -T postgres pg_restore -l < "${DUMP_FILE}" > /dev/null

# 5. Восстановление в песочницу с прямым вводом (без скрывающего ошибки pipe)
docker compose exec -T postgres pg_restore -U commerce_user -d "${SANDBOX_DB}" --exit-on-error < "${DUMP_FILE}"

# 6. Запуск миграций и строгая fail-closed валидация статуса
docker compose exec -T app php -r '
  $sandboxDb = "'"${SANDBOX_DB}"'";
  $u = parse_url(getenv("DATABASE_URL"));
  if (!$u || !isset($u["scheme"], $u["host"], $u["user"])) {
    fwrite(STDERR, "FATAL: Malformed or unparseable DATABASE_URL
");
    exit(1);
  }
  $user = rawurlencode(rawurldecode($u["user"]));
  $pass = isset($u["pass"]) ? ":" . rawurlencode(rawurldecode($u["pass"])) : "";
  $host = (str_contains($u["host"], ":") && !str_starts_with($u["host"], "[")) ? "[" . $u["host"] . "]" : $u["host"];
  $port = isset($u["port"]) ? ":" . $u["port"] : "";
  $query = isset($u["query"]) ? "?" . $u["query"] : "";
  $sandboxUrl = $u["scheme"] . "://" . $user . $pass . "@" . $host . $port . "/" . $sandboxDb . $query;

  // 1. Применение миграций Doctrine
  passthru("DATABASE_URL=" . escapeshellarg($sandboxUrl) . " php bin/console doctrine:migrations:migrate --env=prod --no-interaction", $migrateCode);
  if ($migrateCode !== 0) {
    fwrite(STDERR, "FATAL: Migration execution failed with code: " . $migrateCode . "
");
    exit($migrateCode);
  }

  // 2. Строгий fail-closed анализ вывода doctrine:migrations:status (Doctrine Migrations Bundle 3.7 / MigrationStatusInfosHelper)
  exec("DATABASE_URL=" . escapeshellarg($sandboxUrl) . " php bin/console doctrine:migrations:status --env=prod", $statusOutput, $statusCode);
  if ($statusCode !== 0) {
    fwrite(STDERR, "FATAL: migrations:status command failed with code: " . $statusCode . "
");
    exit($statusCode);
  }
  $statusText = implode("
", $statusOutput);

  // Валидация колонок таблицы: Current, Latest, New
  if (!preg_match("/Current\s*\|\s*([^\s\|]+)/i", $statusText, $currentMatch) ||
      !preg_match("/Latest\s*\|\s*([^\s\|]+)/i", $statusText, $latestMatch) ||
      !preg_match("/New\s*\|\s*([^\s\|]+)/i", $statusText, $newMatch)) {
    fwrite(STDERR, "FATAL: Failed to parse doctrine:migrations:status table output (fail-closed)
");
    exit(1);
  }

  $cleanCurrent = trim(strip_tags($currentMatch[1]));
  $cleanLatest = trim(strip_tags($latestMatch[1]));
  $cleanNew = trim(strip_tags($newMatch[1]));

  if (!ctype_digit($cleanNew)) {
    fwrite(STDERR, "FATAL: Non-numeric New migrations counter: " . $cleanNew . "
");
    exit(1);
  }
  if ((int)$cleanNew !== 0) {
    fwrite(STDERR, "FATAL: Pending migrations remain after migrate: " . $cleanNew . "
");
    exit(1);
  }
  if ($cleanCurrent === "0" || $cleanCurrent !== $cleanLatest) {
    fwrite(STDERR, "FATAL: Version mismatch: Current (" . $cleanCurrent . ") != Latest (" . $cleanLatest . ")
");
    exit(1);
  }
  echo "MIGRATIONS VALIDATED: Current=" . $cleanCurrent . " matches Latest, New=0 (PASS)
";
'

# 7. Тестирование механизма аварийного отката (Rollback via rename)
docker compose exec -T postgres psql -U commerce_user -d postgres -v ON_ERROR_STOP=1 -c "
  SELECT pg_terminate_backend(pid) FROM pg_stat_activity 
  WHERE datname = '${SANDBOX_DB}' AND pid <> pg_backend_pid();
"
docker compose exec -T postgres psql -U commerce_user -d postgres -v ON_ERROR_STOP=1 -c "ALTER DATABASE ${SANDBOX_DB} RENAME TO ${SANDBOX_FAILED_DB};"
CREATED_SANDBOX_DB=0
CREATED_SANDBOX_FAILED_DB=1

# 8. Штатная очистка и сверка baseline рабочей БД
trap - ERR INT TERM
cleanup_sandbox

PROD_OID_AFTER=$(docker compose exec -T postgres psql -U commerce_user -d postgres -tAc "SELECT oid FROM pg_database WHERE datname = 'commerce_db';")
PROD_COUNTS_AFTER=$(docker compose exec -T postgres psql -U commerce_user -d commerce_db -tAc "
  SELECT (SELECT count(*) FROM products) || '|' ||
         (SELECT count(*) FROM import_runs) || '|' ||
         (SELECT count(*) FROM messenger_messages);
")
PROD_AFTER="${PROD_OID_AFTER}|${PROD_COUNTS_AFTER}"

if [ "${PROD_BASELINE}" != "${PROD_AFTER}" ]; then
  echo "CRITICAL: Baseline mismatch! Expected ${PROD_BASELINE}, got ${PROD_AFTER}" >&2
  exit 1
fi

echo "ISOLATION VERIFIED: PROD UNTOUCHED (PASS)"
