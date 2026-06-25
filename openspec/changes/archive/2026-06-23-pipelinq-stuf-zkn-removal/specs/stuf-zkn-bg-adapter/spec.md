# StUF-ZKN/BG adapter — removal delta (relocated to procest)

**Spec refs**: archived capability `stuf-zkn-bg-adapter` (change `2026-06-14-stuf-zkn-bg-adapter`), ADR-016 (route ordering), REQ-ZGW-008 (ZGW coexistence guard — KEPT)
**New home**: procest PR #130 (`feat/stuf-zkn-outbound`) — the outbound SOAP engine, the inbound `/inkomend` receiver, the `stufEndpoint`/`stufMessage` schemas, the settings views, and the integration docs now live in procest. This change removes the pipelinq copy so the fleet has exactly one StUF write path.

## REMOVED Requirements

### Requirement: StUF-ZKN/BG SOAP adapter in pipelinq

pipelinq MUST NOT host the StUF-ZKN/BG SOAP zaaksysteem adapter. The outbound
envelope engine (`StufEnvelopeBuilder` / `StufHttpClient` / `StufAdapterService` /
`CircuitBreakerService` / `StufRetryJob`), the inbound `POST /api/stuf/inkomend`
receiver and the outbound/list `stuf#` routes, the `stufEndpoint` + `stufMessage`
schemas and seed objects, and the `StufEndpoints` / `StufAuditLog` settings
surfaces are removed from pipelinq. The capability is relocated to procest
(PR #130), which is its single canonical home. The separate ZGW REST/JSON bridge
(`lib/Service/Zgw/*`, REQ-ZGW-008) is unaffected and remains in pipelinq.

#### Scenario: pipelinq no longer exposes StUF routes or settings surfaces

- GIVEN pipelinq is loaded on a Nextcloud instance after this change
- WHEN a user opens the pipelinq settings foldout and the main navigation
- THEN no "StUF endpoints" or "StUF audit log" entry is present
- AND the `stuf#outbound` / `stuf#inkomend` / `stuf#endpoints` / `stuf#messages`
  routes are not registered (a request to `/api/stuf/endpoints` falls through to
  the SPA index exactly like any non-existent route, returning no StUF handler)
- AND no `lib/Service/Stuf/*`, `lib/Controller/StufController.php`,
  `lib/BackgroundJob/StufRetryJob.php`, `lib/Settings/register.d/85-stuf-zkn-bg-adapter.json`,
  or StUF frontend file remains in the pipelinq tree

#### Scenario: the ZGW coexistence guard degrades cleanly without the StUF schema

- GIVEN the `stufEndpoint` schema has been removed with the StUF subsystem
- WHEN `ZgwCoexistenceValidator` runs its same-gemeente double-write check
  (REQ-ZGW-008)
- THEN `ZgwRegisterAccess::findAll('stufEndpoint', ...)` returns an empty list
  (its `catch (Throwable)` path), so the validator counts zero active StUF writers
- AND the write-path conflict check reduces to "ZGW only" without raising an error
