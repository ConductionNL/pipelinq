---
kind: code
depends_on: [integration-kvk-opencorporates]
---

## Why

Pipelinq's company-lookup clients (`KvkApiClient`, `OpenCorporatesApiClient`) call the KvK and OpenCorporates APIs directly with pipelinq's own credentials. OpenRegister just shipped the company-lookup leaves (`/apps/openregister/api/integrations/kvk/search`, `/opencorporates/search`, per ADR-022 — connection + credentials live in OpenConnector). The OR sources are configured but **dormant** (no creds yet), so a naive cut-over would regress configured envs that work today.

This change re-points the two company lookups using the **xWiki safe-partial pattern** (`pipelinq-xwiki-through-or`): OR-first, with a byte-for-byte legacy fallback. When an operator later configures + enables the OR sources the lookups silently move to OR; until then they keep working through pipelinq's existing creds, with zero behaviour change.

BRP is explicitly **out of scope** of this change (see Impact → BRP) — re-pointing it through the current OR `/brp/person` leaf cannot be made provably behaviour-identical for the Wet-BRP audit trail, so it stays on the existing `HaalCentraalClient` untouched.

## What Changes

- `KvkApiClient::fetchResults()` — try `GET /apps/openregister/api/integrations/kvk/search?sbiHoofdActiviteit=…` first (via `IClientService` + `IURLGenerator`, internal, `OCS-APIREQUEST`, `allow_local_address`, server-side). On HTTP 200, take the raw KvK rows from `results` and return them in the legacy `{resultaten: […]}` shape, so the existing `searchBySbiCode()` loop maps each row through the **unchanged** `KvkResultMapper` exactly as today. On a 503 (`details.cause` = source-missing/down) or OR-absent, return null → fall back to the existing direct `/zoeken` path **byte-for-byte** (current creds/transport).
- `OpenCorporatesApiClient::fetchCompanies()` — try `GET /apps/openregister/api/integrations/opencorporates/search?q=…` first. On 200, re-wrap the raw company objects from `results` as `{results: {companies: [{company: …}]}}` so the existing `searchByKeyword()` loop maps each through the **unchanged** `OpenCorporatesResultMapper` exactly as today. On 503 / OR-absent → fall back to the existing direct path byte-for-byte.
- Both clients gain an injected `IURLGenerator` to resolve the OR endpoint (the only constructor change). Both are NC-DI autowired, so no `Application.php` registration change.

## Capabilities

### Modified Capabilities

- `company-lookup` — KvK + OpenCorporates lookups become OR-first with a behaviour-preserving legacy fallback (ADR-022, xWiki safe-partial), so the OR sources can be cut over by an operator with no pipelinq change.

## Impact

- **Code**: `lib/Service/KvkApiClient.php`, `lib/Service/OpenCorporatesApiClient.php` — OR-first fetch + legacy fallback; `+IURLGenerator`. The `KvkResultMapper` / `OpenCorporatesResultMapper`, the `pipelinq.kvk.*` / `pipelinq.opencorporates.*` config + creds, and the existing direct transport methods are **kept unchanged** (documented fallback — deleting them is a later change once an operator configures + enables the OR sources).
- **BRP is NOT re-pointed (STOPPED, documented).** `HaalCentraalClient` (BRP) stays on its existing OAuth2 + mTLS path. The OR `/brp/person` leaf returns `{results, total}` (raw HAL+JSON) but **discards the upstream `X-Correlation-ID` header + response timing**, which `HaalCentraalClient::lookupPersoon()` extracts and `BrpController` writes into the `brpLookupVerzoek` audit record (`haalcentraalCorrelationId`, `responseDuurMs`). Those are Wet-BRP compliance fields on live government BSN data, so an OR-first BRP path would NOT be behaviour-identical — it would silently degrade a legally-required audit trail. Per the safe-partial rule, BRP is left as-is and reported rather than risked. `BsnValidationService` (elfproef + mask) and `normalisePerson()` are also untouched.
- **Depends on** `integration-kvk-opencorporates` (the OR company-lookup leaves). With the OR sources dormant, the lookups fall back to legacy (no regression).
- **Operator step (later cutover)**: in OpenConnector, configure + enable the `kvk` and `opencorporates` sources (vendor base URL + key). Once enabled, lookups route through OR automatically; pipelinq's own `pipelinq.kvk.*` / `pipelinq.opencorporates.*` creds then become the dormant fallback and can be removed in a follow-up change.
- No data migration, no schema change, no new route, no Vue change.
