---
kind: code
---

## Why

`ComplaintSlaService` (per-category complaint SLA deadline math — `getSlaHoursForCategory`,
`calculateDeadline`, `isOverdue`, `isOpenStatus`) is the backend service mandated by the
canonical `klachtenregistratie` capability's **Backend SLA Deadline Service** requirement
(historically REQ-KL-009). Its scenarios literally specify `calculateDeadline('service')`,
`calculateDeadline('other')`, and `isOverdue()` behaviour, and three further
`Complaint SLA computation — *` requirements pin the same trio of operations.

A prior change deleted `lib/BackgroundJob/ComplaintSlaJob.php` — a `[]`-returning stub that was
the only runtime caller of `ComplaintSlaService::isOverdue()`. That left `isOverdue()`
**defined but never called**, which Hydra gate-6 (orphan-auth) correctly flags: a `is*` method
with zero external callers is dead code. At the same time the canonical klachtenregistratie spec
still mandates this backend service **and** a timed background job that "periodically checks for
overdue complaints and logs warnings" (Background Job for SLA Monitoring requirement).

The other SLA system in the app — `SlaEngineService` + `SlaDeadlineSweepJob`
(`sla-engine-and-escalation`) — is a **different** abstraction: policy resolution, holiday-aware
deadline math, and escalation chains over a `slaStatus` envelope + `slaBreachEvent` records. It
does **not** compute per-category complaint deadlines and does **not** read the
`complaint_sla_{category}` config that `ComplaintSlaService` (and the `ComplaintForm.vue`
frontend) use. So `ComplaintSlaService` is **not** redundant — deleting it would orphan the
Backend SLA Deadline Service requirement (and the three documented-operations requirements) and
break gate-16 spec-coverage.

The correct resolution is therefore to **wire**, not delete: give `isOverdue()` the runtime
caller it lost, in the place the deleted standalone job used to live.

## What Changes

- **Wire `ComplaintSlaService` into `SlaDeadlineSweepJob`.** The sweep job already iterates the
  configured `complaint_schema` (its `klacht` tracked type). It now injects `ComplaintSlaService`
  and, for each complaint row, calls `ComplaintSlaService::isOverdue()` and logs a warning when an
  open complaint has passed its category-derived `slaDeadline`. This:
  - restores the runtime caller of `isOverdue()` that the deleted `ComplaintSlaJob` provided
    (clears gate-6 orphan-auth), and
  - fulfils the klachtenregistratie **Background Job for SLA Monitoring** requirement (overdue
    complaints are surfaced by a timed job that logs warnings), folding it into the existing
    timed sweep instead of resurrecting a second standalone job.
  The check is read-only: it only emits a warning. The category `slaDeadline` itself is still
  computed at complaint-creation time from `complaint_sla_{category}` (frontend + the
  `calculateDeadline` helper).
- **Re-anchor stale `@spec` annotations.** `ComplaintSlaService`'s method/class `@spec` tags
  pointed at the now-archived `reverse-2026-05-26-be-complaint-sla` change; they are repointed to
  the canonical `openspec/specs/klachtenregistratie/spec.md#Backend-SLA-Deadline-Service`. The
  sweep job's new + modified methods carry `@spec` tags for the same canonical spec
  (Backend SLA Deadline Service + Background Job for SLA Monitoring).
- **No deletion, no schema change, no register edit, no new endpoint, no nc-vue change.** This is
  a `code` change (it edits `lib/` PHP).

## Capabilities

### New Capabilities
<!-- None. -->

### Modified Capabilities
- `klachtenregistratie`: the **Background Job for SLA Monitoring** requirement is now realised by
  `SlaDeadlineSweepJob` (the timed sweep), which checks each open complaint's category SLA
  deadline via `ComplaintSlaService::isOverdue()` and logs a warning when overdue — replacing the
  deleted standalone `ComplaintSlaJob`. The **Backend SLA Deadline Service** requirement is
  unchanged in behaviour but now has a live runtime consumer.

## Impact

- **`lib/BackgroundJob/SlaDeadlineSweepJob.php`** — inject `ComplaintSlaService`; add a read-only
  `checkComplaintDeadline()` per-complaint overdue check that logs a warning; `@spec` tags.
- **`lib/Service/ComplaintSlaService.php`** — repoint stale `@spec` annotations to the canonical
  klachtenregistratie spec (no behaviour change).
- **Gate-6 orphan-auth**: was FAIL (`isOverdue` orphaned) → PASS. **Gate-16 spec-coverage**:
  remains PASS. Coverage-report mapping of the Backend SLA Deadline Service requirement to
  `ComplaintSlaService` is preserved (the service stays).
- **Tests**: existing `ComplaintSlaServiceTest` (10 tests covering `isOverdue` and the deadline
  math) is unchanged; full unit suite stays at 1561 passing.
