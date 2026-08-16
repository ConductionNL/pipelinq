# Proposal: OpenConnector Flow Migration — Scoping & Staging

## Why

The fleet is retiring the classic OpenConnector ingestion dialect (`Source` /
`Mapping` / `Synchronization` / `Job`, stored as OpenRegister objects in
register `openconnector`, id 65) in favour of OpenRegister's native Flow
engine — see **hydra ADR-065** (Flow engine and canvas) and **hydra
ADR-092, "Retire the classic OpenConnector ingestion dialect in favour of
OpenRegister's Flow engine"** (`hydra/openspec/architecture/adr-092-openconnector-dialect-retirement.md`,
status: Proposed, filed 2026-08-16). Note: the umbrella ADR was expected at
number 091, but 091 was already taken by an unrelated, concurrently-drafted
ADR ("Externally-Authenticated API Surface Belongs to OpenConnector") — the
retirement decision landed at **ADR-092** instead. This proposal references
ADR-092 by content, not by the originally-assumed number.

ADR-092's own consumer-app inventory names pipelinq's single dependency
explicitly:

> **pipelinq** | 1 call site | `BlastForm.vue` calls `GET
> /apps/openconnector/api/sources?type=email,sms`

Actual cutover is gated on `openregister/openspec/changes/flow-sync-decomposition`
landing a real implementation (as of ADR-092's filing date it has only
`proposal.md` + `design.md`, no `tasks.md` — decomposition has not started).
Per ADR-092 Decision 2, the policy target of 2026-08-31 is a decision
checkpoint (this ADR filed + `flow-sync-decomposition` scoped with real
tasks), **not** a migration-complete date. This proposal therefore does
**not** attempt the migration. It audits pipelinq's one call site, judges
whether it is actually affected by the retirement, and stages the follow-up
work.

## What changes

Nothing in application code. This is a scoping/audit change only:

- Confirms the exact current behaviour of the `BlastForm.vue` call site
  (see `tasks.md` §1).
- Records the scope judgment: **this call site is not blocked on
  `flow-sync-decomposition`** — it reads `Source` metadata, an entity ADR-092
  explicitly keeps live and supported, not `Synchronization` execution,
  which is what is actually being decomposed/retired (see `tasks.md` §2).
- Surfaces an unrelated, pre-existing defect discovered during the audit:
  the specific REST route this call site targets no longer exists in
  openconnector, and has not for months — independent of ADR-092 entirely
  (see `tasks.md` §3).

## Audit findings

### The call site

`src/views/blasts/BlastForm.vue`, method `loadConnectorSources()` (lines
~396-415), invoked when the wizard's step 4 ("channel") panel mounts. It
populates an `NcSelect` labelled "Connector source" (`connectorSources`,
bound to `selectedConnectorSource`) that lets the operator pick which
OpenConnector Source will dispatch this blast's messages. The selected
option's `id` is written to `model.connectorSourceId`, which is POSTed as
part of the Blast payload on submit (`submit()`, line ~573) and later read
back by `BlastService::dispatchBlastDeliveries()` /
`sendOneDelivery()` in `lib/Service/BlastService.php` to actually place the
send. The task's original read of this component was correct: it is a
picker of email/SMS-capable Source objects for the outbound blast/campaign
feature, not a Synchronization trigger or Job run.

### Is this call site actually in scope for the Flow migration?

**No.** `GET /apps/openconnector/api/sources?type=email,sms` reads `Source`
object *metadata* (id, name/title) to populate a dropdown — it does not
invoke a `Synchronization`, does not read a `Mapping`, and does not trigger
a `Job`. ADR-092 Decision 5 keeps register `openconnector` (id 65) and its
four schemas — including `source` — "live and supported until the last
consumer moves off" of the classic dialect's *execution* model, and the
Flow-native bridge node `openconnector.source-call`
(`lib/Flow/SourceCallNode.php`) calls Sources directly for exactly this
kind of "make a call through a configured Source" use case. Reading Source
metadata is not part of what `flow-sync-decomposition` decomposes — that
change is scoped to `Synchronization`'s per-page fetch / contract-keyed
upsert / hash-based change detection, none of which this call site touches.

This app's action item against ADR-092 is **verify only, no migration
needed** (see `tasks.md` §2).

### A separate, pre-existing defect found during the audit

The specific REST endpoint this call site targets,
`GET /apps/openconnector/api/sources`, **does not exist in openconnector
today** and has not for roughly three months — unrelated to ADR-092:

- `openconnector/appinfo/routes.php` has no `sources#index` (or any GET
  index) route. Its header comment explains why: *"Resource block
  intentionally omitted: chain-C deleted every index/show/create/update/destroy
  from the per-schema controllers. CRUD now lives at OR's
  `/api/objects/openconnector/{schema}/*`."* Only `sources#test`,
  `sources#logs`, and the two circuit-breaker routes remain.
- `openconnector/lib/Controller/SourcesController.php` correspondingly has
  no `index()` method.
- Other openconnector frontend views that need a Source picker already use
  the replacement pattern:
  `GET /apps/openregister/api/objects/openconnector/source` (see
  `SyncConfigWidget.vue::fetchSources()`,
  `NotificatiesAbonnementForm.vue`). That query needs `_limit` (not
  `limit`) per OpenRegister's parameter conventions; an unprefixed `type`
  would be a Source-schema *property filter*, but the current `Source`
  JSON schema's `type` enum is `["json","xml","soap","ftp","sftp"]` — it
  has no `"email"`/`"sms"` values, so `?type=email,sms` would not have
  filtered the way the original author intended even against the old
  endpoint.
- Because `loadConnectorSources()`'s `axios.get()` is wrapped in a bare
  `try { … } catch (_e) { this.connectorSources = [] }`, the 404 is
  swallowed silently: the "Connector source" dropdown in the New Blast
  wizard renders empty today, with no visible error to the operator.
- Downstream, `BlastService::sendOneDelivery()`
  (`lib/Service/BlastService.php` line ~1079) resolves
  `OCA\OpenConnector\Service\SourceService` from the DI container to call
  `executeAction($connectorSourceId, 'send-mail', $rendered)`. That class
  does not exist anywhere in the current openconnector codebase (no
  `SourceService.php` file). The resolution is wrapped in
  `try { … } catch (Throwable $e) { …warning…; return false; }`, so every
  connector-backed blast delivery currently fails closed and silently —
  same root cause family (a stale coupling to an openconnector surface
  that moved), not a Synchronization-retirement issue.

This defect predates ADR-092, is unrelated to the Flow-engine retirement,
and should be tracked and fixed as its own bug-fix change — not gated on
`flow-sync-decomposition`, and not part of this scoping change (see
`tasks.md` §3).

## Out of scope

- Implementing the Flow-native replacement for anything — there is nothing
  to replace here; see the scope judgment above.
- Fixing the broken `/api/sources` route dependency or the missing
  `SourceService` class — real bugs, but independent of ADR-092 and left
  for a dedicated follow-up change (see `tasks.md` §3).
- Any other app's ADR-092 call sites (spectr, openbuild, scholiq,
  zaakafhandelapp) — out of this repo's scope entirely.
- Any code change in this PR. This proposal is audit/scoping only.
