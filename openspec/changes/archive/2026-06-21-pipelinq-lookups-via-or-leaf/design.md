# Design — company lookups via the OpenRegister leaf (safe-partial)

## The re-point (xWiki safe-partial pattern)

Both lookups already split into a `search()` orchestrator → per-criterion `fetch*()` HTTP leg → mapper. The re-point touches only the HTTP leg, so the orchestrator + mapper are byte-identical regardless of source.

### KvK

`KvkApiClient::fetchResults($apiKey, $sbiCode)` previously hit `GET {base}/zoeken?sbiHoofdActiviteit=…&apikey=…`. It now first calls:

```
GET /apps/openregister/api/integrations/kvk/search?sbiHoofdActiviteit={sbi}&type=hoofdvestiging&limit=50&page=1
  headers: { OCS-APIREQUEST: true, Accept: application/json }
  nextcloud: { allow_local_address: true }   // internal, server-side
```

OR's `kvkSearch` round-trips the raw KvK rows under `results` (it does not interpret them — the mapping stays in pipelinq). On HTTP 200, `fetchResults` returns `{resultaten: <results>}` so `searchBySbiCode()` runs each row through the unchanged `KvkResultMapper::mapResult($item, $sbiCode)`. On a client exception (OR's 503 surfaces as one) or any non-200, `fetchResults` falls through to the original `/zoeken` direct path unchanged.

### OpenCorporates

`OpenCorporatesApiClient::fetchCompanies($keyword)` previously hit `GET {base}/companies/search?q=…&jurisdiction_code=nl`. It now first calls `GET /apps/openregister/api/integrations/opencorporates/search?q=…&jurisdiction=nl&limit=30&page=1` (same internal headers). OR's `openCorporatesSearch` returns the raw company objects under `results` (it unwraps `results.companies[].company`). On 200, `fetchCompanies` re-wraps each as `{company: …}` and returns `{results: {companies: [...]}}` so `searchByKeyword()` runs each through the unchanged `OpenCorporatesResultMapper::mapResult($company)`. On 503 / OR-absent → legacy direct path unchanged.

## Safe-partial guarantees (no regression)

- The OR leaf returns the **raw upstream rows** in the exact shape pipelinq's existing mappers consume, so OR-200 output is identical to legacy output for the same upstream data.
- A 503 / OR-absent surfaces as a client exception (or non-200) and triggers the legacy path **byte-for-byte** (same URL, creds, headers, timeout). Configured envs keep working until an operator enables the OR source.
- Covered by unit tests per client: OR-200 → mapped output identical to legacy; OR-503/absent → legacy fallback fires and the configured-env URL + mapped output are unchanged.

## What stays (documented fallback / unchanged)

- `KvkResultMapper`, `OpenCorporatesResultMapper` — unchanged; they are the single mapping authority for both the OR and legacy paths.
- `pipelinq.kvk.api_base_url` + key, `pipelinq.opencorporates.api_base_url` — kept as the dormant fallback config + transport. Removing them is a later change, after an operator configures + enables the OR sources.
- The existing direct `fetchResults` / `fetchCompanies` transport bodies — kept as the fallback leg.

## BRP — STOPPED (not re-pointed)

BRP is the most sensitive surface (live BSN, government data). The OR `/brp/person?bsn=` leaf returns `{results, total}` carrying the raw HAL+JSON `personen`, but it **does not surface** the upstream `X-Correlation-ID` header or the response duration. `HaalCentraalClient::lookupPersoon()` reads both off the HaalCentraal response and `BrpController::lookup()` persists them into the `brpLookupVerzoek` audit object (`haalcentraalCorrelationId`, `responseDuurMs`) — Wet-BRP-required audit-trail fields. An OR-first BRP path would therefore write audit records missing the correlation id + timing: **not behaviour-identical**, and a compliance regression on live gov data.

Per the safe-partial rule ("if the OR-first re-point can't be made provably behaviour-identical for BRP, do KvK+OpenCorporates and STOP+report on BRP"), BRP is left entirely on its existing OAuth2 + mTLS path. The elfproef (`BsnValidationService::validate`, run before any call in `BrpController::lookup`), the BSN masking, and `normalisePerson()` are all untouched. Re-pointing BRP becomes viable once the OR leaf is extended to relay the correlation id + duration (then the same safe-partial pattern applies).

## Operator step (later cutover)

In OpenConnector, configure + enable the `kvk` and `opencorporates` sources (vendor base URL + key). Lookups then route through OR automatically; the `pipelinq.kvk.*` / `pipelinq.opencorporates.*` creds become the dormant fallback and can be removed in a follow-up change.
