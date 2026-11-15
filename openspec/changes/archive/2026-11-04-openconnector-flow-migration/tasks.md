# Tasks: OpenConnector Flow Migration — Scoping & Staging

## 1. Audit the exact current behaviour
- [x] Confirm the call site still exists and grep its exact current path
      (may have moved since last checked): `src/views/blasts/BlastForm.vue`,
      `loadConnectorSources()`, `GET /apps/openconnector/api/sources?type=email,sms`
      — unchanged from the original report.
- [x] Read enough surrounding code to confirm what it is for: populates the
      "Connector source" `NcSelect` on the New Blast wizard's step 4
      ("channel") panel, so the operator can pick which OpenConnector
      `Source` sends this blast's email/SMS. Confirmed — matches the
      original hypothesis exactly.
- [x] Trace where the picked value goes: `model.connectorSourceId` → POSTed
      in `submit()` → read back by `BlastService::dispatchBlastDeliveries()`
      / `sendOneDelivery()` to actually place the send via
      `OCA\OpenConnector\Service\SourceService::executeAction(..., 'send-mail', ...)`.
- [x] Check whether the target route still exists in openconnector today:
      it does not. `openconnector/appinfo/routes.php` has no `sources#index`
      (or any GET index) route — removed by an earlier "chain-C" migration
      that moved openconnector's own per-schema CRUD to OpenRegister's
      generic `/api/objects/openconnector/{schema}` API. Confirmed via the
      routes.php header comment and by confirming `SourcesController.php`
      has no `index()` method.

## 2. Scope judgment against ADR-092 / flow-sync-decomposition
- [x] Judge whether this call site is actually affected by the classic
      OpenConnector dialect retirement (ADR-092) or the `flow-sync-decomposition`
      change: **it is not.** It reads `Source` object metadata only — no
      `Synchronization`, `Mapping`, or `Job` involved. ADR-092 keeps
      register `openconnector` (id 65) and its `source` schema live and
      supported until every `Synchronization`/`Job` consumer moves off; the
      Flow-native bridge node `openconnector.source-call` calls `Source`
      directly for this exact "send through a configured Source" shape.
      `flow-sync-decomposition` is scoped to decomposing `Synchronization`'s
      per-page fetch / contract-keyed upsert / hash-based change detection —
      none of which this call site touches.
- [x] **Action item for pipelinq against ADR-092: verify only, no
      migration needed.** There is no Flow-native equivalent to design or
      plan here, and nothing in this app is blocked on
      `flow-sync-decomposition` landing. Do not invent migration work that
      is not actually required by the ADR.
- [ ] Re-confirm this judgment once `flow-sync-decomposition`'s `tasks.md`
      lands (it has none as of ADR-092's filing date) — in case its final
      scope turns out to include a Source-listing primitive that changes
      today's read. Low probability given the ADR's own text, but cheap to
      recheck.

## 3. Follow-up: pre-existing defect found during the audit (separate track)
- [ ] File a dedicated bug-fix change (not this one, and not gated on
      `flow-sync-decomposition`) for: `BlastForm.vue`'s `loadConnectorSources()`
      calling a route that has not existed in openconnector for ~3 months
      (`GET /apps/openconnector/api/sources`), silently swallowed by its
      `catch` block, leaving the "Connector source" picker permanently
      empty in production today.
- [ ] Same follow-up should cover the downstream break: `BlastService::
      sendOneDelivery()` resolves `OCA\OpenConnector\Service\SourceService`
      via the DI container — that class does not exist in current
      openconnector — so every connector-backed blast delivery currently
      fails closed (silently, via a caught `Throwable`) regardless of
      channel.
- [ ] The fix direction (for that follow-up change, not this one) is
      already visible from other openconnector frontend code:
      `GET /apps/openregister/api/objects/openconnector/source` with
      `_limit` (not `limit`), plus a real filter or client-side filter for
      "email/sms-capable" — note the live `Source` JSON schema's `type`
      enum (`json`/`xml`/`soap`/`ftp`/`sftp`) has no `email`/`sms` values,
      so the original `?type=email,sms` filter never matched the current
      schema either; the follow-up needs to establish what actually
      identifies an email/SMS-capable Source before it can filter on it.
- [ ] Not implemented here per this change's scope (audit/staging only, no
      code changes).

## 4. Verification
- [x] No code changed by this proposal — nothing to test or build.
- [x] Findings cross-checked against ADR-092's own consumer-app inventory,
      which independently names this exact call site.
