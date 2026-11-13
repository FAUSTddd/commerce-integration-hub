# Tasks: Production Observability & Operational Monitoring

**Input**: Design documents from `/specs/001-production-observability/`
**Feature**: Production Observability & Operational Monitoring

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Phase 1: Setup (Infrastructure)

**Purpose**: Initialize directory structure and monitoring core

- [x] T001 Create `src/Monitoring` namespace structure per plan.md
- [x] T002 Create `src/Monitoring/Dto` structure for observability snapshots

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core logic and sanitization utilities

- [x] T003 [P] Implement `src/Monitoring/Sanitizer/MonitoringDataSanitizer.php` for safe normalization of `filename` and `last_error`
- [x] T004 [P] Define `ImportRunSnapshot` DTO in `src/Monitoring/Dto/ImportRunSnapshot.php`
- [x] T005 [P] Define `QueueMetricSnapshot` DTO in `src/Monitoring/Dto/QueueMetricSnapshot.php`
- [x] T006 [P] Define `OutboxMetricSnapshot` DTO in `src/Monitoring/Dto/OutboxMetricSnapshot.php`

---

## Phase 3: User Story 1 - Aggregation Service (Priority: P1) 🎯 MVP

**Goal**: Implement `MonitoringQueryRepository` to fetch metrics from DB without locking.

**Independent Test**: Verify DB queries execute in < 500ms and return correct counts for pending/in-flight/failed.

- [x] T007 [P] Implement `src/Monitoring/Repository/MonitoringQueryRepository.php` using read-only SQL queries with `statement_timeout`
- [x] T008 [US1] Implement `src/Monitoring/Service/SystemHealthMonitorService.php` to aggregate snapshots via read-only `MonitoringQueryRepository` access to `ImportRun`

---

## Phase 4: User Story 2 - System Health Monitoring (Priority: P2)

**Goal**: Classify system status and starvation indicators.

**Independent Test**: Verify health status logic maps correctly to HEALTHY/DEGRADED/CRITICAL.

- [x] T009 [P] Define `SystemHealthOverview` DTO in `src/Monitoring/Dto/SystemHealthOverview.php`
- [x] T010 [US2] Implement health status classification logic in `SystemHealthMonitorService`

---

## Phase 5: User Story 3 - CLI Monitoring Command (Priority: P3)

**Goal**: Expose monitoring data via `bin/console monitor:status`.

**Independent Test**: Verify human-readable output and JSON output formats.

- [x] T011 [US3] Implement `src/Command/MonitorStatusCliCommand.php` with support for `--format` and `--window-hours`, including integration tests verifying exit code 2 on CRITICAL status
- [x] T012 [US3] Wire command output to `SystemHealthMonitorService` data
- [x] T017 [P] [US3] Conduct performance validation (SC-001/SC-002) with 50,000 pending messages; log execution time and resource profile as target indicators

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Validation and final cleanup

- [x] T013 [P] Add unit tests for `MonitoringDataSanitizer` in `tests/Unit/Monitoring/MonitoringDataSanitizerTest.php`
- [x] T014 [P] Add kernel test for `MonitorStatusCliCommand` in `tests/Integration/Command/MonitorStatusCliCommandTest.php`
- [x] T015 Run validation scenarios from `quickstart.md`
- [x] T016 Documentation and final review

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies.
- **Foundational (Phase 2)**: Depends on Phase 1 - BLOCKS all story phases.
- **Story Phases (Phase 3-5)**: All depend on Foundational completion.
- **Polish (Phase 6)**: Depends on completion of all stories.

### Parallel Opportunities

- [P] tasks in Phase 2, 3, and 6 can be executed in parallel where file paths are distinct.

### MVP Scope

- Phase 1 + Phase 2 + Phase 3 (US1: Aggregation Service). This provides the core read-only data access layer required for all subsequent monitoring stories.
