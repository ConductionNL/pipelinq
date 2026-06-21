# Design — Wire ComplaintSlaService into the live SLA path

## REQ-KL-009 (Backend SLA Deadline Service) coverage analysis

The task started from a gate-6 orphan-auth finding: `ComplaintSlaService::isOverdue()` has zero
runtime callers. The risk was that a blind delete of `ComplaintSlaService` would drop the mapped
implementation of the canonical klachtenregistratie **Backend SLA Deadline Service** requirement
(coverage-report `REQ-KL-009`, `lib/Service/ComplaintSlaService.php <all>`) and trip gate-16.

### What the requirement actually demands

`openspec/specs/klachtenregistratie/spec.md` (status: done) contains **six** requirements bound to
this service's exact API:

1. **Backend SLA Deadline Service** — "A PHP service MUST calculate SLA deadlines and provide SLA
   configuration helpers." Scenarios name the methods literally:
   - `calculateDeadline('service')` with 48h config → a `DateTimeImmutable` 48h from now;
   - `calculateDeadline('other')` with no config → `null`;
   - `isOverdue()` → `true` for an open complaint past its deadline, `false` for
     resolved/rejected regardless of deadline.
2. **Background Job for SLA Monitoring** — "A timed background job MUST periodically check for
   overdue complaints and log warnings."
3–5. **Complaint SLA computation — documented operations / results derived from current CRM state /
   defensive handling of absent input** — explicitly enumerate `calculateDeadline`,
   `getSlaHoursForCategory`, `isOverdue` as the operations that MUST exist, read live config, and
   tolerate bad input.

`ComplaintSlaService` implements exactly these: `getSlaHoursForCategory` reads
`complaint_sla_{category}` from `IAppConfig`; `calculateDeadline` returns a `DateTimeImmutable`
(or `null`); `isOverdue` evaluates an open complaint's `slaDeadline` against now. The frontend
`ComplaintForm.vue` independently sets `slaDeadline` from the same per-category config on create.

### Who else could fulfil it — and why they do not

The other SLA path, `SlaEngineService` + `SlaDeadlineSweepJob` (capability
`sla-engine-and-escalation`, REQ-001..010), is a **policy-and-escalation engine**, not
per-category deadline math:

| Concern | `ComplaintSlaService` (REQ-KL-009) | `SlaEngineService` / `SlaDeadlineSweepJob` |
|---|---|---|
| Input | `complaint_sla_{category}` config hours | `slaPolicy` objects (appliesTo / tier / scope) |
| Deadline math | now + N hours (wall-clock) | holiday-aware business-hours over policy targets |
| State carried on object | `slaDeadline` (single date-time) | `slaStatus` envelope (targets, escalation level) |
| Output | `isOverdue()` boolean | escalation chain + `slaBreachEvent` audit rows |

`SlaEngineService` has **no** `isOverdue`, `calculateDeadline`, or `getSlaHoursForCategory`, and
never reads `complaint_sla_*`. It cannot satisfy the Backend SLA Deadline Service scenarios.
**Conclusion: REQ-KL-009 is NOT covered by the live engine path.** Deleting `ComplaintSlaService`
would leave it unimplemented.

## Decision: WIRE, not delete (and not retire)

Per the task's branch logic — "If REQ-KL-009 is NOT covered by the live path … WIRE
`ComplaintSlaService` into the live path (e.g. have the sweep job call `isOverdue`)." The
requirement is genuinely required (it is the documented, tested per-category complaint SLA
backend; the frontend depends on the same config), so retiring it via an openspec change would be
wrong — it would delete a live, spec-mandated feature.

The orphaning was an accident of a prior change deleting the `[]`-stub `ComplaintSlaJob`, which
was the only `isOverdue()` caller. Rather than resurrect a second standalone timed job (the
canonical spec asks for *a* timed job, and the engine already runs one over the same
`complaint_schema`), the overdue check is folded into the existing `SlaDeadlineSweepJob`:

- The sweep already iterates the `complaint_schema` rows (its `klacht` tracked type).
- For each complaint row, before the policy `slaStatus` early-return, it now calls
  `ComplaintSlaService::isOverdue($data, $now)` and logs a warning when the open complaint is past
  its category `slaDeadline`.
- This restores the runtime caller (clears gate-6) and realises the **Background Job for SLA
  Monitoring** requirement with a single timed job.

The check is intentionally read-only (log-only): the category deadline is owned by
complaint-creation, and writing escalation state here would conflate the two SLA models.

`ComplaintSlaService`'s stale `@spec` tags (pointing at the archived
`reverse-2026-05-26-be-complaint-sla` change) are repointed to the canonical
`openspec/specs/klachtenregistratie/spec.md#Backend-SLA-Deadline-Service`.

## Coverage-report

`openspec/coverage-report.json` keeps its `ComplaintSlaService.php <all> → REQ-KL-009` mapping
unchanged: the service is not deleted, so no remap is needed. Gate-16 spec-coverage stays PASS.

## Gates

- **Gate-6 orphan-auth**: `isOverdue` now has 1 caller (`SlaDeadlineSweepJob`) → PASS.
  `isOpenStatus` retains its internal caller → PASS.
- **Gate-16 spec-coverage**: changed methods (`processEntity`, new `checkComplaintDeadline`,
  `ComplaintSlaService` methods) carry `@spec` → PASS.
- Other 23 gates PASS. Gate-27 (no-phantom-cross-app-rpc) FAILs pre-existing on
  `lib/Service/ActivityService.php` (8 `publish()` ADR-041 sites) — untouched by this change,
  already failing on `origin/development`; out of scope for this orphaned-service fix.
