# Tasks — BRP person lookup via the OpenRegister leaf (safe-partial)

## 1. BRP OR-first re-point (MVP)

- [x] 1.1 Inject `IURLGenerator` into `HaalCentraalClient` (autowired; no `Application.php` change).
- [x] 1.2 `lookupPersoon()` — try `GET /apps/openregister/api/integrations/brp/person?bsn=<bsn>` first (internal, OCS-APIREQUEST, allow_local_address, server-side). On 200 with a person, map `results[0]` through the EXISTING `normalisePerson()` and attach `_correlationId`/`_responseDurationMs`/`_responseStatus` from `meta`. On 200 with zero persons, return not-found (`null`) without a legacy call. On 503 / non-200 / OR-absent, return null → fall back to the legacy OAuth2 + mTLS direct path.
- [x] 1.3 Keep the elfproef + mask (`BsnValidationService`), `normalisePerson()`, the BRP OAuth2/mTLS app-config, and the legacy transport as the documented fallback (unchanged).
- [x] 1.4 `BrpController` unchanged — the OR-first logic lives in `HaalCentraalClient`, which returns the same `$remote` shape on both paths, so the controller's audit mapping is source-agnostic.

## 2. Audit-record-identical (Wet-BRP)

- [x] 2.1 `meta.correlationId → _correlationId` (`haalcentraalCorrelationId`), `meta.durationMs → _responseDurationMs` (`responseDuurMs`), `meta.status → _responseStatus` — same keys, same types as the legacy path.
- [x] 2.2 A null `meta.correlationId` persists as an absent field (controller `array_filter` of nulls), exactly as the legacy path records a null `X-Correlation-ID`.

## 3. BSN-never-logged

- [x] 3.1 OR path logs only the masked BSN; fallback debug line uses the masked BSN; BSN passed to the leaf in the query only (leaf BODYs it upstream, never logs it).

## 4. Tests (MVP)

- [x] 4.1 `HaalCentraalClientTest` — OR-200+meta → `normalisePerson` output + `_correlationId`/`_responseDurationMs`/`_responseStatus` populated; null-correlation persists null; OR-200 empty results → not-found (null); OR-503/absent → legacy fallback fires; raw BSN never logged on the OR path AND the fallback path.
- [x] 4.2 `BrpControllerTest` — the persisted `brpLookupVerzoek` carries `haalcentraalCorrelationId`/`responseDuurMs`/`responseStatus` from the client meta, identical regardless of transport; null correlation id is filtered out; raw BSN never a field on the record.

## 5. Quality + verify

- [x] 5.1 `composer lint` + `phpcs --warning-severity=0` clean on changed `lib/`.
- [x] 5.2 Full suite ≥ baseline 1576 passing (1584 green).
- [x] 5.3 All 27 hydra gates green on the diff.
- [x] 5.4 LIVE-verify on :8080 (OR source dormant): a lookup falls back to legacy, degrades with a 503 (no fatal), BSN absent from logs. OR-200 happy path is unit-tested against the documented contract (not live-exercisable on :8080 — the env's OR is on a branch without the BRP leaf).

## 6. Operator step (later cutover)

- [ ] 6.1 In OpenConnector, configure + enable the `brp-haalcentraal` source (OAuth2 creds + PKIoverheid/mTLS cert). Then BRP lookups route through OR automatically and write the same Wet-BRP audit record; pipelinq's `brp.*` creds + cert become the dormant fallback (removable in a follow-up).
