# Tasks — company lookups via the OpenRegister leaf (safe-partial)

## 1. KvK OR-first re-point (MVP)

- [x] 1.1 Inject `IURLGenerator` into `KvkApiClient`.
- [x] 1.2 `fetchResults()` — try `GET /apps/openregister/api/integrations/kvk/search` first (internal, OCS-APIREQUEST, allow_local_address); on 200 return raw rows in `{resultaten: …}` shape; on 503 / non-200 / OR-absent return null → fall back to the legacy `/zoeken` path unchanged.
- [x] 1.3 Keep `KvkResultMapper` + `pipelinq.kvk.*` config + legacy transport as the documented fallback.

## 2. OpenCorporates OR-first re-point (MVP)

- [x] 2.1 Inject `IURLGenerator` into `OpenCorporatesApiClient`.
- [x] 2.2 `fetchCompanies()` — try `GET /apps/openregister/api/integrations/opencorporates/search` first; on 200 re-wrap raw companies as `{results:{companies:[{company:…}]}}`; on 503 / OR-absent fall back to legacy path unchanged.
- [x] 2.3 Keep `OpenCorporatesResultMapper` + `pipelinq.opencorporates.*` config + legacy transport as the documented fallback.

## 3. BRP — STOP + report (decision)

- [x] 3.1 Do NOT re-point BRP. The OR `/brp/person` leaf drops the upstream correlation id + response timing that `HaalCentraalClient` + `BrpController` write into the Wet-BRP `brpLookupVerzoek` audit trail, so OR-first BRP is not behaviour-identical. Leave `HaalCentraalClient` + `BsnValidationService` + `normalisePerson()` untouched and report the blocker.

## 4. Tests (MVP)

- [x] 4.1 Per client: OR-200 → mapped output identical to the legacy mapping; OR leaf endpoint is hit and the direct endpoint is NOT.
- [x] 4.2 Per client: OR-503/absent → legacy fallback fires; the configured-env direct URL is hit and the mapped output is unchanged.
- [x] 4.3 Update existing KvkApiClientTest / OpenCorporatesApiClientTest constructor calls for the new `IURLGenerator` arg; suite ≥ baseline (1576 passing); `composer lint` + gated `phpcs --warning-severity=0` clean on changed `lib/`.

## 5. Verify

- [x] 5.1 Hydra gates green on the diff (gate-6/16/27 especially).
- [x] 5.2 Live on :8080 — with OR sources dormant, a KvK lookup falls back to legacy and returns / honestly degrades with no fatal; BSN never logged.
