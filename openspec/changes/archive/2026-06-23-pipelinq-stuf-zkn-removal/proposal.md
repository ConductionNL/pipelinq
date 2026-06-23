---
kind: code
---

## Why

The StUF-ZKN/BG adapter (the SOAP zaaksysteem outbound engine, the inbound
`/inkomend` receiver, the circuit-breaker + retry machinery, the `stufEndpoint` /
`stufMessage` schemas and seed objects, and the two in-app settings surfaces) was
added to pipelinq by the archived change `2026-06-14-stuf-zkn-bg-adapter`. That
subsystem has now been **relocated to procest** — its new canonical home — under
procest PR #130 (branch `feat/stuf-zkn-outbound`), which owns the outbound engine,
the inbound receiver, the schemas, the settings views, and the integration docs.

Keeping a second copy of the StUF subsystem in pipelinq would mean two write paths
to the same zaaksysteem, divergent schemas, and duplicated maintenance. Per the
fleet's "one write path / shared abstraction" rule, the subsystem must live in
exactly one app. This change removes it from pipelinq now that procest is the home.

This is **STEP 2** of the migration. STEP 1 (procest PR #130) establishes the new
home and owns the procest-side docs. This change conceptually `depends_on` procest
PR #130: that PR should land first (or together) so the fleet always has a StUF
home. As this is the `development` channel the window is low-risk.

Out of scope and explicitly KEPT: the separate **ZGW REST/JSON-API bridge**
(`lib/Service/Zgw/*` — ZrcClient/ZtcClient/DrcClient/BrcClient/AcClient,
NrcSubscriptionService, ZgwNotificationController, NrcNotificationListener). That
is a different integration (REST zaakgericht-werken APIs, not SOAP StUF). Its
`ZgwCoexistenceValidator` references the `stufEndpoint` schema slug only as a
tolerant string-based double-write guard (REQ-ZGW-008); with the StUF schema gone
its `findAll` returns empty and the validator reduces to "ZGW only" exactly as
documented. No ZGW code is touched.

## What Changes

- **Backend services** removed: `lib/Service/Stuf/` in full — `StufEnvelopeBuilder`,
  `StufHttpClient`, `StufVaultService`, `StufMessageParser`, `StufMessageHandler`,
  `StufAdapterService`, `CircuitBreakerService`, `StufRegisterAccess`,
  `NeedsInputDispatcher`, `ContactBetrokkeneMapper`, `StufRequestIntegrationService`
  (confirmed dead — zero live callers of `registerZaak` / `syncContactToBetrokkene`),
  plus the six StUF exception classes.
- **Background job** removed: `lib/BackgroundJob/StufRetryJob.php` (it was
  self-scheduled by `StufAdapterService`, never listed in `info.xml`
  `<background-jobs>`, so no info.xml job entry needed removal).
- **Controller + routes** removed: `lib/Controller/StufController.php` and the four
  `stuf#` route entries (`outbound`, `inkomend`, `endpoints`, `messages`) in
  `appinfo/routes.php`.
- **Register/schemas** removed: `lib/Settings/register.d/85-stuf-zkn-bg-adapter.json`
  (the `stufEndpoint` + `stufMessage` schemas and their seed objects).
- **Frontend** removed: `src/views/settings/StufEndpoints.vue`,
  `src/views/settings/StufAuditLog.vue`, `src/services/stufApi.js`,
  `src/components/StufLinkedZaakBadge.vue` (the badge was orphaned — no references),
  their two `registry.js` imports + entries, the two `manifest.json` menu items and
  two `manifest.json` pages, the `StufEndpoints` entry + prose in
  `menu-layout.json`.
- **Tests** removed: `tests/Unit/Service/Stuf/` (the StUF unit tests).
- **Docs** removed: `docs/Integrations/stuf-zkn-bg-adapter.md` (procest STEP 1 owns
  the procest-side docs; not recreated here).
- `appinfo/info.xml` `<version>` bumped `0.5.12` → `0.5.13` (immutable cache-bust).

The archived change `openspec/changes/archive/2026-06-14-stuf-zkn-bg-adapter/`
stays in place as history (its `stuf-zkn-bg-adapter` capability was never synced
into `openspec/specs/`, so there is no main spec to remove — the removal is code).
