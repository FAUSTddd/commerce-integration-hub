# Controlled E2E Execution Report: Outbox Pipeline Validation

- **Date:** 2026-11-06
- **Environment:** `APP_ENV=test`
- **Database:** `commerce_db_test`
- **Component under test:** Outbox → Publisher → Messenger → Worker → ACK Lifecycle

## 1. Scenario Summary
A targeted, single-message end-to-end verification of the resilient asynchronous processing pipeline was conducted to validate the complete round-trip from Outbox persistence to physical queue consumption and exact ACK marking.

## 2. Test Execution Details
- **ImportRun Entity:**
  - `run_id`: `87`
  - `type`: `offers`
  - `filename`: `offers_e2e_test_20261106.xml` (SHA256: `379610addb5e478a398d05bbe4d87b999f6c32831fd0be5b4bb62b872d15ecd3`)
  - Initial status: `PENDING` (`attempt = 0`, `items_count = 0`)
  - Final status: `SUCCESS` (`attempt = 1`, `items_count = 1`, `finished_at IS NOT NULL`)
- **Outbox Entity:**
  - `outbox_id`: `43`
  - `kind`: `retry`
  - `queue_name`: `offers_test`
  - Pre-dispatch state: `published_at = NULL`, `acked_at = NULL`
  - Post-dispatch state: `published_at = 2026-11-06 07:56:40+00`
  - Post-worker state: `acked_at = 2026-11-06 08:02:08+00`
- **Publisher Execution:**
  - Mechanism: programmatic invocation of `App\Outbox\OutboxPublisher::publishAvailableMessages(1, 87)` via Symfony `test.service_container`.
  - Result: `published_count = 1`.
  - Dispatched Envelope: enriched with `App\Messenger\ImportRunStamp(87)`.
- **Messenger Transport:**
  - Physical queue: `offers_test`
  - Created message ID: `100` (`delivered_at = NULL`)
  - Message command: `FaustDDD\Symfony1cImport\Application\Command\ImportOffersCommand`
- **Worker Execution:**
  - Command: `php bin/console messenger:consume async_offers --limit=1 --time-limit=30 --env=test -vv`
  - Handler: `App\MessageHandler\OffersImportHandler::__invoke`
  - Acknowledgment: exact Outbox ACK confirmed via `MessengerDispatchSubscriber` / `ImportRunRepository::markOutboxAckedById(43)`
  - Queue cleanup: message ID `100` cleanly acknowledged and removed from `messenger_messages` (`offers_test` remaining count = 0).

## 3. Isolation & Baseline Invariants
- **Database Isolation:** All operations strictly executed within `commerce_db_test`. The live production/development database `commerce_db` was not touched.
- **Daemon Isolation:** Background daemons (`app:cml:publish-outbox`, `async_offers`, `async_catalog` running under `APP_ENV=dev`) remained unaffected.
- **Baseline Preserved:**
  - `import_runs` baseline IDs 8 and 9 retained status `PROCESSING`.
  - `import_run_outbox` baseline IDs 7 and 8 retained `published_at = NULL` and `acked_at = NULL`.
  - `messenger_messages` baseline ID 94 (`queue_name = offers`) was not inspected, decoded, delivered, or altered (`delivered_at = NULL`).
  - Pre-test baseline database backups were retained without modification.

## 4. Conclusion & Scope Limitation
- **Status:** PASS (Specific controlled lifecycle verified).
- **Scope Boundary:** This verification confirms the functional contract of the happy-path Outbox dispatch, transport serialization, consumer execution, and exact ACK stamping for `offers`. It does **not** generalize across all network failure modes, unhandled exception deadlocks, poison pills, or multi-worker concurrency race conditions.
