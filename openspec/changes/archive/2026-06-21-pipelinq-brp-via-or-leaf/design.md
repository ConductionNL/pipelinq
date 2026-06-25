# Design — BRP person lookup via the OpenRegister leaf (safe-partial)

## The re-point (xWiki/KvK safe-partial pattern, applied to BRP)

`HaalCentraalClient::lookupPersoon($bsn, $verzoekIdContext)` already returns a `normalisePerson()`-shaped array stamped with three audit keys (`_correlationId`, `_responseDurationMs`, `_responseStatus`) that `BrpController::lookup()` reads (lines ~264-267) and persists into the `brpLookupVerzoek` record. The re-point touches only the **transport leg** inside `lookupPersoon()`; the return shape into the controller is unchanged, so `BrpController` is byte-identical and the elfproef + mask still run before any call.

```
GET /apps/openregister/api/integrations/brp/person?bsn={bsn}
  headers: { OCS-APIREQUEST: true, Accept: application/json }
  nextcloud: { allow_local_address: true }   // internal, server-side
  timeout: 5s, connect_timeout: 2s
```

The BSN is in the query only; the OR leaf places it in the upstream request BODY and never logs it (OR `integration-brp-audit-metadata` contract). This client logs only the masked BSN.

### Success (HTTP 200)

OR returns `{ results: [<raw HAL+JSON person, 0..1>], total, meta: { correlationId, durationMs, status } }`.

- `results[0]` present → `normalisePerson(raw: results[0])` (the SAME mapper the legacy path uses → identical BrpPersoon body for the same upstream data), then attach:
  - `_correlationId`      ← `meta.correlationId` (string|null)
  - `_responseDurationMs` ← `(int) meta.durationMs`
  - `_responseStatus`     ← `(int) meta.status`
- `results` empty → return the `OR_EMPTY_RESULT` sentinel → `lookupPersoon()` maps it to `null` (legacy not-found semantics) WITHOUT a legacy call.

### Failure / dormant (503 / non-200 / OR-absent)

A 503 (`details.cause` ∈ openconnector-down / openconnector-source-missing / provider-auth / upstream-service-down) surfaces as a client exception on `get()`, as does connection-refused / OR absent. Any non-200, or a malformed body, also returns null. `lookupPersoon()` then falls through to the existing OAuth2 + mTLS direct HaalCentraal path **byte-for-byte** (same token grant, same `/personen` POST body, same `X-Correlation-ID` header read, same `microtime` duration, same status). Configured envs keep working and write the same audit record as today.

## Audit-record-identical proof (OR path vs legacy)

| `brpLookupVerzoek` field        | Legacy source                                   | OR source                          |
|---------------------------------|-------------------------------------------------|------------------------------------|
| `haalcentraalCorrelationId`     | `_correlationId` ← `X-Correlation-ID` header    | `_correlationId` ← `meta.correlationId` |
| `responseDuurMs`                | `_responseDurationMs` ← `(int)(elapsed*1000)`   | `_responseDurationMs` ← `(int) meta.durationMs` |
| `responseStatus` (`responseCode`)| `_responseStatus` ← `(int) response status`    | `_responseStatus` ← `(int) meta.status` |

Both paths populate the **same three keys with the same types**, and the controller (unchanged) maps them to the same record fields. A null correlation id (no upstream header on either path) persists as an absent field via the controller's existing `array_filter` of nulls. The record shape and values are therefore identical for the same upstream data — the Wet-BRP audit trail is preserved exactly.

## No-regression fallback proof

- The OR leaf returns the **raw upstream HAL+JSON person** in the exact shape `normalisePerson()` consumes, so OR-200 output equals legacy output for the same data.
- A 503 / OR-absent surfaces as a client exception (or non-200) and triggers the legacy path **byte-for-byte** (same OAuth grant, mTLS cert, base URL, `/personen` body, headers, timeout). The legacy `getAccessToken()`, `buildHttpClient()`, and the POST transport are unchanged.
- Covered by unit tests: OR-200+meta → mapped output + audit fields; OR-200 empty → not-found; OR-503/absent → legacy fallback reached; BSN never logged on either leg.

## What stays (documented fallback / unchanged)

- `BsnValidationService` (elfproef + mask) — runs before any call in `BrpController::lookup`, unchanged; the first line of `lookupPersoon()` still masks.
- `normalisePerson()` — the single mapping authority for both the OR and legacy paths.
- `getAccessToken()`, `buildHttpClient()` (mTLS), the legacy `/personen` POST, and the `brp.*` OAuth/mTLS app-config — kept as the dormant fallback transport. Removing them is a later change, after an operator configures + enables the OR `brp-haalcentraal` source.
- `BrpController` — entirely unchanged (the re-point is contained in `HaalCentraalClient`).

## Live vs unit

- **Live (:8080)**: the env's OpenRegister is on a branch without the BRP leaf, so the OR-first GET 404s/refuses → the lookup falls back to legacy → unconfigured OAuth → a clean 503 `HaalCentraalException` (no fatal). Verified: the raw BSN appears 0 times in `nextcloud.log`.
- **Unit (documented contract)**: the OR-200 happy path (person + meta → audit fields), the OR-200 empty (not-found), and the null-correlation case are unit-tested against the documented OR leaf contract (`{ results, total, meta }`), since they are not live-exercisable on :8080 with the OR source dormant.

## Operator step (later cutover)

In OpenConnector, configure + enable the `brp-haalcentraal` source (OAuth2 client credentials + PKIoverheid/mTLS certificate). BRP lookups then route through OR automatically and write the same Wet-BRP `brpLookupVerzoek` audit record; pipelinq's own `brp.*` OAuth/mTLS creds become the dormant fallback and can be removed in a follow-up change.
