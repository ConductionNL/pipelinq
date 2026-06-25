---
kind: code
depends_on: [pipelinq-lookups-via-or-leaf]
---

## Why

`pipelinq-lookups-via-or-leaf` re-pointed the KvK + OpenCorporates company lookups OR-first but **explicitly STOPPED on BRP**: the OpenRegister `/brp/person` leaf returned only `{results, total}` (raw HAL+JSON) and discarded the upstream `X-Correlation-ID` header + response timing. `HaalCentraalClient::lookupPersoon()` reads both off the direct HaalCentraal response and `BrpController::lookup()` persists them into the legally-required Wet-BRP `brpLookupVerzoek` audit record (`haalcentraalCorrelationId`, `responseDuurMs`, `responseStatus`). An OR-first BRP path would therefore have written audit records missing those fields — a compliance regression on live government BSN data.

OpenRegister has now landed `integration-brp-audit-metadata` (openregister/development `c519217a7`): the `/brp/person` leaf relays the Wet-BRP audit metadata as `meta: { correlationId, durationMs, status }` on a 200, leaving the 503 degraded contract unchanged and never placing the BSN in `meta`. With the correlation id + duration + status now available on the OR path, the BRP re-point can be made **provably audit-record-identical**, so the same xWiki/KvK safe-partial pattern now applies to BRP.

This change re-points BRP OR-first with a byte-for-byte legacy fallback, preserving the legal audit trail exactly. It is the deferred follow-up the prior change named ("Re-pointing BRP becomes viable once the OR leaf is extended to relay the correlation id + duration").

## What Changes

- `HaalCentraalClient::lookupPersoon()` — try `GET /apps/openregister/api/integrations/brp/person?bsn=<bsn>` first (via `IClientService` + `IURLGenerator`, internal, `OCS-APIREQUEST`, `allow_local_address`, server-side; the BSN is passed in the query, which the leaf places in the upstream request BODY and never logs). On HTTP 200 with a person, map `results[0]` through the EXISTING `normalisePerson()` exactly as today, AND map `meta` into the audit fields the controller derives — `meta.correlationId → _correlationId` (`haalcentraalCorrelationId`), `meta.durationMs → _responseDurationMs` (`responseDuurMs`), `meta.status → _responseStatus` — so the persisted `brpLookupVerzoek` record is IDENTICAL in shape and values to the legacy path. On a 200 with zero persons, return not-found (`null`) without a legacy call. On a 503 / non-200 / OR-absent, fall back to the existing OAuth2 + mTLS direct HaalCentraal path **byte-for-byte**.
- The `BsnValidationService` elfproef + mask runs BEFORE any call (in `BrpController::lookup`) regardless of path — **unchanged**. `BrpController` itself is **unchanged**: the OR-first logic lives entirely in `HaalCentraalClient`, which returns the same `$remote` array shape on both paths, so the controller's audit mapping (lines ~264-267) is source-agnostic and byte-identical.
- `HaalCentraalClient` gains an injected `IURLGenerator` to resolve the OR endpoint (the only constructor change). It is NC-DI autowired, so no `Application.php` registration change.

## Capabilities

### Added Capabilities

- `brp-lookup` — the BRP person lookup becomes OR-first with a behaviour-preserving legacy fallback (ADR-022, safe-partial), the Wet-BRP audit record (`haalcentraalCorrelationId`, `responseDuurMs`, `responseStatus`) preserved exactly across both transports, and the BSN never logged on either path.

## Impact

- **Code**: `lib/Service/HaalCentraalClient.php` — OR-first lookup + meta→audit mapping + legacy fallback; `+IURLGenerator`. `BsnValidationService` (elfproef + mask), `normalisePerson()`, the BRP OAuth2/mTLS app-config, and the existing direct transport (`getAccessToken()`, `buildHttpClient()`, the legacy POST body) are **kept unchanged** (documented fallback — deleting them is a later change once an operator configures + enables the OR `brp-haalcentraal` source). `BrpController` is untouched.
- **Audit-record-identical proof**: both transports populate the same three keys (`_correlationId` string|null, `_responseDurationMs` int, `_responseStatus` int) with the same types; the controller maps them to `haalcentraalCorrelationId` / `responseDuurMs` / `responseStatus` identically. A null `meta.correlationId` (no upstream header) persists as an absent field, exactly as the legacy path records a null `X-Correlation-ID`.
- **BSN-never-logged**: the OR path logs only the masked BSN (`BsnValidationService::mask`); the fallback debug line uses the masked BSN; the BSN is passed to the leaf in the query string only (the leaf BODYs it upstream and never logs it). Covered by unit tests.
- **Depends on** `pipelinq-lookups-via-or-leaf` (KvK/OpenCorporates re-point) and the OR `integration-brp-audit-metadata` leaf. With the OR `brp-haalcentraal` source dormant (no OAuth creds / no PKIoverheid cert until an operator configures it), the lookup falls back to legacy (no regression).
- **Operator step (later cutover)**: in OpenConnector, configure + enable the `brp-haalcentraal` source (OAuth2 creds + PKIoverheid/mTLS cert). Once enabled, BRP lookups route through OR automatically and write the same Wet-BRP audit record; pipelinq's own `brp.*` creds + cert then become the dormant fallback and can be removed in a follow-up change.
- No data migration, no schema change, no new route, no Vue change.
