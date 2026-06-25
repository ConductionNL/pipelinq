## 1. Coverage analysis (gate before any deletion)

- [x] 1.1 Read the canonical `klachtenregistratie` Backend SLA Deadline Service requirement and confirm its scenarios name `calculateDeadline('service')`, `calculateDeadline('other')`, `isOverdue()` — the exact `ComplaintSlaService` API
- [x] 1.2 Compare against the live `SlaEngineService` / `SlaDeadlineSweepJob` path; confirm the engine has no `isOverdue`/`calculateDeadline`/`getSlaHoursForCategory` and never reads `complaint_sla_*` → REQ-KL-009 is NOT covered by the engine
- [x] 1.3 Conclude: deleting `ComplaintSlaService` would orphan REQ-KL-009 (+ 3 documented-operations requirements) → WIRE, do not delete or retire

## 2. Wire ComplaintSlaService into the live sweep job

- [x] 2.1 Inject `ComplaintSlaService` into `SlaDeadlineSweepJob`'s constructor
- [x] 2.2 In `processEntity()`, for the `klacht` tracked type, call `ComplaintSlaService::isOverdue($data, $now)` before the policy `slaStatus` early-return
- [x] 2.3 Add a read-only `checkComplaintDeadline()` helper that logs a warning (uuid, category, status, slaDeadline) when an open complaint is past its category SLA deadline (REQ Background Job for SLA Monitoring)
- [x] 2.4 Confirm `ComplaintSlaService::isOverdue()` now has exactly one external caller (gate-6 cleared)

## 3. Spec annotations

- [x] 3.1 Repoint `ComplaintSlaService` class + method `@spec` tags from the archived `reverse-2026-05-26-be-complaint-sla` change to `openspec/specs/klachtenregistratie/spec.md#Backend-SLA-Deadline-Service`
- [x] 3.2 Add `@spec` tags to the sweep job class, `processEntity`, and `checkComplaintDeadline` for the Backend SLA Deadline Service + Background Job for SLA Monitoring requirements

## 4. Verify

- [x] 4.1 `php -l` + `phpcs --warning-severity=0` clean on both changed lib files
- [x] 4.2 Full unit suite ≥ baseline (1561 passing; no test added or removed)
- [x] 4.3 Hydra gate-6 orphan-auth PASS and gate-16 spec-coverage PASS (gate-27 fail is pre-existing in `ActivityService.php`, out of scope)
- [x] 4.4 `openspec validate pipelinq-wire-complaint-sla-service --strict`
